<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */


namespace neodev\anubisbb\core;

use phpbb\config\config;
use phpbb\user;

class logger
{
	private $config;
	private $user;
	private $enabled;
	private $log_file;

	public function __construct(config $config, user $user)
	{
		$this->config  = $config;
		$this->user    = $user;

		$this->config->set('anubisbb_log_enabled', '1');
		$this->enabled  = $this->config['anubisbb_log_enabled'];
		$this->log_file = '';
	}

	// TODO: log level
	public function log($message)
	{
		if (!$this->enabled)
		{
			return;
		}

		$message = ' "' . $message . '"';
		$message = date('(Y-m-d H:i:s) ') . $this->user->ip . $message . " \n";

		$path = $this->config['anubisbb_log_path'];
		if ($path === '')
		{
			global $phpbb_root_path;
			$path = $phpbb_root_path . 'store/anubisbb/log/';
			$this->config->set('anubisbb_log_path', $path);
		}
		if (!file_exists($path))
		{
			mkdir($path, 0770, true);
		}
		$this->log_file = $path . 'anubis_log-' . date('Ymd');
		file_put_contents($this->log_file, $message, FILE_APPEND);
	}
}
