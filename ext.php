<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb;

use phpbb\extension\base;

/**
 *
 * AnubisBB Extension base
 *
 */
class ext extends base
{
	/**
	 * Indicate whether or not the extension can be enabled.
	 *
	 * @return bool|array    True if extension is enableable, array of reasons
	 *                        if not, false for generic reason.
	 */
	public function is_enableable()
	{
		if (!extension_loaded('sodium') || !defined('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES') || !function_exists('sodium_crypto_sign_keypair'))
		{
			return ['Missing requirement: Sodium php extension not loaded'];
		}
		return true;
	}
}
