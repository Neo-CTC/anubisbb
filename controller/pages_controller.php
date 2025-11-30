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
use phpbb\language\language;
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

	private $language;

	private $routes;

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config     $config   Config object
	 * @param \phpbb\controller\helper $helper   Controller helper object
	 * @param \phpbb\template\template $template Template object
	 */
	public function __construct(config $config, controller_helper $helper, request $request, template $template, path_helper $path_helper, user $user, driver_interface $db, auth $auth, language $language)
	{
		$this->config            = $config;
		$this->controller_helper = $helper;
		$this->request           = $request;
		$this->template          = $template;
		$this->user              = $user;
		$this->db                = $db;
		$this->auth              = $auth;
		$this->language          = $language;

		$this->web_root_path = $path_helper->get_web_root_path();

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
		$this->logger = new logger($this->config, $this->user);

		// Routing but without sid
		$this->routes = [
			'contact' => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'contact'],true,''),
			'login'   => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'login'],true,''),
		];
	}

	public function handler($name)
	{
		// Make paths to the other pages
		$this->template->assign_vars([
			'contact' => $this->routes['contact'],
			'login'   => $this->routes['login'],
		]);

		switch ($name)
		{
			// Used to allow users to login when there is an error or javascript is disabled
			case 'login':
				//TODO: redirects, use cookies?
				// TODO: logging

				// Cookie check. If the user isn't saving cookies, then we can kill the session
				$cc_cookie = $this->request->variable($this->config['cookie_name'] . '_anubisbb_cc', '', false, request_interface::COOKIE);
				$cc_page   = $this->request->is_set('cc', request_interface::GET);

				// Cookie not set or gone stale
				if (!$cc_cookie || $this->anubis->jwt_unpack($cc_cookie) === false){
					$this->user->session_kill(false);

					// No cookie and yet we are on the cookie check page. Cookie check, failed!
					if ($cc_page)
					{
						return $this->build_error_page('Cookies disabled.');
					}

					// Bake a new cookie
					$t = time();
					$e = $t + 3600;
					$cc = $this->anubis->jwt_create('cookies enabled', $t, $e);
					$this->user->set_cookie('anubisbb_cc',$cc,$e);
					redirect($this->routes['login'] . '?cc');
				}
				if ($cc_page)
				{
					// Let's leave the cookie check page
					redirect($this->routes['login']);
				}

				// TODO: follow redirects

				// phpBB login system
				// Put our styles first to override login box template
				$this->template->set_style(['ext/neodev/anubisbb/styles','styles']);
				login_box();



			case 'contact':
				return $this->controller_helper->render('@neodev_anubisbb/contact.html');

			case 'nojs':
				// Kill the new session, we don't need it
				$this->user->session_kill(false);

				return $this->controller_helper->render('@neodev_anubisbb/nojs.html');

			// TODO: cookies disabled page

			default:
				return $this->build_error_page('Page not found');
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
