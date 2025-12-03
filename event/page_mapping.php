<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\event;

use phpbb\language\language;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * AntiBot Event listener.
 */
class page_mapping implements EventSubscriberInterface
{
	private $language;

	public static function getSubscribedEvents()
	{
		return [
			'core.viewonline_overwrite_location' => 'location_map',
		];
	}

	public function __construct(language $language)
	{
		$this->language = $language;
	}

	public function location_map($event)
	{
		$page = $event['on_page'];

		// Not a route
		if ($page[1] != 'app')
		{
			return;
		}

		$user_row = $event['row'];
		preg_match('~app.php/anubis/(api|pages)/(\w+)$~', $user_row['session_page'], $matches);

		// No matches, bye.
		if (!$matches)
		{
			return;
		}

		if ($matches[1] == 'api')
		{
			// This shouldn't happen, the session should have been killed
			return;
		}

		if (!$this->language->is_set('ANUBISBB_LOCATION_login'))
		{
			$this->language->add_lang('locations', 'neodev/anubisbb');
		}

		$location              = $this->language->lang('ANUBISBB_LOCATION_' . $matches[2]);
		$event['location']     = $location;
		$event['location_url'] = '';
	}
}
