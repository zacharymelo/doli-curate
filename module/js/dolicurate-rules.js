/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Rules screen: edit rule sets and preview exactly what a run would change
 * before anything is written.
 */
(function () {
	'use strict';

	var D = DoliCurate;
	if (!D || !D.cfg || D.cfg.screen !== 'rules') { return; }

	var state = { sets: [], current: null, matchTypes: {} };

	var MATCH_LABELS = {
		1: 'Reference starts with',
		2: 'Reference ends with',
		3: 'Reference matches regex',
		4: 'Label contains',
		5: 'Product type is (0=product, 1=service)',
		6: 'Has supplier (thirdparty id)',
		7: 'Every product',
		8: 'Reference is exactly'
	};

	/** Match type 7 ("every product") takes no value. */
	function needsValue(t) { return parseInt(t, 10) !== 7; }

	function loadSets(selectId) {
		D.get(D.cfg.urlRules, { action: 'list' }).then(function (data) {
			if (!data.ok) { D.notify(data.error || 'Error', 'error'); return; }
			state.sets = data.rulesets;
			state.matchTypes = data.matchtypes || {};
			renderSets();
			if (selectId) { openSet(selectId); }
			else if (!state.current && state.sets.length) { openSet(state.sets[0].id); }
			else if (!state.sets.length) { renderEmptyDetail(); }
		});
	}

	function renderSets() {
		var host = D.el('dc-ruleset-list');
		D.clear(host);

		if (!state.sets.length) {
			host.appendChild(D.make('div', 'dc-empty', 'No rule sets yet.'));
			return;
		}

		state.sets.forEach(function (s) {
			var item = D.make('button', 'dc-setitem' + (state.current && state.current.id === s.id ? ' active' : ''));
			item.type = 'button';
			item.appendChild(D.make('span', 'dc-setname', s.label));
			item.appendChild(D.make('span', 'dc-setmeta', s.rulecount + ' rule(s)' + (s.only_untagged ? ' - untagged only' : '')));
			item.addEventListener('click', function () { openSet(s.id); });
			host.appendChild(item);
		});
	}

	function renderEmptyDetail() {
		var host = D.el('dc-ruleset-detail');
		D.clear(host);
		host.appendChild(D.make('div', 'dc-empty', 'Create a rule set to tag products automatically.'));
	}

	function openSet(id) {
		D.get(D.cfg.urlRules, { action: 'get', id: id }).then(function (data) {
			if (!data.ok) { D.notify(data.error || 'Error', 'error'); return; }
			state.current = data.ruleset;
			renderSets();
			renderDetail();
		});
	}

	function categorySelect() {
		var tpl = D.el('dc-cat-template');
		var sel = document.createElement('select');
		sel.className = 'dc-input';
		sel.innerHTML = tpl ? tpl.innerHTML : '';
		return sel;
	}

	function renderDetail() {
		var set = state.current;
		var host = D.el('dc-ruleset-detail');
		D.clear(host);
		if (!set) { renderEmptyDetail(); return; }

		var head = D.make('div', 'dc-detail-head');
		head.appendChild(D.make('h3', '', set.label));

		var del = D.make('button', 'dc-linkbtn dc-danger', 'Delete rule set');
		del.type = 'button';
		del.addEventListener('click', function () {
			if (!confirm('Delete this rule set and all its rules? Applied changes are kept.')) { return; }
			D.post(D.cfg.urlRules, { action: 'deleteset', id: set.id }).then(function (r) {
				if (!r.ok) { D.notify(r.error || 'Failed', 'error'); return; }
				state.current = null;
				loadSets();
			});
		});
		head.appendChild(del);
		host.appendChild(head);

		// Existing rules.
		var table = D.make('table', 'dc-table');
		var thead = D.make('thead');
		var hr = D.make('tr');
		['Match', 'Value', 'Assign to', ''].forEach(function (t) { hr.appendChild(D.make('th', '', t)); });
		thead.appendChild(hr);
		table.appendChild(thead);

		var tbody = D.make('tbody');
		if (!set.rules.length) {
			var tr = D.make('tr');
			var td = D.make('td', 'dc-empty', 'No rules yet. Add one below.');
			td.colSpan = 4;
			tr.appendChild(td);
			tbody.appendChild(tr);
		}
		set.rules.forEach(function (r) {
			var tr = D.make('tr', 'dc-row');
			tr.appendChild(D.make('td', '', MATCH_LABELS[r.match_type] || r.match_type));
			tr.appendChild(D.make('td', 'dc-mono', needsValue(r.match_type) ? r.match_value : '-'));
			tr.appendChild(D.make('td', '', r.category_label || ('#' + r.category)));

			var tdAct = D.make('td', 'right');
			var rm = D.make('button', 'dc-linkbtn dc-danger', 'Remove');
			rm.type = 'button';
			rm.addEventListener('click', function () {
				D.post(D.cfg.urlRules, { action: 'deleterule', id: r.id }).then(function (res) {
					if (!res.ok) { D.notify(res.error || 'Failed', 'error'); return; }
					openSet(set.id);
					loadSets(set.id);
				});
			});
			tdAct.appendChild(rm);
			tr.appendChild(tdAct);
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		host.appendChild(table);

		// Add-rule row.
		var adder = D.make('div', 'dc-addrule');

		var typeSel = document.createElement('select');
		typeSel.className = 'dc-input';
		Object.keys(MATCH_LABELS).forEach(function (k) {
			var o = document.createElement('option');
			o.value = k;
			o.textContent = MATCH_LABELS[k];
			typeSel.appendChild(o);
		});

		var valInput = document.createElement('input');
		valInput.type = 'text';
		valInput.className = 'dc-input';
		valInput.placeholder = 'Value';

		typeSel.addEventListener('change', function () {
			valInput.disabled = !needsValue(typeSel.value);
			valInput.value = valInput.disabled ? '' : valInput.value;
		});

		var catSel = categorySelect();

		var addBtn = D.make('button', 'button', 'Add rule');
		addBtn.type = 'button';
		addBtn.addEventListener('click', function () {
			D.post(D.cfg.urlRules, {
				action: 'addrule',
				ruleset: set.id,
				match_type: typeSel.value,
				match_value: valInput.value,
				category: catSel.value
			}).then(function (res) {
				if (!res.ok) { D.notify(res.error || 'Failed', 'error'); return; }
				valInput.value = '';
				openSet(set.id);
				loadSets(set.id);
			});
		});

		adder.appendChild(typeSel);
		adder.appendChild(valInput);
		adder.appendChild(catSel);
		adder.appendChild(addBtn);
		host.appendChild(adder);

		// Preview / apply.
		var actions = D.make('div', 'dc-detail-actions');

		var prev = D.make('button', 'button', 'Preview');
		prev.type = 'button';
		prev.addEventListener('click', function () { preview(set.id); });

		var apply = D.make('button', 'button', 'Apply rule set');
		apply.type = 'button';
		apply.addEventListener('click', function () { applySet(set.id); });

		actions.appendChild(prev);
		actions.appendChild(apply);
		host.appendChild(actions);

		host.appendChild(D.make('div', 'dc-preview', ''));
	}

	function preview(id) {
		var host = document.querySelector('.dc-preview');
		D.clear(host);
		host.appendChild(D.make('div', 'dc-empty', 'Previewing...'));

		D.get(D.cfg.urlRules, { action: 'preview', id: id }).then(function (data) {
			D.clear(host);
			if (!data.ok) { D.notify(data.error || 'Error', 'error'); return; }

			var summary = D.make('div', 'dc-preview-head');
			if (data.total_changes === 0) {
				summary.appendChild(D.make('span', '', 'This rule set would change nothing - everything it matches is already tagged.'));
				host.appendChild(summary);
				return;
			}
			summary.appendChild(D.make('strong', '', data.total_changes + ' change(s)'));
			summary.appendChild(document.createTextNode(' across ' + data.products + ' product(s)'));
			host.appendChild(summary);

			data.rules.forEach(function (r) {
				var block = D.make('div', 'dc-preview-rule');
				var h = D.make('div', 'dc-preview-rulehead');
				h.appendChild(D.make('span', '', (MATCH_LABELS[r.rule.match_type] || '') + ' "' + r.rule.match_value + '" -> ' + (r.rule.category_label || '')));
				h.appendChild(D.make('span', 'dc-count', r.count + ' match(es)'));
				block.appendChild(h);

				if (r.matches.length) {
					var ul = D.make('div', 'dc-preview-list');
					r.matches.slice(0, 25).forEach(function (m) {
						ul.appendChild(D.make('span', 'dc-chip', m.ref));
					});
					if (r.matches.length > 25) {
						ul.appendChild(D.make('span', 'dc-chip dc-more', '+' + (r.matches.length - 25) + ' more'));
					}
					block.appendChild(ul);
				}
				host.appendChild(block);
			});
		});
	}

	function applySet(id) {
		if (!confirm('Apply this rule set now? Changes are recorded and can be undone from History.')) { return; }

		D.post(D.cfg.urlRules, { action: 'apply', id: id }).then(function (data) {
			if (!data.ok) {
				D.notify(data.error || (data.errors || []).join(', ') || 'Failed', 'error');
				return;
			}
			D.notify('Applied ' + data.applied + (data.skipped ? ', ' + data.skipped + ' already set' : ''), 'ok');
			preview(id);
			loadSets(id);
		});
	}

	function init() {
		D.el('dc-newset-go').addEventListener('click', function () {
			var label = D.el('dc-newset-label').value.trim();
			if (!label) { D.notify('A name is required.', 'error'); return; }

			D.post(D.cfg.urlRules, {
				action: 'createset',
				label: label,
				description: '',
				only_untagged: D.el('dc-newset-untagged').checked ? 1 : 0
			}).then(function (data) {
				if (!data.ok) { D.notify(data.error || 'Failed', 'error'); return; }
				D.el('dc-newset-label').value = '';
				loadSets(data.id);
			});
		});

		loadSets();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
