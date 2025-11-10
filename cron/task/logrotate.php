<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\cron\task;

use DateTime;
use phpbb\config\config;
use phpbb\cron\task\base;

class logrotate extends base
{
	// Run every 4 hours
	private $cron_frequency = 14400;
	private $config;

	public function __construct(config $config)
	{
		$this->config = $config;
	}

	public function run()
	{
		$this->config->set('anubisbb_log_cron', time());


		$path = $this->config['anubisbb_log_path'];
		if ($path[0] !== '/')
		{
			global $phpbb_root_path;
			$path = $phpbb_root_path . $path;
		}

		$days        = $this->config['anubisbb_log_rotate_max'];
		$cutoff_date = new DateTime("-$days days");
		$cutoff_date = (int) $cutoff_date->format('Ymd');

		$files = scandir($path);
		foreach ($files as $file)
		{
			preg_match('/anubis_log-(\d+)$/', $file, $matches);
			if (empty($matches))
			{
				continue;
			}

			$file_date = (int) $matches[1];
			if ($file_date < $cutoff_date)
			{
				unlink($path . $file);
			}
		}
	}

	public function is_runnable()
	{
		return $this->config['anubisbb_log_enabled'];
	}

	public function should_run()
	{
		return time() - $this->cron_frequency > $this->config['anubisbb_log_cron'];
	}
}
