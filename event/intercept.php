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
use phpbb\request\request;
use phpbb\user;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
	private $request;
	private $config;
	private $controller_helper;
	private $db;
	private $cache;

	/**
	 * @var \neodev\anubisbb\core\anubis_core
	 */
	private $anubis;
	private $logger;

	public function __construct(user $user, request $request, config $config, controller_helper $helper, driver_interface $db, cache $cache)
	{
		$this->user    = $user;
		$this->request = $request;
		$this->config  = $config;

		$this->controller_helper = $helper;

		$this->db    = $db;
		$this->cache = $cache;

		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
		$this->logger = new logger($this->config, $this->user);
	}

	/**
	 * Intercepts the request as early as possible before a user session is created
	 *
	 * @return void
	 */
	public function early_intercept()
	{
		if ($this->config['anubisbb_early'] !== '1')
		{
			return;
		}

		// Preload user variable with basic data
		$this->user->browser = $this->request->header('User-Agent', '-');

		global $phpbb_root_path;
		$this->user->page = $this->user->extract_current_page($phpbb_root_path);

		// The ip address code in session.php is far more complex than this, but we
		// just need it for logging... so it should be okay?
		// TODO: revisit and bring inline with phpBB's ip code
		$this->user->ip = $this->request->server('REMOTE_ADDR', '-');

		// Check for cookies
		$cookie_name = $this->config['cookie_name'];
		if (
			// Continue if user has an AnubisBB cookie...
			$this->request->is_set($cookie_name . '_anubisbb', $this->request::COOKIE) ||
			// ...or if user has a user id other anonymous
			$this->request->variable($cookie_name . '_u', ANONYMOUS, false, $this->request::COOKIE) !== ANONYMOUS
			// We'll verify the cookies in the late intercept
		)
		{
			return;
		}

		// Bot check, from phpbb/session.php
		$active_bots = $this->cache->obtain_bots();
		foreach ($active_bots as $row)
		{
			if ($row['bot_agent'] && preg_match('#' . str_replace('\*', '.*?', preg_quote($row['bot_agent'], '#')) . '#i', $this->user->browser))
			{
				return;
			}
		}

		// Check if we can skip this request
		switch ($this->path_normalize())
		{
			case 'app':
				// Grab the route
				$route = substr($this->user->page['page_name'], strpos($this->user->page['page_name'], '/'));
				if (preg_match('~^/(?:feed(?:/|$)|anubis/(?:api|pages/\w+$))~', $route))
				{
					return;
				}
			break;

			case 'download/file':
				if ($this->config['anubisbb_hot_linking'] === '1')
				{
					return;
				}
			break;

			case 'ucp':
				$mode = $this->request->variable('mode', '');

				// Used by some captcha
				if ($mode == 'confirm')
				{
					return;
				}
			break;
		}

		$this->logger->log('Intercept (early)');
		$this->intercept();
	}

	public function anubis_check()
	{
		// TODO: Deny bad bots

		// Good cookie, stop here
		if ($this->anubis->validate_cookie())
		{
			return;
		}

		// Skip users and bots
		if ($this->user->data['is_bot'] || $this->user->data['is_registered'])
		{
			return;
		}

		// Skip paths
		if ($this->skip_path($this->user->page))
		{
			return;
		}

		// Kill the session to remove user from session table
		$this->user->session_kill(false);

		// Intercept request and send user to challenge page
		$this->logger->log('Intercept');
		$this->intercept();
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

	private function path_normalize()
	{
		// Need to normalize the names due to weird paths for app.php and adm/index.php
		return substr($this->user->page['page'], 0, strpos($this->user->page['page'], '.'));
	}

	private function skip_path($early = false)
	{
		// Skip import pages
		// Need to normalize the names due to weird paths for app.php and adm/index.php
		$page_normalized = substr($this->user->page['page'], 0, strpos($this->user->page['page'], '.'));

		// Not early
		if (!$early)
		{
			// When early intercept is off, allow important pages to bypass.
			// Namely, the login and contact pages, plus a few others.
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
					$route = substr($this->user->page['page_name'], strpos($this->user->page['page_name'], '/'));

					// Everyone has access to the cron and feed routes
					// user route is used for deleting cookies and forgotten passwords
					// And of course don't block myself
					if (preg_match('~^/(?:cron|feed|anubis/(?:api|pages/\w+)|user|help)(?:/|$)~', $route))
					{
						return true;
					}

				break;

				// Allow visitors to call for help
				case 'memberlist':
					$mode = $this->request->variable('mode', '');

					if ($mode == 'contactadmin')
					{
						return true;
					}
				break;

				// Allow visitors to login
				case 'ucp':
					$mode = $this->request->variable('mode', '');

					// You are on this site...
					// but we do not grant you the rank of visitor
					if (in_array($mode, ['privacy', 'terms']))
					{
						$this->user->session_kill(false);
						return true;
					}

					// Ignore login pages
					if (in_array($mode, ['login', 'login_link', 'logout', 'confirm', 'sendpassword', 'activate', 'resend_act', 'delete_cookies']))
					{
						return true;
					}
				break;

				case 'download/file':
					if ($this->config['anubisbb_hot_linking'] === '1')
					{
						return true;
					}
			}
		}
		return false;
	}

	private function intercept()
	{
		$this->user->session_kill(false);
		$make_challenge = $this->controller_helper->route('neodev_anubisbb_make_challenge', [], true, '');
		$no_js          = $this->controller_helper->route('neodev_anubisbb_pages', ['name' => 'nojs'], true, '', UrlGeneratorInterface::ABSOLUTE_URL);

		// Prevent an infinite loop: don't cache the "Loading..." redirect to Anubis.
		// Otherwise the user will keep being sent back to Anubis, even after they have a valid cookie.
		header('Cache-Control: no-store');

		echo <<< END
<!DOCTYPE html>
<html lang="en">
<head>
	<meta http-equiv="refresh" content="1;url=$no_js" />
	<title>Loading...</title>
	<style>
		body{background: #f9f5d7;margin: 0}
		.box{display: grid;height: 100vh;place-items: center}
		.spinner{margin:auto;height:40px;text-align:center;font-size:10px}
		.spinner>div{background-color:#333;height:100%;width:6px;display:inline-block;animation:sk-stretchdelay 1.2s infinite ease-in-out}
		.spinner>div:nth-child(even){margin:0 3px}
		.spinner .rect2{animation-delay:-1.1s}
		.spinner .rect3{animation-delay:-1s}
		.spinner .rect4{animation-delay:-.9s}
		.spinner .rect5{animation-delay:-.8s}
		@keyframes sk-stretchdelay{0%,100%,40%{transform:scaleY(.4)}20%{transform:scaleY(1)}}
	</style>
</head>
<body>
<div class="box">
	<div>
		<div class="spinner">
			<div class="rect1"></div><div class="rect2"></div><div class="rect3"></div><div class="rect4"></div><div class="rect5"></div>
		</div>
		<noscript><br><a href="$no_js" style="font-family: sans-serif">Javascript is disabled, click to continue</a></noscript>
	</div>
</div>
<script>
	const goto=(() => {
		const u = new URL('$make_challenge', window.location.href);
		u.searchParams.set('redir', window.location.href);
		window.location.assign(u.toString());
	});
	setTimeout(goto,500)
</script>
</body>
</html>
END;
		// Exit functions
		garbage_collection();
		exit_handler();
	}
}
