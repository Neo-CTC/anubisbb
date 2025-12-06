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

use neodev\anubisbb\core\anubis_core;
use neodev\anubisbb\core\logger;

use phpbb\config\config;
use phpbb\controller\helper as controller_helper;
use phpbb\language\language;
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
	private $config;
	private $controller_helper;
	private $request;
	private $template;
	private $user;
	private $language;

	private $anubis;
	private $logger;
	private $routes;

	public function __construct(config $config, controller_helper $helper, request $request, template $template, user $user, language $language)
	{
		$this->config            = $config;
		$this->controller_helper = $helper;
		$this->request           = $request;
		$this->template          = $template;
		$this->user              = $user;
		$this->language          = $language;

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
		$this->logger = new logger($this->config, $this->user);

		$this->language->add_lang('common', 'neodev/anubisbb');

		// Set session id to '' or it will append a session id
		$this->routes = [
			'contact' => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'contact'], true, ''),
			'login'   => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'login'], true, ''),
			'cc'      => $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'c_check'], true, ''),
		];
	}

	public function handler($name)
	{
		// Make paths to the other pages
		$this->template->assign_vars([
			'contact_path' => $this->routes['contact'],
			'login_path'   => $this->routes['login'],
		]);

		switch ($name)
		{
			case 'login':
				$this->cookie_check($name);
				$this->logger->log('Login page');

				$this->language->add_lang('ucp');
				$this->template->assign_var('title', $this->language->lang('ANUBISBB_LOGIN_TITLE'));
				login_box('./');

				// Just in case
				return $this->build_error_page('Login box error(?)');

			case 'contact':
				$this->cookie_check($name);
				$this->logger->log('Contact page');

				if ($this->request->is_set_post('submit'))
				{
					// Copied from memberlist.php
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

				$this->language->add_lang('memberlist');
				$this->template->assign_var('title', $this->language->lang('ANUBISBB_CONTACT_TITLE'));
				return $this->controller_helper->render('@neodev_anubisbb/contact.html');

			case 'nojs':
				// Kill the new session, we don't need it
				$this->user->session_kill(false);
				$this->logger->log('No JavaScript page');

				$ref = $this->request->header('Referer', '');
				$ref = $this->user->host === parse_url($ref, PHP_URL_HOST) ? $ref : '';
				$this->template->assign_var('referer', $ref);

				$this->template->assign_var('title', $this->language->lang('ANUBISBB_OH_NO'));
				return $this->controller_helper->render('@neodev_anubisbb/nojs.html');

			case 'c_check':
				$cc_cookie = $this->request->variable($this->config['cookie_name'] . '_anubisbb_cc', '', false, request_interface::COOKIE);
				$cc_cookie = $this->anubis->jwt_unpack($cc_cookie);

				// The user is on this page and still no cookie? Cookie check, FAILED!
				if ($cc_cookie === false)
				{
					$this->logger->log('Cookie check: cookies disabled');
					return $this->build_error_page($this->language->lang('ANUBISBB_ERROR_COOKIES_DISABLED'));
				}

				// Send user back to original page
				redirect($this->routes[$cc_cookie['data']['page']]);

			default:
				return $this->build_error_page($this->language->lang('PAGE_NOT_FOUND'));
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
		$e  = $t + $this->anubis::GUEST_TTL;
		$cc = $this->anubis->jwt_create(['page' => $base_page], $t, $e);
		$this->user->set_cookie('anubisbb_cc', $cc, $e);
		redirect($this->routes['cc']);
	}

	private function build_error_page($error_message)
	{
		$this->template->assign_vars([
			'title'         => $this->language->lang('ANUBISBB_OH_NO'),
			'error_message' => $error_message,
		]);
		return $this->controller_helper->render('@neodev_anubisbb/fail_challenge.html');
	}
}
