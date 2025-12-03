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

// File contains the
$lang = array_merge($lang, [
	'ANUBISBB_LOCATION_login'   => 'AnubisBB: Login page',
	'ANUBISBB_LOCATION_contact' => 'AnubisBB: Contact page',
	'ANUBISBB_LOCATION_nojs'    => 'Anubisbb: No script landing page.', // In theory no one should see this
]);
