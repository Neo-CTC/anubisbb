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

use messenger;

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

		$this->language->add_lang('common', 'neodev/anubisbb');

		// Routing but without sid
		global $phpbb_root_path;
		$this->routes = [
			'contact' => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'contact'], true, ''),
			'login'   => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'login'], true, ''),
			'cc'      => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'c_check'], true, ''),
			'index'   => $phpbb_root_path,
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
			// Login page when there is an error or javascript is disabled
			case 'login':
				$this->template->assign_var('title', $this->language->lang('ANUBISBB_LOGIN_TITLE'));
				// TODO: redirects, use cookies?
				// TODO: logging
				// TODO: set user page to anubis login for view online page

				// Continue if cookies are found or bake new cookies and redirect to the cookie check page
				$this->cookie_check($name);

				// TODO: follow redirects

				// phpBB login system
				// Put our styles first to override login box template
				$this->template->set_style(['ext/neodev/anubisbb/styles', 'styles']);
				$redirect = $this->request->variable('redirect', '') ?: $this->routes['index'];
				login_box($redirect);

				// Just in case
				return $this->build_error_page('Login box error');

			case 'contact':
				// Continue if cookies are found or bake new cookies and redirect to the cookie check page
				$this->cookie_check($name);

				$this->language->add_lang('memberlist');

				if ($this->request->is_set_post('submit'))
				{
					if (!class_exists('messenger'))
					{
						global $phpbb_root_path, $phpEx, $phpbb_container;
						include($phpbb_root_path . 'includes/functions_messenger.' . $phpEx);
					}
					/** @var $form \phpbb\message\form */
					$form = $phpbb_container->get('message.form.admin');

					$form->bind($this->request);
					$error = $form->check_allow();
					if ($error)
					{
						return $this->build_error_page($this->language->lang($error));
					}

					$messenger = new messenger(false);
					$form->submit($messenger);

					// Oh, you're still here? Probably an error.
					// Form render runs add_form_key and populates the ERROR_MESSAGE variable
					$form->render($this->template);
				}
				else
				{
					add_form_key('memberlist_email');
				}

				$this->template->assign_var('title', $this->language->lang('CONTACT_ADMIN'));
				return $this->controller_helper->render('@neodev_anubisbb/contact.html');

			case 'nojs':
				// Kill the new session, we don't need it
				$this->user->session_kill(false);

				$this->template->assign_var('title', $this->language->lang('ANUBISBB_OH_NO'));
				return $this->controller_helper->render('@neodev_anubisbb/nojs.html');

			case 'c_check':
				$cc_cookie = $this->request->variable($this->config['cookie_name'] . '_anubisbb_cc', '', false, request_interface::COOKIE);
				$cc_cookie = $this->anubis->jwt_unpack($cc_cookie);

				// The user is on this page and still no cookie? Cookie check, FAILED!
				if ($cc_cookie === false)
				{
					return $this->build_error_page('Cookies not enabled');
				}

				// Send user back to original page
				redirect($this->routes[$cc_cookie['data']['page']]);

			default:
				return $this->build_error_page('Page not found');
		}
	}

	private function cookie_check($base_page)
	{
		/*
		 * Normally we would kill the user session until the user passes the Anubis challenge.
		 * Unfortunately, the login and contact pages requires a user session to work. Therefore, we
		 * run a cookie check. If the user isn't saving cookies, then there's no point in saving the
		 * user session.
		 */

		$cc_cookie = $this->request->variable($this->config['cookie_name'] . '_anubisbb_cc', '', false, request_interface::COOKIE);
		$cc_cookie = $this->anubis->jwt_unpack($cc_cookie);

		if ($cc_cookie !== false && $cc_cookie['data']['page'] === $base_page)
		{
			return;
		}

		// Missing or stale cookie, or we're on a different page.
		$this->user->session_kill(false);
		$t  = time();
		$e  = $t + 3600; // 1 hour
		$cc = $this->anubis->jwt_create(['page' => $base_page], $t, $e);
		$this->user->set_cookie('anubisbb_cc', $cc, $e);
		redirect($this->routes['cc']);
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
