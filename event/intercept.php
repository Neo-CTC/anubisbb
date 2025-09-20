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

use phpbb\config\config;
use phpbb\controller\helper;
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
	private $helper;
	private $path_helper;
	private $db;
	/**
	 * @var \neodev\anubisbb\core\anubis_core
	 */
	private $anubis;
	private $logger;

	public function __construct(user $user, template $template, request $request, config $config, helper $helper, path_helper $path_helper, driver_interface $db)
	{
		$this->user        = $user;
		$this->template    = $template;
		$this->request     = $request;
		$this->config      = $config;
		$this->helper      = $helper;
		$this->path_helper = $path_helper;
		$this->db          = $db;
		$this->anubis      = new anubis_core($this->config, $this->request, $this->user);
		$this->logger      = new logger('Intercept', $path_helper->get_phpbb_root_path(), $user);
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

		// Skip import pages
		// Need to normalize the names due to weird paths for app.php and adm/index.php
		$page = substr($this->user->page['page'], 0, strpos($this->user->page['page'], '.'));
		switch ($page)
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
				$this->logger->log('Routed page ' . $route);
				// Everyone has access to the cron and feed routes
				// user route is used for deleting cookies and forgotten passwords
				// And of course don't block myself
				if (preg_match('~^/(?:cron|feed|anubis|user|help)/~', $route))
				{
					$this->logger->end('Skipping route');
					return;
				}
			break;

			// Allow visitors to call for help
			case 'memberlist':
				$mode = $this->request->variable('mode', '');

				if ($mode == 'contactadmin')
				{
					$this->logger->end('Skipping contact page');
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
					$this->logger->end('Skipping ucp, killing session');
					$this->user->session_kill(false);
					return;
				}

				// Ignore login pages
				if (in_array($mode, ['login', 'login_link', 'logout', 'confirm', 'sendpassword', 'activate', 'resend_act', 'delete_cookies']))
				{
					$this->logger->end('Skipping ucp');
					return;
				}
			break;
		}

		// Kill the session to remove user from session table
		$this->logger->log('Killing session');
		$this->user->session_kill(false);

		$root_path = $this->path_helper->get_web_root_path();
		$this->template->assign_var('root_path', $root_path);

		// Paths for static files and the verification api
		$this->template->assign_vars([
			'static_path' => $root_path . 'ext/neodev/anubisbb/styles/all/theme/',
			'route_path'  => $this->helper->route('neodev_anubisbb_pass_challenge'),
			'version'     => $this->anubis->version,
		]);

		// Fetch the challenge hash
		$challenge = $this->anubis->make_challenge();
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
			$sql = 'UPDATE ' . SESSIONS_TABLE . ' 
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
}
