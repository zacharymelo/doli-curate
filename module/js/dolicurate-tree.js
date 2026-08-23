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

		acts.appendChild(actionBtn('Rename', function () { renameFlow(n); }));
		acts.appendChild(actionBtn('Move', function () { moveFlow(n, row); }));
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

	function renameFlow(n) {
		var next = prompt('Rename category', n.label);
		if (next === null) { return; }
		next = next.trim();
		if (!next || next === n.label) { return; }

		D.post(D.cfg.urlTree, { action: 'rename', id: n.id, label: next, color: n.color || '' })
			.then(function (r) {
				if (!r.ok) { D.notify(r.error || 'Failed', 'error'); return; }
				D.notify('Renamed.', 'ok');
				load();
			});
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

	function moveFlow(n, row) {
		inlinePicker(row, 'Move "' + n.label + '" under:', n.id, function (parent) {
			D.post(D.cfg.urlTree, { action: 'move', id: n.id, parent: parent }).then(function (r) {
				if (!r.ok) { D.notify(r.error || 'Failed', 'error'); return; }
				D.notify('Moved.', 'ok');
				load();
			});
		});
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
