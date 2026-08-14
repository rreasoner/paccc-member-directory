/* PACCC Member Directory — admin behavior. */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {

		/* ---------- Copy buttons (unique links + shortcodes) ---------- */
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.paccc-md-copy');
			if (!btn) {
				return;
			}
			var text = btn.getAttribute('data-clipboard') || btn.getAttribute('data-link') || '';
			copyText(text).then(
				function () { flash(btn, 'Copied!'); },
				function () {
					flash(btn, 'Copy failed');
					window.prompt('Copy:', text);
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

		/* ---------- "View Shortcodes" toggle (member list screen) ----------
		 * The panel is rendered (hidden) by PHP only on the member list screen,
		 * so the toggle is added next to "Add New Member" only when it exists. */
		var scPanel = document.getElementById('paccc-md-shortcodes-panel');
		var titleAction = document.querySelector('.wrap .page-title-action');
		if (scPanel && titleAction) {
			var scToggle = document.createElement('a');
			scToggle.href = '#';
			scToggle.className = 'page-title-action';
			scToggle.id = 'paccc-md-shortcodes-toggle';
			scToggle.textContent = 'View Shortcodes';
			titleAction.parentNode.insertBefore(scToggle, titleAction.nextSibling);

			scToggle.addEventListener('click', function (e) {
				e.preventDefault();
				scPanel.hidden = !scPanel.hidden;
				scToggle.textContent = scPanel.hidden ? 'View Shortcodes' : 'Hide Shortcodes';
				scToggle.setAttribute('aria-expanded', scPanel.hidden ? 'false' : 'true');
				if (!scPanel.hidden) {
					scPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				}
			});
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

		/* ---------- Certifications: add / delete rows (client-side; saved with the member) ---------- */
		(function () {
			var certList = document.getElementById('paccc-md-cert-list');
			var certToggle = document.getElementById('paccc-md-add-cert-toggle');
			var certAddRow = document.getElementById('paccc-md-add-cert-row');
			var certInput = document.getElementById('paccc-md-new-cert');
			var certAddBtn = document.getElementById('paccc-md-add-cert-btn');
			var certFeedback = document.getElementById('paccc-md-cert-feedback');

			function certNote(msg) {
				if (certFeedback) {
					certFeedback.textContent = msg;
					setTimeout(function () { certFeedback.textContent = ''; }, 4000);
				}
			}

			if (certToggle && certAddRow) {
				certToggle.addEventListener('click', function (e) {
					e.preventDefault();
					certAddRow.hidden = !certAddRow.hidden;
					if (!certAddRow.hidden && certInput) { certInput.focus(); }
				});
			}

			function existingCertRow(code) {
				if (!certList) { return null; }
				var rows = certList.querySelectorAll('.paccc-md-cert-row');
				for (var i = 0; i < rows.length; i++) {
					if ((rows[i].getAttribute('data-cert') || '').toLowerCase() === code.toLowerCase()) { return rows[i]; }
				}
				return null;
			}

			function addCert() {
				if (!certList || !certInput) { return; }
				var code = (certInput.value || '').replace(/,/g, '').trim();
				if (!code) { certNote('Enter a certification name.'); return; }
				var dupe = existingCertRow(code);
				if (dupe) {
					var box = dupe.querySelector('input[type="checkbox"]');
					if (box) { box.checked = true; }
					certNote('"' + code + '" already exists \u2014 checked it for you.');
					certInput.value = '';
					return;
				}
				var row = document.createElement('div');
				row.className = 'paccc-md-cert-row';
				row.setAttribute('data-cert', code);

				var lbl = document.createElement('label');
				lbl.className = 'paccc-md-cert';
				var cb = document.createElement('input');
				cb.type = 'checkbox'; cb.name = 'paccc_certifications[]'; cb.value = code; cb.checked = true;
				var strong = document.createElement('strong'); strong.textContent = code;
				lbl.appendChild(cb); lbl.appendChild(document.createTextNode(' ')); lbl.appendChild(strong);

				var label = document.createElement('input');
				label.type = 'text'; label.className = 'paccc-md-cert-label regular-text';
				label.name = 'paccc_cert_labels[' + code + ']';
				label.placeholder = 'Full title, e.g. Certified Professional Animal Care Provider';

				var hidden = document.createElement('input');
				hidden.type = 'hidden'; hidden.name = 'paccc_cert_codes[]'; hidden.value = code;

				var del = document.createElement('button');
				del.type = 'button'; del.className = 'paccc-md-row-delete paccc-md-cert-delete';
				del.setAttribute('aria-label', 'Delete this certification'); del.innerHTML = '&times;';

				row.appendChild(lbl); row.appendChild(label); row.appendChild(hidden); row.appendChild(del);
				certList.appendChild(row);
				certNote('Added "' + code + '". It saves when you update the member.');
				certInput.value = '';
			}

			if (certAddBtn) {
				certAddBtn.addEventListener('click', addCert);
				if (certInput) {
					certInput.addEventListener('keydown', function (e) {
						if (e.key === 'Enter') { e.preventDefault(); addCert(); }
					});
				}
			}
		})();

		/* ---------- CEUs: add / edit / delete rows (client-side; saved with the member) ---------- */
		(function () {
			var ceuList = document.getElementById('paccc-md-ceu-list');
			var ceuToggle = document.getElementById('paccc-md-add-ceu-toggle');
			var ceuAddRow = document.getElementById('paccc-md-add-ceu-row');
			var ceuInput = document.getElementById('paccc-md-new-ceu');
			var ceuAddBtn = document.getElementById('paccc-md-add-ceu-btn');

			if (ceuToggle && ceuAddRow) {
				ceuToggle.addEventListener('click', function (e) {
					e.preventDefault();
					ceuAddRow.hidden = !ceuAddRow.hidden;
					if (!ceuAddRow.hidden && ceuInput) { ceuInput.focus(); }
				});
			}

			function addCeu() {
				if (!ceuList || !ceuInput) { return; }
				var text = (ceuInput.value || '').trim();
				if (!text) { return; }
				var row = document.createElement('div');
				row.className = 'paccc-md-ceu-row';

				var lbl = document.createElement('label');
				lbl.className = 'paccc-md-ceu';
				var cb = document.createElement('input');
				cb.type = 'checkbox'; cb.name = 'paccc_member_ceus[]'; cb.value = text; cb.checked = true;
				lbl.appendChild(cb);

				var inp = document.createElement('input');
				inp.type = 'text'; inp.className = 'paccc-md-ceu-input regular-text';
				inp.name = 'paccc_ceus[]'; inp.value = text;

				var del = document.createElement('button');
				del.type = 'button'; del.className = 'paccc-md-row-delete paccc-md-ceu-delete';
				del.setAttribute('aria-label', 'Delete this CEU'); del.innerHTML = '&times;';

				row.appendChild(lbl); row.appendChild(inp); row.appendChild(del);
				ceuList.appendChild(row);
				ceuInput.value = '';
			}

			if (ceuAddBtn) {
				ceuAddBtn.addEventListener('click', addCeu);
				if (ceuInput) {
					ceuInput.addEventListener('keydown', function (e) {
						if (e.key === 'Enter') { e.preventDefault(); addCeu(); }
					});
				}
			}

			// Keep each CEU checkbox value synced to its text input so renaming a
			// CEU keeps the member's selection pointing at the right value on save.
			if (ceuList) {
				ceuList.addEventListener('input', function (e) {
					if (!e.target.classList || !e.target.classList.contains('paccc-md-ceu-input')) { return; }
					var r = e.target.closest('.paccc-md-ceu-row');
					var box = r && r.querySelector('input[type="checkbox"]');
					if (box) { box.value = e.target.value; }
				});
			}
		})();

		/* ---------- Row delete (x on certification and CEU rows) ---------- */
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.paccc-md-row-delete');
			if (!btn) { return; }
			var row = btn.closest('.paccc-md-cert-row, .paccc-md-ceu-row');
			if (!row) { return; }
			var isCert = row.classList.contains('paccc-md-cert-row');
			var name = isCert
				? (row.getAttribute('data-cert') || 'this certification')
				: (((row.querySelector('.paccc-md-ceu-input') || {}).value) || 'this CEU');
			if (!window.confirm('Remove "' + name + '"? It will be unassigned from every member when you save.')) { return; }
			if (row.parentNode) { row.parentNode.removeChild(row); }
		});

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
