/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * History screen: every recorded batch, what it touched, and undo.
 *
 * A batch row summarises the operation; expanding it lists the individual
 * membership changes, because "3 added" is not enough to decide whether a
 * batch should be reversed.
 */
(function () {
	'use strict';

	var D = DoliCurate;
	if (!D || !D.cfg || D.cfg.screen !== 'history') { return; }

	var SOURCES = { 1: 'Assign screen', 2: 'Rule set', 3: 'Category merge', 4: 'Undo' };
	var ACTION_ADD = 1;

	/** Batch id -> loaded detail, so re-expanding does not refetch. */
	var detailCache = {};

	function load() {
		var host = D.el('dc-history');
		D.clear(host);
		host.appendChild(D.make('div', 'dc-empty', 'Loading...'));

		D.get(D.cfg.urlStats, { action: 'history', limit: 100 }).then(function (data) {
			D.clear(host);
			if (!data.ok) { D.notify(data.error || 'Error', 'error'); return; }
			if (!data.batches.length) {
				host.appendChild(D.make('div', 'dc-empty', 'Nothing has been changed yet.'));
				return;
			}
			detailCache = {};
			host.appendChild(table(data.batches));
		});
	}

	function fmtDate(ts) {
		if (!ts) { return ''; }
		return new Date(ts * 1000).toLocaleString();
	}

	/** Short human summary of what a batch touched. */
	function summaryText(b) {
		var cats = b.categories || [];
		var shown = cats.slice(0, 3).join(', ');
		if (cats.length > 3) { shown += ' +' + (cats.length - 3) + ' more'; }

		var what = b.products + (b.products === 1 ? ' product' : ' products');
		if (!cats.length) { return what; }

		// Direction reads differently depending on what the batch did.
		if (b.adds && !b.removes) { return what + ' → ' + shown; }
		if (b.removes && !b.adds) { return what + ' ✕ ' + shown; }
		return what + ' ⇄ ' + shown;
	}

	function table(batches) {
		var t = D.make('table', 'dc-table dc-history-table');

		var thead = D.make('thead');
		var hr = D.make('tr');
		['', 'When', 'By', 'Source', 'What changed', 'Added', 'Removed', ''].forEach(function (h, i) {
			hr.appendChild(D.make('th', (i === 5 || i === 6) ? 'right' : '', h));
		});
		thead.appendChild(hr);
		t.appendChild(thead);

		var tbody = D.make('tbody');
		batches.forEach(function (b) {
			var tr = D.make('tr', 'dc-row dc-batchrow' + (b.undone ? ' dc-undone' : ''));

			// Expander
			var tdEx = D.make('td', 'dc-c-ex');
			var caret = D.make('button', 'dc-caret', '▸');
			caret.type = 'button';
			caret.title = 'Show the individual changes';
			tdEx.appendChild(caret);
			tr.appendChild(tdEx);

			tr.appendChild(D.make('td', 'dc-nowrap', fmtDate(b.started)));
			tr.appendChild(D.make('td', '', b.user || ''));

			var tdSrc = D.make('td', '');
			tdSrc.appendChild(document.createTextNode(SOURCES[b.source] || b.source));
			if (b.ruleset_label) {
				tdSrc.appendChild(D.make('span', 'dc-badge', b.ruleset_label));
			}
			tr.appendChild(tdSrc);

			tr.appendChild(D.make('td', 'dc-summary', summaryText(b)));
			tr.appendChild(D.make('td', 'right', b.adds));
			tr.appendChild(D.make('td', 'right', b.removes));

			var tdAct = D.make('td', 'right');
			if (b.undone) {
				tdAct.appendChild(D.make('span', 'dc-badge', 'undone'));
			} else if (D.cfg.can.undo) {
				var btn = D.make('button', 'dc-linkbtn', 'Undo');
				btn.type = 'button';
				btn.addEventListener('click', function (ev) {
					ev.stopPropagation();
					undo(b, btn);
				});
				tdAct.appendChild(btn);
			}
			tr.appendChild(tdAct);

			// Detail row, inserted collapsed directly beneath.
			var detailTr = D.make('tr', 'dc-detailrow');
			detailTr.hidden = true;
			var detailTd = D.make('td', 'dc-detailcell');
			detailTd.colSpan = 8;
			detailTr.appendChild(detailTd);

			var toggle = function () {
				var opening = detailTr.hidden;
				detailTr.hidden = !opening;
				caret.textContent = opening ? '▾' : '▸';
				tr.classList.toggle('dc-expanded', opening);
				if (opening) { ensureDetail(b, detailTd); }
			};

			caret.addEventListener('click', function (ev) { ev.stopPropagation(); toggle(); });
			tr.addEventListener('click', toggle);
			tr.style.cursor = 'pointer';

			tbody.appendChild(tr);
			tbody.appendChild(detailTr);
		});
		t.appendChild(tbody);

		return t;
	}

	function ensureDetail(b, cell) {
		if (detailCache[b.batch]) { return; }

		D.clear(cell);
		cell.appendChild(D.make('div', 'dc-empty', 'Loading changes...'));

		D.get(D.cfg.urlStats, { action: 'batch', batch: b.batch, limit: 200 }).then(function (data) {
			D.clear(cell);
			if (!data.ok) {
				cell.appendChild(D.make('div', 'dc-empty', data.error || 'Could not load this batch.'));
				return;
			}
			detailCache[b.batch] = true;
			cell.appendChild(renderDetail(data));
		}).catch(function () {
			D.clear(cell);
			cell.appendChild(D.make('div', 'dc-empty', 'Could not load this batch.'));
		});
	}

	function renderDetail(data) {
		var wrap = D.make('div', 'dc-detailwrap');

		var t = D.make('table', 'dc-detailtable');
		var thead = D.make('thead');
		var hr = D.make('tr');
		['Change', 'Product', 'Category', ''].forEach(function (h) {
			hr.appendChild(D.make('th', '', h));
		});
		thead.appendChild(hr);
		t.appendChild(thead);

		var tbody = D.make('tbody');
		data.rows.forEach(function (r) {
			var tr = D.make('tr');

			var tdAct = D.make('td', 'dc-c-act');
			tdAct.appendChild(D.make(
				'span',
				'dc-actpill ' + (r.action === ACTION_ADD ? 'dc-add' : 'dc-rem'),
				r.action === ACTION_ADD ? 'added' : 'removed'
			));
			tr.appendChild(tdAct);

			// Product, linked to its card unless it no longer exists.
			var tdP = D.make('td', 'dc-c-prod');
			if (r.product_deleted) {
				tdP.appendChild(D.make('span', 'dc-gone', r.product_ref + ' (deleted)'));
			} else {
				var a = D.make('a', 'dc-mono', r.product_ref);
				a.href = data.productUrl + '?id=' + r.product;
				a.target = '_blank';
				a.rel = 'noopener';
				tdP.appendChild(a);
				if (r.product_label) {
					tdP.appendChild(D.make('span', 'dc-prodlabel', r.product_label));
				}
				if (r.product_type === 1) {
					tdP.appendChild(D.make('span', 'dc-badge dc-badge-svc', 'service'));
				}
			}
			tr.appendChild(tdP);

			// Category, likewise.
			var tdC = D.make('td', 'dc-c-cat');
			if (r.category_deleted) {
				tdC.appendChild(D.make('span', 'dc-gone', r.category_label + ' (deleted)'));
			} else {
				var chip = D.make('a', 'dc-chip', r.category_label);
				chip.href = data.categoryUrl + '?id=' + r.category + '&type=product';
				chip.target = '_blank';
				chip.rel = 'noopener';
				if (r.category_color) { chip.style.borderLeft = '3px solid #' + r.category_color; }
				tdC.appendChild(chip);
			}
			tr.appendChild(tdC);

			var tdU = D.make('td', 'right');
			if (r.undone) { tdU.appendChild(D.make('span', 'dc-badge', 'reversed')); }
			tr.appendChild(tdU);

			tbody.appendChild(tr);
		});
		t.appendChild(tbody);
		wrap.appendChild(t);

		if (data.total > data.rows.length) {
			wrap.appendChild(D.make(
				'div',
				'dc-truncnote',
				'Showing ' + data.rows.length + ' of ' + data.total + ' changes in this batch.'
			));
		}

		return wrap;
	}

	function undo(b, btn) {
		if (!confirm('Reverse all ' + b.changes + ' change(s) in this batch?\n\n' + summaryText(b))) { return; }
		btn.disabled = true;
		D.post(D.cfg.urlAssign, { action: 'undo', batch: b.batch }).then(function (r) {
			btn.disabled = false;
			if (!r.ok) {
				D.notify((r.errors || []).join(', ') || r.error || 'Failed', 'error');
				return;
			}
			D.notify('Reversed ' + r.reversed + ' change(s).', 'ok');
			load();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', load);
	} else {
		load();
	}
})();
