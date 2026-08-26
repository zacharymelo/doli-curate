<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modDoliCurate.class.php
 * \ingroup dolicurate
 * \brief   Module descriptor for Doli Curate.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Doli Curate — screens for organising the product catalogue into categories.
 */
class modDoliCurate extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 500500;
		$this->rights_class = 'dolicurate';
		$this->family = 'products';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = 'Screens for assigning, sorting and curating product categories in bulk';
		$this->descriptionlong = 'Bulk-assign categories to products from a filterable worklist, define re-runnable tagging rules with a live preview, reshape the category tree itself, and track how much of the catalogue is organised. Every membership change is recorded in an audit trail and can be undone as a batch.';

		$this->editor_name = 'Zachary Melo';
		$this->editor_url = '';

		$this->version = '1.4.3';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'category';

		$this->module_parts = array(
			'triggers' => 0,
			// Not registered here: module_parts emits an unversioned <link>, which
			// browsers cache indefinitely. Emitted by dolicurateStylesheetTag().
			'hooks' => array('data' => array(), 'entity' => '0'),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@dolicurate');

		$this->depends = array('modProduct', 'modCategorie');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('dolicurate@dolicurate');

		$this->phpmin = array(7, 3);
		$this->need_dolibarr_version = array(19, 0);

		// The last value of each row is deleteonunactive. It is 0 deliberately.
		// Dolibarr's delete_const() drops any constant flagged 1 when the module is
		// disabled, and several changes here need a disable/enable cycle to take
		// effect (menus and hook contexts are only written at activation). Flagging
		// these 1 would wipe every configured setting during a routine upgrade.
		$this->const = array(
			array('DOLICURATE_PAGE_SIZE', 'chaine', '50', 'Products shown per page in the assign worklist', 0, 'current', 0),
			array('DOLICURATE_PREVIEW_LIMIT', 'chaine', '200', 'Maximum rows shown in a rule preview', 0, 'current', 0),
			array('DOLICURATE_BATCH_LIMIT', 'chaine', '2000', 'Maximum membership changes in a single operation', 0, 'current', 0),
			array('DOLICURATE_SHOW_IMAGES', 'chaine', '0', 'Show product thumbnails in the worklist', 0, 'current', 0),
			array('DOLICURATE_KEEP_LOG_DAYS', 'chaine', '90', 'Days of audit history to retain', 0, 'current', 0),
			array('DOLICURATE_DEBUG_MODE', 'chaine', '0', 'Expose the diagnostic endpoint', 0, 'current', 0),
		);

		// Permissions. Read is granted by default; every writing action is not.
		$r = 0;

		$r++;
		$this->rights[$r][0] = 500501;
		$this->rights[$r][1] = 'Read the curation screens';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'curate';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = 500502;
		$this->rights[$r][1] = 'Assign and remove product categories';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'curate';
		$this->rights[$r][5] = 'assign';

		$r++;
		$this->rights[$r][0] = 500503;
		$this->rights[$r][1] = 'Create and run tagging rule sets';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'curate';
		$this->rights[$r][5] = 'rules';

		$r++;
		$this->rights[$r][0] = 500504;
		$this->rights[$r][1] = 'Reshape the category tree (rename, move, merge, delete)';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'curate';
		$this->rights[$r][5] = 'tree';

		$r++;
		$this->rights[$r][0] = 500505;
		$this->rights[$r][1] = 'Undo a previously applied batch';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'curate';
		$this->rights[$r][5] = 'undo';

		// Menus. This module is a catalogue tool, not a top-level concern, so it
		// lives at the bottom of the Products | Services left menu rather than
		// claiming its own entry in the main navigation bar. A high position keeps
		// it below the native product entries.
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products',
			'type'     => 'left',
			'titre'    => 'DoliCurate',
			'prefix'   => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'dolicurate',
			'url'      => '/dolicurate/index.php',
			'langs'    => 'dolicurate@dolicurate',
			'position' => 1000,
			'enabled'  => 'isModEnabled("dolicurate")',
			'perms'    => '$user->hasRight("dolicurate", "curate", "read")',
			'target'   => '',
			'user'     => 0,
		);

		// Children appear once the parent entry is the active left menu.
		$submenus = array(
			array('Dashboard',   'dolicurate_dashboard', '/dolicurate/index.php',   'read'),
			array('MenuAssign',  'dolicurate_assign',    '/dolicurate/assign.php',  'read'),
			array('MenuRules',   'dolicurate_rules',     '/dolicurate/rules.php',   'rules'),
			array('MenuTree',    'dolicurate_tree',      '/dolicurate/tree.php',    'tree'),
			array('MenuHistory', 'dolicurate_history',   '/dolicurate/history.php', 'read'),
		);

		$position = 1001;
		foreach ($submenus as $sub) {
			list($title, $leftmenu, $url, $right) = $sub;

			$this->menu[$r++] = array(
				'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=dolicurate',
				'type'     => 'left',
				'titre'    => $title,
				'mainmenu' => 'products',
				'leftmenu' => $leftmenu,
				'url'      => $url,
				'langs'    => 'dolicurate@dolicurate',
				'position' => $position++,
				'enabled'  => 'isModEnabled("dolicurate")',
				'perms'    => '$user->hasRight("dolicurate", "curate", "'.$right.'")',
				'target'   => '',
				'user'     => 0,
			);
		}

		$this->tabs = array();
	}

	/**
	 * Enable the module: create tables, then run the standard init.
	 *
	 * @param  string $options Options
	 * @return int             1 on success, <=0 on failure
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/dolicurate/sql/');
		if ($result < 0) {
			return -1;
		}

		// Upgrade cleanup: delete_module_parts() only removes constants for keys
		// still in the descriptor, so dropping 'css' orphaned this one and
		// Dolibarr kept emitting a second, unversioned stylesheet link.
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."const";
		$sql .= " WHERE ".$this->db->decrypt('name')." = '".$this->db->escape($this->const_name.'_CSS')."'";
		$this->db->query($sql);

		$this->migratePermissionIds();

		$this->delete_menus();

		return $this->_init(array(), $options);
	}

	/**
	 * Move granted permissions from the module's old ids to its current ones.
	 *
	 * Releases up to 1.3.x numbered this module 500420 with permission ids
	 * 500421-500425, which collided with another module. Permission grants are
	 * stored in llx_user_rights and llx_usergroup_rights by permission id, so
	 * renumbering without moving them would leave every existing grant pointing
	 * at an id this module no longer owns - users would silently lose access
	 * with nothing to indicate why.
	 *
	 * An old id is only migrated when nothing currently claims it in
	 * rights_def. If another module has since taken it, its grants are left
	 * alone rather than stolen.
	 *
	 * @return void
	 */
	private function migratePermissionIds()
	{
		$map = array(
			500421 => 500501,
			500422 => 500502,
			500423 => 500503,
			500424 => 500504,
			500425 => 500505,
		);

		foreach ($map as $old => $new) {
			$sql = "SELECT id FROM ".MAIN_DB_PREFIX."rights_def";
			$sql .= " WHERE id = ".((int) $old);
			$sql .= " AND module <> '".$this->db->escape($this->rights_class)."'";

			$resql = $this->db->query($sql);
			$claimed = ($resql && $this->db->num_rows($resql) > 0);
			if ($resql) {
				$this->db->free($resql);
			}
			if ($claimed) {
				continue;
			}

			// Drop any grant already sitting on the new id, so re-running this
			// cannot produce a user holding the same permission twice.
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."user_rights"
				." WHERE fk_id = ".((int) $new)
				." AND fk_user IN (SELECT fk_user FROM (SELECT fk_user FROM ".MAIN_DB_PREFIX."user_rights WHERE fk_id = ".((int) $old).") x)");
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."usergroup_rights"
				." WHERE fk_id = ".((int) $new)
				." AND fk_usergroup IN (SELECT fk_usergroup FROM (SELECT fk_usergroup FROM ".MAIN_DB_PREFIX."usergroup_rights WHERE fk_id = ".((int) $old).") x)");

			$this->db->query("UPDATE ".MAIN_DB_PREFIX."user_rights SET fk_id = ".((int) $new)." WHERE fk_id = ".((int) $old));
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."usergroup_rights SET fk_id = ".((int) $new)." WHERE fk_id = ".((int) $old));

			// Remove the stale definition row if the old activation left one.
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."rights_def"
				." WHERE id = ".((int) $old)
				." AND module = '".$this->db->escape($this->rights_class)."'");
		}
	}

	/**
	 * Disable the module.
	 *
	 * @param  string $options Options
	 * @return int             1 on success, <=0 on failure
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
