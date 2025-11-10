<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\migrations;

use phpbb\db\migration\migration;

class v400 extends migration
{
	public function effectively_installed()
	{
		return isset($this->config['anubisbb_log_cron']);
	}

	public static function depends_on()
	{
		return ['\neodev\anubisbb\migrations\install_acp_module'];
	}

	public function update_data()
	{
		return [
			// Set to dynamic, whatever that means.
			['config.add', ['anubisbb_log_enabled', 0]],
			['config.add', ['anubisbb_log_path', 'store/anubisbb/']],
			['config.add', ['anubisbb_log_rotate_max', 30]],
			['config.add', ['anubisbb_log_cron', 0]],
			// TODO, exclude paths
		];
	}
}
