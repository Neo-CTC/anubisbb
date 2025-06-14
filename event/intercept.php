<?php
/**
 *
 * AntiBot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2022, Neo, https://crosstimecafe.com
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\event;

use neodev\anubisbb\core\anubis_core;
use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\path_helper;
use phpbb\request\request;
use phpbb\user;
use phpbb\template\template;
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
		];
	}

	private $user;
	private $template;
	private $request;
	private $config;
	private $helper;
	private $path_helper;
	/**
	 * @var \neodev\anubisbb\core\anubis_core
	 */
	private $anubis;

	public function __construct(user $user, template $template, request $request, config $config, helper $helper, path_helper $path_helper)
	{
		$this->user        = $user;
		$this->template    = $template;
		$this->request     = $request;
		$this->config      = $config;
		$this->helper      = $helper;
		$this->path_helper = $path_helper;
		$this->anubis = new anubis_core($this->config, $this->request, $this->user);
	}

	public function anubis_check()
	{
		// TODO: Deny bad bots

		// Good cookie, stop here
		if($this->anubis->validate_cookie())
		{
			return;
		}

		// Skip users and bots
		if ($this->user->data['is_bot'] || $this->user->data['is_registered'])
		{
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

				// Everyone has access to the cron and feed routes
				// And of course don't block myself
				// TODO: other possible routes: '/help/faq'
				if (preg_match('~^/(?:cron|feed|anubis)/~', $route))
				{
					return;
				}
			break;

			// Allow visitors call for help and sign in, plus a few other needed pages
			case 'memberlist':
			case 'ucp':
				$mode = $this->request->variable('mode', '');
				// TODO: in theory we could let Anubis run on every page
				// TODO: add login & contact links to template
				// TODO: other possible modes: 'sendpassword'
				// TODO: consider dropping some of these or run it at low difficulty
				if (in_array($mode, [/* memberlist.php */ 'contactadmin', /* ucp.php */ 'login', 'logout', 'delete_cookies', 'confirm', 'privacy', 'terms']))
				{
					// You are on this site...
					// but we do not grant you the rank of visitor
					// $this->user->session_kill(false);
					// Well, that was a mistake
					// TODO: find a way to remove the visitor from the online list when on these pages
					return;
				}
			break;
		}

		// Kill the session to remove user from session table
		$this->user->session_kill(false);

		// Paths for static files and the verification api
		$this->template->assign_vars([
			'static_path' => $this->path_helper->get_web_root_path() . 'ext/neodev/anubisbb/styles/all/theme/',
			'route_path'  => $this->helper->route('neodev_anubisbb_pass_challenge'),
			'version' => $this->anubis->version,
		]);

		// Fetch the challenge hash
		$challenge = $this->anubis->make_challenge();
		if(!$challenge)
		{
			// Problem making the challenge?
			// TODO: log the error to phpBB
			$this->template->assign_vars([
				'title' => 'Oh noes!',
				'error_message' => $this->anubis->error,
				'contact' => $this->path_helper->get_web_root_path() . 'memberlist.php?mode=contactadmin',
				'retry_link' => build_url(), // Basically a link to the current url
				// TODO: test the retry link
			]);
			$this->template->set_filenames(['body' => '@neodev_anubisbb/failure_challenge.html']);

		}
		else
		{
			// Display challenge page
			$this->template->assign_vars([
				'title' => 'Making sure you&#39;re not a bot!',
				'difficulty' => $this->config['anubisbb_difficulty'],
				'challenge' => $challenge,
			]);
			$this->template->set_filenames(['body' => '@neodev_anubisbb/make_challenge.html']);
		}

		// Have phpBB finalize the page
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
		}
	}

}
