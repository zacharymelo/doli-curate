/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * History screen: every recorded batch, with undo.
 */
(function () {
	'use strict';

	var D = DoliCurate;
	if (!D || !D.cfg || D.cfg.screen !== 'history') { return; }

	var SOURCES = { 1: 'Assign screen', 2: 'Rule set', 3: 'Category merge', 4: 'Undo' };

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
			host.appendChild(table(data.batches));
		});
	}

	function fmtDate(ts) {
		if (!ts) { return ''; }
		var d = new Date(ts * 1000);
		return d.toLocaleString();
	}

	function table(batches) {
		var t = D.make('table', 'dc-table');
		var thead = D.make('thead');
		var hr = D.make('tr');
		['When', 'By', 'Source', 'Added', 'Removed', ''].forEach(function (h) {
			hr.appendChild(D.make('th', '', h));
		});
		thead.appendChild(hr);
		t.appendChild(thead);

		var tbody = D.make('tbody');
		batches.forEach(function (b) {
			var tr = D.make('tr', 'dc-row' + (b.undone ? ' dc-undone' : ''));
			tr.appendChild(D.make('td', '', fmtDate(b.started)));
			tr.appendChild(D.make('td', '', b.user || ''));
			tr.appendChild(D.make('td', '', SOURCES[b.source] || b.source));
			tr.appendChild(D.make('td', 'right', b.adds));
			tr.appendChild(D.make('td', 'right', b.removes));

			var td = D.make('td', 'right');
			if (b.undone) {
				td.appendChild(D.make('span', 'dc-badge', 'undone'));
			} else if (D.cfg.can.undo) {
				var btn = D.make('button', 'dc-linkbtn', 'Undo');
				btn.type = 'button';
				btn.addEventListener('click', function () {
					if (!confirm('Reverse all ' + b.changes + ' change(s) in this batch?')) { return; }
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
				});
				td.appendChild(btn);
			}
			tr.appendChild(td);
			tbody.appendChild(tr);
		});
		t.appendChild(tbody);

		return t;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', load);
	} else {
		load();
	}
})();
