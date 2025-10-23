<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine
//
// Some characters you may want to copy&paste:
// ’ » “ ” …
//

$lang = array_merge($lang, [
	'ACP_ANUBISBB_TITLE_MODULE'   => 'AnubisBB',
	'ACP_ANUBISBB_TITLE_SETTINGS' => 'AnubisBB Settings',

	'ACP_ANUBISBB_SETTINGS_DIFFICULTY'         => 'Difficulty',
	'ACP_ANUBISBB_SETTINGS_DIFFICULTY_EXPLAIN' => 'Difficulty of authentication challenge. 4 or 5 is typical.',

	'ACP_ANUBISBB_SETTINGS_COOKIE_TIME'         => 'Access token duration',
	'ACP_ANUBISBB_SETTINGS_COOKIE_TIME_EXPLAIN' => 'Length of time before the user must reauthenticate in seconds. Default: 604800 (one week)',

	'ACP_ANUBISBB_SETTINGS_STRICT_LABEL'   => 'Access token checking',
	'ACP_ANUBISBB_SETTINGS_STRICT_EXPLAIN' => 'Strict: Check IP address and User-Agent<br>Lenient: Accept any valid token',
	'ACP_ANUBISBB_SETTINGS_STRICT_STRICT'  => 'Strict',
	'ACP_ANUBISBB_SETTINGS_STRICT_LENIENT' => 'Lenient',

	'ACP_ANUBISBB_SETTINGS_EARLY'         => 'Early intercept',
	'ACP_ANUBISBB_SETTINGS_EARLY_EXPLAIN' => 'Run a simple script before all phpBB pages. This adds protection to the login and contact pages but may cause issues.',

	'ACP_ANUBISBB_SETTINGS_HOT_LINKING'         => 'Allow hot linking',
	'ACP_ANUBISBB_SETTINGS_HOT_LINKING_EXPLAIN' => 'Disable protections for uploaded files (attachment & avatars). This allows visitors to view files without passing a challenge both onsite and offsite (hot linking).',

	'ACP_ANUBISBB_SETTINGS_SECRET_KEY_TITLE'   => 'Secret Key',
	'ACP_ANUBISBB_SETTINGS_SECRET_KEY'         => 'Regenerate secret key',
	'ACP_ANUBISBB_SETTINGS_SECRET_KEY_EXPLAIN' => 'Creates a new secret key. This will invalidate all access tokens.',
	'ACP_ANUBISBB_SETTINGS_SECRET_KEY_REGEN'   => 'Secret key regenerated',

	'ACP_ANUBISBB_SETTINGS_SAVED' => 'AnubisBB settings saved',
	'LOG_ACP_ANUBISBB_SETTINGS'   => '<strong>AnubisBB settings updated</strong>',
]);
