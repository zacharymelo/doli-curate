/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Dashboard: collapse and expand the per-category breakdown.
 *
 * The user's toggles are kept in a cookie so the page renders the same way
 * next time. index.php reads that cookie to render the initial state, so this
 * script only reacts to clicks and writes the cookie back. The cookie lists
 * the branches that differ from the admin's default (see
 * dolicurateReadListCookie()), so categories added later follow that default.
 */
(function () {
	'use strict';

	var COOKIE = 'dolicurate_dash_open';
	var table = document.getElementById('dc-dash-tree');
	if (!table) { return; }

	var rows = Array.prototype.slice.call(table.querySelectorAll('tr[data-id]'));
	var byParent = {};
	rows.forEach(function (tr) {
		var p = tr.getAttribute('data-parent');
		(byParent[p] = byParent[p] || []).push(tr);
	});

	function isOpen(tr) {
		var btn = tr.querySelector('.dc-tree-toggle');
		return !!btn && btn.getAttribute('aria-expanded') === 'true';
	}

	function setOpen(tr, open) {
		var btn = tr.querySelector('.dc-tree-toggle');
		if (!btn) { return; }
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		btn.textContent = open ? '▾' : '▸';
	}

	// Show a row's children when it is visible and open; hide the whole
	// subtree otherwise. Each child's own open state is left untouched, so
	// re-expanding a branch restores what was open inside it.
	function sync(tr, visible) {
		(byParent[tr.getAttribute('data-id')] || []).forEach(function (child) {
			var show = visible && isOpen(tr);
			child.classList.toggle('dc-hidden', !show);
			sync(child, show);
		});
	}

	function save() {
		var expandDefault = table.getAttribute('data-expand-default') === '1';
		var ids = rows.filter(function (tr) {
			return tr.querySelector('.dc-tree-toggle') && isOpen(tr) !== expandDefault;
		}).map(function (tr) { return tr.getAttribute('data-id'); });
		var path = table.getAttribute('data-cookie-path') || '/';
		var value = ids.length ? (expandDefault ? 'closed:' : 'open:') + ids.join(',') : '';
		document.cookie = COOKIE + '=' + encodeURIComponent(value)
			+ '; path=' + path
			+ '; max-age=' + (value === '' ? 0 : 60 * 60 * 24 * 365)
			+ '; SameSite=Lax'
			+ (location.protocol === 'https:' ? '; Secure' : '');
	}

	function syncAll() {
		(byParent['0'] || []).forEach(function (root) { sync(root, true); });
	}

	table.addEventListener('click', function (e) {
		var btn = e.target.closest('.dc-tree-toggle');
		if (!btn) { return; }
		var tr = btn.closest('tr');
		setOpen(tr, !isOpen(tr));
		sync(tr, true);
		save();
	});

	Array.prototype.forEach.call(document.querySelectorAll('.dc-tree-all'), function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var open = a.getAttribute('data-open') === '1';
			rows.forEach(function (tr) { setOpen(tr, open); });
			syncAll();
			save();
		});
	});
})();
