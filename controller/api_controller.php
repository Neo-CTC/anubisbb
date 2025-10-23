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
use phpbb\controller\helper as controller_helper;
use phpbb\db\driver\driver_interface;
use phpbb\path_helper;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;

/**
 * AnubisBB main controller.
 */
class api_controller
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

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config     $config   Config object
	 * @param \phpbb\controller\helper $helper   Controller helper object
	 * @param \phpbb\template\template $template Template object
	 */
	public function __construct(config $config, controller_helper $helper, request $request, template $template, path_helper $path_helper, user $user, driver_interface $db)
	{
		$this->config            = $config;
		$this->controller_helper = $helper;
		$this->request           = $request;
		$this->template      = $template;
		$this->user          = $user;
		$this->db            = $db;
		$this->web_root_path = $path_helper->get_web_root_path();
		$this->redirect      = '';

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
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

		if ($redirect === '')
		{
			return $this->build_error_page('Invalid request');
		}

		// Bad character test (control characters, backslash)
		if (preg_match('/[\x00-\x1F\x5C]/',$redirect))
		{
			return $this->build_error_page('Invalid request');
		}


		// The redirect hostname must match that of the server
		$url_parts = parse_url($redirect);
		if ($url_parts === false || $url_parts['host'] !== $this->user->host)
		{
			return $this->build_error_page('Invalid request');
		}

		// Somehow the redirect points back to the api??
		if (strpos($redirect, 'anubis/api/') !== false)
		{
			// Send them back to the forum index
			$redirect = 'index.php';
		}

		$this->redirect = $redirect;

		$this->template->assign_var('version', $this->anubis->version);

		if ($this->anubis->pass_challenge())
		{
			$data = ['anubisbb_pass' => 1];
			$sql = 'UPDATE ' . SESSIONS_TABLE . ' 
				SET ' . $this->db->sql_build_array('UPDATE', $data) . '
				WHERE session_id = "' . $this->user->data['session_id'] . '"';
			$this->db->sql_query($sql);

			// TODO: test redirects, make sure we can't go offsite
			redirect($redirect);
		}
		else
		{
			return $this->build_error_page($this->anubis->error);
		}
	}

	public function make_challenge()
	{
		$this->user->session_kill(false);

		// Set a cookie to bypass the early intercept allowing access to log in and contact pages.
		// Todo: remove this when we ever figure out a non JS solution
		$this->user->set_cookie('anubisbb_early','true', 0, false);

		$root_path = $this->web_root_path;
		$this->template->assign_var('root_path', $root_path);

		$time = time();

		// Paths for static files and the verification api
		$this->template->assign_vars([
			'static_path' => $root_path . 'ext/neodev/anubisbb/styles/all/theme/',
			'route_path'  => $this->controller_helper->route('neodev_anubisbb_pass_challenge'),
			'version'     => $this->anubis->version,
		]);

		// Fetch the challenge hash
		$challenge = $this->anubis->make_challenge($time);
		if (!$challenge)
		{
			// Problem making the challenge?
			// TODO: log the error to phpBB
			$this->template->assign_vars([
				'title'         => 'Oh noes!',
				'error_message' => $this->anubis->error,
				'retry_link'    => build_url(), // Basically a link to the current url
			]);
			return $this->controller_helper->render('@neodev_anubisbb/failure_challenge.html');
		}
		else
		{
			// Display challenge page
			$this->template->assign_vars([
				'title'      => 'Making sure you&#39;re not a bot!',
				'difficulty' => $this->config['anubisbb_difficulty'],
				'challenge'  => $challenge,
				'timestamp'  => $time,
			]);
			return $this->controller_helper->render('@neodev_anubisbb/make_challenge.html');
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
			'title'         => 'Oh noes!',
			'error_message' => $error_message,
			'static_path'   => $this->web_root_path . 'ext/neodev/anubisbb/styles/all/theme/',
			'contact'       => $this->web_root_path . 'memberlist.php?mode=contactadmin',
			// TODO: test this. it should lead to the same page the visitor was just at. Which should allow them to retry the challenge.
			'retry_link'    => $this->redirect,
		]);

		// Send to Symfony for rendering
		return $this->controller_helper->render('@neodev_anubisbb/fail_challenge.html');
	}

	public function benchmark()
	{
		$this->template->assign_vars([
			'title'       => 'Speed test',
			'static_path' => $this->web_root_path . 'ext/neodev/anubisbb/styles/all/theme/',
		]);
		return $this->controller_helper->render('@neodev_anubisbb/benchmark.html');
	}
}
