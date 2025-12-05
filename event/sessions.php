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

use neodev\anubisbb\core\anubis_core;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\request\request;
use phpbb\user;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * AntiBot Event listener.
 */
class sessions implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.session_kill_after'   => 'logout', // User just logged out, set variable to remember that
			'core.session_create_after' => 'logout_check', // After the user logged out a new session was created, catch it.
			'core.session_gc_after'     => 'session_cleanup', // Cleanup unverified visitors
		];
	}

	private $user;
	private $request;
	private $config;
	private $db;

	private $anubis;

	public function __construct(user $user, request $request, config $config, driver_interface $db)
	{
		$this->user    = $user;
		$this->request = $request;
		$this->config  = $config;
		$this->db      = $db;

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
	}

	public function logout($event)
	{
		$new = $event['new_session'];
		$uid = $event['user_id'];
		if ($new && $uid > 1)
		{
			// The user is currently in the middle of logging out, and a new session will be created for them
			// This lets us remember the logout and bake the user a new cookie once the new session is ready
			define('IN_LOGOUT', true);
		}
	}

	public function logout_check()
	{
		// User has just logged out, make them a cookie
		if (defined('IN_LOGOUT'))
		{
			$this->anubis->logout_cookie();

			$data = ['anubisbb_pass' => 1];
			$sql  = 'UPDATE ' . SESSIONS_TABLE . '
				SET ' . $this->db->sql_build_array('UPDATE', $data) . '
				WHERE session_id = "' . $this->user->data['session_id'] . '"';
			$this->db->sql_query($sql);
		}
	}

	public function session_cleanup()
	{
		// Remove unverified visitors sessions after a period of inactivity.
		$ttl = time() - $this->anubis::GUEST_TTL;
		$sql = 'DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id = ' . ANONYMOUS . ' and anubisbb_pass = 0 and session_time < ' . $ttl;
		$this->db->sql_query($sql);
	}
}
