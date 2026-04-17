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

class v500 extends migration
{
	public function effectively_installed()
	{
		// return isset($this->config['anubisbb_paths']);
		return false;
	}

	public static function depends_on()
	{
		return ['\neodev\anubisbb\migrations\v400'];
	}

	public function update_data()
	{
		return [
			['config_text.add', ['anubisbb_paths', '# Place paths/routes to bypass protections
# Lines starting with # will be ignored
# Use * for wildcard matching
# Paths must match full path or use a wildcard

# Example path
# /extension/path

# Example wildcard path
# /extension/*
']],
			['config.add', ['anubisbb_allow_extra_pages', 0]],
			['config.remove', ['anubisbb_early']],
		];
	}
}
