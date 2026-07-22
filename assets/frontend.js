/* PACCC Member Directory — frontend behavior. */
(function () {
	'use strict';

	/* Same paw-print cursor as assets/frontend.css's --paccc-cursor-paw.
	 * Kept in sync manually — jsVectorMap sets this as an inline SVG
	 * "cursor" attribute via JS, so it can't read the CSS custom property. */
	var PACCC_PAW_CURSOR =
		'url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzMiIgaGVpZ2h0PSIzMiIgdmlld0JveD0iMCAwIDMyIDMyIj48ZWxsaXBzZSBjeD0iMTYiIGN5PSIyMyIgcng9IjkiIHJ5PSI3LjUiIGZpbGw9IiMwMDAiLz48ZWxsaXBzZSBjeD0iNiIgY3k9IjE0IiByeD0iMy42IiByeT0iNC42IiB0cmFuc2Zvcm09InJvdGF0ZSgtMTUgNiAxNCkiIGZpbGw9IiMwMDAiLz48ZWxsaXBzZSBjeD0iMTIuNSIgY3k9IjcuMyIgcng9IjMuOCIgcnk9IjQuOCIgdHJhbnNmb3JtPSJyb3RhdGUoLTYgMTIuNSA3LjMpIiBmaWxsPSIjMDAwIi8+PGVsbGlwc2UgY3g9IjE5LjUiIGN5PSI3LjMiIHJ4PSIzLjgiIHJ5PSI0LjgiIHRyYW5zZm9ybT0icm90YXRlKDYgMTkuNSA3LjMpIiBmaWxsPSIjMDAwIi8+PGVsbGlwc2UgY3g9IjI2IiBjeT0iMTQiIHJ4PSIzLjYiIHJ5PSI0LjYiIHRyYW5zZm9ybT0icm90YXRlKDE1IDI2IDE0KSIgZmlsbD0iIzAwMCIvPjwvc3ZnPgo=) 16 16, pointer';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		var data = window.PACCC_DIR || {};
		var counts = data.counts || {};
		var names = data.names || {};
		var highlight = data.highlight || '#ffe399';

		/* ---------- US map ----------
		 * The map is hidden by CSS at <=1000px. Building it while the
		 * container is display:none gives getBBox() zeros and mangles every
		 * label, so initialize only once the map is actually visible, and
		 * again if the visitor resizes up past the breakpoint.
		 */
		var MAP_MIN_WIDTH = 1000;
		var mapEl = document.getElementById('paccc-map');
		var mapReady = false;

		function mapVisible() {
			return !!mapEl && mapEl.offsetParent !== null && mapEl.clientWidth > 0;
		}

		function stateName(code) {
			var st = code.split('-')[1];
			return names[st] || st;
		}

		function sizeLabels() {
			if (!mapEl) {
				return;
			}
			var w = mapEl.clientWidth || 960;
			var size = w >= 860 ? 9 : (w >= 680 ? 8 : (w >= 540 ? 7 : 6));
			mapEl.querySelectorAll('svg text').forEach(function (t) {
				t.setAttribute('font-size', size + 'px');
			});
		}

		function initMap() {
			if (mapReady || !mapEl || !window.jsVectorMap) {
				return;
			}
			if (window.innerWidth <= MAP_MIN_WIDTH || !mapVisible()) {
				return;
			}
			mapReady = true;

			// The map data file registers itself as 'us_aea_en' via
			// jsVectorMap.addMap(). There is no public registry to inspect
			// (jsVectorMap.maps is internal), so reference the name directly.
			var mapName = 'us_aea_en';

			if (mapName) {
				// Every state with at least one member is colored #ffe399
				// (or whichever color is set in Directory Settings).
				var values = {};
				Object.keys(counts).forEach(function (st) {
					values['US-' + st] = 'member';
				});

				/*
				 * Label offsets, in map units, relative to each state's
				 * BOUNDING-BOX CENTER (that's where jsVectorMap anchors a
				 * label). For states with panhandles or islands the bbox
				 * center can fall outside the state's visual mass — which is
				 * why labels drifted onto borders.
				 *
				 * These values were computed from the map's own path data:
				 * each is the "pole of inaccessibility" (the point furthest
				 * from any edge) of the state's largest polygon, minus the
				 * bbox center. Adjust any pair by hand if you want a nudge.
				 */
				var labelOffsets = {
					// Small Northeast states + Hawaii: names can't fit inside
					// the shape at any size, so these are placed offshore.
					'US-NH': [8, -30], 'US-VT': [-18, -24], 'US-MA': [46, -4],
					'US-RI': [48, 9], 'US-CT': [54, 21], 'US-NJ': [32, 6],
					'US-DE': [37, 2], 'US-MD': [58, 16], 'US-DC': [68, 29],
					'US-HI': [0, 12],

					// Computed centers.
					'US-AK': [53, -29.1], 'US-AL': [-1.9, 4.9], 'US-AR': [-5.2, -3.5], 'US-AZ': [4.4, 8.3],
					'US-CA': [-3.1, 33.4], 'US-CO': [4.2, 1.1], 'US-FL': [47.2, 5.8], 'US-GA': [6.5, 12.8],
					'US-IA': [-8.7, 1.1], 'US-ID': [-10.4, 39.5], 'US-IL': [0.3, -8], 'US-IN': [1.2, 2.5],
					'US-KS': [6.2, 0.5], 'US-KY': [21.2, -3.8], 'US-LA': [-22.9, -17.3], 'US-ME': [-3.7, -7.6],
					'US-MI': [30.7, 39.5], 'US-MN': [-13.9, -14], 'US-MO': [-1.4, 5.8], 'US-MS': [-0.2, -15.2],
					'US-MT': [-24, -0.8], 'US-NC': [23.6, 0.5], 'US-ND': [15.9, 0.5], 'US-NE': [8.7, 0.5],
					'US-NM': [1.8, -3], 'US-NV': [-0.2, -26.8], 'US-NY': [8.1, -1.8], 'US-OH': [-2.5, 6.2],
					'US-OK': [39.8, -1.4], 'US-OR': [11.3, 7.7], 'US-PA': [-8.4, 1.3], 'US-SC': [9.2, -5.2],
					'US-SD': [-20.7, -4.4], 'US-TN': [-13.1, 3.1], 'US-TX': [31.5, 2.5], 'US-UT': [1.1, 10.9],
					'US-VA': [15.8, 1.5], 'US-WA': [7.9, 0.9], 'US-WI': [1, -3.2], 'US-WV': [-13.2, 15.2],
					'US-WY': [5.4, 1.1]
				};

				try {
					new jsVectorMap({
						selector: '#paccc-map',
						map: mapName,
						backgroundColor: 'transparent',
						zoomButtons: false,
						zoomOnScroll: false,
						draggable: false,
						regionStyle: {
							initial: { fill: '#ffffff', stroke: '#000000', strokeWidth: 0.6 },
							hover: { fillOpacity: 0.85, cursor: PACCC_PAW_CURSOR }
						},
						series: {
							regions: [{
								attribute: 'fill',
								scale: { member: highlight },
								values: values
							}]
						},
						labels: {
							regions: {
								render: stateName,
								offsets: function (code) {
									return labelOffsets[code] || [0, 0];
								}
							}
						},
						regionLabelStyle: {
							initial: {
								fontFamily: data.fontFamily ? '"' + data.fontFamily + '", inherit' : 'inherit',
								fontSize: '9px',
								fontWeight: data.fontWeight || '500',
								fill: '#000000',
								cursor: PACCC_PAW_CURSOR
							}
						},
						onRegionTooltipShow: function (event, tooltip, code) {
							try {
								var st = code.split('-')[1];
								var n = counts[st] || 0;
								tooltip.text(
									tooltip.text() + (n ? ' — ' + n + ' member' + (n === 1 ? '' : 's') : ' — no members')
								);
							} catch (e) { /* keep default tooltip */ }
						},
						onRegionClick: function (event, code) {
							var st = code.split('-')[1];
							if (counts[st]) {
								filterByState(st);
							}
						}
					});

					// Labels don't shrink with the map on their own — size them
					// to the rendered width so full names stay proportionate.
					sizeLabels();
				} catch (e) {
					// Don't fail silently — a hidden container made an earlier
					// bug invisible. Log loudly; the directory below still works.
					mapReady = false;
					mapEl.style.display = 'none';
					if (window.console) {
						console.error('PACCC Member Directory: map failed to initialize.', e);
					}
				}
			} else {
				mapEl.style.display = 'none';
			}
		}

		if (mapEl && !window.jsVectorMap) {
			mapEl.style.display = 'none';
			if (window.console) {
				console.error('PACCC Member Directory: jsVectorMap library did not load.');
			}
		}

		// Build now if we're on a wide screen; otherwise wait until a resize
		// crosses the breakpoint. Also re-size labels on every resize.
		initMap();
		var mapResizeTimer;
		window.addEventListener('resize', function () {
			clearTimeout(mapResizeTimer);
			mapResizeTimer = setTimeout(function () {
				initMap();
				if (mapReady) {
					sizeLabels();
				}
			}, 150);
		});

		/* ---------- State filter + pagination ----------
		 * One render() owns row visibility. A row shows only if it matches the
		 * current state AND falls inside the current page slice. The dropdown
		 * is the single source of truth; map clicks just set it.
		 */
		var PER_PAGE = parseInt(data.perPage, 10) || 20;
		var rows = Array.prototype.slice.call(document.querySelectorAll('.paccc-member'));
		var select = document.getElementById('paccc-state-filter');
		var statusEl = document.querySelector('.paccc-status');
		var pager = document.querySelector('.paccc-pagination');
		var listEl = document.getElementById('paccc-members');

		var currentState = '';
		var currentPage = 1;

		function matchingRows() {
			if (!currentState) {
				return rows;
			}
			return rows.filter(function (r) {
				return r.getAttribute('data-state') === currentState;
			});
		}

		function render() {
			var list = matchingRows();
			var total = list.length;
			var pages = Math.max(1, Math.ceil(total / PER_PAGE));

			if (currentPage > pages) {
				currentPage = pages;
			}
			if (currentPage < 1) {
				currentPage = 1;
			}

			var start = (currentPage - 1) * PER_PAGE;
			var end = Math.min(start + PER_PAGE, total);

			rows.forEach(function (r) {
				r.hidden = true;
			});
			list.slice(start, end).forEach(function (r) {
				r.hidden = false;
			});

			renderStatus(total, start, end);
			renderPager(pages);
		}

		function renderStatus(total, start, end) {
			if (!statusEl) {
				return;
			}
			var where = currentState ? ' in ' + (names[currentState] || currentState) : '';
			if (!total) {
				statusEl.textContent = 'No members found' + where + '.';
				return;
			}
			statusEl.textContent = 'Showing ' + (start + 1) + '\u2013' + end +
				' of ' + total + ' member' + (total === 1 ? '' : 's') + where + '.';
		}

		function renderPager(pages) {
			if (!pager) {
				return;
			}
			pager.innerHTML = '';
			if (pages < 2) {
				pager.hidden = true;
				return;
			}
			pager.hidden = false;

			addPageBtn('\u2039 Prev', currentPage - 1, currentPage === 1);

			// Windowed numbers: first, last, and a couple either side of current.
			var shown = [];
			for (var i = 1; i <= pages; i++) {
				if (i === 1 || i === pages || Math.abs(i - currentPage) <= 1) {
					shown.push(i);
				}
			}
			var prev = 0;
			shown.forEach(function (n) {
				if (prev && n - prev > 1) {
					var gap = document.createElement('span');
					gap.className = 'paccc-page-gap';
					gap.textContent = '\u2026';
					pager.appendChild(gap);
				}
				addPageBtn(String(n), n, false, n === currentPage);
				prev = n;
			});

			addPageBtn('Next \u203a', currentPage + 1, currentPage === pages);
		}

		function addPageBtn(label, page, disabled, isCurrent) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'paccc-page' + (isCurrent ? ' paccc-page-current' : '');
			b.textContent = label;
			if (disabled) {
				b.disabled = true;
			} else {
				b.addEventListener('click', function () {
					goToPage(page);
				});
			}
			if (isCurrent) {
				b.setAttribute('aria-current', 'page');
			}
			pager.appendChild(b);
		}

		function goToPage(page) {
			currentPage = page;
			render();
			if (listEl) {
				listEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}

		function setState(st, scroll) {
			currentState = st || '';
			currentPage = 1;
			if (select) {
				select.value = currentState;
			}
			render();
			if (scroll && listEl) {
				listEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}

		// Called by the map when a highlighted state is clicked.
		function filterByState(st) {
			setState(st, true);
		}

		if (select) {
			select.addEventListener('change', function () {
				setState(select.value, false);
			});
		}

		render();

		/* ---------- "View Member" accordions ---------- */
		document.querySelectorAll('.paccc-view-toggle').forEach(function (btn) {
			btn.addEventListener('click', function () {
				togglePanel(btn);
			});
		});

		function togglePanel(btn, forceOpen) {
			var panel = document.getElementById(btn.getAttribute('aria-controls'));
			if (!panel) {
				return;
			}
			var isOpen = btn.getAttribute('aria-expanded') === 'true';
			if (forceOpen && isOpen) {
				return;
			}
			var next = forceOpen ? true : !isOpen;
			btn.setAttribute('aria-expanded', next ? 'true' : 'false');
			panel.hidden = !next;
			var row = btn.closest('.paccc-member');
			if (row) {
				row.classList.toggle('paccc-open', next);
			}
			if (next) {
				loadEmbed(panel);
			}
		}

		/* Google map iframes are injected only when a panel first opens. */
		function loadEmbed(panel) {
			var box = panel.querySelector('.paccc-map-embed');
			if (!box || box.dataset.loaded) {
				return;
			}
			var address = box.getAttribute('data-address');
			if (!address) {
				return;
			}
			var iframe = document.createElement('iframe');
			iframe.src = 'https://www.google.com/maps?q=' + encodeURIComponent(address) + '&output=embed';
			iframe.loading = 'lazy';
			iframe.title = 'Map of ' + address;
			iframe.referrerPolicy = 'no-referrer-when-downgrade';
			box.appendChild(iframe);
			box.dataset.loaded = '1';
		}

		/* ---------- Deep links: ?paccc_member=1234567 or #member-1234567 ---------- */
		var target = '';
		try {
			target = new URLSearchParams(window.location.search).get('paccc_member') || '';
		} catch (e) { /* older browsers */ }
		if (!target && window.location.hash.indexOf('#member-') === 0) {
			target = window.location.hash.substring(8);
		}
		if (target) {
			var row = document.getElementById('member-' + target);
			if (row) {
				// A unique link must reach its member regardless of filter or
				// page, so clear the filter and jump to that member's page.
				currentState = '';
				if (select) {
					select.value = '';
				}
				var idx = rows.indexOf(row);
				currentPage = idx > -1 ? Math.floor(idx / PER_PAGE) + 1 : 1;
				render();

				var btn = row.querySelector('.paccc-view-toggle');
				if (btn) {
					togglePanel(btn, true);
				}
				row.classList.add('paccc-linked');
				setTimeout(function () {
					row.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}, 150);
				setTimeout(function () {
					row.classList.remove('paccc-linked');
				}, 3500);
			}
		}
	});
})();
