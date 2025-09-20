<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\core;

class logger
{
	private $root;
	private $debug_log;
	private $debug;

	public function __construct($base, $root, $user)
	{
		$this->root = $root . "store/";
		$this->debug_log = date('c')." | {$base} | {$user->ip} | {$user->browser}\n";
		$this->debug = true;
	}

	public function log($msg)
	{
		if (!$this->debug)
		{
			return;
		}

		$this->debug_log .= "\t" . $msg . "\n";
	}

	public function end($msg = '')
	{
		if (!$this->debug)
		{
			return;
		}

		if ($msg)
		{
			$this->log($msg);
		}
		if (file_exists($this->root) && is_writeable($this->root))
		{
			file_put_contents($this->root . 'anubisbb.log', $this->debug_log, FILE_APPEND);
		}
	}
}
