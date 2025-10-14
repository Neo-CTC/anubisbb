<?php
/**
 *
 * AnubisBB. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace neodev\anubisbb\acp;

/**
 * AnubisBB ACP module.
 */
class main_module
{
	public $page_title;
	public $tpl_name;
	public $u_action;

	/**
	 * Main ACP module
	 *
	 * @param int    $id   The module ID
	 * @param string $mode The module mode (for example: manage or settings)
	 *
	 * @throws \Exception
	 */
	public function main($id, $mode)
	{
		global $phpbb_container;
		/** @var \neodev\anubisbb\controller\acp_controller $acp_controller */
		$acp_controller = $phpbb_container->get('neodev.anubisbb.controller.acp');

		switch ($mode)
		{
			case 'settings':
			default:
				$this->tpl_name   = 'acp_anubisbb_settings_body';
				$this->page_title = 'ACP_ANUBISBB_TITLE_SETTINGS';
		}

		// Make the $u_action url available in our ACP controller
		$acp_controller->set_page_url($this->u_action);

		// Load the display options handle in our ACP controller
		$acp_controller->display_options();
	}
}
