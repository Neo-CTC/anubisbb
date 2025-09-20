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
use phpbb\controller\helper;
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
	protected $config;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\template\template */
	protected $template;
	/**
	 * @var \neodev\anubisbb\core\anubis_core
	 */
	private $anubis;
	/**
	 * @var \neodev\anubisbb\core\logger
	 */
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

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config     $config   Config object
	 * @param \phpbb\controller\helper $helper   Controller helper object
	 * @param \phpbb\template\template $template Template object
	 */
	public function __construct(config $config, helper $helper, request $request, template $template, path_helper $path_helper, user $user, driver_interface $db)
	{
		$this->config        = $config;
		$this->helper        = $helper;
		$this->request       = $request;
		$this->template      = $template;
		$this->user          = $user;
		$this->db            = $db;
		$this->web_root_path = $path_helper->get_web_root_path();
		$this->redirect      = '';

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
		$this->logger = new logger('API', $path_helper->get_phpbb_root_path(),$user);
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
		$this->logger->log('Challenge check page');
		$redirect = $this->request->variable('redir', '');

		if ($redirect === '')
		{
			$this->logger->log('Missing redirect');
			return $this->build_error_page('Invalid request');
		}

		// Bad character test (control characters, backslash)
		if (preg_match('/[\x00-\x1F\x5C]/',$redirect))
		{
			$this->logger->log('Bad characters in redirect');
			return $this->build_error_page('Invalid request');
		}

		$this->logger->log('Redirect set to ' . $redirect);

		// The redirect hostname must match that of the server
		$url_parts = parse_url($redirect);
		if ($url_parts === false || $url_parts['host'] !== $this->user->host)
		{
			$this->logger->log("Redirect host ({$url_parts['host']}) did not match server host ({$this->user->host})");
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
			$this->logger->log('Challenge, pass');
			$data = ['anubisbb_pass' => 1];
			$sql = 'UPDATE ' . SESSIONS_TABLE . ' 
				SET ' . $this->db->sql_build_array('UPDATE', $data) . '
				WHERE session_id = "' . $this->user->data['session_id'] . '"';
			$this->db->sql_query($sql);

			// TODO: test redirects, make sure we can't go offsite
			$this->logger->end('Sending redirect');
			redirect($redirect);
		}
		else
		{
			$this->logger->log('Challenge, fail');
			return $this->build_error_page($this->anubis->error);
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
		$this->logger->end('Sending error page with message: '. $error_message);
		$this->template->assign_vars([
			'title'         => 'Oh noes!',
			'error_message' => $error_message,
			'static_path'   => $this->web_root_path . 'ext/neodev/anubisbb/styles/all/theme/',
			'contact'       => $this->web_root_path . 'memberlist.php?mode=contactadmin',
			// TODO: test this. it should lead to the same page the visitor was just at. Which should allow them to retry the challenge.
			'retry_link'    => $this->redirect,
		]);

		// Send to Symfony for rendering
		return $this->helper->render('@neodev_anubisbb/fail_challenge.html');
	}

	public function benchmark()
	{
		$this->template->assign_vars([
			'title'       => 'Speed test',
			'static_path' => $this->web_root_path . 'ext/neodev/anubisbb/styles/all/theme/',
		]);
		return $this->helper->render('@neodev_anubisbb/benchmark.html');
	}
}
