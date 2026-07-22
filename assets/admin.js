/* PACCC Member Directory — admin behavior. */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {

		/* ---------- Copy Unique Link buttons ---------- */
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.paccc-md-copy');
			if (!btn) {
				return;
			}
			var link = btn.getAttribute('data-link') || '';
			copyText(link).then(
				function () { flash(btn, 'Copied!'); },
				function () {
					flash(btn, 'Copy failed');
					window.prompt('Copy this link:', link);
				}
			);
		});

		function copyText(text) {
			if (navigator.clipboard && window.isSecureContext) {
				return navigator.clipboard.writeText(text);
			}
			return new Promise(function (resolve, reject) {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild(ta);
				ta.select();
				try {
					if (document.execCommand('copy')) {
						resolve();
					} else {
						reject(new Error('execCommand failed'));
					}
				} catch (err) {
					reject(err);
				} finally {
					document.body.removeChild(ta);
				}
			});
		}

		function flash(btn, msg) {
			var original = btn.textContent;
			btn.textContent = msg;
			btn.disabled = true;
			setTimeout(function () {
				btn.textContent = original;
				btn.disabled = false;
			}, 1600);
		}

		/* ---------- Delete confirmation ---------- */
		document.addEventListener('click', function (e) {
			var link = e.target.closest('.paccc-md-delete');
			if (!link) {
				return;
			}
			// Certification deletes supply their own message (including how
			// many members will lose the certification).
			var msg = link.getAttribute('data-confirm') || 'Delete this member? This cannot be undone.';
			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});

		/* ---------- Add a certification (add/edit form) ---------- */
		var toggle = document.getElementById('paccc-md-add-cert-toggle');
		var row = document.getElementById('paccc-md-add-cert-row');
		var input = document.getElementById('paccc-md-new-cert');
		var addBtn = document.getElementById('paccc-md-add-cert-btn');
		var list = document.getElementById('paccc-md-cert-list');
		var feedback = document.getElementById('paccc-md-cert-feedback');

		if (toggle && row) {
			toggle.addEventListener('click', function (e) {
				e.preventDefault();
				row.hidden = !row.hidden;
				if (!row.hidden && input) {
					input.focus();
				}
			});
		}

		if (addBtn && input && list) {
			addBtn.addEventListener('click', submitCert);
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					submitCert();
				}
			});
		}

		function submitCert() {
			var name = (input.value || '').trim();
			if (!name) {
				note('Enter a certification name.');
				return;
			}
			addBtn.disabled = true;

			var body = new URLSearchParams();
			body.append('action', 'paccc_md_add_cert');
			body.append('nonce', window.PACCC_MD ? PACCC_MD.certNonce : '');
			body.append('cert', name);

			fetch((window.PACCC_MD && PACCC_MD.ajaxUrl) || window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					addBtn.disabled = false;
					if (!res || !res.success) {
						note((res && res.data && res.data.message) || 'Could not add certification.');
						return;
					}
					var certName = res.data.name;
					var existing = findCheckbox(certName);
					if (existing) {
						existing.checked = true;
						note('"' + certName + '" already exists — checked it for you.');
					} else {
						var label = document.createElement('label');
						label.className = 'paccc-md-cert';
						var cb = document.createElement('input');
						cb.type = 'checkbox';
						cb.name = 'certifications[]';
						cb.setAttribute('value', certName);
						cb.checked = true;
						label.appendChild(cb);
						label.appendChild(document.createTextNode(' ' + certName));
						list.appendChild(label);
						note('Added "' + certName + '".');
					}
					input.value = '';
				})
				.catch(function () {
					addBtn.disabled = false;
					note('Request failed. Please try again.');
				});
		}

		function findCheckbox(value) {
			var boxes = list.querySelectorAll('input[type="checkbox"]');
			for (var i = 0; i < boxes.length; i++) {
				if (boxes[i].value === value) {
					return boxes[i];
				}
			}
			return null;
		}

		function note(msg) {
			if (feedback) {
				feedback.textContent = msg;
				setTimeout(function () {
					feedback.textContent = '';
				}, 4000);
			}
		}
		/* ---------- Map style settings ---------- */

		// WP's color picker (Iris) is a jQuery plugin.
		if (window.jQuery && jQuery.fn.wpColorPicker) {
			jQuery('.paccc-md-color').wpColorPicker();
		}

		// Style options must reflect the weights the chosen family actually
		// publishes — offering Bold for a font that ships only 400 would
		// silently render as a synthesized/incorrect weight.
		var fontSelect = document.getElementById('paccc_map_font');
		var weightSelect = document.getElementById('paccc_map_font_weight');

		if (fontSelect && weightSelect) {
			fontSelect.addEventListener('change', function () {
				var fonts = (window.PACCC_MD && PACCC_MD.fonts) || {};
				var labels = (window.PACCC_MD && PACCC_MD.weightLabels) || {};
				var weights = fonts[fontSelect.value] || ['400'];
				var previous = weightSelect.value;

				weightSelect.innerHTML = '';
				weights.forEach(function (w) {
					var opt = document.createElement('option');
					opt.value = w;
					opt.textContent = labels[w] || w;
					weightSelect.appendChild(opt);
				});

				// Keep the current weight if the new family has it, else
				// prefer Regular, else fall back to the lightest available.
				if (weights.indexOf(previous) !== -1) {
					weightSelect.value = previous;
				} else if (weights.indexOf('400') !== -1) {
					weightSelect.value = '400';
				} else {
					weightSelect.value = weights[0];
				}
			});
		}
	});
})();
