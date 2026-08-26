(function () {
	'use strict';

	var DATA = window.__ATLAS__;
	var WIKI = 'https://wiki.p2pfoundation.net/';

	/* ---- decode ---------------------------------------------------- */

	function u16(b64) {
		var bin = atob(b64);
		var bytes = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
		return new Uint16Array(bytes.buffer);
	}
	function u8(b64) {
		var bin = atob(b64);
		var bytes = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
		return bytes;
	}

	var N = DATA.n;
	var TITLES = DATA.titles.split('\n');
	var LOWER = TITLES.map(function (t) { return t.replace(/_/g, ' ').toLowerCase(); });
	var X = u16(DATA.x);
	var Y = u16(DATA.y);
	var CLUSTER = u8(DATA.cluster);
	var SIZE = u16(DATA.size);
	var CATNAMES = DATA.catNames;
	var CATLOWER = CATNAMES.map(function (c) { return c.toLowerCase(); });
	var CATS = DATA.cats.split('\n').map(function (row) {
		return row ? row.split(',').map(Number) : [];
	});
	var HULLS = DATA.hulls || [];
	var LABELS = DATA.clusterLabels;
	var SIZES = DATA.clusterSizes;
	var K = LABELS.length;
	var SPAN = 4096;

	/* ---- spatial grid for hit-testing ------------------------------ */

	var GRID = 96;
	var CELL = SPAN / GRID;
	var buckets = new Array(GRID * GRID);
	for (var i = 0; i < N; i++) {
		var gx = Math.min(GRID - 1, (X[i] / CELL) | 0);
		var gy = Math.min(GRID - 1, (Y[i] / CELL) | 0);
		var key = gy * GRID + gx;
		(buckets[key] || (buckets[key] = [])).push(i);
	}

	/* ---- region centroids ------------------------------------------ */

	var cx = new Float64Array(K), cy = new Float64Array(K), cn = new Float64Array(K);
	for (i = 0; i < N; i++) {
		var k = CLUSTER[i];
		cx[k] += X[i]; cy[k] += Y[i]; cn[k]++;
	}
	for (k = 0; k < K; k++) { if (cn[k]) { cx[k] /= cn[k]; cy[k] /= cn[k]; } }

	var order = [];
	for (k = 0; k < K; k++) { order.push(k); }
	order.sort(function (a, b) { return SIZES[b] - SIZES[a]; });

	/* ---- theme-aware colours --------------------------------------- */

	var css = getComputedStyle(document.documentElement);
	var C = {};
	function readTokens() {
		css = getComputedStyle(document.documentElement);
		C.dot = css.getPropertyValue('--dot').trim();
		C.ink = css.getPropertyValue('--ink').trim();
		C.ink2 = css.getPropertyValue('--ink-2').trim();
		C.ink3 = css.getPropertyValue('--ink-3').trim();
		C.ground = css.getPropertyValue('--ground').trim();
		C.surface = css.getPropertyValue('--surface').trim();
		C.series = [
			css.getPropertyValue('--s1').trim(),
			css.getPropertyValue('--s2').trim(),
			css.getPropertyValue('--s3').trim()
		];
	}
	readTokens();
	if (window.matchMedia) {
		var mq = window.matchMedia('(prefers-color-scheme: dark)');
		if (mq.addEventListener) { mq.addEventListener('change', function () { readTokens(); draw(); }); }
	}
	new MutationObserver(function () { readTokens(); draw(); })
		.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

	/* ---- view ------------------------------------------------------- */

	var cv = document.getElementById('cv');
	var ctx = cv.getContext('2d');
	var mapEl = document.getElementById('map');
	var W = 0, H = 0, dpr = 1;
	var view = { scale: 1, ox: 0, oy: 0 };

	function fit() {
		var s = Math.min(W, H) / SPAN * 0.94;
		view.scale = s;
		view.ox = (W - SPAN * s) / 2;
		view.oy = (H - SPAN * s) / 2;
	}

	function resize() {
		var r = mapEl.getBoundingClientRect();
		dpr = Math.min(window.devicePixelRatio || 1, 2);
		W = Math.max(1, Math.round(r.width));
		H = Math.max(1, Math.round(r.height));
		cv.width = W * dpr;
		cv.height = H * dpr;
		ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
	}

	function sx(x) { return x * view.scale + view.ox; }
	function sy(y) { return y * view.scale + view.oy; }

	/* ---- search ------------------------------------------------------ */

	var terms = [];          // [{text, colour, hits:Int32Array-ish}]
	var match = new Uint8Array(N);   // 0 = none, else term index + 1

	function runSearch(raw) {
		terms = raw.split(',').map(function (t) { return t.trim(); })
			.filter(function (t) { return t.length > 1; }).slice(0, 3);
		match.fill(0);
		var counts = terms.map(function () { return 0; });
		if (terms.length) {
			var low = terms.map(function (t) { return t.toLowerCase(); });
			// a category matches a term -> every page in it matches
			var catHit = CATLOWER.map(function (c) {
				for (var t = 0; t < low.length; t++) { if (c.indexOf(low[t]) !== -1) { return t + 1; } }
				return 0;
			});
			for (var i = 0; i < N; i++) {
				var hit = 0;
				for (var t = 0; t < low.length; t++) {
					if (LOWER[i].indexOf(low[t]) !== -1) { hit = t + 1; break; }
				}
				if (!hit) {
					var cs = CATS[i];
					for (var j = 0; j < cs.length; j++) {
						if (catHit[cs[j]]) { hit = catHit[cs[j]]; break; }
					}
				}
				match[i] = hit;
				if (hit) { counts[hit - 1]++; }
			}
		}
		renderLegend(counts);
		renderMatches();
		draw();
	}

	function renderLegend(counts) {
		var el = document.getElementById('legend');
		el.innerHTML = '';
		el.hidden = terms.length < 1;
		terms.forEach(function (t, i) {
			var li = document.createElement('li');
			var sw = document.createElement('span');
			sw.className = 'swatch';
			sw.style.background = C.series[i];
			var name = document.createElement('span');
			name.textContent = t;
			var n = document.createElement('span');
			n.className = 'count';
			n.textContent = counts[i].toLocaleString();
			li.appendChild(sw); li.appendChild(name); li.appendChild(n);
			el.appendChild(li);
		});
	}

	function regionName(k) { return LABELS[k][0] || ('Region ' + (k + 1)); }

	function renderMatches() {
		var list = document.getElementById('matches');
		var title = document.getElementById('matches-title');
		list.innerHTML = '';
		if (!terms.length) {
			title.textContent = 'Matches';
			var li = document.createElement('li');
			li.style.padding = '0.45rem 0.25rem';
			li.style.color = 'var(--ink-3)';
			li.style.fontSize = 'var(--step--1)';
			li.textContent = 'Type a term above to light up the articles that carry it.';
			list.appendChild(li);
			return;
		}
		var rows = [];
		for (var i = 0; i < N && rows.length < 400; i++) {
			if (match[i]) { rows.push(i); }
		}
		rows.sort(function (a, b) { return SIZE[b] - SIZE[a]; });
		title.textContent = 'Matches — longest first, first ' + Math.min(rows.length, 60) + ' shown';
		rows.slice(0, 60).forEach(function (i) {
			var li = document.createElement('li');
			var a = document.createElement('a');
			a.href = WIKI + encodeURIComponent(TITLES[i]);
			a.target = '_blank';
			a.rel = 'noopener';
			var tick = document.createElement('span');
			tick.className = 'tick';
			tick.style.background = C.series[match[i] - 1];
			var name = document.createElement('span');
			name.textContent = TITLES[i].replace(/_/g, ' ');
			var where = document.createElement('span');
			where.className = 'where';
			where.textContent = regionName(CLUSTER[i]) + ' · ' + Math.round(SIZE[i] * 64 / 1024) + ' KB';
			a.appendChild(tick); a.appendChild(name); a.appendChild(where);
			li.appendChild(a);
			list.appendChild(li);
		});
	}

	/* ---- draw -------------------------------------------------------- */

	var hover = -1;
	var active = -1;
	var raf = 0;

	function schedule() {
		if (!raf) { raf = requestAnimationFrame(function () { raf = 0; draw(); }); }
	}

	function draw() {
		ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		ctx.clearRect(0, 0, W, H);

		var dim = terms.length > 0 || active >= 0;
		var base = 'rgba(' + C.dot + ',' + (dim ? 0.22 : 0.55) + ')';
		var r = Math.max(1.4, Math.min(3.4, view.scale * SPAN / 850));

		drawTerritories();

		ctx.fillStyle = base;
		for (var i = 0; i < N; i++) {
			if (match[i] || (active >= 0 && CLUSTER[i] === active)) { continue; }
			var px = sx(X[i]), py = sy(Y[i]);
			if (px < -4 || py < -4 || px > W + 4 || py > H + 4) { continue; }
			ctx.fillRect(px, py, r, r);
		}

		if (active >= 0) {
			ctx.fillStyle = 'rgba(' + C.dot + ',0.85)';
			for (i = 0; i < N; i++) {
				if (CLUSTER[i] !== active || match[i]) { continue; }
				px = sx(X[i]); py = sy(Y[i]);
				if (px < -4 || py < -4 || px > W + 4 || py > H + 4) { continue; }
				ctx.fillRect(px, py, r + 0.6, r + 0.6);
			}
		}

		if (dim) {
			for (var t = 0; t < terms.length; t++) {
				ctx.fillStyle = C.series[t];
				ctx.strokeStyle = C.ground;
				ctx.lineWidth = 2;
				for (i = 0; i < N; i++) {
					if (match[i] !== t + 1) { continue; }
					px = sx(X[i]); py = sy(Y[i]);
					if (px < -6 || py < -6 || px > W + 6 || py > H + 6) { continue; }
					ctx.beginPath();
					ctx.arc(px, py, Math.max(2.2, r * 1.4), 0, 6.2832);
					ctx.stroke();
					ctx.fill();
				}
			}
		}

		drawLabels();

		if (hover >= 0) {
			px = sx(X[hover]); py = sy(Y[hover]);
			ctx.strokeStyle = C.ink;
			ctx.lineWidth = 1.5;
			ctx.beginPath();
			ctx.arc(px, py, 7, 0, 6.2832);
			ctx.stroke();
		}
	}

	// Only the region the reader has picked gets an outline. Drawing all 26
	// convex hulls at once just stacks translucent grey over the whole map.
	function drawTerritories() {
		if (active < 0) { return; }
		var h = HULLS[active];
		if (!h || h.length < 3) { return; }
		ctx.beginPath();
		ctx.moveTo(sx(h[0][0]), sy(h[0][1]));
		for (var v = 1; v < h.length; v++) { ctx.lineTo(sx(h[v][0]), sy(h[v][1])); }
		ctx.closePath();
		ctx.fillStyle = 'rgba(' + C.dot + ',0.05)';
		ctx.fill();
		ctx.lineWidth = 1.5;
		ctx.strokeStyle = 'rgba(' + C.dot + ',0.35)';
		ctx.stroke();
	}

	function drawLabels() {
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.lineJoin = 'round';
		var taken = [];
		for (var n = 0; n < K; n++) {
			var k = order[n];
			var px = sx(cx[k]), py = sy(cy[k]);
			if (px < -80 || py < -20 || px > W + 80 || py > H + 20) { continue; }
			var label = regionName(k).toUpperCase();
			ctx.font = '600 11px "Public Sans", sans-serif';
			var half = ctx.measureText(label).width / 2 + 6;
			var box = [px - half, py - 9, px + half, py + 9];
			var clash = false;
			for (var b = 0; b < taken.length; b++) {
				var o = taken[b];
				if (box[0] < o[2] && box[2] > o[0] && box[1] < o[3] && box[3] > o[1]) { clash = true; break; }
			}
			if (clash) { continue; }
			taken.push(box);
			ctx.font = (k === active ? '600 13px' : '600 11px') + ' "Public Sans", sans-serif';
			ctx.lineWidth = 3.5;
			ctx.strokeStyle = C.ground;
			ctx.strokeText(label, px, py);
			ctx.fillStyle = k === active ? C.ink : C.ink;
			ctx.fillText(label, px, py);

			if (view.scale * SPAN > 2400) {
				var sub = LABELS[k].slice(1, 3).join(' · ');
				ctx.font = '400 9.5px "Public Sans", sans-serif';
				ctx.lineWidth = 3;
				ctx.strokeStyle = C.ground;
				ctx.strokeText(sub, px, py + 12);
				ctx.fillStyle = C.ink3;
				ctx.fillText(sub, px, py + 12);
			}
		}
	}

	/* ---- interaction -------------------------------------------------- */

	function pick(mx, my) {
		var wx = (mx - view.ox) / view.scale;
		var wy = (my - view.oy) / view.scale;
		var rad = 10 / view.scale;
		var g0x = Math.max(0, ((wx - rad) / CELL) | 0), g1x = Math.min(GRID - 1, ((wx + rad) / CELL) | 0);
		var g0y = Math.max(0, ((wy - rad) / CELL) | 0), g1y = Math.min(GRID - 1, ((wy + rad) / CELL) | 0);
		var best = -1, bestD = rad * rad;
		for (var gy = g0y; gy <= g1y; gy++) {
			for (var gx = g0x; gx <= g1x; gx++) {
				var b = buckets[gy * GRID + gx];
				if (!b) { continue; }
				for (var i = 0; i < b.length; i++) {
					var idx = b[i];
					var dx = X[idx] - wx, dy = Y[idx] - wy;
					var d = dx * dx + dy * dy;
					if (d < bestD) { bestD = d; best = idx; }
				}
			}
		}
		return best;
	}

	var tip = document.getElementById('tip');

	function showTip(i, mx, my) {
		tip.innerHTML = '';
		var s = document.createElement('strong');
		s.textContent = TITLES[i].replace(/_/g, ' ');
		var e = document.createElement('em');
		var cats = CATS[i].map(function (j) { return CATNAMES[j]; }).slice(0, 4).join(' · ');
		e.textContent = regionName(CLUSTER[i]) + (cats ? ' — ' + cats : '');
		tip.appendChild(s);
		tip.appendChild(e);
		tip.classList.add('on');
		var w = tip.offsetWidth, h = tip.offsetHeight;
		tip.style.left = Math.min(Math.max(8, mx + 14), W - w - 8) + 'px';
		tip.style.top = Math.min(Math.max(8, my - h - 12), H - h - 8) + 'px';
	}

	var dragging = false, lastX = 0, lastY = 0, moved = 0;

	cv.addEventListener('pointerdown', function (e) {
		dragging = true; moved = 0;
		lastX = e.clientX; lastY = e.clientY;
		cv.classList.add('dragging');
		cv.setPointerCapture(e.pointerId);
	});

	cv.addEventListener('pointermove', function (e) {
		var r = cv.getBoundingClientRect();
		var mx = e.clientX - r.left, my = e.clientY - r.top;
		if (dragging) {
			view.ox += e.clientX - lastX;
			view.oy += e.clientY - lastY;
			moved += Math.abs(e.clientX - lastX) + Math.abs(e.clientY - lastY);
			lastX = e.clientX; lastY = e.clientY;
			tip.classList.remove('on');
			schedule();
			return;
		}
		var i = pick(mx, my);
		if (i !== hover) {
			hover = i;
			if (i >= 0) { showTip(i, mx, my); } else { tip.classList.remove('on'); }
			schedule();
		} else if (i >= 0) {
			showTip(i, mx, my);
		}
	});

	function endDrag(e) {
		if (!dragging) { return; }
		dragging = false;
		cv.classList.remove('dragging');
		if (e && cv.hasPointerCapture && cv.hasPointerCapture(e.pointerId)) {
			cv.releasePointerCapture(e.pointerId);
		}
	}
	cv.addEventListener('pointerup', function (e) {
		var wasDrag = moved > 4;
		endDrag(e);
		if (!wasDrag) {
			var r = cv.getBoundingClientRect();
			var i = pick(e.clientX - r.left, e.clientY - r.top);
			if (i >= 0) { window.open(WIKI + encodeURIComponent(TITLES[i]), '_blank', 'noopener'); }
		}
	});
	cv.addEventListener('pointercancel', endDrag);
	cv.addEventListener('pointerleave', function () { tip.classList.remove('on'); hover = -1; schedule(); });

	cv.addEventListener('wheel', function (e) {
		e.preventDefault();
		var r = cv.getBoundingClientRect();
		zoomAt(e.clientX - r.left, e.clientY - r.top, Math.pow(0.9985, e.deltaY));
	}, { passive: false });

	function zoomAt(mx, my, factor) {
		var next = Math.max(0.02, Math.min(24, view.scale * factor));
		var wx = (mx - view.ox) / view.scale;
		var wy = (my - view.oy) / view.scale;
		view.scale = next;
		view.ox = mx - wx * next;
		view.oy = my - wy * next;
		schedule();
	}

	document.getElementById('zin').onclick = function () { zoomAt(W / 2, H / 2, 1.5); };
	document.getElementById('zout').onclick = function () { zoomAt(W / 2, H / 2, 1 / 1.5); };
	document.getElementById('zreset').onclick = function () { fit(); draw(); };

	/* ---- rail -------------------------------------------------------- */

	function flyTo(k) {
		// Frame the same trimmed hull the outline draws, so the outlined shape
		// lands centred; the raw point bbox includes outliers the hull drops.
		var h = HULLS[k];
		var minx = 4096, maxx = 0, miny = 4096, maxy = 0;
		if (h && h.length > 2) {
			h.forEach(function (pt) {
				if (pt[0] < minx) { minx = pt[0]; }
				if (pt[0] > maxx) { maxx = pt[0]; }
				if (pt[1] < miny) { miny = pt[1]; }
				if (pt[1] > maxy) { maxy = pt[1]; }
			});
		} else {
			var any = false;
			for (var i = 0; i < N; i++) {
				if (CLUSTER[i] !== k) { continue; }
				any = true;
				if (X[i] < minx) { minx = X[i]; }
				if (X[i] > maxx) { maxx = X[i]; }
				if (Y[i] < miny) { miny = Y[i]; }
				if (Y[i] > maxy) { maxy = Y[i]; }
			}
			if (!any) { return; }
		}
		var pad = 60;
		var s = Math.min(W / (maxx - minx + pad), H / (maxy - miny + pad));
		view.scale = Math.max(0.02, Math.min(24, s));
		view.ox = W / 2 - (minx + maxx) / 2 * view.scale;
		view.oy = H / 2 - (miny + maxy) / 2 * view.scale;
		draw();
	}

	var regions = document.getElementById('regions');
	order.forEach(function (k) {
		var li = document.createElement('li');
		var b = document.createElement('button');
		b.type = 'button';
		var wrap = document.createElement('span');
		var nm = document.createElement('span');
		nm.className = 'name';
		nm.textContent = regionName(k);
		var sub = document.createElement('span');
		sub.className = 'sub';
		sub.textContent = LABELS[k].slice(1).join(' · ');
		wrap.appendChild(nm); wrap.appendChild(sub);
		var cnt = document.createElement('span');
		cnt.className = 'count';
		cnt.textContent = SIZES[k].toLocaleString();
		b.appendChild(wrap); b.appendChild(cnt);
		b.onclick = function () {
			var already = li.getAttribute('aria-current') === 'true';
			Array.prototype.forEach.call(regions.children, function (c) { c.removeAttribute('aria-current'); });
			if (already) { active = -1; draw(); return; }
			li.setAttribute('aria-current', 'true');
			active = k;
			flyTo(k);
		};
		li.appendChild(b);
		regions.appendChild(li);
	});

	var q = document.getElementById('q');
	var debounce;
	q.addEventListener('input', function () {
		clearTimeout(debounce);
		debounce = setTimeout(function () { runSearch(q.value); }, 140);
	});

	/* ---- go ---------------------------------------------------------- */

	document.getElementById('fig-articles').textContent = N.toLocaleString();
	document.getElementById('fig-cats').textContent = CATNAMES.length.toLocaleString();
	document.getElementById('fig-regions').textContent = String(K);

	window.addEventListener('resize', function () { resize(); draw(); });
	resize();
	fit();
	renderMatches();
	draw();
}());
