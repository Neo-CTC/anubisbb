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
use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\path_helper;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\HttpFoundation\Response;

/**
 * AnubisBB main controller.
 */
class api_controller
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\template\template */
	protected $template;
	/**
	 * @var int
	 */
	private $cookie_time;
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

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config     $config   Config object
	 * @param \phpbb\controller\helper $helper   Controller helper object
	 * @param \phpbb\template\template $template Template object
	 */
	public function __construct(config $config, helper $helper, request $request, template $template, path_helper $path_helper, user $user)
	{
		$this->config   = $config;
		$this->helper   = $helper;
		$this->request  = $request;
		$this->template = $template;
		$this->user     = $user;
		$this->web_root_path     = $path_helper->get_web_root_path();
		$this->redirect = '';
		// TODO: cookie time settings
		$this->cookie_time = 60;
	}

	/**
	 * Validates the challenge
	 *
	 * URI /anubis/api/pass_challenge
	 *
	 * @return \Symfony\Component\HttpFoundation\Response|void
	 */
	public function pass_challenge()
	{
		$redirect = $this->request->variable('redir', '');
		$host = $this->request->server('SERVER_NAME', '');

		// The redirect hostname must match that of the server
		if ($host !== parse_url($redirect, PHP_URL_HOST) || $redirect === '')
		{
			return $this->build_error_page('Invalid request');
		}
		$this->redirect = $redirect;
		// TODO: catch redirect loops

		$anubis = new anubis_core($this->config, $this->request, $this->user);
		$this->template->assign_var('version', $anubis->version);

		if (!$anubis->pass_challenge())
		{
			// Didn't pass, no cookie for you!!
			return $this->build_error_page($anubis->error);
		}
		else
		{
			// Passed! Grab yourself a cookie.
			$cookie = $anubis->grab_cookie();
			if (!$cookie)
			{
				return $this->build_error_page("No cookie?");
			}

			// TODO: grab cookie time setting
			$this->user->set_cookie('anubisbb', $cookie, time() + $this->cookie_time);

			// TODO: test redirects, make sure we can't go offsite
			redirect($redirect);
		}
	}

	/**
	 * Display an error page
	 *
	 * @param $error_message
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	private function build_error_page($error_message)
	{
		$this->template->assign_vars([
			'title'        => 'Oh noes!',
			'error_message' => $error_message,
			'static_path'  => $this->web_root_path . 'ext/neodev/anubisbb/styles/all/theme/',
			'contact' => $this->web_root_path . 'memberlist.php?mode=contactadmin',
			// TODO: test this. it should lead to the same page the visitor was just at. Which should allow them to retry the challenge.
			'retry_link' => $this->redirect,  
		]);

		// Send to Symfony for rendering
		return $this->helper->render('@neodev_anubisbb/fail_challenge.html');
	}

	public function benchmark()
	{
		$this->template->assign_vars([
			'title' => 'Speed test',
			'static_path'  => $this->web_root_path . 'ext/neodev/anubisbb/styles/all/theme/',
		]);
		return $this->helper->render('@neodev_anubisbb/benchmark.html');
	}

	public function test()
	{
		global $phpbb_container;
		/** @var \phpbb\captcha\plugins\ $captcha */
		$captcha = $phpbb_container->get('captcha.factory')->get_instance($this->config['captcha_plugin']);
		$captcha->init(CONFIRM_REG);
		$captcha->reset();
		$this->template->assign_var('CAPTCHA_TEMPLATE', $captcha->get_template());
		return $this->helper->render('@neodev_anubisbb/test.html');
	}
}
