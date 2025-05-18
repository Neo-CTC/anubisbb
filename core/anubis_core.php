<?php
// PHPStorm complains that sodium needs to be added to composer but sodium
// is builtin since php7.2, so this "should" work
/** @noinspection PhpComposerExtensionStubsInspection */

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

	private $cookie;

	public function __construct(config $config, request $request, user $user)
	{
		$this->error   = '';
		$this->version = 'v1.18.0-pre1-4-g3701b2b';

		$this->request = $request;
		$this->user    = $user;

		$this->difficulty = $config['anubisbb_difficulty'];
		// $this->difficulty = 5;
		$this->secret_key = hex2bin($config['anubisbb_sk']);
		$this->cookie     = '';
		$this->cookie_time = 60;
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
		$lang = $this->request->server('HTTP_ACCEPT_LANGUAGE', '');
		if (!$lang)
		{
			$this->error = 'Missing Accept-Language header';
			return false;
		}

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
			"Accept-Language=%s,X-Real-IP=%s,User-Agent=%s,WeekTime=%s,Fingerprint=%s,Difficulty=%d",
			$lang,
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
	public function pass_challenge($bake_cookie = true): bool
	{
		$response = $this->request->variable('response', '');
		if (!$response)
		{
			$this->error ?: $this->error = "Invalid request";
			return false;
		}

		$nonce = $this->request->variable('nonce', 0);
		if (!$nonce)
		{
			$this->error ?: $this->error = "Invalid request";
			return false;
		}

		$challenge = $this->make_challenge();
		if (!$challenge)
		{
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

		if ($bake_cookie)
		{
			$this->bake_cookie($challenge, $response, $nonce);
		}

		return true;
	}

	public function validate_cookie()
	{
		$jwt = $this->request->variable($this->cookie_name, '',true, request_interface::COOKIE);

		// Split the token into header, payload, signature
		$hps = explode('.', $jwt);


		// Decode the signature back to bytes
		$sig = $this->b64_decode_url($hps[2]);

		// Rebuild the signed string
		$string = $hps[0] . '.' . $hps[1];

		// Grab the public key from the secret key
		$public_key = substr($this->secret_key, SODIUM_CRYPTO_SIGN_SEEDBYTES);

		// Validate the signed string
		if (!sodium_crypto_sign_verify_detached($sig, $string, $public_key))
		{
			return false;
		}

		// Unpack the cookie
		$payload = json_decode($this->b64_decode_url($hps[1]), true);

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
	 * Grabs a pre baked cookie
	 *
	 * @return false|string
	 */
	public function grab_cookie()
	{
		return ($this->cookie ?: false);
	}

	/**
	 * Create a Json Web Token (JWT), must first pass the challenge
	 *
	 * @param $challenge
	 * @param $response
	 * @param $nonce
	 *
	 * @throws \SodiumException
	 */
	private function bake_cookie($challenge, $response, $nonce)
	{
		$header = json_encode([
			"alg" => 'EdDSA',
			'typ' => "JWT",
		]);

		$time = time();
		// TODO: expiration setting
		$payload = json_encode([
			"challenge" => $challenge,
			"exp"       => $time + $this->cookie_time, // Expiration, 1 week
			"iat"       => $time,      // Issued
			"nbf"       => $time - 60, // Not before
			"nonce"     => $nonce,
			"response"  => $response,
		]);

		// Combine parts 1 & 2 of the token
		$jwt = $this->b64_encode_url($header) . "." . $this->b64_encode_url($payload);

		// Sign the token
		$sig = sodium_crypto_sign_detached($jwt, $this->secret_key);

		// Append the signature to the token, and the cookie is baked
		$this->cookie = $jwt . "." . $this->b64_encode_url($sig);
	}

	/**
	 * Create a Json Web Token (JWT) when the user logs out
	 *
	 * @throws \SodiumException
	 */
	public function logout_cookie()
	{
		$header = json_encode([
			"alg" => 'EdDSA',
			'typ' => "JWT",
		]);

		$time = time();
		// TODO: expiration setting
		$payload = json_encode([
			"challenge" => $this->make_challenge(),
			"exp"       => $time + $this->cookie_time, // Expiration, 1 week
			"iat"       => $time,      // Issued
			"nbf"       => $time - 60, // Not before
		]);

		// Combine parts 1 & 2 of the token
		$jwt = $this->b64_encode_url($header) . "." . $this->b64_encode_url($payload);

		// Sign the token
		$sig = sodium_crypto_sign_detached($jwt, $this->secret_key);

		// Append the signature to the token, and the cookie is baked
		return $jwt . "." . $this->b64_encode_url($sig);
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
