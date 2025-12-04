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


/** @noinspection HtmlUnknownTarget */
$lang = array_merge($lang, [
	'ANUBISBB_CHALLENGE_TITLE' => 'Making sure you&#39;re not a bot!',
	'ANUBISBB_CONTACT_TITLE'   => 'Contact the staff',

	'ANUBISBB_ERROR_CHALLENGE_FAILED' => 'You did not pass the challenge',
	'ANUBISBB_ERROR_COOKIES_DISABLED' => 'Cookies are disabled. Please enable cookies before trying again',

	'ANUBISBB_FAIL_TEXT' => '<a href="%s">Try again</a> or if you believe you should not be blocked, please <a href="%s">contact the administrator</a>',

	'ANUBISBB_LOGIN_TITLE' => 'Login to phpBB',

	'ANUBISBB_NOJS_EXPLAIN' => 'Sadly, you must enable JavaScript to get past this challenge. This is required because AI companies have changed the social contract around how website hosting works. A no-JS solution is a work-in-progress.',
	'ANUBISBB_NOJS_RETRY'   => 'Click to retry once JavaScript is enabled',

	'ANUBISBB_OH_NO' => 'Oh noes!',

	'ANUBISBB_WHAT_SUMMARY' => 'Why am I seeing this?',


	'ANUBISBB_WHAT_DETAILS' => <<<'EOF'
<p>You are seeing this because the administrator of this website has set up
	<a href="https://github.com/TecharoHQ/anubis">Anubis</a> to protect the server against the scourge of
	<a href="https://thelibre.news/foss-infrastructure-is-under-attack-by-ai-companies/">AI companies aggressively scraping
		websites</a>. This can and does cause downtime for the websites, which makes their resources inaccessible for everyone.
</p>
<p>Anubis is a compromise. Anubis uses a <a href="https://anubis.techaro.lol/docs/design/why-proof-of-work">Proof-of-Work</a>
	scheme in the vein of <a href="https://en.wikipedia.org/wiki/Hashcash">Hashcash</a>, a proposed proof-of-work scheme for
	reducing email spam. The idea is that at individual scales the additional load is ignorable, but at mass scraper levels it
	adds up and makes scraping much more expensive.</p>
<p>Ultimately, this is a hack whose real purpose is to give a "good enough" placeholder solution so that more time can be spent
	on fingerprinting and identifying headless browsers (EG: via how they do font rendering) so that the challenge proof of work
	page doesn't need to be presented to users that are much more likely to be legitimate.</p>
<p>Please note that Anubis requires the use of modern JavaScript features that plugins like
	<a href="https://jshelter.org/">JShelter</a>
	will disable. Please disable JShelter or other such plugins for this domain.</p>
EOF,

	'ANUBISBB_FOOTER' => <<<'EOF'
<p>Protected by <a href="https://github.com/TecharoHQ/anubis">Anubis</a> from <a href="https://techaro.lol">Techaro</a>. Made with ❤️ in 🇨🇦.</p>
<p>Mascot design by <a href="https://bsky.app/profile/celphase.bsky.social">CELPHASE</a>.</p>
<p>Modified for <a href="https://phpBB.com">phpBB</a> by <a href="https://github.com/Neo-CTC">NeoDev</a></p>
EOF,
]);
