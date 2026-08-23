<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/dolicuratecatalog.class.php
 * \ingroup dolicurate
 * \brief   Read-only catalogue and category-tree queries.
 *
 * Reads native tables only. All mutation lives in DoliCurateCurator so that
 * every membership change passes through one audited code path.
 */

/**
 * Catalogue queries backing the worklist, the tree screen and the dashboard.
 */
class DoliCurateCatalog
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/** Product-type discriminator in llx_categorie.type */
	const CATEGORY_TYPE_PRODUCT = 0;

	/** Depth guard for tree walks; llx_categorie has no constraint against cycles */
	const MAX_TREE_DEPTH = 64;

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
	 * Every product category in the entity, as a flat list with depth and path.
	 *
	 * Built in PHP from a single flat SELECT rather than a recursive query, so
	 * the module behaves the same on MySQL, MariaDB and PostgreSQL.
	 *
	 * @return array<int,array<string,mixed>> Categories ordered depth-first by label
	 */
	public function getCategoryTree()
	{
		$sql = "SELECT c.rowid, c.label, c.fk_parent, c.color, c.description, c.position";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";
		$sql .= " ORDER BY c.position ASC, c.label ASC";

		$rows = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		while ($o = $this->db->fetch_object($resql)) {
			$rows[(int) $o->rowid] = array(
				'id' => (int) $o->rowid,
				'label' => (string) $o->label,
				'parent' => (int) $o->fk_parent,
				'color' => (string) $o->color,
				'description' => (string) $o->description,
				'position' => (int) $o->position,
				'children' => array(),
			);
		}
		$this->db->free($resql);

		// Counts of directly-linked products, resolved in one pass.
		$counts = $this->getDirectCounts();

		foreach ($rows as $id => $row) {
			$rows[$id]['count_direct'] = isset($counts[$id]) ? $counts[$id] : 0;
		}

		// Link children to parents. A parent id that does not exist (orphaned
		// subtree) is treated as a root so the branch stays reachable.
		foreach ($rows as $id => $row) {
			$p = $row['parent'];
			if ($p > 0 && isset($rows[$p])) {
				$rows[$p]['children'][] = $id;
			}
		}

		$out = array();
		$seen = array();

		$walk = function ($id, $depth, $path) use (&$walk, &$rows, &$out, &$seen) {
			if (isset($seen[$id]) || $depth > self::MAX_TREE_DEPTH) {
				return;
			}
			$seen[$id] = true;

			$node = $rows[$id];
			$node['depth'] = $depth;
			$node['path'] = $path === '' ? $node['label'] : $path.' / '.$node['label'];
			unset($node['children']);
			$out[] = $node;

			foreach ($rows[$id]['children'] as $child) {
				$walk($child, $depth + 1, $node['path']);
			}
		};

		foreach ($rows as $id => $row) {
			if ($row['parent'] <= 0 || !isset($rows[$row['parent']])) {
				$walk($id, 0, '');
			}
		}

		// Anything still unvisited sits in a cycle; surface it rather than hide it.
		foreach ($rows as $id => $row) {
			if (!isset($seen[$id])) {
				$walk($id, 0, '');
			}
		}

		return $out;
	}

	/**
	 * Number of products linked directly to each category.
	 *
	 * @return array<int,int> Category id => product count
	 */
	public function getDirectCounts()
	{
		$sql = "SELECT cp.fk_categorie, COUNT(DISTINCT cp.fk_product) as cnt";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie_product as cp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cp.fk_product";
		$sql .= " WHERE p.entity IN (".getEntity('product').")";
		$sql .= " GROUP BY cp.fk_categorie";

		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$out[(int) $o->fk_categorie] = (int) $o->cnt;
			}
			$this->db->free($resql);
		}

		return $out;
	}

	/**
	 * A category and every category beneath it.
	 *
	 * @param  int   $catId Category id
	 * @return int[]        Category ids including the starting point
	 */
	public function getDescendantIds($catId)
	{
		$catId = (int) $catId;
		if ($catId <= 0) {
			return array();
		}

		$all = array($catId => $catId);
		$frontier = array($catId);
		$guard = 0;

		while (!empty($frontier) && $guard < self::MAX_TREE_DEPTH) {
			$guard++;

			$sql = "SELECT c.rowid FROM ".MAIN_DB_PREFIX."categorie as c";
			$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
			$sql .= " AND c.entity IN (".getEntity('category').")";
			$sql .= " AND c.fk_parent IN (".$this->db->sanitize(implode(',', $frontier)).")";

			$resql = $this->db->query($sql);
			if (!$resql) {
				break;
			}

			$next = array();
			while ($o = $this->db->fetch_object($resql)) {
				$id = (int) $o->rowid;
				if (!isset($all[$id])) {
					$all[$id] = $id;
					$next[] = $id;
				}
			}
			$this->db->free($resql);
			$frontier = $next;
		}

		return array_values($all);
	}

	/**
	 * Build the WHERE fragment shared by the worklist and its counter.
	 *
	 * @param  array<string,mixed> $f Filters
	 * @return string                 SQL beginning with " WHERE ..."
	 */
	private function buildWhere($f)
	{
		$sql = " WHERE p.entity IN (".getEntity('product').")";

		$type = isset($f['type']) ? (int) $f['type'] : -1;
		if ($type >= 0) {
			$sql .= " AND p.fk_product_type = ".$type;
		}

		$status = isset($f['status']) ? (string) $f['status'] : '';
		if ($status === 'sell') {
			$sql .= " AND p.tosell = 1";
		} elseif ($status === 'buy') {
			$sql .= " AND p.tobuy = 1";
		}

		$search = isset($f['search']) ? trim((string) $f['search']) : '';
		if ($search !== '') {
			$needle = $this->db->escape($this->db->escapeforlike($search));
			$sql .= " AND (p.ref LIKE '%".$needle."%'";
			$sql .= " OR p.label LIKE '%".$needle."%'";
			$sql .= " OR p.barcode LIKE '%".$needle."%')";
		}

		// Tagging state is the filter the whole screen exists for.
		$tagged = isset($f['tagged']) ? (string) $f['tagged'] : 'all';
		if ($tagged === 'untagged') {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cpx WHERE cpx.fk_product = p.rowid)";
		} elseif ($tagged === 'tagged') {
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cpx WHERE cpx.fk_product = p.rowid)";
		}

		// Restrict to a branch of the tree.
		$category = isset($f['category']) ? (int) $f['category'] : 0;
		if ($category > 0) {
			$ids = !empty($f['deep']) ? $this->getDescendantIds($category) : array($category);
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cpy";
			$sql .= " WHERE cpy.fk_product = p.rowid";
			$sql .= " AND cpy.fk_categorie IN (".$this->db->sanitize(implode(',', $ids)).") )";
		}

		$supplier = isset($f['supplier']) ? (int) $f['supplier'] : 0;
		if ($supplier > 0) {
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."product_fournisseur_price pfp";
			$sql .= " WHERE pfp.fk_product = p.rowid AND pfp.fk_soc = ".$supplier.")";
		}

		return $sql;
	}

	/**
	 * Count products matching the worklist filters.
	 *
	 * @param  array<string,mixed> $filters Filters
	 * @return int                          Matching product count
	 */
	public function countProducts($filters = array())
	{
		$sql = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= $this->buildWhere($filters);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0;
		}
		$o = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $o ? (int) $o->cnt : 0;
	}

	/**
	 * One page of the worklist, each row carrying its current categories.
	 *
	 * @param  array<string,mixed> $filters Filters plus 'limit' and 'offset'
	 * @return array<int,array<string,mixed>> Product rows
	 */
	public function listProducts($filters = array())
	{
		$limit = isset($filters['limit']) ? (int) $filters['limit'] : 50;
		$limit = max(1, min($limit, 500));
		$offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

		$sql = "SELECT p.rowid, p.ref, p.label, p.barcode, p.fk_product_type,";
		$sql .= " p.tosell, p.tobuy, p.price, p.stock";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= $this->buildWhere($filters);
		$sql .= " ORDER BY p.ref ASC";
		$sql .= $this->db->plimit($limit, $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return array();
		}

		$rows = array();
		$ids = array();
		while ($o = $this->db->fetch_object($resql)) {
			$id = (int) $o->rowid;
			$ids[] = $id;
			$rows[$id] = array(
				'id' => $id,
				'ref' => (string) $o->ref,
				'label' => (string) $o->label,
				'barcode' => (string) $o->barcode,
				'type' => (int) $o->fk_product_type,
				'tosell' => (int) $o->tosell,
				'tobuy' => (int) $o->tobuy,
				'price' => (float) $o->price,
				'stock' => (float) $o->stock,
				'categories' => array(),
			);
		}
		$this->db->free($resql);

		if (empty($ids)) {
			return array();
		}

		// Attach categories for the page in one query rather than per row.
		$sql = "SELECT cp.fk_product, c.rowid, c.label, c.color";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie_product as cp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = cp.fk_categorie";
		$sql .= " WHERE cp.fk_product IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " AND c.entity IN (".getEntity('category').")";
		$sql .= " ORDER BY c.label ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$pid = (int) $o->fk_product;
				if (isset($rows[$pid])) {
					$rows[$pid]['categories'][] = array(
						'id' => (int) $o->rowid,
						'label' => (string) $o->label,
						'color' => (string) $o->color,
					);
				}
			}
			$this->db->free($resql);
		}

		return array_values($rows);
	}

	/**
	 * Headline numbers for the dashboard.
	 *
	 * @return array<string,mixed> Coverage figures
	 */
	public function getCoverage()
	{
		$out = array(
			'total' => 0, 'tagged' => 0, 'untagged' => 0, 'pct' => 0,
			'products_total' => 0, 'products_untagged' => 0,
			'services_total' => 0, 'services_untagged' => 0,
			'categories' => 0, 'categories_empty' => 0, 'links' => 0,
		);

		$scope = " FROM ".MAIN_DB_PREFIX."product as p WHERE p.entity IN (".getEntity('product').")";
		$untaggedClause = " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cp WHERE cp.fk_product = p.rowid)";

		$pairs = array(
			'total' => "SELECT COUNT(*) c".$scope,
			'untagged' => "SELECT COUNT(*) c".$scope.$untaggedClause,
			'products_total' => "SELECT COUNT(*) c".$scope." AND p.fk_product_type = 0",
			'products_untagged' => "SELECT COUNT(*) c".$scope." AND p.fk_product_type = 0".$untaggedClause,
			'services_total' => "SELECT COUNT(*) c".$scope." AND p.fk_product_type = 1",
			'services_untagged' => "SELECT COUNT(*) c".$scope." AND p.fk_product_type = 1".$untaggedClause,
		);

		foreach ($pairs as $key => $sql) {
			$resql = $this->db->query($sql);
			if ($resql && ($o = $this->db->fetch_object($resql))) {
				$out[$key] = (int) $o->c;
				$this->db->free($resql);
			}
		}

		$out['tagged'] = $out['total'] - $out['untagged'];
		$out['pct'] = $out['total'] > 0 ? round(($out['tagged'] / $out['total']) * 100, 1) : 0;

		$resql = $this->db->query("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX."categorie"
			." WHERE type = ".((int) self::CATEGORY_TYPE_PRODUCT)
			." AND entity IN (".getEntity('category').")");
		if ($resql && ($o = $this->db->fetch_object($resql))) {
			$out['categories'] = (int) $o->c;
			$this->db->free($resql);
		}

		$resql = $this->db->query("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX."categorie c2"
			." WHERE c2.type = ".((int) self::CATEGORY_TYPE_PRODUCT)
			." AND c2.entity IN (".getEntity('category').")"
			." AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cp WHERE cp.fk_categorie = c2.rowid)");
		if ($resql && ($o = $this->db->fetch_object($resql))) {
			$out['categories_empty'] = (int) $o->c;
			$this->db->free($resql);
		}

		$resql = $this->db->query("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX."categorie_product");
		if ($resql && ($o = $this->db->fetch_object($resql))) {
			$out['links'] = (int) $o->c;
			$this->db->free($resql);
		}

		return $out;
	}
}
