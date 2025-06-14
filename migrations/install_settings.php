<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\migrations;

use phpbb\db\migration\migration;

class install_settings extends migration
{
	public function effectively_installed()
	{
		return isset($this->config['anubisbb_difficulty']);
	}

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	public function update_data()
	{
		return [
			['config.add', ['anubisbb_difficulty', 4]],
			['config.add', ['anubisbb_sk', '']],
			['config.add', ['anubisbb_ctime', 604800]], // One week
		];
	}
}
