/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Bulk assign screen: filtered worklist, row selection, batch apply.
 */
(function () {
	'use strict';

	var D = DoliCurate;
	if (!D || !D.cfg || D.cfg.screen !== 'assign') { return; }

	var state = {
		offset: 0,
		total: 0,
		rows: [],
		// Selection survives paging and filtering, so a user can gather
		// products from several different filter views before applying.
		selected: {},
		// Row index of the last checkbox clicked, used as the anchor for a
		// shift-click range. Positional, so it is cleared whenever the rows
		// underneath it change.
		anchor: null,
		seq: 0
	};

	function filters() {
		return {
			search: D.el('dc-search').value.trim(),
			tagged: D.el('dc-tagged').value,
			type: D.el('dc-type').value,
			status: D.el('dc-status').value,
			category: D.el('dc-category').value,
			deep: D.el('dc-deep').checked ? 1 : 0,
			limit: D.cfg.pageSize,
			offset: state.offset
		};
	}

	function load() {
		var seq = ++state.seq;
		var host = D.el('dc-worklist');
		D.clear(host);
		host.appendChild(D.make('div', 'dc-empty', 'Loading...'));

		D.get(D.cfg.urlProducts, filters()).then(function (data) {
			if (seq !== state.seq) { return; }
			if (!data.ok) { D.notify(data.error || 'Error', 'error'); return; }
			state.rows = data.rows;
			state.total = data.total;
			// Indices refer to the page that was just replaced.
			state.anchor = null;
			render();
		}).catch(function () {
			if (seq !== state.seq) { return; }
			D.notify('Could not load products.', 'error');
		});
	}

	function render() {
		var host = D.el('dc-worklist');
		D.clear(host);

		if (!state.rows.length) {
			host.appendChild(D.make('div', 'dc-empty', 'No products match these filters.'));
			renderPager();
			renderSelection();
			return;
		}

		var table = D.make('table', 'dc-table');
		var thead = D.make('thead');
		var hr = D.make('tr');

		var th0 = D.make('th', 'dc-c-sel');
		var all = document.createElement('input');
		all.type = 'checkbox';
		all.id = 'dc-selectall';
		all.title = 'Select all on this page';
		all.addEventListener('change', function () {
			state.rows.forEach(function (r) {
				if (all.checked) { state.selected[r.id] = r; } else { delete state.selected[r.id]; }
			});
			// A page-wide toggle invalidates any previous anchor position.
			state.anchor = null;
			render();
		});
		th0.appendChild(all);
		hr.appendChild(th0);

		['Ref', 'Label', 'Categories'].forEach(function (t, i) {
			hr.appendChild(D.make('th', 'dc-c-' + ['ref', 'label', 'cats'][i], t));
		});
		thead.appendChild(hr);
		table.appendChild(thead);

		var tbody = D.make('tbody');
		state.rows.forEach(function (r, i) {
			tbody.appendChild(row(r, i));
		});
		table.appendChild(tbody);
		host.appendChild(table);

		all.checked = state.rows.length > 0 && state.rows.every(function (r) {
			return !!state.selected[r.id];
		});

		renderPager();
		renderSelection();
	}

	/**
	 * Set one row's selection, keeping state and the DOM in step.
	 *
	 * @param {number}  index   Row index on the current page
	 * @param {boolean} checked Desired state
	 */
	function setRowSelected(index, checked) {
		var r = state.rows[index];
		if (!r) { return; }

		if (checked) { state.selected[r.id] = r; } else { delete state.selected[r.id]; }

		var tr = D.el('dc-worklist').querySelector('tr[data-index="' + index + '"]');
		if (tr) {
			tr.classList.toggle('selected', checked);
			var box = tr.querySelector('input[type=checkbox]');
			if (box) { box.checked = checked; }
		}
	}

	/**
	 * Apply one state across an inclusive span of rows.
	 *
	 * The span takes the state of the checkbox that was shift-clicked, so
	 * shift-clicking a box off clears the range rather than selecting it. That
	 * matches how file managers and mail clients behave, and makes undoing a
	 * mis-selected range the same gesture as making one.
	 *
	 * @param {number}  from    Anchor index
	 * @param {number}  to      Index that was shift-clicked
	 * @param {boolean} checked State to apply
	 */
	function selectRange(from, to, checked) {
		var start = Math.min(from, to);
		var end = Math.max(from, to);

		for (var i = start; i <= end; i++) {
			setRowSelected(i, checked);
		}
	}

	/**
	 * Keep the header checkbox truthful after in-place row changes.
	 *
	 * Rows are updated without re-rendering the table, so nothing else would
	 * notice that a range selection had just completed the page.
	 */
	function syncSelectAll() {
		var all = D.el('dc-selectall');
		if (!all) { return; }

		all.checked = state.rows.length > 0 && state.rows.every(function (r) {
			return !!state.selected[r.id];
		});
	}

	function row(r, index) {
		var tr = D.make('tr', 'dc-row' + (state.selected[r.id] ? ' selected' : ''));
		tr.setAttribute('data-index', index);

		var tdSel = D.make('td', 'dc-c-sel');
		var cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.checked = !!state.selected[r.id];
		cb.title = 'Shift-click to select a range';

		// Bound on click rather than change: only a click event carries
		// shiftKey, and by the time it fires the browser has already toggled
		// the box, so cb.checked is the state the user is asking for.
		cb.addEventListener('click', function (ev) {
			var checked = cb.checked;

			if (ev.shiftKey && state.anchor !== null && state.anchor !== index) {
				selectRange(state.anchor, index, checked);
				// Shift-clicking drags a text selection across the rows it
				// spans; clear it so the range does not come back highlighted.
				var sel = window.getSelection();
				if (sel && sel.removeAllRanges) { sel.removeAllRanges(); }
			} else {
				setRowSelected(index, checked);
			}

			state.anchor = index;
			syncSelectAll();
			renderSelection();
		});

		tdSel.appendChild(cb);
		tr.appendChild(tdSel);

		tr.appendChild(D.make('td', 'dc-c-ref', r.ref));

		var tdLabel = D.make('td', 'dc-c-label');
		tdLabel.appendChild(D.make('span', '', r.label));
		if (r.type === 1) {
			tdLabel.appendChild(D.make('span', 'dc-badge dc-badge-svc', 'service'));
		}
		tr.appendChild(tdLabel);

		var tdCats = D.make('td', 'dc-c-cats');
		if (!r.categories.length) {
			tdCats.appendChild(D.make('span', 'dc-untagged', 'untagged'));
		} else {
			r.categories.forEach(function (c) {
				var chip = D.make('span', 'dc-chip', c.label);
				if (c.color) { chip.style.borderLeft = '3px solid #' + c.color; }
				tdCats.appendChild(chip);
			});
		}
		tr.appendChild(tdCats);

		return tr;
	}

	function renderPager() {
		var host = D.el('dc-pager');
		D.clear(host);

		var size = D.cfg.pageSize;
		var from = state.total === 0 ? 0 : state.offset + 1;
		var to = Math.min(state.offset + size, state.total);

		host.appendChild(D.make('span', 'dc-pageinfo', from + '-' + to + ' / ' + state.total));

		var prev = D.make('button', 'button button-cancel', '<');
		prev.type = 'button';
		prev.disabled = state.offset <= 0;
		prev.addEventListener('click', function () {
			state.offset = Math.max(0, state.offset - size);
			load();
		});

		var next = D.make('button', 'button button-cancel', '>');
		next.type = 'button';
		next.disabled = (state.offset + size) >= state.total;
		next.addEventListener('click', function () {
			state.offset += size;
			load();
		});

		host.appendChild(prev);
		host.appendChild(next);
	}

	function selectedIds() {
		return Object.keys(state.selected).map(function (k) { return parseInt(k, 10); });
	}

	function renderSelection() {
		var info = D.el('dc-selinfo');
		if (!info) { return; }

		var n = selectedIds().length;
		D.clear(info);
		info.appendChild(D.make('strong', '', n));
		info.appendChild(document.createTextNode(' selected'));

		if (n > 0) {
			var clearBtn = D.make('button', 'dc-linkbtn', 'Clear');
			clearBtn.type = 'button';
			clearBtn.addEventListener('click', function () {
				state.selected = {};
				render();
			});
			info.appendChild(clearBtn);
		}

		var cats = D.multiValues(D.el('dc-target'));
		var ready = (n > 0 && cats.length > 0);
		D.el('dc-add').disabled = !ready;
		D.el('dc-remove').disabled = !ready;
	}

	function apply(action) {
		var products = selectedIds();
		var categories = D.multiValues(D.el('dc-target'));
		if (!products.length || !categories.length) { return; }

		var btn = D.el(action === 'add' ? 'dc-add' : 'dc-remove');
		btn.disabled = true;

		D.post(D.cfg.urlAssign, {
			action: action,
			products: JSON.stringify(products),
			categories: JSON.stringify(categories)
		}).then(function (data) {
			btn.disabled = false;
			if (!data.ok) {
				D.notify((data.error || (data.errors || []).join(', ') || 'Failed'), 'error');
				return;
			}
			var msg = 'Applied ' + data.applied;
			if (data.skipped) { msg += ', ' + data.skipped + ' already set'; }
			D.notify(msg, 'ok');
			state.selected = {};
			load();
		}).catch(function () {
			btn.disabled = false;
			D.notify('Request failed.', 'error');
		});
	}

	function init() {
		var reload = function () { state.offset = 0; load(); };

		D.el('dc-search').addEventListener('input', D.debounce(reload, 250));
		['dc-tagged', 'dc-type', 'dc-status', 'dc-category'].forEach(function (id) {
			D.el(id).addEventListener('change', reload);
		});
		D.el('dc-deep').addEventListener('change', reload);

		var target = D.el('dc-target');
		if (target) { target.addEventListener('change', renderSelection); }

		if (D.el('dc-add')) {
			D.el('dc-add').addEventListener('click', function () { apply('add'); });
			D.el('dc-remove').addEventListener('click', function () { apply('remove'); });
		}

		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
