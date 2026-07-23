/* PACCC Member Directory — single member page: heading font + lazy map embed. */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	/* The detail labels should adopt the theme's heading typography, but CSS
	 * can't inherit another element's styles and every theme sets its headings
	 * differently. So drop a hidden <h2> inside the card, read what the theme
	 * actually gave it, and publish those values as custom properties the
	 * stylesheet consumes.
	 *
	 * Font SIZE is deliberately not copied -- these are small inline labels,
	 * not section headings, so an h2's size would dwarf the values beside it.
	 * Keeping them as <dt> (rather than real <h3>s) keeps field labels out of
	 * the document outline, which matters on a page built to be indexed. */
	var HEADING_PROPS = {
		'--paccc-heading-font': 'fontFamily',
		'--paccc-heading-weight': 'fontWeight',
		'--paccc-heading-style': 'fontStyle',
		'--paccc-heading-spacing': 'letterSpacing',
		'--paccc-heading-transform': 'textTransform'
	};

	function applyHeadingFont(host) {
		var probe = document.createElement('h2');
		probe.setAttribute('aria-hidden', 'true');
		probe.style.cssText = 'position:absolute;visibility:hidden;height:0;margin:0;padding:0;overflow:hidden;';
		host.appendChild(probe);

		var computed = null;
		try {
			computed = window.getComputedStyle(probe);
		} catch (e) { /* fall back to the stylesheet defaults */ }

		if (computed) {
			Object.keys(HEADING_PROPS).forEach(function (prop) {
				var value = computed[HEADING_PROPS[prop]];
				if (value) {
					host.style.setProperty(prop, value);
				}
			});
		}

		host.removeChild(probe);
	}

	/* Google map iframe is injected on load rather than server-rendered, so a
	 * member page with no address ships no third-party embed at all. */
	function loadMap(host) {
		var box = host.querySelector('.paccc-map-embed');
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

	ready(function () {
		var host = document.querySelector('.paccc-member-single');
		if (!host) {
			return;
		}
		applyHeadingFont(host);
		loadMap(host);
	});
})();
