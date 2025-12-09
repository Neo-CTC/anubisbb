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

use phpbb\config\config;
use phpbb\controller\helper as controller_helper;
use phpbb\request\request;
use phpbb\request\request_interface;
use phpbb\user;
use SodiumException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class anubis_core
{
	public $error;
	public $version;
	public $cookie_name;
	private $cookie_time;

	private $config;
	private $request;
	private $user;
	private $difficulty;
	private $secret_key;
	public $routes;

	const GUEST_TTL = 1800; // 30 minutes

	public function __construct(config $config, controller_helper $controller_helper, request $request, user $user)
	{
		$this->error   = '';
		$this->version = 'v1.18.0-pre1-4-g3701b2b';

		$this->config  = $config;
		$this->request = $request;
		$this->user    = $user;

		$this->difficulty = $this->config['anubisbb_difficulty'];

		$this->secret_key = hex2bin($this->config['anubisbb_sk']);
		if (strlen($this->secret_key) != SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)
		{
			$this->gen_sk();
		}

		// Set cookie ttl with a safe minimum of 2 minutes to prevent lockouts
		$this->cookie_time = max($this->config['anubisbb_ctime'], 120);

		$this->cookie_name = $this->config['cookie_name'] . '_anubisbb';

		$pages_base = $controller_helper->route('neodev_anubisbb_pages', [], true, '');
		$api_base   = $controller_helper->route('neodev_anubisbb_api', [], true, '');

		$this->routes = [
			'cc'      => $pages_base . '/c_check',
			'contact' => $pages_base . '/contact',
			'login'   => $pages_base . '/login',
			'nojs'    => $controller_helper->route('neodev_anubisbb_pages', ['name' => 'nojs'], true, '', UrlGeneratorInterface::ABSOLUTE_URL),

			'make' => $api_base . '/make_challenge',
			'pass' => $api_base . '/pass_challenge',
		];
	}

	public function gen_sk(): void
	{
		// Generate secret+public keys
		$kp = sodium_crypto_sign_keypair();

		// Save only the secret key
		$sk = sodium_crypto_sign_secretkey($kp);

		$this->config->set('anubisbb_sk', bin2hex($sk));
		$this->secret_key = $sk;
	}

	/**
	 * Generates the challenge string
	 *
	 * Based on the challengeFor function from Anubis
	 * https://github.com/TecharoHQ/anubis/blob/main/lib/anubis.go#L71
	 *
	 * @param $time
	 *
	 * @return false|string
	 */
	public function make_challenge($time)
	{
		// TODO: add in hmac somewhere
		$ip      = $this->user->ip;
		$browser = $this->user->browser;
		if (!$browser)
		{
			$this->error = 'Missing User-Agent header';
			return false;
		}

		$fingerprint = hash('sha256', $this->secret_key);

		$challengeData = sprintf(
			'X-Real-IP=%s,User-Agent=%s,WeekTime=%s,Fingerprint=%s,Difficulty=%d',
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
			$this->error = 'Missing response';
			return false;
		}

		$nonce = $this->request->variable('nonce', 0);
		if (!$nonce)
		{
			$this->error = 'Missing nonce';
			return false;
		}

		$timestamp = $this->request->variable('timestamp', 0);
		$time      = time();

		// Timestamp can't be in the future nor older than 10 minutes
		if ($timestamp > $time || $timestamp < $time - 600)
		{
			$this->error = 'Invalid timestamp';
			return false;
		}

		$challenge = $this->make_challenge($timestamp);
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
			$this->error = 'Invalid hash';
			return false;
		}

		if (substr($challenge_hash, 0, $this->difficulty) !== str_repeat('0', $this->difficulty))
		{
			$this->error = 'Wrong hash';
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
		$jwt = $this->request->variable($this->cookie_name, '', false, request_interface::COOKIE);
		if (!$jwt)
		{
			return false;
		}

		// Unpack the cookie and grab the challenge hash
		$payload = $this->jwt_unpack($jwt);

		// Bad cookie
		if ($payload === false)
		{
			return false;
		}

		// Lenient mode, skip challenge check
		if ($this->config['anubisbb_strict_cookies'] === '0')
		{
			return true;
		}

		// The challenge string is more or less a fingerprint of the browser.
		// Make sure it still matches
		return hash_equals($this->make_challenge($payload['iat']), $payload['data']);
	}

	/**
	 * Create a Json Web Token (JWT)
	 */
	private function bake_cookie()
	{
		$time    = time();
		$expires = $time + $this->cookie_time;
		$payload = $this->make_challenge($time);

		$jwt = $this->jwt_create($payload, $time, $expires);
		if ($jwt)
		{
			$this->user->set_cookie('anubisbb', $jwt, $expires);
		}
		else
		{
			$this->remove_cookie();
		}
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
	 */
	public function logout_cookie()
	{
		// Double check user is logging out
		if (defined('IN_LOGOUT'))
		{
			$this->bake_cookie();
		}
	}

	public function jwt_create($data, $timestamp, $expires)
	{
		$payload = json_encode([
			'data' => $data,
			'exp'  => $expires,        // Expires
			'iat'  => $timestamp,      // Issued at
			'nbf'  => $timestamp - 30, // Not before
		]);

		// Encode the token
		$encoded_payload = $this->b64_encode_url($payload);

		// Sign the token
		try
		{
			$sig = sodium_crypto_sign_detached($encoded_payload, $this->secret_key);
		}
		catch (SodiumException $e)
		{
			// Problem?
			// TODO: log errors
			return false;
		}

		// Append the signature to the token
		return $encoded_payload . '.' . $this->b64_encode_url($sig);
	}

	public function jwt_unpack($jwt)
	{
		// Split token into payload and signature
		$token = explode('.', $jwt);
		if (count($token) !== 2)
		{
			return false;
		}
		$encoded_payload = $token[0];
		$signature       = $this->b64_decode_url($token[1]);

		// Validate the signature
		try
		{
			$public_key = substr($this->secret_key, SODIUM_CRYPTO_SIGN_SEEDBYTES);
			if (!sodium_crypto_sign_verify_detached($signature, $encoded_payload, $public_key))
			{
				// TODO: log bad key
				return false;
			}
		}
		catch (SodiumException $e)
		{
			return false;
		}

		// Decode token
		$payload = json_decode($this->b64_decode_url($encoded_payload), true);

		// Stale cookies, yuck!
		if (time() > $payload['exp'] || time() < $payload['nbf'])
		{
			// TODO: log, maybe? the client should have cleared the cookie before sending it to us
			return false;
		}

		return $payload;
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
