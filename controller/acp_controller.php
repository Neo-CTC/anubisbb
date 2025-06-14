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
	protected $config;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\log\log */
	protected $log;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var string Custom form action */
	protected $u_action;

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
		// Add our common language file
		$this->language->add_lang('common', 'neodev/anubisbb');

		// Create a form key for preventing CSRF attacks
		add_form_key('neodev_anubisbb_acp');

		// Create an array to collect errors that will be output to the user
		$errors = [];

		// Is the form being submitted to us?
		if ($this->request->is_set_post('submit'))
		{
			// Test if the submitted form is valid
			if (!check_form_key('neodev_anubisbb_acp'))
			{
				$errors[] = $this->language->lang('FORM_INVALID');
			}

			// If no errors, process the form data
			if (empty($errors))
			{
				// Set the options the user configured
				$this->config->set('neodev_anubisbb_goodbye', $this->request->variable('neodev_anubisbb_goodbye', 0));

				// Add option settings change action to the admin log
				$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_ACP_ANUBISBB_SETTINGS');

				// Option settings have been updated and logged
				// Confirm this to the user and provide link back to previous page
				trigger_error($this->language->lang('ACP_ANUBISBB_SETTING_SAVED') . adm_back_link($this->u_action));
			}
		}

		$s_errors = !empty($errors);

		// Set output variables for display in the template
		$this->template->assign_vars([
			'S_ERROR'   => $s_errors,
			'ERROR_MSG' => $s_errors ? implode('<br />', $errors) : '',

			'U_ACTION' => $this->u_action,

			'NEODEV_ANUBISBB_GOODBYE' => (bool) $this->config['neodev_anubisbb_goodbye'],
		]);
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
