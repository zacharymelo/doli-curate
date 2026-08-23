/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Shared helpers for the Doli Curate screens. Plain DOM APIs so the module
 * does not depend on whichever jQuery version the host theme ships.
 */
var DoliCurate = (function () {
	'use strict';

	var cfgEl = document.getElementById('dolicurate-config');
	var CFG = {};
	if (cfgEl) {
		try {
			CFG = JSON.parse(cfgEl.textContent || cfgEl.innerText);
		} catch (e) {
			CFG = {};
		}
	}

	function el(id) { return document.getElementById(id); }

	function make(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = String(text); }
		return n;
	}

	function clear(node) {
		while (node && node.firstChild) { node.removeChild(node.firstChild); }
	}

	function debounce(fn, wait) {
		var t = null;
		return function () {
			var a = arguments, s = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(s, a); }, wait);
		};
	}

	/** GET JSON. */
	function get(url, params) {
		var q = new URLSearchParams(params || {});
		return fetch(url + '?' + q.toString(), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (r) { return r.json(); });
	}

	/** POST JSON, always carrying the CSRF token. */
	function post(url, params) {
		var body = new URLSearchParams(params || {});
		body.set('token', CFG.token);
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString()
		}).then(function (r) {
			return r.json().catch(function () { return { ok: false, error: 'BadResponse' }; });
		});
	}

	/** Transient message strip at the top of the tab content. */
	function notify(message, kind) {
		var host = document.querySelector('.dc-notify');
		if (!host) {
			host = make('div', 'dc-notify');
			var anchor = document.querySelector('.fichecenter, .tabBar') || document.body;
			anchor.insertBefore(host, anchor.firstChild);
		}
		clear(host);
		var box = make('div', 'dc-note dc-note-' + (kind || 'info'), message);
		host.appendChild(box);
		setTimeout(function () {
			if (box.parentNode) { box.parentNode.removeChild(box); }
		}, 6000);
	}

	/** Values of a multi-select, as integers. */
	function multiValues(select) {
		if (!select) { return []; }
		return Array.prototype.slice.call(select.selectedOptions).map(function (o) {
			return parseInt(o.value, 10);
		}).filter(function (v) { return v > 0; });
	}

	return {
		cfg: CFG,
		el: el,
		make: make,
		clear: clear,
		debounce: debounce,
		get: get,
		post: post,
		notify: notify,
		multiValues: multiValues
	};
})();
