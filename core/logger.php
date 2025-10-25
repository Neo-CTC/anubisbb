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

	public function __construct(config $config, user $user)
	{
		$this->config  = $config;
		$this->user    = $user;
		$this->enabled  = $this->config['anubisbb_log_enabled'];
	}

	// TODO: log level
	public function log($message)
	{
		if (!$this->enabled)
		{
			return;
		}

		$log_line = [
			date('Y-m-d H:i:s'),
			$this->user->ip,
			$message,
		];

		global $phpbb_root_path;
		$path = $phpbb_root_path . 'store/anubisbb/log/';

		if (!file_exists($path))
		{
			mkdir($path, 0770, true);
		}
		$log_file = fopen($path . 'request_log-' . date('Ymd'), 'a');
		if ($log_file === false)
		{
			return;
		}

		// Lock the file, so we can handle multiple requests without collisions
		// Technically writes less than 4kB are atomic and don't need locking but just in case...
		flock($log_file, LOCK_EX);

		// Write in csv format for easy retrieval later
		fputcsv($log_file, $log_line);

		flock($log_file,LOCK_UN);
		fclose($log_file);
	}
}
