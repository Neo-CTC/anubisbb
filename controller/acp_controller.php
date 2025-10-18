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
use phpbb\language\language;
use phpbb\log\log;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;

/**
 * AnubisBB ACP controller.
 */
class acp_controller
{
	/** @var \phpbb\config\config */
	private $config;

	/** @var \phpbb\language\language */
	private $language;

	/** @var \phpbb\log\log */
	private $log;

	/** @var \phpbb\request\request */
	private $request;

	/** @var \phpbb\template\template */
	private $template;

	/** @var \phpbb\user */
	private $user;

	/** @var string Custom form action */
	private $u_action;

	/**
	 * Constructor.
	 *
	 * @param \phpbb\config\config     $config   Config object
	 * @param \phpbb\language\language $language Language object
	 * @param \phpbb\log\log           $log      Log object
	 * @param \phpbb\request\request   $request  Request object
	 * @param \phpbb\template\template $template Template object
	 * @param \phpbb\user              $user     User object
	 */
	public function __construct(config $config, language $language, log $log, request $request, template $template, user $user)
	{
		$this->config   = $config;
		$this->language = $language;
		$this->log      = $log;
		$this->request  = $request;
		$this->template = $template;
		$this->user     = $user;
	}

	/**
	 * Display the options a user can configure for this extension.
	 *
	 * @return void
	 */
	public function display_options()
	{
		// Create a form key for preventing CSRF attacks
		add_form_key('neodev_anubisbb_acp');

		// Create an array to collect errors that will be output to the user
		$errors = [];

		if ($this->request->is_set_post('sk_submit'))
		{
			$core = new anubis_core($this->config, $this->request, $this->user);
			$core->gen_sk();
			trigger_error($this->language->lang('ACP_ANUBISBB_SETTINGS_SECRET_KEY_REGEN') . adm_back_link($this->u_action));
		}

		// Is the form being submitted to us?
		if ($this->request->is_set_post('submit'))
		{
			// Test if the submitted form is valid
			if (!check_form_key('neodev_anubisbb_acp'))
			{
				$errors[] = $this->language->lang('FORM_INVALID');
			}

			if (empty($errors))
			{
				$d = $this->request->variable('difficulty', 0);
				$d = ($d <= 8 && $d >= 1) ? $d : 4;
				$this->config->set('anubisbb_difficulty', $d);

				$t = $this->request->variable('ctime', 0);
				// Set sane limits, min 5 minutes, max 13 months
				$t = ($t >= 300 && $t <= 34186670) ? $t : 604800;
				$this->config->set('anubisbb_ctime', $t);

				$s = $this->request->variable('strict_cookies', 1);
				$this->config->set('anubisbb_strict_cookies', $s !== 0 ? 1 : 0);

				$e = $this->request->variable('early', 0);
				$this->config->set('anubisbb_early', $e !== 0 ? 1 : 0);


				// Add option settings change action to the admin log
				$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_ACP_ANUBISBB_SETTINGS');

				// Option settings have been updated and logged
				// Confirm this to the user and provide link back to previous page
				trigger_error($this->language->lang('ACP_ANUBISBB_SETTINGS_SAVED') . adm_back_link($this->u_action));
			}
		}

		$s_errors = !empty($errors);

		// Set output variables for display in the template
		$this->template->assign_vars([
			'S_ERROR'   => $s_errors,
			'ERROR_MSG' => $s_errors ? implode('<br />', $errors) : '',

			'U_ACTION' => $this->u_action,

			'CONFIG_DIFFICULTY' => $this->config['anubisbb_difficulty'],
			'CONFIG_TIME'       => $this->config['anubisbb_ctime'],
			'CONFIG_STRICT'     => $this->config['anubisbb_strict_cookies'],
			'CONFIG_EARLY'      => $this->config['anubisbb_early'],
		]);


		// TODO: button to reset secret key
		// TODO: validate cookie data against challenge
		// TODO: early intercept
		// TODO: exclude paths
		// TODO: strict & lenient cookie check
	}

	/**
	 * Set custom form action.
	 *
	 * @param string $u_action Custom form action
	 *
	 * @return void
	 */
	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}
}
