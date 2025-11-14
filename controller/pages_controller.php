<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\controller;

use Exception;
use neodev\anubisbb\core\anubis_core;
use neodev\anubisbb\core\logger;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\controller\helper as controller_helper;
use phpbb\db\driver\driver_interface;
use phpbb\path_helper;
use phpbb\request\request;
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbb\user;

/**
 * AnubisBB main controller.
 */
class pages_controller
{
	/** @var \phpbb\config\config */
	private $config;

	/** @var \phpbb\controller\helper */
	private $controller_helper;

	/** @var \phpbb\template\template */
	private $template;
	/**
	 * @var \neodev\anubisbb\core\anubis_core
	 */
	private $anubis;
	private $logger;

	/**
	 * @var string
	 */
	private $redirect;
	/**
	 * @var \phpbb\request\request
	 */
	private $request;
	/**
	 * @var \phpbb\path_helper
	 */
	private $web_root_path;
	/**
	 * @var \phpbb\user
	 */
	private $user;

	private $db;

	private $auth;

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config     $config   Config object
	 * @param \phpbb\controller\helper $helper   Controller helper object
	 * @param \phpbb\template\template $template Template object
	 */
	public function __construct(config $config, controller_helper $helper, request $request, template $template, path_helper $path_helper, user $user, driver_interface $db, auth $auth)
	{
		$this->config            = $config;
		$this->controller_helper = $helper;
		$this->request           = $request;
		$this->template          = $template;
		$this->user              = $user;
		$this->db                = $db;
		$this->auth              = $auth;

		$this->web_root_path = $path_helper->get_web_root_path();

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
		$this->logger = new logger($this->config, $this->user);
	}

	public function handler($name)
	{
		switch ($name)
		{
			// Used to allow users to login when there is an error or javascript is disabled
			case 'login':
				//TODO: redirects, use cookies?
				// TODO: catch non-db based logins (OAuth, etc) and block
				// TODO: logging
				// TODO: csrf testing

				// Not killing the user session just yet, need it for the login process
				if ($this->request->is_set_post('login'))
				{
					if ($this->login_process())
					{
						// TODO: follow redirect
						redirect('index.php');
					}
				}

				// We no long need the user session, kill it to prevent bots from
				// filling up the session table.
				$this->user->session_kill(false);

				// Make a token for the login form
				if (!$this->csrf_token())
				{
					// TODO: retry link
					// TODO: logging
					// There was a problem making the token
					return $this->build_error_page('Error processing page');
				}

				$this->template->assign_var('contact', $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'contact']));
				return $this->controller_helper->render('@neodev_anubisbb/login.html');

			case 'contact':
				return $this->controller_helper->render('@neodev_anubisbb/contact.html');

			case 'nojs':
				// Kill the new session, we don't need it
				$this->user->session_kill(false);

				// Make paths to the other pages
				$this->template->assign_vars([
					'contact' => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'contact']),
					'login'   => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'login']),
				]);

				return $this->controller_helper->render('@neodev_anubisbb/nojs.html');

			// TODO: cookies disabled page

			default:
				return $this->build_error_page('Page not found');
		}
	}

	private function csrf_token()
	{
		$timestamp = time();
		/**************
		 * Because we killed the user session, we must create our own CSRF token
		 * Ref: https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
		 **************/

		try
		{
			// It's not enough to create a CSRF token, we must also connect it
			// to a user session, which we simulate by creating a cookie with random bytes
			$sid = bin2hex(random_bytes(32));

			// Since the session is not stored anywhere, we need a way to validate it.
			// This is accomplished by signing the session with a Json Web Token.
			// Create the token, expires in 5 minutes
			$cookie_expire = $timestamp + 300;
			$jwt           = $this->anubis->jwt_create($sid, $timestamp, $cookie_expire);

			$this->user->set_cookie('anubisbb_session', $jwt, $cookie_expire);
		}
		catch (Exception $e)
		{
			// Catch sodium exceptions
			// TODO: logging
			return false;
		}
		$challenge = $this->anubis->make_challenge($timestamp);

		// Finally bind the sid and challenge to form the final token
		$token = hash('sha256', $challenge . $sid . 'login');

		$this->template->assign_vars([
			// Don't need the timestamp, we'll grab it from the session JWT
			// 'timestamp' => $timestamp,
			'token' => $token,
		]);
		return true;
	}

	private function login_process()
	{
		// TODO: log the errors

		$cookie_name = $this->anubis->cookie_name . '_session';

		// Do we have the cookie?
		if (!$this->request->is_set($cookie_name, request_interface::COOKIE))
		{
			$this->template->assign_var('error', 'Cookies missing or disabled');
			return false;
		}

		// We have the cookie
		$jwt = $this->request->variable($cookie_name, '', false, request_interface::COOKIE);

		// We don't have the cookie??
		if (!$jwt)
		{
			$this->template->assign_var('error', 'Invalid form, try again');
			return false;
		}

		// Unpack the cookie
		$payload = $this->anubis->jwt_unpack($jwt);
		// Bad cookie
		if ($payload === false)
		{
			$this->template->assign_var('error', 'Invalid form, try again');
			return false;
		}

		$sid       = $payload['data'];
		$timestamp = $payload['iat']; // Timestamp already verified in range
		$token     = $this->request->variable('token', '');

		// Start building known hash
		$challenge = $this->anubis->make_challenge($timestamp);
		$known_token = hash('sha256', $challenge . $sid . 'login');

		if (!hash_equals($known_token, $token))
		{
			$this->template->assign_var('error', 'Invalid form, try again');
			return false;
		}

		$username = $this->request->variable('username', '');
		$password = $this->request->untrimmed_variable('password', '', true);

		$result = $this->auth->login($username, $password);
		if ($result['status'] == LOGIN_SUCCESS)
		{
			return true;
		}
		// TODO: handle the various login results
		else
		{
			$this->template->assign_var('error', $result['error_msg']);
			return false;
		}
	}

	private function build_error_page($error_message)
	{
		$this->template->assign_vars([
			'title'         => 'Oh noes!',
			'error_message' => $error_message,
			// TODO: retry/redirect somewhere other than index
			'retry_link'    => $this->web_root_path . 'index.php',
		]);

		// Send to Symfony for rendering
		return $this->controller_helper->render('@neodev_anubisbb/fail_challenge.html');
	}
}
