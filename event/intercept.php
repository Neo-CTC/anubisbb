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
use phpbb\request\request;
use phpbb\user;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
	private $controller_helper;
	private $cache;

	private $anubis;
	private $logger;

	public function __construct(user $user, request $request, config $config, controller_helper $helper, cache $cache)
	{
		$this->user              = $user;
		$this->request           = $request;
		$this->config            = $config;
		$this->controller_helper = $helper;
		$this->cache             = $cache;

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
		$path_normalize = $this->path_normalize();
		switch ($path_normalize)
		{
			case 'app':
				// Grab the route
				$route = substr($this->user->page['page_name'], strpos($this->user->page['page_name'], '/'));

				// Allow only RSS feeds and myself
				if (preg_match('~^/(?:feed(?:/|$)|anubis/(?:api|pages)/\w+$)~', $route))
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

	public function late_intercept()
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

		// Check if we can skip this request
		$path_normalize = $this->path_normalize();
		switch ($path_normalize)
		{
			// TODO: check bypasses
			case 'app':

				// Grab the route
				$route = substr($this->user->page['page_name'], strpos($this->user->page['page_name'], '/'));

				// Everyone has access to the cron and feed routes
				// user route is used for deleting cookies and forgotten passwords
				// And of course don't block myself
				if (preg_match('~^/(?:cron|feed|user|help|anubis/(?:api|pages)/\w+)(?:/|$)~', $route))
				{
					return;
				}

			break;

			// Allow visitors to call for help
			case 'memberlist':
				$mode = $this->request->variable('mode', '');

				if ($mode == 'contactadmin')
				{
					return;
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
					return;
				}

				// Ignore login pages
				if (in_array($mode, ['login', 'login_link', 'logout', 'confirm', 'sendpassword', 'activate', 'resend_act', 'delete_cookies']))
				{
					return;
				}
			break;

			case 'download/file':
				if ($this->config['anubisbb_hot_linking'] === '1')
				{
					return;
				}
		}

		// Intercept request and send user to challenge page
		$this->logger->log('Intercept (late)');

		$this->user->session_kill(false);
		$this->intercept();
	}

	private function path_normalize()
	{
		// Need to normalize the names due to weird paths for app.php and adm/index.php
		return substr($this->user->page['page'], 0, strpos($this->user->page['page'], '.'));
	}

	private function intercept()
	{
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
