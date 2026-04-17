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
use phpbb\config\db_text as config_text;
use phpbb\controller\helper as controller_helper;
use phpbb\request\request;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class intercept implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.common'           => 'early_intercept',
			'core.user_setup_after' => 'late_intercept', // We want to run on every page
		];
	}

	private $user;
	private $request;
	private $config;
	private $cache;
	private $config_text;

	private $anubis;
	private $logger;

	private $intercept_request;

	public function __construct(user $user, request $request, config $config, controller_helper $helper, cache $cache, config_text $config_text)
	{
		$this->user        = $user;
		$this->request     = $request;
		$this->config      = $config;
		$this->cache       = $cache;
		$this->config_text = $config_text;

		$this->anubis = new anubis_core($this->config, $helper, $this->request, $this->user);
		$this->logger = new logger($this->config, $this->user);

		$this->intercept_request = true;
	}

	/**
	 * Intercepts the request as early as possible before a user session is created
	 *
	 * @return void
	 */
	public function early_intercept()
	{
		// Preload user variable with basic data
		$this->user->browser = $this->request->header('User-Agent', '-');

		global $phpbb_root_path;
		$this->user->page = $this->user->extract_current_page($phpbb_root_path);

		// The ip address code in session.php is far more complex than this, but we
		// just need it for logging... so it should be okay?
		// TODO: revisit and bring inline with phpBB's ip code
		$this->user->ip = $this->request->server('REMOTE_ADDR', '-');

		// Check for cookies
		// We'll verify the cookies in the late intercept
		$cookie_name = $this->config['cookie_name'];
		if (
			// Continue if user has an AnubisBB cookie...
			$this->request->is_set($cookie_name . '_anubisbb', $this->request::COOKIE) ||
			// ...or if user has a user id other anonymous
			$this->request->variable($cookie_name . '_u', ANONYMOUS, false, $this->request::COOKIE) !== ANONYMOUS
		)
		{
			return;
		}

		// Bot check, from phpbb/session.php
		// We'll check a second time in the late intercept
		$active_bots = $this->cache->obtain_bots();
		foreach ($active_bots as $row)
		{
			if ($row['bot_agent'] && preg_match('#' . str_replace('\*', '.*?', preg_quote($row['bot_agent'], '#')) . '#i', $this->user->browser))
			{
				return;
			}
		}


		// Is the path one of the allowed bypass paths?
		if ($this->path_bypass())
		{
			$this->intercept_request = false;
		}

		/**
		 * Event to bypass Anubis
		 *
		 * @event anubisbb.intercept.bypass
		 * @var string user_agent Browser of the user
		 * @var string ip_address IP address of the user
		 * @var string path Current phpBB path
		 * @var bool intercept_request Should AnubisBB intercept the request
		 * @since 0.5.0
		 */

		// That's one way to shut up PHPStorm
		/** @noinspection PhpUnusedLocalVariableInspection */
		{
			$user_agent        = $this->user->browser;
			$ip_address        = $this->user->ip;
			$page              = $this->user->page['page'];
			$intercept_request = $this->intercept_request;
		}

		global $phpbb_dispatcher;
		$vars = ['user_agent', 'ip_address', 'page', 'intercept_request'];
		extract($phpbb_dispatcher->trigger_event('anubisbb.intercept.bypass', compact($vars)));

		$this->intercept_request = $intercept_request;

		if ($this->intercept_request !== true)
		{
			return;
		}

		$this->logger->log('Intercept');
		$this->intercept();
	}

	public function late_intercept()
	{
		// Already decided not to intercept the request
		if ($this->intercept_request !== true)
		{
			return;
		}

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

		// Intercept request and send user to challenge page
		$this->user->session_kill(false);
		$this->logger->log('Intercept (late)');
		$this->intercept();
	}

	private function get_path_cache()
	{
		$d     = $this->cache->get_driver();
		$paths = $d->get('anbuisbb_paths_cache');
		if ($paths === false)
		{
			$paths = explode("\n", $this->config_text->get('anubisbb_paths'));
			$paths = array_filter($paths, function ($v) {
				if (preg_match('/^\s*#|^\s*$/', $v))
				{
					return false;
				}
				return true;
			});
			$d->put('anbuisbb_paths_cache', $paths);
		}
		return $paths;
	}

	private function path_bypass()
	{
		// Grab the page minus the query string
		$path_normalized = $this->user->page['page_name'];

		// Strip off app from routes
		$path_normalized = preg_replace('#^app\.php#', '', $path_normalized);

		$mode = $this->request->variable('mode', '');

		switch ($path_normalized)
		{
			// Non regex paths first
			case 'download/file.php':
				if ($this->config['anubisbb_hot_linking'] === '1')
				{
					return true;
				}
			break;

			case 'memberlist.php':
				if ($this->config['anubisbb_allow_extra_pages'] &&
					$mode == 'contactadmin')
				{
					return true;
				}
			break;

			case 'ucp.php':
				// Used by some captcha
				if ($mode == 'confirm')
				{
					return true;
				}

				// Allow login pages
				if ($this->config['anubisbb_allow_extra_pages'] &&
					in_array($mode, [
						'activate',
						'confirm',
						'delete_cookies',
						'login',
						'login_link',
						'logout',
						'privacy',
						'resend_act',
						'sendpassword',
						'terms',
					]))
				{
					return true;
				}

			break;

			default:
				// Allow RSS feeds and myself
				if (preg_match('~^/(?:feed(?:/|$)|anubis/(?:api|pages)/\w+$)~', $path_normalized))
				{
					return true;
				}

				// Allow extra pages
				// cron: cron tasks, user: deleting cookies & forgotten passwords, help: faq page
				if ($this->config['anubisbb_allow_extra_pages'] &&
					preg_match('~^/(?:cron|user|help)(?:/|$)~', $path_normalized))
				{
					return true;
				}

				// Check admin provided paths
				// These should all fall under the control of app.php
				$path_cache = $this->get_path_cache();
				foreach ($path_cache as $path)
				{
					// Take the path, escape any regex characters, convert escaped * into a wildcard.
					// Always match the path from the start of the route
					if (preg_match('#^' . str_replace('\*', '.*?', preg_quote($path, '#')) . '$#i', $path_normalized))
					{
						return true;
					}
				}
			break;
		}

		return false;
	}

	private function intercept()
	{
		$make_challenge = $this->anubis->routes['make'];
		$no_js          = $this->anubis->routes['nojs'];

		// Prevent an infinite loop: don't cache the "Loading..." redirect to Anubis.
		// Otherwise the user will keep being sent back to Anubis, even after they have a valid cookie.
		header('Cache-Control: no-store');

		echo <<< END
<!DOCTYPE html>
<html lang="en">
<head>
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
