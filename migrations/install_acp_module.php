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

class install_acp_module extends migration
{
	public function effectively_installed()
	{
		return isset($this->config['anubisbb_hot_linking']);
	}

	public static function depends_on()
	{
		return ['\neodev\anubisbb\migrations\install_settings'];
	}

	public function update_data()
	{
		return [
			['config.add', ['anubisbb_early', 0]],
			['config.add', ['anubisbb_strict_cookies', 1]],
			['config.add', ['anubisbb_hot_linking', 0]],

			[
				'module.add', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_ANUBISBB_TITLE_MODULE'
			]],
			['module.add', [
				'acp',
				'ACP_ANUBISBB_TITLE_MODULE',
				[
					'module_basename'	=> '\neodev\anubisbb\acp\main_module',
					'modes'				=> ['settings'],
				],
			]],
		];
	}
}
