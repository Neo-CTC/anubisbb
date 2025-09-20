<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\core;

use DateTime;
use DateTimeZone;
use phpbb\config\config;
use phpbb\request\request;
use phpbb\request\request_interface;
use phpbb\user;
use SodiumException;

class anubis_core
{
	public $error;
	public $version;
	private $cookie_name;
	private $cookie_time;

	private $request;
	private $user;
	private $difficulty;
	private $secret_key;

	public function __construct(config $config, request $request, user $user)
	{
		$this->error   = '';
		$this->version = 'v1.18.0-pre1-4-g3701b2b';

		$this->request = $request;
		$this->user    = $user;

		$this->difficulty = $config['anubisbb_difficulty'];
		$this->secret_key = hex2bin($config['anubisbb_sk']);

		// Set cookie ttl with a safe minimum of 60 seconds
		$this->cookie_time = ((int) $config['anubisbb_ctime'] > 60) ? (int) $config['anubisbb_ctime'] : 60;

		$this->cookie_name = $config['cookie_name'] . '_anubisbb';

		if (strlen($this->secret_key) != SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)
		{
			// Generate secret+public keys
			$kp = sodium_crypto_sign_keypair();

			// Save only the secret key
			$sk = sodium_crypto_sign_secretkey($kp);

			$config->set('anubisbb_sk', bin2hex($sk));
			$this->secret_key = $sk;
		}
	}

	/**
	 * Generates the challenge string
	 *
	 * Based on the challengeFor function from Anubis
	 * https://github.com/TecharoHQ/anubis/blob/main/lib/anubis.go#L71
	 *
	 * @return false|string
	 */
	public function make_challenge()
	{
		// TODO: Do we really need $user for this? Can't we get it from $_SERVER?
		$ip      = $this->user->ip;
		$browser = $this->user->browser;
		if (!$browser)
		{
			$this->error = 'Missing User-Agent header';
			return false;
		}

		// Anubis rounds the time to the nearest week, a.k.a. the nearest Monday
		$monday = new DateTime('now', new DateTimeZone('UTC'));
		$monday->modify((getdate()['wday'] !== 1 ? 'last' : 'this') . " monday");

		// Half a week is 3.5 days or 302400 seconds
		if (time() - $monday->getTimestamp() >= 302400)
		{
			$monday = new DateTime('now', new DateTimeZone('UTC'));
			$monday->modify('next monday');
		}
		// RFC3339 format but with a Z
		$time = $monday->format("Y-m-d\TH:i:s\Z");

		$fingerprint = hash('sha256', $this->secret_key);

		$challengeData = sprintf(
			"X-Real-IP=%s,User-Agent=%s,WeekTime=%s,Fingerprint=%s,Difficulty=%d",
			$ip,
			$browser,
			$time,
			$fingerprint,
			$this->difficulty
		);
		return hash('sha256', $challengeData);
	}

	/**
	 * Compares response hash to known hash
	 *
	 * @return bool
	 */
	public function pass_challenge(): bool
	{
		$response = $this->request->variable('response', '');
		if (!$response)
		{
			$this->error = "Invalid request";
			return false;
		}

		$nonce = $this->request->variable('nonce', 0);
		if (!$nonce)
		{
			$this->error = "Invalid request";
			return false;
		}

		$challenge = $this->make_challenge();
		if (!$challenge)
		{
			// The make challenge function should have set its own error message
			return false;
		}

		$challenge_hash = hash('sha256', $challenge . $nonce);

		// Important! Known hash must go first because the php manual says so
		if (!hash_equals($challenge_hash, $response))
		{
			// TODO: log bad attempts to log file
			$this->error = "Invalid hash";
			return false;
		}

		if (substr($challenge_hash, 0, $this->difficulty) !== str_repeat('0', $this->difficulty))
		{
			$this->error = "Invalid hash";
			return false;
		}

		$this->bake_cookie();
		return true;
	}

	public function validate_cookie()
	{
		// Can't validate a cookie that's not there
		if (!$this->request->is_set($this->cookie_name, request_interface::COOKIE))
		{
			return false;
		}

		// Run checks on cookie, remove cookie if any check fails
		if ($this->cookie_check())
		{
			return true;
		}
		else
		{
			$this->remove_cookie();
			return false;
		}
	}

	private function cookie_check()
	{
		// JSON Web Token
		$jwt = $this->request->variable($this->cookie_name, '', true, request_interface::COOKIE);

		// Split the token into payload and signature
		$token = explode('.', $jwt);

		// Decode the signature back to bytes
		$sig = $this->b64_decode_url($token[1]);

		// Grab the public key from the secret key
		$public_key = substr($this->secret_key, SODIUM_CRYPTO_SIGN_SEEDBYTES);

		// Validate the signed string
		try
		{
			if (!sodium_crypto_sign_verify_detached($sig, $token[0], $public_key))
			{
				return false;
			}
		}
		catch (SodiumException $e)
		{
			return false;
		}

		// Unpack the cookie
		$payload = json_decode($this->b64_decode_url($token[0]), true);

		// Ignore stale cookies
		if ($payload['exp'] < time() || time() < $payload['nbf'])
		{
			return false;
		}

		// The challenge string is more or less a fingerprint of the browser.
		// Make sure it still matches
		if ($payload['challenge'] !== $this->make_challenge())
		{
			return false;
		}

		return true;
	}

	/**
	 * Create a Json Web Token (JWT)
	 */
	private function bake_cookie()
	{
		$time    = time();
		$payload = json_encode([
			"challenge" => $this->make_challenge(), // Challenge str from browser fingerprint
			"exp"       => $time + $this->cookie_time, // Expiration, 1 week
			"iat"       => $time,      // Issued
			"nbf"       => $time - 60, // Not before

			// Unused
			// "nonce"     => $nonce,
			// "response"  => $response,
		]);

		// Encode the token
		$jwt = $this->b64_encode_url($payload);

		// Sign the token
		try
		{
			$sig = sodium_crypto_sign_detached($jwt, $this->secret_key);
		}
		catch (SodiumException $e)
		{
			// Problem?
			// TODO: log errors
			$this->remove_cookie();
			return;
		}

		// Append the signature to the token, and the cookie is baked
		$jwt .= "." . $this->b64_encode_url($sig);
		$this->user->set_cookie('anubisbb', $jwt, $time + $this->cookie_time);
	}

	/**
	 * Remove expired/invalid cookies
	 */
	private function remove_cookie()
	{
		$this->user->set_cookie('anubisbb', '', 1);
	}

	/**
	 * Create a Json Web Token (JWT) when the user logs out
	 *
	 * @throws \SodiumException
	 */
	public function logout_cookie()
	{
		// Double check user is logging out
		if (defined('IN_LOGOUT'))
		{
			$this->bake_cookie();
		}
	}

	/**
	 * URL safe base64 encoding
	 *
	 * @param $string
	 *
	 * @return array|string|string[]
	 */
	private function b64_encode_url($string)
	{
		return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($string));
	}

	/**
	 * URL safe base64 decoding
	 *
	 * @param $string
	 *
	 * @return false|string
	 */
	private function b64_decode_url($string)
	{
		return base64_decode(str_replace(['-', '_'], ['+', '/'], $string));
	}
}
