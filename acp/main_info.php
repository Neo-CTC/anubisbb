<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\acp;

/**
 * AnubisBB ACP module info.
 */
class main_info
{
	public function module()
	{
		return [
			'filename' => '\neodev\anubisbb\acp\main_module',
			'title'    => 'ACP_ANUBISBB_TITLE_MODULE',
			'modes'    => [
				'settings' => [
					'title' => 'ACP_ANUBISBB_TITLE_SETTINGS',
					'auth'  => 'ext_neodev/anubisbb && acl_a_board',
					'cat'   => ['ACP_ANUBISBB_TITLE_MODULE'],
				],
			],
		];
	}
}
