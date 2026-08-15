/* PACCC Member Directory — frontend behavior. */
(function () {
	'use strict';

	/* Same paw-print cursor as assets/frontend.css's --paccc-cursor-paw.
	 * Kept in sync manually — jsVectorMap sets this as an inline SVG
	 * "cursor" attribute via JS, so it can't read the CSS custom property. */
	var PACCC_PAW_CURSOR =
		'url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzNiIgaGVpZ2h0PSIzNiIgdmlld0JveD0iMCAwIDM2IDM2Ij48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgyIDIpIj48ZyBmaWxsPSJub25lIiBzdHJva2U9IiNGRUNBMzgiIHN0cm9rZS13aWR0aD0iNCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+PGVsbGlwc2UgY3g9IjE2IiBjeT0iMjMiIHJ4PSI5IiByeT0iNy41Ii8+PGVsbGlwc2UgY3g9IjYiIGN5PSIxNCIgcng9IjMuNiIgcnk9IjQuNiIgdHJhbnNmb3JtPSJyb3RhdGUoLTE1IDYgMTQpIi8+PGVsbGlwc2UgY3g9IjExLjUiIGN5PSI3LjMiIHJ4PSIzLjgiIHJ5PSI0LjgiIHRyYW5zZm9ybT0icm90YXRlKC02IDExLjUgNy4zKSIvPjxlbGxpcHNlIGN4PSIyMC41IiBjeT0iNy4zIiByeD0iMy44IiByeT0iNC44IiB0cmFuc2Zvcm09InJvdGF0ZSg2IDIwLjUgNy4zKSIvPjxlbGxpcHNlIGN4PSIyNiIgY3k9IjE0IiByeD0iMy42IiByeT0iNC42IiB0cmFuc2Zvcm09InJvdGF0ZSgxNSAyNiAxNCkiLz48L2c+PGcgZmlsbD0iIzVDMjQ2MSI+PGVsbGlwc2UgY3g9IjE2IiBjeT0iMjMiIHJ4PSI5IiByeT0iNy41Ii8+PGVsbGlwc2UgY3g9IjYiIGN5PSIxNCIgcng9IjMuNiIgcnk9IjQuNiIgdHJhbnNmb3JtPSJyb3RhdGUoLTE1IDYgMTQpIi8+PGVsbGlwc2UgY3g9IjExLjUiIGN5PSI3LjMiIHJ4PSIzLjgiIHJ5PSI0LjgiIHRyYW5zZm9ybT0icm90YXRlKC02IDExLjUgNy4zKSIvPjxlbGxpcHNlIGN4PSIyMC41IiBjeT0iNy4zIiByeD0iMy44IiByeT0iNC44IiB0cmFuc2Zvcm09InJvdGF0ZSg2IDIwLjUgNy4zKSIvPjxlbGxpcHNlIGN4PSIyNiIgY3k9IjE0IiByeD0iMy42IiByeT0iNC42IiB0cmFuc2Zvcm09InJvdGF0ZSgxNSAyNiAxNCkiLz48L2c+PC9nPjwvc3ZnPg==) 18 18, pointer';

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

		/* Publish the theme's heading font so the state intro (a <p>) can adopt
		 * it -- CSS can't inherit another element's font-family, and every theme
		 * defines its headings differently. Measure a real hidden <h2> and set
		 * the values as custom properties the stylesheet reads. If the theme's
		 * heading font changes later, this picks it up automatically. */
		(function () {
			var wrap = document.querySelector('.paccc-directory-wrap');
			if (!wrap) {
				return;
			}
			var probe = document.createElement('h2');
			probe.setAttribute('aria-hidden', 'true');
			probe.style.cssText = 'position:absolute;visibility:hidden;height:0;margin:0;padding:0;overflow:hidden;';
			wrap.appendChild(probe);
			try {
				var cs = window.getComputedStyle(probe);
				if (cs.fontFamily) {
					wrap.style.setProperty('--paccc-heading-font', cs.fontFamily);
				}
				if (cs.fontWeight) {
					wrap.style.setProperty('--paccc-heading-weight', cs.fontWeight);
				}
			} catch (e) { /* fall back to the stylesheet default */ }
			wrap.removeChild(probe);
		})();

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
					'US-IA': [-8.7, 1.1], 'US-ID': [2, 39.5], 'US-IL': [0.3, -8], 'US-IN': [1.2, 2.5],
					'US-KS': [-1, 0.5], 'US-KY': [21.2, -3.8], 'US-LA': [-22.9, -17.3], 'US-ME': [-3.7, -7.6],
					'US-MI': [30.7, 39.5], 'US-MN': [-13.9, -14], 'US-MO': [-1.4, 5.8], 'US-MS': [-0.2, -15.2],
					'US-MT': [7, -0.8], 'US-NC': [6, 0.5], 'US-ND': [-2, 0.5], 'US-NE': [-5, 0.5],
					'US-NM': [1.8, -3], 'US-NV': [-0.2, -26.8], 'US-NY': [8.1, -1.8], 'US-OH': [-2.5, 6.2],
					'US-OK': [26, -1.4], 'US-OR': [11.3, 7.7], 'US-PA': [-8.4, 1.3], 'US-SC': [9.2, -5.2],
					'US-SD': [0, -4.4], 'US-TN': [-13.1, 3.1], 'US-TX': [31.5, 2.5], 'US-UT': [1.1, 10.9],
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
							/* Warm taupe borders rather than pure black -- hard black
							 * state outlines were the map's biggest "corporate" tell. */
							initial: { fill: '#ffffff', stroke: '#BFAE9C', strokeWidth: 0.8 },
							hover: { fillOpacity: 0.85, cursor: PACCC_PAW_CURSOR },
							selected: { cursor: PACCC_PAW_CURSOR },
							selectedHover: { cursor: PACCC_PAW_CURSOR }
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
							/* jsVectorMap's own defaults are initial: {cursor:'default'}
							 * and hover: {cursor:'pointer'} -- both must be overridden
							 * here, or hovering directly over the state-name text swaps
							 * away from the paw to the browser's default pointer. */
							initial: {
								fontFamily: data.fontFamily ? '"' + data.fontFamily + '", inherit' : 'inherit',
								fontSize: '9px',
								fontWeight: data.fontWeight || '500',
								fill: '#4A3550',
								cursor: PACCC_PAW_CURSOR
							},
							hover: {
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

		var alphaButtons = Array.prototype.slice.call(document.querySelectorAll('.paccc-alpha'));
		var stateHeadingEl = document.querySelector('.paccc-state-heading');
		var stateIntroEl = document.querySelector('.paccc-state-intro');
		var viewStateBtn = document.querySelector('.paccc-view-state');
		var liveStateSpans = Array.prototype.slice.call(document.querySelectorAll('.paccc-live-state'));

		// Per-state URLs (e.g. /paccc-certified-members/texas/). slugs maps code->slug;
		// codeBySlug is the reverse, for reading a state back out of the URL.
		var slugs = data.slugs || {};
		var basePath = data.basePath || '';
		var codeBySlug = {};
		Object.keys(slugs).forEach(function (c) { codeBySlug[slugs[c]] = c; });
		var canRoute = !!(basePath && window.history && window.history.pushState);

		// Seed from the URL the page was served at, so the initial render is
		// already filtered without a flash or a second pass.
		var currentState = data.initialState || '';
		var currentLetter = '';
		var currentPage = 1;
		var currentCountry = '';
		var currentCountryName = '';
		var currentRegion = '';
		var currentRegionLabel = '';
		var countrySelect = document.getElementById('paccc-country-filter');
		var countryChips = Array.prototype.slice.call(document.querySelectorAll('.paccc-country-chip'));
		var countryMaps = data.countryMaps || {};
		var countryMapEl = document.getElementById('paccc-country-map');
		var loadedMapFiles = {};
		var countryMapInstance = null;
		var countryMapShownFor = '';

		function stateUrl(code) {
			if (!basePath) {
				return '';
			}
			var slug = code ? slugs[code] : '';
			return slug ? basePath + slug + '/' : basePath;
		}

		function pushStateUrl(code) {
			if (!canRoute) {
				return;
			}
			var url = stateUrl(code);
			if (url) {
				window.history.pushState({ pacccState: code || '' }, '', url);
			}
		}

		function updateStateHeading(code) {
			if (!stateHeadingEl) {
				return;
			}
			// Generic heading on the unfiltered directory; state-specific once
			// a state is active. Always visible (never hidden) now.
			stateHeadingEl.textContent = code ? stateTitleText(code) : 'PACCC Members by State';
			stateHeadingEl.hidden = false;
		}

		// Text builders, kept in sync with the PHP equivalents so client-side
		// updates read identically to the server render.
		function stateTitleText(code) {
			return 'PACCC Certified Members in ' + (names[code] || code);
		}

		function stateIntroText(code) {
			var n = counts[code] || 0;
			var name = names[code] || code;
			if (n < 1) {
				return 'There are no PACCC-certified members in ' + name + ' yet.';
			}
			return (n === 1 ? 'There is 1 PACCC-certified member in ' : 'There are ' + n + ' PACCC-certified members in ') + name + '.';
		}

		// Total across every state, for the unfiltered directory. rows is the
		// full set of member articles in the DOM.
		function allMembersIntroText() {
			var total = rows.length;
			if (total < 1) {
				return 'There are no PACCC-certified members yet.';
			}
			return total === 1 ? 'There is 1 PACCC-certified member.' : 'There are ' + total + ' PACCC-certified members.';
		}

		// State intro sentence (the directory's own element), mirrored so it
		// stays in step as the visitor filters.
		function updateStateIntro(code) {
			if (!stateIntroEl) {
				return;
			}
			stateIntroEl.textContent = code ? stateIntroText(code) : allMembersIntroText();
			stateIntroEl.hidden = false;
		}

		// Custom [paccc_current_state*] spans placed by a page builder: keep
		// them in step with client-side filtering too. Each carries data-kind
		// (name|title|intro) and a data-default for the no-state case.
		function updateLiveStateSpans(code) {
			if (!liveStateSpans.length) {
				return;
			}
			liveStateSpans.forEach(function (el) {
				var kind = el.getAttribute('data-kind') || 'name';
				if (!code) {
					el.textContent = el.getAttribute('data-default') || '';
				} else if (kind === 'title') {
					el.textContent = stateTitleText(code);
				} else if (kind === 'intro') {
					el.textContent = stateIntroText(code);
				} else {
					el.textContent = names[code] || code;
				}
			});
		}

		// "View State Page" links to the selected state's own URL; hidden when
		// no state (or no routing) is active.
		function updateViewStateBtn(code) {
			if (!viewStateBtn) {
				return;
			}
			var url = code ? stateUrl(code) : '';
			if (url && code) {
				viewStateBtn.setAttribute('href', url);
				viewStateBtn.hidden = false;
			} else {
				viewStateBtn.hidden = true;
			}
		}

		// A row shows only if it matches BOTH the active state and the active
		// letter. Empty currentState / currentLetter mean "no restriction".
		function matchingRows() {
			return rows.filter(function (r) {
				if (currentCountry) {
					if (r.getAttribute('data-country') !== currentCountry) {
						return false;
					}
					if (currentRegion && r.getAttribute('data-region') !== currentRegion) {
						return false;
					}
				} else if (currentState && r.getAttribute('data-state') !== currentState) {
					return false;
				}
				if (currentLetter && r.getAttribute('data-letter') !== currentLetter) {
					return false;
				}
				return true;
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
			var where = '';
			if (currentCountry) {
				where = ' in ' + (currentRegion ? currentRegionLabel + ', ' + currentCountryName : currentCountryName);
			} else if (currentState) {
				where = ' in ' + (names[currentState] || currentState);
			}
			var startingWith = currentLetter ? ' starting with ' + currentLetter : '';
			var qualifier = where + startingWith;
			if (!total) {
				statusEl.textContent = 'No members' + qualifier + ' just yet.';
				return;
			}
			statusEl.textContent = 'Showing ' + (start + 1) + '\u2013' + end +
				' of ' + total + ' member' + (total === 1 ? '' : 's') + qualifier + '.';
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

		// pushUrl controls whether the address bar is updated: true for direct
		// user actions (dropdown, map), false when we're reflecting a URL that's
		// already current (initial load, browser back/forward).
		function setState(st, scroll, pushUrl) {
			currentState = st || '';
			currentCountry = '';
			currentCountryName = '';
			currentRegion = '';
			currentRegionLabel = '';
			if (countrySelect) {
				countrySelect.value = '';
			}
			countryChips.forEach(function (c) {
				c.classList.remove('paccc-country-chip-current');
				c.setAttribute('aria-pressed', 'false');
			});
			updateCountryMap();
			currentPage = 1;
			if (select) {
				select.value = currentState;
			}
			updateStateHeading(currentState);
			updateStateIntro(currentState);
			updateLiveStateSpans(currentState);
			updateViewStateBtn(currentState);
			if (pushUrl) {
				pushStateUrl(currentState);
			}
			render();
			if (scroll && listEl) {
				listEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}

		// Called by the map when a highlighted state is clicked.
		function filterByState(st) {
			setState(st, true, true);
		}

		if (select) {
			select.addEventListener('change', function () {
				setState(select.value, false, true);
			});
		}

		// Back/forward buttons: re-apply the state from the URL being restored,
		// without pushing a new history entry.
		if (canRoute) {
			window.addEventListener('popstate', function (e) {
				var code = (e.state && e.state.pacccState) || codeFromUrl();
				setState(code, false, false);
			});
		}

		function codeFromUrl() {
			var path = window.location.pathname;
			if (basePath && path.indexOf(basePath) === 0) {
				var rest = path.slice(basePath.length).replace(/\/+$/, '');
				if (rest && codeBySlug[rest]) {
					return codeBySlug[rest];
				}
			}
			return '';
		}

		// A-Z name filter. Highlights the active letter and re-renders; combines
		// with the state filter (both must match).
		function setLetter(letter) {
			currentLetter = letter || '';
			currentPage = 1;
			alphaButtons.forEach(function (b) {
				var active = (b.getAttribute('data-letter') || '') === currentLetter;
				b.classList.toggle('paccc-alpha-current', active);
				b.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
			render();
		}

		alphaButtons.forEach(function (b) {
			b.addEventListener('click', function () {
				setLetter(b.getAttribute('data-letter') || '');
			});
		});

		// "Browse outside the U.S." dropdown. Value is "" (none), a country code
		// ("CA"), or "CC|Region". Mutually exclusive with the state filter/map.
		function countryIntroText(n, name) {
			if (n < 1) {
				return 'There are no PACCC-certified members in ' + name + ' yet.';
			}
			return (n === 1 ? 'There is 1 PACCC-certified member in ' : 'There are ' + n + ' PACCC-certified members in ') + name + '.';
		}

		// Lazy-load a jsVectorMap map data file once, then run cb.
		function loadScriptOnce(url, cb) {
			if (loadedMapFiles[url] === true) { cb(); return; }
			if (loadedMapFiles[url]) { loadedMapFiles[url].push(cb); return; }
			loadedMapFiles[url] = [cb];
			var sc = document.createElement('script');
			sc.src = url;
			sc.onload = function () { var cbs = loadedMapFiles[url]; loadedMapFiles[url] = true; cbs.forEach(function (f) { f(); }); };
			sc.onerror = function () { var cbs = loadedMapFiles[url] || []; loadedMapFiles[url] = false; cbs.forEach(function (f) { f(); }); };
			document.head.appendChild(sc);
		}

		// Show/hide + lazily build the province map for the selected country.
		// Countries without a map (e.g. city-states) just hide the box.
		function updateCountryMap() {
			if (!countryMapEl) { return; }
			var cfg = currentCountry ? countryMaps[currentCountry] : null;
			if (!cfg) {
				countryMapEl.hidden = true;
				if (countryMapInstance) { try { countryMapInstance.destroy(); } catch (e) {} countryMapInstance = null; }
				countryMapShownFor = '';
				return;
			}
			countryMapEl.hidden = false;
			if (countryMapShownFor === currentCountry && countryMapInstance) { return; }
			var ccode = currentCountry;
			loadScriptOnce(cfg.file, function () {
				if (ccode !== currentCountry) { return; }
				if (!window.jsVectorMap) { countryMapEl.hidden = true; return; }
				if (countryMapInstance) { try { countryMapInstance.destroy(); } catch (e) {} countryMapInstance = null; }
				countryMapEl.innerHTML = '';
				var values = {};
				Object.keys(cfg.regions).forEach(function (code) { values[code] = 'member'; });
				try {
					countryMapInstance = new jsVectorMap({
						selector: '#paccc-country-map',
						map: cfg.map,
						backgroundColor: 'transparent',
						zoomButtons: false,
						zoomOnScroll: false,
						draggable: false,
						regionStyle: {
							initial: { fill: '#ffffff', stroke: '#BFAE9C', strokeWidth: 0.8 },
							hover: { fillOpacity: 0.85, cursor: PACCC_PAW_CURSOR }
						},
						series: { regions: [{ attribute: 'fill', scale: { member: highlight }, values: values }] },
						onRegionTooltipShow: function (event, tooltip, code) {
							var r = cfg.regions[code];
							try { tooltip.text(tooltip.text() + (r ? ' \u2014 ' + r.count + ' member' + (r.count === 1 ? '' : 's') : ' \u2014 no members')); } catch (e) {}
						},
						onRegionClick: function (event, code) {
							var r = cfg.regions[code];
							if (r) { setCountry(ccode + '|' + r.name); }
						}
					});
					countryMapShownFor = ccode;
				} catch (e) { countryMapEl.hidden = true; }
			});
		}

		function setCountry(value) {
			var parts = (value || '').split('|');
			currentCountry = parts[0] || '';
			currentRegion = parts[1] || '';
			currentRegionLabel = currentRegion;
			currentState = '';
			if (select) {
				select.value = '';
			}
			if (countrySelect) {
				countrySelect.value = value || '';
			}
			currentPage = 1;

			// Country name comes from the selected option's optgroup label.
			currentCountryName = currentCountry;
			if (countrySelect && countrySelect.selectedOptions && countrySelect.selectedOptions.length) {
				var grp = countrySelect.selectedOptions[0].parentNode;
				if (grp && grp.label) {
					currentCountryName = grp.label;
				}
			}

			countryChips.forEach(function (c) {
				var active = currentCountry && c.getAttribute('data-country') === currentCountry;
				c.classList.toggle('paccc-country-chip-current', active);
				c.setAttribute('aria-pressed', active ? 'true' : 'false');
			});

			updateCountryMap();

			if (currentCountry) {
				var label = currentRegion ? currentRegionLabel + ', ' + currentCountryName : currentCountryName;
				if (stateHeadingEl) {
					stateHeadingEl.textContent = 'PACCC Certified Members in ' + label;
					stateHeadingEl.hidden = false;
				}
				if (stateIntroEl) {
					var n = rows.filter(function (r) {
						if (r.getAttribute('data-country') !== currentCountry) {
							return false;
						}
						return !currentRegion || r.getAttribute('data-region') === currentRegion;
					}).length;
					stateIntroEl.textContent = countryIntroText(n, label);
					stateIntroEl.hidden = false;
				}
				updateViewStateBtn('');
				updateLiveStateSpans('');
			} else {
				updateStateHeading('');
				updateStateIntro('');
				updateLiveStateSpans('');
				updateViewStateBtn('');
			}

			render();
			if (listEl) {
				listEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}

		if (countrySelect) {
			countrySelect.addEventListener('change', function () {
				setCountry(countrySelect.value);
			});
		}

		// Country chips under the map: quick country-level filter, kept in sync
		// with the dropdown. Clicking the active country's chip clears it.
		countryChips.forEach(function (chip) {
			chip.addEventListener('click', function () {
				var code = chip.getAttribute('data-country') || '';
				setCountry( ( currentCountry === code && ! currentRegion ) ? '' : code );
			});
		});

		// Initial paint reflects the state the page was served at (from the URL),
		// so sync the control + heading but don't push a new history entry.
		if (select) {
			select.value = currentState;
		}
		updateStateHeading(currentState);
		updateStateIntro(currentState);
		updateLiveStateSpans(currentState);
		updateViewStateBtn(currentState);
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
