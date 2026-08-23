<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/dolicuratetree.class.php
 * \ingroup dolicurate
 * \brief   Structural changes to the product category tree.
 *
 * llx_categorie has no constraint preventing a category from becoming its own
 * ancestor, and Dolibarr's own tree walks would hang on such a cycle. Every
 * move here is therefore validated against the target's ancestry first.
 *
 * Merges move product memberships through DoliCurateCurator so the operation
 * lands in the audit trail and can be undone.
 */

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
dol_include_once('/dolicurate/class/dolicuratecurator.class.php');
dol_include_once('/dolicurate/class/dolicuratecatalog.class.php');

/**
 * Create, rename, move, merge and delete product categories.
 */
class DoliCurateTree
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Load a product category, refusing categories of any other type.
	 *
	 * @param  int            $id Category id
	 * @return Categorie|null     Loaded category, or null
	 */
	private function fetchProductCategory($id)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return null;
		}

		$cat = new Categorie($this->db);
		if ($cat->fetch($id) <= 0) {
			$this->error = 'CategoryNotFound';
			return null;
		}
		if ((int) $cat->type !== DoliCurateCatalog::CATEGORY_TYPE_PRODUCT) {
			$this->error = 'NotAProductCategory';
			return null;
		}

		return $cat;
	}

	/**
	 * Ancestors of a category, nearest first.
	 *
	 * @param  int   $id Category id
	 * @return int[]     Ancestor ids
	 */
	public function getAncestorIds($id)
	{
		$out = array();
		$seen = array();
		$cur = (int) $id;
		$guard = 0;

		while ($cur > 0 && $guard < DoliCurateCatalog::MAX_TREE_DEPTH) {
			$guard++;
			if (isset($seen[$cur])) {
				break;
			}
			$seen[$cur] = true;

			$sql = "SELECT fk_parent FROM ".MAIN_DB_PREFIX."categorie WHERE rowid = ".$cur;
			$resql = $this->db->query($sql);
			$o = $resql ? $this->db->fetch_object($resql) : null;
			if ($resql) {
				$this->db->free($resql);
			}
			if (!$o) {
				break;
			}

			$parent = (int) $o->fk_parent;
			if ($parent <= 0) {
				break;
			}
			$out[] = $parent;
			$cur = $parent;
		}

		return $out;
	}

	/**
	 * Create a category.
	 *
	 * @param  string $label    Label
	 * @param  int    $parentId Parent id, 0 for a root
	 * @param  string $color    Hex colour without '#'
	 * @param  User   $user     Acting user
	 * @return int              New id, or -1 on error
	 */
	public function createCategory($label, $parentId, $color, $user)
	{
		$label = trim($label);
		if ($label === '') {
			$this->error = 'LabelRequired';
			return -1;
		}

		$parentId = (int) $parentId;
		if ($parentId > 0 && !$this->fetchProductCategory($parentId)) {
			return -1;
		}

		// Sibling labels must stay unique or paths become ambiguous.
		if ($this->siblingExists($label, $parentId, 0)) {
			$this->error = 'SiblingLabelExists';
			return -1;
		}

		$cat = new Categorie($this->db);
		$cat->label = $label;
		$cat->type = DoliCurateCatalog::CATEGORY_TYPE_PRODUCT;
		$cat->fk_parent = $parentId;
		$cat->color = preg_replace('/[^0-9a-f]/i', '', (string) $color);
		// Core leaves this at 0; set it so rows created here are not anomalous.
		$cat->visible = 1;

		$id = $cat->create($user);
		if ($id <= 0) {
			$this->error = $cat->error ?: 'CreateFailed';
			return -1;
		}

		return (int) $id;
	}

	/**
	 * Whether a sibling with this label already exists under a parent.
	 *
	 * @param  string $label     Label to test
	 * @param  int    $parentId  Parent id
	 * @param  int    $excludeId Category to ignore (itself, when renaming)
	 * @return bool              True when a clash exists
	 */
	private function siblingExists($label, $parentId, $excludeId)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie";
		$sql .= " WHERE type = ".DoliCurateCatalog::CATEGORY_TYPE_PRODUCT;
		$sql .= " AND entity IN (".getEntity('category').")";
		$sql .= " AND fk_parent = ".((int) $parentId);
		$sql .= " AND label = '".$this->db->escape($label)."'";
		if ($excludeId > 0) {
			$sql .= " AND rowid <> ".((int) $excludeId);
		}
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		$found = ($resql && $this->db->num_rows($resql) > 0);
		if ($resql) {
			$this->db->free($resql);
		}

		return $found;
	}

	/**
	 * Update a category's name, colour and location in one operation.
	 *
	 * Replaces the previous separate rename and move. Splitting them validated
	 * the wrong pair: renaming checked the new label against the OLD parent,
	 * while moving checked the OLD label against the new parent. Neither checked
	 * the new name against the new location, so renaming and re-parenting in two
	 * steps could produce two siblings sharing a label.
	 *
	 * @param  int         $id       Category id
	 * @param  string      $label    New label
	 * @param  string|null $color    New colour, hex without '#'; null leaves it
	 * @param  int|null    $parentId New parent, 0 for a root; null leaves it
	 * @param  User        $user     Acting user
	 * @return int                   1 on success, -1 on error
	 */
	public function updateCategory($id, $label, $color, $parentId, $user)
	{
		$id = (int) $id;
		$label = trim((string) $label);

		if ($label === '') {
			$this->error = 'LabelRequired';
			return -1;
		}

		$cat = $this->fetchProductCategory($id);
		if (!$cat) {
			return -1;
		}

		// A null parent means "leave where it is".
		$targetParent = ($parentId === null) ? (int) $cat->fk_parent : (int) $parentId;

		if ($targetParent === $id) {
			$this->error = 'CannotBeItsOwnParent';
			return -1;
		}

		if ($targetParent > 0 && $targetParent !== (int) $cat->fk_parent) {
			if (!$this->fetchProductCategory($targetParent)) {
				return -1;
			}

			// The destination must not sit beneath the category being moved.
			$catalog = new DoliCurateCatalog($this->db);
			if (in_array($targetParent, $catalog->getDescendantIds($id), true)) {
				$this->error = 'CannotMoveIntoOwnSubtree';
				return -1;
			}
		}

		// One check, against the values the category will actually have.
		if ($this->siblingExists($label, $targetParent, $id)) {
			$this->error = 'SiblingLabelExists';
			return -1;
		}

		$cat->label = $label;
		$cat->fk_parent = $targetParent;
		if ($color !== null) {
			$cat->color = preg_replace('/[^0-9a-f]/i', '', (string) $color);
		}

		if ($cat->update($user) <= 0) {
			$this->error = $cat->error ?: 'UpdateFailed';
			return -1;
		}

		return 1;
	}

	/**
	 * Merge one category into another.
	 *
	 * Every product in the source gains the target, then loses the source; the
	 * source's children are re-parented to the target; finally the source is
	 * deleted. The membership half runs through the curator, so the whole merge
	 * appears in history as one undoable batch.
	 *
	 * Undo restores memberships. It does not resurrect the deleted category, so
	 * the UI must say so before the user commits.
	 *
	 * @param  int  $sourceId Category to absorb
	 * @param  int  $targetId Category to keep
	 * @param  User $user     Acting user
	 * @return array<string,mixed> Outcome with 'ok', 'moved', 'batch', 'errors'
	 */
	public function mergeCategories($sourceId, $targetId, $user)
	{
		$out = array('ok' => false, 'moved' => 0, 'reparented' => 0, 'batch' => '', 'errors' => array());

		$sourceId = (int) $sourceId;
		$targetId = (int) $targetId;

		if ($sourceId === $targetId) {
			$out['errors'][] = 'SourceAndTargetIdentical';
			return $out;
		}

		$source = $this->fetchProductCategory($sourceId);
		$target = $this->fetchProductCategory($targetId);
		if (!$source || !$target) {
			$out['errors'][] = $this->error ?: 'CategoryNotFound';
			return $out;
		}

		// Merging a parent into its own descendant would destroy the branch.
		$catalog = new DoliCurateCatalog($this->db);
		if (in_array($targetId, $catalog->getDescendantIds($sourceId), true)) {
			$out['errors'][] = 'CannotMergeIntoOwnDescendant';
			return $out;
		}

		// Products linked to the source.
		$sql = "SELECT fk_product FROM ".MAIN_DB_PREFIX."categorie_product WHERE fk_categorie = ".$sourceId;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$out['errors'][] = $this->db->lasterror();
			return $out;
		}
		$productIds = array();
		while ($o = $this->db->fetch_object($resql)) {
			$productIds[] = (int) $o->fk_product;
		}
		$this->db->free($resql);

		$curator = new DoliCurateCurator($this->db);

		$changes = array();
		foreach ($productIds as $pid) {
			$changes[] = array('product' => $pid, 'category' => $targetId, 'action' => DoliCurateCurator::ACTION_ADD);
			$changes[] = array('product' => $pid, 'category' => $sourceId, 'action' => DoliCurateCurator::ACTION_REMOVE);
		}

		if (!empty($changes)) {
			$res = $curator->applyChanges($changes, $user, DoliCurateCurator::SOURCE_MERGE, null);
			$out['batch'] = $res['batch'];
			if (empty($res['ok'])) {
				$out['errors'] = array_merge($out['errors'], $res['errors']);
				return $out;
			}
			$out['moved'] = count($productIds);
		}

		// Re-parent the source's direct children onto the target.
		$this->db->begin();

		$sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."categorie WHERE fk_parent = ".$sourceId;
		$resql = $this->db->query($sql);
		$children = array();
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$children[] = array('id' => (int) $o->rowid, 'label' => (string) $o->label);
			}
			$this->db->free($resql);
		}

		foreach ($children as $child) {
			// A name clash under the new parent would break path uniqueness.
			$label = $child['label'];
			if ($this->siblingExists($label, $targetId, $child['id'])) {
				$label = $label.' ('.$source->label.')';
			}

			$upd = "UPDATE ".MAIN_DB_PREFIX."categorie";
			$upd .= " SET fk_parent = ".$targetId.", label = '".$this->db->escape($label)."'";
			$upd .= " WHERE rowid = ".((int) $child['id']);

			if (!$this->db->query($upd)) {
				$out['errors'][] = $this->db->lasterror();
				$this->db->rollback();
				return $out;
			}
			$out['reparented']++;
		}

		$this->db->commit();

		// Finally drop the now-empty source.
		if ($source->delete($user) <= 0) {
			$out['errors'][] = $source->error ?: 'DeleteFailed';
			return $out;
		}

		$out['ok'] = true;

		return $out;
	}

	/**
	 * Delete a category.
	 *
	 * Refuses while the category still has children or product links, unless
	 * $force is set, in which case the memberships are removed through the
	 * curator first so the deletion is reversible in part.
	 *
	 * @param  int  $id    Category id
	 * @param  bool $force Detach products first
	 * @param  User $user  Acting user
	 * @return array<string,mixed> Outcome with 'ok', 'detached', 'batch', 'errors'
	 */
	public function deleteCategory($id, $force, $user)
	{
		$out = array('ok' => false, 'detached' => 0, 'batch' => '', 'errors' => array());

		$cat = $this->fetchProductCategory($id);
		if (!$cat) {
			$out['errors'][] = $this->error;
			return $out;
		}

		$resql = $this->db->query("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX."categorie WHERE fk_parent = ".((int) $id));
		$o = $resql ? $this->db->fetch_object($resql) : null;
		if ($resql) {
			$this->db->free($resql);
		}
		if ($o && (int) $o->c > 0) {
			$out['errors'][] = 'HasChildren';
			return $out;
		}

		$resql = $this->db->query("SELECT fk_product FROM ".MAIN_DB_PREFIX."categorie_product WHERE fk_categorie = ".((int) $id));
		$productIds = array();
		if ($resql) {
			while ($row = $this->db->fetch_object($resql)) {
				$productIds[] = (int) $row->fk_product;
			}
			$this->db->free($resql);
		}

		if (!empty($productIds)) {
			if (!$force) {
				$out['errors'][] = 'HasProducts:'.count($productIds);
				return $out;
			}

			$curator = new DoliCurateCurator($this->db);
			$res = $curator->unassign($productIds, array((int) $id), $user, DoliCurateCurator::SOURCE_MERGE);
			$out['batch'] = $res['batch'];
			if (empty($res['ok'])) {
				$out['errors'] = array_merge($out['errors'], $res['errors']);
				return $out;
			}
			$out['detached'] = count($productIds);
		}

		if ($cat->delete($user) <= 0) {
			$out['errors'][] = $cat->error ?: 'DeleteFailed';
			return $out;
		}

		$out['ok'] = true;

		return $out;
	}
}
