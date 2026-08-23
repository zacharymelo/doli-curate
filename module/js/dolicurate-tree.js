/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Category tree screen: create, rename, move, merge and delete categories.
 */
(function () {
	'use strict';

	var D = DoliCurate;
	if (!D || !D.cfg || D.cfg.screen !== 'tree') { return; }

	var state = { tree: [] };

	function load() {
		D.get(D.cfg.urlTree, { action: 'list' }).then(function (data) {
			if (!data.ok) { D.notify(data.error || 'Error', 'error'); return; }
			state.tree = data.tree;
			renderParentOptions();
			render();
		});
	}

	function renderParentOptions() {
		var sel = D.el('dc-cat-parent');
		D.clear(sel);

		var root = document.createElement('option');
		root.value = '0';
		root.textContent = '(root)';
		sel.appendChild(root);

		state.tree.forEach(function (n) {
			var o = document.createElement('option');
			o.value = n.id;
			// Non-breaking spaces indent the option text inside the dropdown.
			o.textContent = new Array(n.depth + 1).join('  ') + n.label;
			sel.appendChild(o);
		});
	}

	/** Options excluding a subtree, so a category cannot be moved into itself. */
	function targetOptions(excludeId) {
		var excluded = {};
		if (excludeId) {
			var start = null;
			state.tree.forEach(function (n, i) { if (n.id === excludeId) { start = i; } });
			if (start !== null) {
				var baseDepth = state.tree[start].depth;
				excluded[excludeId] = true;
				for (var i = start + 1; i < state.tree.length; i++) {
					if (state.tree[i].depth <= baseDepth) { break; }
					excluded[state.tree[i].id] = true;
				}
			}
		}

		var sel = document.createElement('select');
		sel.className = 'dc-input';
		var root = document.createElement('option');
		root.value = '0';
		root.textContent = '(root)';
		sel.appendChild(root);

		state.tree.forEach(function (n) {
			if (excluded[n.id]) { return; }
			var o = document.createElement('option');
			o.value = n.id;
			o.textContent = new Array(n.depth + 1).join('  ') + n.label;
			sel.appendChild(o);
		});

		return sel;
	}

	function render() {
		var host = D.el('dc-tree');
		D.clear(host);

		if (!state.tree.length) {
			host.appendChild(D.make('div', 'dc-empty', 'No product categories exist yet.'));
			return;
		}

		state.tree.forEach(function (n) {
			host.appendChild(node(n));
		});
	}

	function node(n) {
		var row = D.make('div', 'dc-treerow');
		row.style.paddingLeft = (8 + n.depth * 22) + 'px';

		var main = D.make('div', 'dc-treemain');
		if (n.color) {
			var sw = D.make('span', 'dc-swatch');
			sw.style.background = '#' + n.color;
			main.appendChild(sw);
		}
		main.appendChild(D.make('span', 'dc-treelabel', n.label));
		main.appendChild(D.make('span', 'dc-treecount', n.count_direct + ' products'));
		row.appendChild(main);

		var acts = D.make('div', 'dc-treeacts');

		acts.appendChild(actionBtn('Modify', function () { modifyFlow(n, row); }));
		acts.appendChild(actionBtn('Merge', function () { mergeFlow(n, row); }));
		acts.appendChild(actionBtn('Delete', function () { deleteFlow(n); }, 'dc-danger'));

		row.appendChild(acts);

		return row;
	}

	function actionBtn(text, fn, cls) {
		var b = D.make('button', 'dc-linkbtn' + (cls ? ' ' + cls : ''), text);
		b.type = 'button';
		b.addEventListener('click', fn);
		return b;
	}

	/**
	 * Edit name, colour and parent together.
	 *
	 * These were three separate actions. Splitting them meant a rename and a
	 * re-parent were validated against each other's stale value, and made the
	 * common case - "this category is misnamed and in the wrong place" - two
	 * round trips.
	 */
	function modifyFlow(n, row) {
		var existing = row.parentNode.querySelector('.dc-inline');
		if (existing) { existing.parentNode.removeChild(existing); }

		var box = D.make('div', 'dc-inline dc-editor');

		var nameWrap = D.make('label', 'dc-field');
		nameWrap.appendChild(D.make('span', 'dc-fieldlabel', 'Name'));
		var nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.className = 'dc-input';
		nameInput.value = n.label;
		nameWrap.appendChild(nameInput);
		box.appendChild(nameWrap);

		var colorWrap = D.make('label', 'dc-field');
		colorWrap.appendChild(D.make('span', 'dc-fieldlabel', 'Colour'));
		var colorInput = document.createElement('input');
		colorInput.type = 'color';
		colorInput.value = '#' + (n.color || '3b82f6');
		colorWrap.appendChild(colorInput);
		box.appendChild(colorWrap);

		var parentWrap = D.make('label', 'dc-field');
		parentWrap.appendChild(D.make('span', 'dc-fieldlabel', 'Location'));
		// Excludes its own subtree, so an invalid destination cannot be picked.
		var parentSel = targetOptions(n.id);
		parentSel.value = String(n.parent || 0);
		parentWrap.appendChild(parentSel);
		box.appendChild(parentWrap);

		var save = D.make('button', 'button', 'Save');
		save.type = 'button';

		var cancel = D.make('button', 'button button-cancel', 'Cancel');
		cancel.type = 'button';
		cancel.addEventListener('click', function () { box.parentNode.removeChild(box); });

		function commit() {
			var label = nameInput.value.trim();
			if (!label) { D.notify('A name is required.', 'error'); return; }

			save.disabled = true;
			D.post(D.cfg.urlTree, {
				action: 'update',
				id: n.id,
				label: label,
				color: (colorInput.value || '').replace('#', ''),
				parent: parentSel.value
			}).then(function (r) {
				save.disabled = false;
				if (!r.ok) { D.notify(friendlyError(r.error), 'error'); return; }
				if (box.parentNode) { box.parentNode.removeChild(box); }
				D.notify('Saved.', 'ok');
				load();
			});
		}

		save.addEventListener('click', commit);
		nameInput.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') { ev.preventDefault(); commit(); }
			if (ev.key === 'Escape' && box.parentNode) { box.parentNode.removeChild(box); }
		});

		box.appendChild(save);
		box.appendChild(cancel);

		row.parentNode.insertBefore(box, row.nextSibling);
		nameInput.focus();
		nameInput.select();
	}

	/** Turn a server error code into something a user can act on. */
	function friendlyError(code) {
		var map = {
			SiblingLabelExists: 'A category with that name already exists in that location.',
			CannotMoveIntoOwnSubtree: 'A category cannot be moved inside itself.',
			CannotBeItsOwnParent: 'A category cannot be its own parent.',
			LabelRequired: 'A name is required.',
			NotAProductCategory: 'That is not a product category.',
			CategoryNotFound: 'Category not found.'
		};
		return map[code] || code || 'Failed';
	}

	/** Inline picker so the user sees valid destinations rather than typing an id. */
	function inlinePicker(row, labelText, excludeId, onConfirm) {
		var existing = row.parentNode.querySelector('.dc-inline');
		if (existing) { existing.parentNode.removeChild(existing); }

		var box = D.make('div', 'dc-inline');
		box.appendChild(D.make('span', '', labelText));

		var sel = targetOptions(excludeId);
		box.appendChild(sel);

		var go = D.make('button', 'button', 'OK');
		go.type = 'button';
		go.addEventListener('click', function () {
			box.parentNode.removeChild(box);
			onConfirm(parseInt(sel.value, 10));
		});

		var cancel = D.make('button', 'button button-cancel', 'Cancel');
		cancel.type = 'button';
		cancel.addEventListener('click', function () { box.parentNode.removeChild(box); });

		box.appendChild(go);
		box.appendChild(cancel);
		row.parentNode.insertBefore(box, row.nextSibling);
	}

	function mergeFlow(n, row) {
		inlinePicker(row, 'Merge "' + n.label + '" into:', n.id, function (target) {
			if (!target) { D.notify('Pick a destination category.', 'error'); return; }

			var targetName = '';
			state.tree.forEach(function (x) { if (x.id === target) { targetName = x.label; } });

			var warn = 'Every product in "' + n.label + '" moves to "' + targetName + '", '
				+ 'any subcategories are re-parented, and "' + n.label + '" is deleted.\n\n'
				+ 'The product moves can be undone from History. The deleted category cannot be restored.\n\nContinue?';
			if (!confirm(warn)) { return; }

			D.post(D.cfg.urlTree, { action: 'merge', source: n.id, target: target }).then(function (r) {
				if (!r.ok) { D.notify((r.errors || []).join(', ') || r.error || 'Failed', 'error'); return; }
				D.notify('Merged: ' + r.moved + ' product(s) moved, ' + r.reparented + ' subcategory(ies) re-parented.', 'ok');
				load();
			});
		});
	}

	function deleteFlow(n) {
		if (n.count_direct > 0) {
			if (!confirm('"' + n.label + '" still holds ' + n.count_direct + ' product(s). Detach them and delete anyway?')) { return; }
			send(1);
			return;
		}
		if (!confirm('Delete category "' + n.label + '"?')) { return; }
		send(0);

		function send(force) {
			D.post(D.cfg.urlTree, { action: 'delete', id: n.id, force: force }).then(function (r) {
				if (!r.ok) {
					var e = (r.errors || []).join(', ') || r.error || 'Failed';
					if (e.indexOf('HasChildren') === 0) { e = 'This category has subcategories. Move or merge them first.'; }
					D.notify(e, 'error');
					return;
				}
				D.notify('Deleted' + (r.detached ? ', ' + r.detached + ' product(s) detached' : '') + '.', 'ok');
				load();
			});
		}
	}

	function init() {
		D.el('dc-cat-create').addEventListener('click', function () {
			var label = D.el('dc-cat-label').value.trim();
			if (!label) { D.notify('A name is required.', 'error'); return; }

			D.post(D.cfg.urlTree, {
				action: 'create',
				label: label,
				parent: D.el('dc-cat-parent').value,
				color: (D.el('dc-cat-color').value || '').replace('#', '')
			}).then(function (r) {
				if (!r.ok) { D.notify(r.error || 'Failed', 'error'); return; }
				D.el('dc-cat-label').value = '';
				D.notify('Created.', 'ok');
				load();
			});
		});

		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
