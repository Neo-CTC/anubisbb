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
use neodev\anubisbb\core\logger;
use phpbb\cache\service as cache;
use phpbb\config\config;
use phpbb\controller\helper as controller_helper;
use phpbb\db\driver\driver_interface;
use phpbb\path_helper;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * AntiBot Event listener.
 */
class intercept implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.common'               => 'early_intercept',
			'core.user_setup_after'     => 'anubis_check', // We want to run on every page
			'core.session_kill_after'   => 'logout', // User just logged out, set variable to remember that
			'core.session_create_after' => 'logout_check', // After the user logged out a new session was created, catch it.
			'core.session_gc_after'     => 'session_cleanup', // Cleanup unverified visitors
		];
	}

	private $user;
	private $template;
	private $request;
	private $config;
	private $controller_helper;
	private $path_helper;
	private $db;
	private $cache;

	/**
	 * @var \neodev\anubisbb\core\anubis_core
	 */
	private $anubis;
	private $logger;

	public function __construct(user $user, template $template, request $request, config $config, controller_helper $helper, path_helper $path_helper, driver_interface $db, cache $cache)
	{
		$this->user     = $user;
		$this->template = $template;
		$this->request  = $request;
		$this->config   = $config;

		$this->controller_helper = $helper;
		$this->path_helper       = $path_helper;

		$this->db    = $db;
		$this->cache = $cache;

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
		$this->logger = new logger('Intercept', $path_helper->get_phpbb_root_path(), $user);
	}

	/**
	 * Intercepts the request as early as possible before a user session is created
	 * @return void
	 */
	public function early_intercept()
	{
		if ($this->config['anubisbb_early'] !== '1')
		{
			return;
		}

		// Cookie check
		// Look for anubis cookies or user cookie
		$cookie_name = $this->config['cookie_name'];
		if (
			$this->request->is_set($cookie_name . '_anubisbb_early', $this->request::COOKIE) ||
			$this->request->is_set($cookie_name . '_anubisbb', $this->request::COOKIE) ||
			($this->request->is_set($cookie_name . '_u', $this->request::COOKIE) && $this->request->variable($cookie_name . '_u',0) > 1)
		)
		{
			return;
		}

		// Bot check, from phpbb/session.php
		$ua          = $this->request->header('User-Agent');
		$active_bots = $this->cache->obtain_bots();
		foreach ($active_bots as $row)
		{
			if ($row['bot_agent'] && preg_match('#' . str_replace('\*', '.*?', preg_quote($row['bot_agent'], '#')) . '#i', $ua))
			{
				return;
			}
		}

		// Path check
		// Grab current url
		global $phpbb_root_path;
		$page = $this->user::extract_current_page($phpbb_root_path);
		if ($this->skip_path($page, true))
		{
			return;
		}

		$route = $this->controller_helper->route('neodev_anubisbb_make_challenge');
		// $this->user->set_cookie('anubisbb_early','true', 0, false);

		echo <<< END
<html lang="en">
<head>
	<title>Loading...</title>
</head>
<body>
<noscript>Javascript is required to continue</noscript>
<a href="$route">Go</a>
<script>
const goto = (() => {
	const u = new URL('$route', window.location.href);
	u.searchParams.set('redir', window.location.href)
	window.location = u.toString();
})
setTimeout(goto, 50)
</script>
</body>
</html>
END;

		// Exit functions
		garbage_collection();
		exit_handler();
	}

	public function anubis_check()
	{
		// TODO: Deny bad bots

		// Good cookie, stop here
		if ($this->anubis->validate_cookie())
		{
			$this->logger->end('Bypassing, valid cookie');
			return;
		}

		// Skip users and bots
		if ($this->user->data['is_bot'] || $this->user->data['is_registered'])
		{
			$this->logger->end("Bypassing, user/bot ({$this->user->data['username']}/{$this->user->data['user_id']})");
			return;
		}

		// Skip paths
		if ($this->skip_path($this->user->page))
		{
			return;
		}

		// Kill the session to remove user from session table
		$this->logger->log('Killing session');
		$this->user->session_kill(false);

		$root_path = $this->path_helper->get_web_root_path();
		$this->template->assign_var('root_path', $root_path);

		// Paths for static files and the verification api
		$this->template->assign_vars([
			'static_path' => $root_path . 'ext/neodev/anubisbb/styles/all/theme/',
			'route_path'  => $this->controller_helper->route('neodev_anubisbb_pass_challenge'),
			'version'     => $this->anubis->version,
		]);

		$timestamp = time();

		// Fetch the challenge hash
		$challenge = $this->anubis->make_challenge($timestamp);
		$this->logger->log('Challenge created: ' . $challenge);
		if (!$challenge)
		{
			// Problem making the challenge?
			// TODO: log the error to phpBB
			$this->template->assign_vars([
				'title'         => 'Oh noes!',
				'error_message' => $this->anubis->error,
				'retry_link'    => build_url(), // Basically a link to the current url
			]);
			$this->template->set_filenames(['body' => '@neodev_anubisbb/failure_challenge.html']);
		}
		else
		{
			// Display challenge page
			$this->template->assign_vars([
				'title'      => 'Making sure you&#39;re not a bot!',
				'difficulty' => $this->config['anubisbb_difficulty'],
				'challenge'  => $challenge,
				'timestamp'  => $timestamp,
			]);
			$this->logger->log('Difficulty set to ' . $this->config['anubisbb_difficulty']);
			$this->template->set_filenames(['body' => '@neodev_anubisbb/make_challenge.html']);
		}

		// Have phpBB finalize the page
		$this->logger->end('Sending challenge page');
		page_footer();
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
		// Remove unverified visitors sessions after 10 minutes.
		$ttl = time() - 600;
		$sql = 'DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id = ' . ANONYMOUS . ' and anubisbb_pass = 0 and session_time < ' . $ttl;
		$this->db->sql_query($sql);
	}

	private function skip_path($page, $early = false)
	{
		// Skip import pages
		// Need to normalize the names due to weird paths for app.php and adm/index.php
		$page_normalized = substr($page['page'], 0, strpos($page['page'], '.'));
		switch ($page_normalized)
		{
			// Meh, should be fine
			// Skip the administration zone
			// case 'adm/index':
			// 	return;
			// break;

			// Deal with routed pages, aka app.php
			case 'app':

				// Grab the route
				$route = substr($page['page_name'], strpos($page['page_name'], '/'));

				// Everyone has access to the cron and feed routes
				// user route is used for deleting cookies and forgotten passwords
				// And of course don't block myself
				if ($early && preg_match('~^/feed(?:/|$)~', $route))
				{
					return true;
				}
				else
				{
					if (preg_match('~^/(?:cron|feed|anubis|user|help)(?:/|$)~', $route))
					{
						$this->logger->end('Skipping route');
						return true;
					}
				}
			break;

			// Allow visitors to call for help
			case 'memberlist':
				if ($early)
				{
					return false;
				}

				$mode = $this->request->variable('mode', '');

				if ($mode == 'contactadmin')
				{
					$this->logger->end('Skipping contact page');
					return true;
				}
			break;

			// Allow visitors to login
			case 'ucp':
				if ($early)
				{
					return false;
				}

				$mode = $this->request->variable('mode', '');

				// You are on this site...
				// but we do not grant you the rank of visitor
				if (in_array($mode, ['privacy', 'terms']))
				{
					$this->logger->end('Skipping ucp, killing session');
					$this->user->session_kill(false);
					return true;
				}

				// Ignore login pages
				if (in_array($mode, ['login', 'login_link', 'logout', 'confirm', 'sendpassword', 'activate', 'resend_act', 'delete_cookies']))
				{
					$this->logger->end('Skipping ucp');
					return true;
				}
			break;
		}
		return false;
	}
}
