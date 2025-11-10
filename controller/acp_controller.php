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
				$difficulty = $this->request->variable('difficulty', 0);
				$difficulty = max($difficulty, 1);
				$difficulty = min($difficulty, 8);

				$cookie_time = $this->request->variable('ctime', 0);
				$cookie_time = max($cookie_time, 300);      // lower limit 5 minutes
				$cookie_time = min($cookie_time, 34186670); // upper limit 13 months

				$log_enable = $this->request->variable('log_enable', false);
				$path       = $this->request->variable('log_path', '');
				if ($path[-1] !== '/')
				{
					$path = $path . '/';
				}

				// Verify the path but only when logging is enabled
				if ($log_enable)
				{
					$this->dir_check($path, $errors);
				}

				$log_rotate = $this->request->variable('log_rotate_max', 30);
				$log_rotate = max($log_rotate, 1);
				$log_rotate = min($log_rotate, 365);

				if (!$errors)
				{
					$this->config->set('anubisbb_difficulty', $difficulty);
					$this->config->set('anubisbb_ctime', $cookie_time);
					$this->config->set('anubisbb_strict_cookies', $this->request->variable('strict_cookies', true));
					$this->config->set('anubisbb_early', $this->request->variable('early', false));
					$this->config->set('anubisbb_hot_linking', $this->request->variable('hot_linking', false));
					$this->config->set('anubisbb_log_enabled', $log_enable);
					$this->config->set('anubisbb_log_path', $path);
					$this->config->set('anubisbb_log_rotate_max', $log_rotate);

					// Add option settings change action to the admin log
					$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_ACP_ANUBISBB_SETTINGS');

					// Option settings have been updated and logged
					// Confirm this to the user and provide link back to previous page
					trigger_error($this->language->lang('ACP_ANUBISBB_SETTINGS_SAVED') . adm_back_link($this->u_action));
				}
			}
		}

		$s_errors = !empty($errors);

		// Set output variables for display in the template
		$this->template->assign_vars([
			'S_ERROR'   => $s_errors,
			'ERROR_MSG' => $s_errors ? implode('<br />', $errors) : '',

			'U_ACTION' => $this->u_action,

			'CONFIG_DIFFICULTY'  => $this->config['anubisbb_difficulty'],
			'CONFIG_TIME'        => $this->config['anubisbb_ctime'],
			'CONFIG_STRICT'      => $this->config['anubisbb_strict_cookies'],
			'CONFIG_EARLY'       => $this->config['anubisbb_early'],
			'CONFIG_HOT_LINKING' => $this->config['anubisbb_hot_linking'],

			'CONFIG_LOG_ENABLE'     => $this->config['anubisbb_log_enabled'],
			'CONFIG_LOG_PATH'       => $this->config['anubisbb_log_path'],
			'CONFIG_LOG_ROTATE_MAX' => $this->config['anubisbb_log_rotate_max'],
		]);
		// TODO: exclude paths
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

	private function dir_check($path, &$errors)
	{
		// No empty paths, or path traversal
		if (preg_match('~(?:^|/)[./]+/~', $path))
		{
			$errors[] = $this->language->lang('ACP_ANUBISBB_SETTINGS_LOG_PATH_INVALID');
			return;
		}

		// relative path
		if ($path[0] !== '/')
		{
			global $phpbb_root_path;
			$real_path = $phpbb_root_path . $path;
		}
		else
		{
			$real_path = $path;
		}

		if (!is_dir($real_path))
		{
			// We will try to make the default directory but otherwise fail if
			// the directory doesn't exist
			if ($path == 'store/anubisbb/')
			{
				if (!mkdir($real_path, 0770))
				{
					$errors[] = $this->language->lang('DIRECTORY_DOES_NOT_EXIST', $path);
					return;
				}
			}
			else
			{
				$errors[] = $this->language->lang('DIRECTORY_DOES_NOT_EXIST', $path);
				return;
			}
		}

		if (!is_writable($real_path))
		{
			$errors[] = $this->language->lang('DIRECTORY_NOT_WRITABLE', $path);
		}
	}
}
