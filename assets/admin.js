/* PACCC Member Directory — admin behavior. */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {

		/* ---------- Member photo management from the list table ---------- */
		(function () {
			if (!window.wp || !window.wp.media) {
				return;
			}
			var frame;
			var activeCell;

			function nonce() { return (window.PACCC_MD && PACCC_MD.imageNonce) || ''; }
			function ajaxUrl() { return (window.PACCC_MD && PACCC_MD.ajaxUrl) || window.ajaxurl; }

			function setPreview(cell, url) {
				var preview = cell.querySelector('.paccc-md-list-photo-preview');
				var setBtn = cell.querySelector('.paccc-md-list-photo-set');
				var delBtn = cell.querySelector('.paccc-md-list-photo-remove');
				if (preview) { preview.textContent = ''; }
				if (url && preview) {
					var img = document.createElement('img');
					img.src = url;
					img.alt = '';
					preview.appendChild(img);
				}
				if (setBtn) { setBtn.textContent = url ? 'Replace' : 'Set'; }
				if (delBtn) { delBtn.style.display = url ? '' : 'none'; }
			}

			function post(action, params, done) {
				var body = new URLSearchParams();
				body.append('action', action);
				body.append('nonce', nonce());
				Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
				fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(done)
					.catch(function () {});
			}

			document.addEventListener('click', function (e) {
				var setBtn = e.target.closest('.paccc-md-list-photo-set');
				var delBtn = e.target.closest('.paccc-md-list-photo-remove');
				if (setBtn) {
					e.preventDefault();
					activeCell = setBtn.closest('.paccc-md-list-photo');
					if (!frame) {
						frame = window.wp.media({
							title: 'Select or upload an image',
							button: { text: 'Use this image' },
							library: { type: 'image' },
							multiple: false
						});
						frame.on('select', function () {
							var att = frame.state().get('selection').first().toJSON();
							if (!activeCell) { return; }
							var cell = activeCell;
							post('paccc_md_set_image', { post_id: cell.getAttribute('data-post'), attachment_id: att.id }, function (res) {
								if (res && res.success && res.data && res.data.url) { setPreview(cell, res.data.url); }
							});
						});
					}
					frame.open();
				} else if (delBtn) {
					e.preventDefault();
					var cell = delBtn.closest('.paccc-md-list-photo');
					if (cell && window.confirm('Remove this member’s photo?')) {
						post('paccc_md_delete_image', { post_id: cell.getAttribute('data-post') }, function (res) {
							if (res && res.success) { setPreview(cell, ''); }
						});
					}
				}
			});
		})();

		/* ---------- Member image / logo (media library picker) ---------- */
		(function () {
			var idField = document.getElementById('paccc-md-image-id');
			var wrap = document.getElementById('paccc-md-image');
			var selectBtn = document.getElementById('paccc-md-image-select');
			var removeBtn = document.getElementById('paccc-md-image-remove');
			if (!idField || !wrap || !selectBtn || !window.wp || !window.wp.media) {
				return;
			}
			var preview = wrap.querySelector('.paccc-md-image-preview');
			var frame;

			function showImage(url) {
				if (preview) {
					preview.src = url;
					preview.hidden = false;
				}
				wrap.removeAttribute('data-empty');
				if (removeBtn) { removeBtn.hidden = false; }
				selectBtn.textContent = 'Replace image';
			}

			function clearImage() {
				idField.value = '';
				if (preview) { preview.src = ''; preview.hidden = true; }
				wrap.setAttribute('data-empty', '1');
				if (removeBtn) { removeBtn.hidden = true; }
				selectBtn.textContent = 'Select image';
			}

			selectBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = window.wp.media({
					title: 'Select or upload an image',
					button: { text: 'Use this image' },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('select', function () {
					var att = frame.state().get('selection').first().toJSON();
					idField.value = att.id;
					var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
					showImage(url);
				});
				frame.open();
			});

			if (removeBtn) {
				removeBtn.addEventListener('click', function (e) {
					e.preventDefault();
					clearImage();
				});
			}
		})();

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

		/* ---------- CEUs: one table; add from the All CEUs catalog, remove any row (saved with the member) ---------- */
		(function () {
			var picked = document.getElementById('paccc-md-ceu-picked');
			var none = document.getElementById('paccc-md-ceu-none');
			var catalog = document.getElementById('paccc-md-ceu-catalog');
			var ceuToggle = document.getElementById('paccc-md-add-ceu-toggle');
			var ceuAddRow = document.getElementById('paccc-md-add-ceu-row');
			var ceuInput = document.getElementById('paccc-md-new-ceu');
			var ceuAddBtn = document.getElementById('paccc-md-add-ceu-btn');
			var msg = document.getElementById('paccc-md-ceu-msg');
			if (!picked || !catalog) { return; }

			function say(text) { if (msg) { msg.textContent = text || ''; } }
			function rows() { return picked.querySelectorAll('.paccc-md-ceu-pick'); }
			function syncEmpty() { if (none) { none.hidden = rows().length > 0; } }

			if (ceuToggle && ceuAddRow) {
				ceuToggle.addEventListener('click', function (e) {
					e.preventDefault();
					ceuAddRow.hidden = !ceuAddRow.hidden;
					if (!ceuAddRow.hidden && ceuInput) { ceuInput.focus(); }
				});
			}

			// The typed text must match a catalog entry exactly (choosing from
			// the suggestion list fills it in); free text is not accepted.
			function findOption(text) {
				var opts = catalog.options;
				for (var i = 0; i < opts.length; i++) {
					if (opts[i].value === text) { return opts[i]; }
				}
				return null;
			}

			function cell(text) {
				var td = document.createElement('td');
				td.textContent = text || '';
				return td;
			}

			function addCeu() {
				if (!ceuInput) { return; }
				var text = (ceuInput.value || '').trim();
				if (!text) { return; }
				var opt = findOption(text);
				if (!opt) {
					say('Choose a CEU from the suggestion list — type part of its name or program code, then pick it.');
					return;
				}
				var id = opt.getAttribute('data-id');
				if (picked.querySelector('.paccc-md-ceu-pick[data-kind="catalog"][data-id="' + id + '"]')) {
					say('This member already has that CEU.');
					ceuInput.value = '';
					return;
				}
				var name = opt.getAttribute('data-name') || text;

				var tr = document.createElement('tr');
				tr.className = 'paccc-md-ceu-pick';
				tr.setAttribute('data-kind', 'catalog');
				tr.setAttribute('data-id', id);

				var nameTd = cell(name);
				nameTd.className = 'paccc-md-ceu-pick-name';
				tr.appendChild(nameTd);
				tr.appendChild(cell(opt.getAttribute('data-provider')));
				tr.appendChild(cell(opt.getAttribute('data-code')));
				tr.appendChild(cell(opt.getAttribute('data-amount')));
				tr.appendChild(cell(''));
				tr.appendChild(cell('Yes'));

				var last = document.createElement('td');
				var hid = document.createElement('input');
				hid.type = 'hidden'; hid.name = 'paccc_member_ceu_ids[]'; hid.value = id;
				last.appendChild(hid);
				var del = document.createElement('button');
				del.type = 'button'; del.className = 'paccc-md-ceu-remove';
				del.setAttribute('aria-label', 'Remove ' + name); del.innerHTML = '&times;';
				last.appendChild(del);
				tr.appendChild(last);

				picked.insertBefore(tr, none || null);
				ceuInput.value = '';
				say('Added "' + name + '". Click Update to save.');
				syncEmpty();
				ceuInput.focus();
			}

			if (ceuAddBtn) {
				ceuAddBtn.addEventListener('click', addCeu);
			}
			if (ceuInput) {
				ceuInput.addEventListener('keydown', function (e) {
					if (e.key === 'Enter') { e.preventDefault(); addCeu(); }
				});
				// Picking a suggestion fires "input" with the full label: add it right away.
				ceuInput.addEventListener('input', function () {
					say('');
					if (findOption(ceuInput.value)) { addCeu(); }
				});
			}

			// Remove one CEU from this member only (saved when you click Update).
			// Rows that didn't come from the catalog can't be added back, so confirm those.
			picked.addEventListener('click', function (e) {
				var btn = e.target.closest('.paccc-md-ceu-remove');
				if (!btn) { return; }
				var tr = btn.closest('.paccc-md-ceu-pick');
				if (!tr) { return; }
				if (tr.getAttribute('data-kind') !== 'catalog') {
					var nm = ((tr.querySelector('.paccc-md-ceu-pick-name') || {}).textContent || 'this CEU').trim();
					if (!window.confirm('Remove "' + nm + '" from this member? It isn\'t in the All CEUs catalog, so it can\'t be added back from there.')) { return; }
				}
				if (tr.parentNode) { tr.parentNode.removeChild(tr); }
				say('Removed. Click Update to save.');
				syncEmpty();
			});
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
