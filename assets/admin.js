jQuery(function ($) {
	'use strict';

	/* ---------------------------------------------------------- Media field */
	$(document).on('click', '.cdnhub-resync', function (e) {
		e.preventDefault();
		var $btn = $(this),
			$box = $btn.closest('.cdnhub-field'),
			label = $btn.text();
		$btn.prop('disabled', true).text('Uploading…');
		$.post(CDNHub.ajax, { action: 'cdnhub_resync', nonce: CDNHub.nonce, id: $btn.data('id') })
			.done(function (r) {
				if (r && r.success) {
					$box.find('.cdnhub-status').html(r.data.badge);
					var $url = $box.find('.cdnhub-url');
					if (r.data.url) { $url.val(r.data.url).show(); }
					$box.find('.cdnhub-error').hide();
					$btn.text(r.data.ok ? 'Re-sync to CDN' : label);
					if (!r.data.ok) { $box.find('.cdnhub-error').text(r.data.message).show(); }
				} else {
					$box.find('.cdnhub-error').text(r && r.data ? r.data.message : 'Request failed.').show();
					$btn.text(label);
				}
			})
			.fail(function () {
				$box.find('.cdnhub-error').text('Request failed.').show();
				$btn.text(label);
			})
			.always(function () { $btn.prop('disabled', false); });
	});

	/* Keep an image on this server / hand it back to the CDN (swaps the whole field). */
	$(document).on('click', '.cdnhub-exclude, .cdnhub-include', function (e) {
		e.preventDefault();
		var $btn = $(this),
			$box = $btn.closest('.cdnhub-field'),
			working = $btn.hasClass('cdnhub-exclude') ? 'Restoring…' : 'Working…';
		$btn.prop('disabled', true).text(working);
		$.post(CDNHub.ajax, {
			action: 'cdnhub_local', nonce: CDNHub.nonce,
			id: $btn.data('id'),
			exclude: $btn.hasClass('cdnhub-exclude') ? 1 : 0
		}).done(function (r) {
			if (r && r.success) {
				$box.html(r.data.field);
				if (!r.data.ok) { $box.find('.cdnhub-error').text(r.data.message).show(); }
			} else {
				$box.find('.cdnhub-error').text(r && r.data ? r.data.message : 'Request failed.').show();
				$btn.prop('disabled', false);
			}
		}).fail(function () {
			$box.find('.cdnhub-error').text('Request failed.').show();
			$btn.prop('disabled', false);
		});
	});

	/* --------------------------------------------- Media Upload Toggle Option */
	var STORAGE_KEY = 'cdnhub_upload_toggle';

	function isUploadOptionChecked() {
		var stored = null;
		try {
			stored = localStorage.getItem(STORAGE_KEY);
		} catch (e) {}

		if (stored !== null) {
			return stored === '1' || stored === 'true';
		}
		if (typeof CDNHub !== 'undefined' && typeof CDNHub.upload_to_cdn !== 'undefined') {
			return Boolean(CDNHub.upload_to_cdn);
		}
		return true;
	}

	function setUploadOptionChecked(enabled) {
		try {
			localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0');
		} catch (e) {}

		$('.cdnhub-upload-toggle').prop('checked', enabled);
		syncUploaderParams(enabled);
	}

	// The server ignores the choice unless it comes with this nonce.
	var OFFLOAD_NONCE = (typeof CDNHub !== 'undefined' && CDNHub.offload_nonce) ? CDNHub.offload_nonce : '';

	function setOffloadParams(params, enabled) {
		params.cdnhub_offload = enabled ? 1 : 0;
		params.cdnhub_offload_nonce = OFFLOAD_NONCE;
	}

	function syncUploaderParams(enabled) {
		if (typeof window.wpUploaderInit !== 'undefined' && window.wpUploaderInit.multipart_params) {
			setOffloadParams(window.wpUploaderInit.multipart_params, enabled);
		}
		if (typeof window._wpPluploadSettings !== 'undefined' && window._wpPluploadSettings.defaults && window._wpPluploadSettings.defaults.multipart_params) {
			setOffloadParams(window._wpPluploadSettings.defaults.multipart_params, enabled);
		}
		if (window.wp && wp.Uploader && wp.Uploader.defaults && wp.Uploader.defaults.multipart_params) {
			setOffloadParams(wp.Uploader.defaults.multipart_params, enabled);
		}
	}

	// Always sync current state right away
	syncUploaderParams(isUploadOptionChecked());

	// Intercept plupload Uploader init so BeforeUpload always has the latest value
	if (window.plupload && plupload.Uploader) {
		var origPluploadInit = plupload.Uploader.prototype.init;
		plupload.Uploader.prototype.init = function () {
			this.bind('BeforeUpload', function (up) {
				up.settings.multipart_params = up.settings.multipart_params || {};
				setOffloadParams(up.settings.multipart_params, isUploadOptionChecked());
			});
			return origPluploadInit.apply(this, arguments);
		};
	}

	// Intercept wp.Uploader constructor
	if (window.wp && wp.Uploader) {
		var origWpUploader = wp.Uploader;
		wp.Uploader = function (options) {
			options = options || {};
			options.params = options.params || {};
			setOffloadParams(options.params, isUploadOptionChecked());
			return origWpUploader.apply(this, arguments);
		};
		$.extend(wp.Uploader, origWpUploader);
	}

	// Intercept Gutenberg REST media uploads if apiFetch is present
	if (window.wp && wp.apiFetch) {
		wp.apiFetch.use(function (options, next) {
			if (options && options.path && options.path.indexOf('/wp/v2/media') !== -1 && options.method === 'POST') {
				var params = {};
				setOffloadParams(params, isUploadOptionChecked());
				if (options.data instanceof FormData) {
					options.data.append('cdnhub_offload', params.cdnhub_offload);
					options.data.append('cdnhub_offload_nonce', params.cdnhub_offload_nonce);
				} else if (typeof options.path === 'string') {
					var sep = options.path.indexOf('?') !== -1 ? '&' : '?';
					options.path += sep + 'cdnhub_offload=' + params.cdnhub_offload +
						'&cdnhub_offload_nonce=' + encodeURIComponent(params.cdnhub_offload_nonce);
				}
			}
			return next(options);
		});
	}

	function getToggleHtml() {
		var checked = isUploadOptionChecked() ? ' checked="checked"' : '';
		return '<div class="cdnhub-upload-option-wrap cdnhub-injected">' +
			'<label class="cdnhub-upload-option-label" for="cdnhub-upload-toggle-inline">' +
				'<span class="cdnhub-toggle-switch">' +
					'<input type="checkbox" id="cdnhub-upload-toggle-inline" class="cdnhub-upload-toggle" value="1"' + checked + '>' +
					'<span class="cdnhub-toggle-slider"></span>' +
				'</span>' +
				'<span class="cdnhub-toggle-info">' +
					'<strong class="cdnhub-toggle-title">' +
						'<svg class="cdnhub-svg-icon" width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>' +
						' Upload to GitHub via CDNHub' +
					'</strong>' +
					'<span class="cdnhub-toggle-desc">Images will be stored on GitHub and served from CDN</span>' +
				'</span>' +
			'</label>' +
		'</div>';
	}

	function injectUploadToggles() {
		if (typeof CDNHub !== 'undefined' && !CDNHub.is_active) {
			return;
		}
		var enabled = isUploadOptionChecked();

		// 1. media-new.php
		var $serverWrap = $('.cdnhub-upload-option-wrap:not(.cdnhub-injected)');
		if ($serverWrap.length) {
			$serverWrap.find('.cdnhub-upload-toggle').prop('checked', enabled);
			var $dragArea = $('#drag-drop-area');
			if ($dragArea.length && !$dragArea.next('.cdnhub-upload-option-wrap').length) {
				$serverWrap.insertAfter($dragArea);
			}
		}

		// 2. Inline uploader (modal / grid)
		$('.uploader-inline:visible').each(function () {
			var $inline = $(this);
			if (!$inline.find('.cdnhub-upload-option-wrap').length) {
				var $target = $inline.find('.upload-ui');
				if (!$target.length) { $target = $inline.find('.uploader-inline-content'); }
				if ($target.length) {
					$target.append(getToggleHtml());
				}
			}
		});
	}

	$(document).on('change', '.cdnhub-upload-toggle', function () {
		setUploadOptionChecked($(this).is(':checked'));
	});

	// Trigger toggle positioning on ready and on media events
	injectUploadToggles();
	setTimeout(injectUploadToggles, 100);

	if (window.wp && wp.media) {
		$(document).on('click', '.open-plugin-details-modal, .media-button, .upload-files, .add-new-h2, .page-title-action', function () {
			setTimeout(injectUploadToggles, 150);
		});
		if (wp.media.view && wp.media.view.UploaderInline) {
			var origInlineRender = wp.media.view.UploaderInline.prototype.render;
			wp.media.view.UploaderInline.prototype.render = function () {
				var res = origInlineRender.apply(this, arguments);
				var $el = this.$el;
				setTimeout(function () {
					if (!$el.find('.cdnhub-upload-option-wrap').length) {
						var $target = $el.find('.upload-ui');
						if (!$target.length) { $target = $el.find('.uploader-inline-content'); }
						if ($target.length) {
							$target.append(getToggleHtml());
						}
					}
				}, 50);
				return res;
			};
		}
	}

	if (!$('.cdnhub').length) { return; }

	/* ----------------------------------------------------------------- Tabs */
	var formTabs = ['connection', 'storage', 'rules'];
	function showTab(tab) {
		if (!$('.cdnhub-tabs [data-tab="' + tab + '"]').length) { tab = 'connection'; }
		$('.cdnhub-tabs .nav-tab').removeClass('nav-tab-active').filter('[data-tab="' + tab + '"]').addClass('nav-tab-active');
		$('.cdnhub-panel').removeClass('is-active').filter('[data-panel="' + tab + '"]').addClass('is-active');
		$('.cdnhub-savebar').toggleClass('is-active', formTabs.indexOf(tab) !== -1);
		try { sessionStorage.setItem('cdnHubTab', tab); } catch (e) {}
	}
	$('.cdnhub-tabs').on('click', '.nav-tab', function (e) {
		e.preventDefault();
		showTab($(this).data('tab'));
	});
	var initial = (location.hash || '').replace('#', '');
	if (!initial) { try { initial = sessionStorage.getItem('cdnHubTab') || ''; } catch (e) {} }
	showTab(initial || 'connection');

	/* ------------------------------------------------- Conditional fields + live preview */
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function cleanPath(p) {
		return String(p || '').replace(/\\/g, '/').split('/').map(function (seg) {
			return seg.replace(/[^A-Za-z0-9._-]/g, '-').replace(/^[.-]+|[.-]+$/g, '');
		}).filter(Boolean).join('/');
	}
	var helpText = {
		jsdelivr: 'Fast global CDN with caching. Works with public repositories. Replaced files can take a while to refresh.',
		raw: 'Direct GitHub links. Simple, but rate-limited and not meant for heavy traffic.',
		pages: 'Requires GitHub Pages to be enabled for the repository/branch (Repository → Settings → Pages).',
		custom: 'Use your own domain, a Cloudflare Worker or another proxy that maps to the repository root.'
	};

	function update() {
		var mode = $('#cdnhub-urlmode').val(),
			struct = $('#cdnhub-structure').val(),
			owner = $('#cdnhub-owner').val() || 'your-username',
			repo = $('#cdnhub-repo').val() || 'your-repo',
			branch = $('#cdnhub-branch').val() || 'main',
			folder = cleanPath($('#cdnhub-folder').val());

		$('#cdnhub-custom-pattern-wrap').toggle(struct === 'custom');
		$('#cdnhub-custom-template-wrap').toggle(mode === 'custom');
		$('#cdnhub-urlmode-help').text(helpText[mode] || '');

		var d = new Date(),
			tok = { year: d.getUTCFullYear(), month: pad(d.getUTCMonth() + 1), day: pad(d.getUTCDate()), post_id: '123' },
			pattern = { year_month: '{year}/{month}', year: '{year}', post_id: '{post_id}', flat: '', custom: $('#cdnhub-custom-pattern').val() }[struct] || '',
			dir = cleanPath(pattern.replace(/\{(year|month|day|post_id)\}/g, function (m, k) { return tok[k]; })),
			path = [folder, dir, 'my-blog-image.webp'].filter(Boolean).join('/'),
			url;

		if (mode === 'raw') {
			url = 'https://raw.githubusercontent.com/' + owner + '/' + repo + '/' + branch + '/' + path;
		} else if (mode === 'pages') {
			url = 'https://' + owner + '.github.io/' + repo + '/' + path;
		} else if (mode === 'custom' && $('#cdnhub-custom-template').val()) {
			var tpl = $('#cdnhub-custom-template').val();
			if (tpl.indexOf('{path}') === -1) { tpl = tpl.replace(/\/+$/, '') + '/{path}'; }
			url = tpl.replace('{owner}', owner).replace('{repo}', repo).replace('{branch}', branch).replace('{path}', path);
		} else {
			url = 'https://cdn.jsdelivr.net/gh/' + owner + '/' + repo + '@' + branch + '/' + path;
		}
		$('#cdnhub-preview').text(url);
	}
	$('#cdnhub-form').on('input change', 'input, select', update);
	update();

	/* ------------------------------------------------------- Test connection */
	$('#cdnhub-test').on('click', function (e) {
		e.preventDefault();
		var $out = $('#cdnhub-test-out').attr('class', 'cdnhub-out').text('Testing…');
		$.post(CDNHub.ajax, {
			action: 'cdnhub_test', nonce: CDNHub.nonce,
			token: $('#cdnhub-token').val() || '',
			owner: $('#cdnhub-owner').val(), repo: $('#cdnhub-repo').val(), branch: $('#cdnhub-branch').val()
		}).done(function (r) {
			var msg = r && r.data && r.data.message ? r.data.message : 'Unexpected response.';
			$out.text(msg).addClass(r && r.success ? 'is-ok' : 'is-bad');
		}).fail(function () {
			$out.text('Request failed.').addClass('is-bad');
		});
	});

	/* ----------------------------------------------------- Bulk sync (Tools) */
	var running = false;
	$('#cdnhub-sync').on('click', function (e) {
		e.preventDefault();
		if (running) { return; }
		running = true;
		var $btn = $(this).prop('disabled', true),
			$out = $('#cdnhub-sync-out').attr('class', 'cdnhub-out').text('Starting…'),
			$log = $('#cdnhub-sync-log').empty(),
			$bar = $('#cdnhub-bar').show().attr('max', 1).val(0),
			total = 0, ok = 0, failed = 0, last = 0;

		function finish() { running = false; $btn.prop('disabled', false); }

		function step() {
			$.post(CDNHub.ajax, { action: 'cdnhub_sync', nonce: CDNHub.nonce, last_id: last })
				.done(function (r) {
					if (!r || !r.success) {
						$out.addClass('is-bad').text(r && r.data && r.data.message ? r.data.message : 'Error.');
						return finish();
					}
					var d = r.data;
					if (d.done) {
						var doneMsg = 'Finished. Uploaded: ' + ok + ', failed: ' + failed + '.';
						if (d.notice) { doneMsg += ' ' + d.notice; }
						$out.addClass(failed ? 'is-bad' : 'is-ok').text(doneMsg);
						return finish();
					}
					if (!total) { total = d.remaining; }
					last = d.id;
					if (d.ok) { ok++; } else {
						failed++;
						$log.append($('<li>').text('Image #' + d.id + ': ' + d.message));
					}
					$bar.attr('max', total).val(Math.min(total, ok + failed));
					$out.text('Processed ' + (ok + failed) + ' of ' + total + '…');
					if (d.stop) {
						$out.addClass('is-bad').text('Paused: ' + d.message + ' Failed images will be retried automatically.');
						return finish();
					}
					step();
				})
				.fail(function () {
					$out.addClass('is-bad').text('The request failed or timed out. Click the button again to continue.');
					finish();
				});
		}
		step();
	});

	/* ------------------------------------------------------- Adopt (Tools) */
	var adopting = false;
	$('#cdnhub-adopt').on('click', function (e) {
		e.preventDefault();
		if (adopting) { return; }
		adopting = true;
		var $btn = $(this).prop('disabled', true),
			$out = $('#cdnhub-adopt-out').attr('class', 'cdnhub-out').text('Reading the repository…'),
			$log = $('#cdnhub-adopt-log').empty(),
			$bar = $('#cdnhub-adopt-bar').show().attr('max', 1).val(0),
			total = 0, adopted = 0, missing = 0, failures = 0, last = 0;

		function finish(msg, cls) {
			adopting = false;
			$btn.prop('disabled', false);
			if (msg) { $out.attr('class', 'cdnhub-out' + (cls ? ' ' + cls : '')).text(msg); }
		}
		function step() {
			$.post(CDNHub.ajax, { action: 'cdnhub_adopt', nonce: CDNHub.nonce, last_id: last })
				.done(function (r) {
					if (!r || !r.success) {
						return finish(r && r.data && r.data.message ? r.data.message : 'Error.', 'is-bad');
					}
					var d = r.data;
					if (d.done) {
						return finish(
							'Finished. Adopted: ' + adopted + '.'
								+ (missing ? ' Not on GitHub: ' + missing + ' — use “Send existing images” for those.' : '')
								+ (failures ? ' Failed: ' + failures + '.' : ''),
							failures ? 'is-bad' : 'is-ok');
					}
					last = d.last;
					adopted += d.adopted; missing += d.missing; failures += d.failures;
					(d.messages || []).forEach(function (m) { $log.append($('<li>').text(m)); });
					$bar.attr('max', Math.max(total, 1)).val(Math.max(total - d.remaining, 0));
					$out.text('Adopted ' + adopted + ' of ' + total + '… (' + d.remaining + ' left)');
					if (d.stop) {
						return finish('Paused: ' + (d.message || 'GitHub rate limit reached.') + ' Missing files retry automatically; click again later to continue.', 'is-bad');
					}
					step();
				})
				.fail(function () {
					finish('The request failed or timed out. Click the button again to continue.', 'is-bad');
				});
		}

		$.post(CDNHub.ajax, { action: 'cdnhub_adopt_start', nonce: CDNHub.nonce })
			.done(function (r) {
				if (!r || !r.success) { return finish(r && r.data && r.data.message ? r.data.message : 'Error.', 'is-bad'); }
				total = r.data.remain;
				if (!total) {
					var emptyMsg = 'Nothing to do — every enabled image is already synced.';
					if (r.data.notice) { emptyMsg = r.data.notice; }
					return finish(emptyMsg, 'is-ok');
				}
				$bar.attr('max', Math.max(total, 1));
				$out.text('Repository holds ' + r.data.files + ' file(s). Matching ' + total + ' image(s)…');
				step();
			})
			.fail(function () { finish('The request failed. Try again.', 'is-bad'); });
	});

	/* ------------------------------------------- Re-include all (Tools) */
	var reincluding = false;
	$('#cdnhub-reinclude-all').on('click', function (e) {
		e.preventDefault();
		if (reincluding) { return; }
		reincluding = true;
		var $btn = $(this).prop('disabled', true),
			$out = $('#cdnhub-reinclude-out').attr('class', 'cdnhub-out').text('Starting…'),
			$bar = $('#cdnhub-reinclude-bar').show().attr('max', 1).val(0),
			total = 0, reincluded = 0, failures = 0, last = 0;

		function finish(msg, cls) {
			reincluding = false;
			$btn.prop('disabled', false);
			if (msg) { $out.attr('class', 'cdnhub-out' + (cls ? ' ' + cls : '')).text(msg); }
		}

		function step() {
			$.post(CDNHub.ajax, { action: 'cdnhub_reinclude', nonce: CDNHub.nonce, last_id: last })
				.done(function (r) {
					if (!r || !r.success) {
						return finish(r && r.data && r.data.message ? r.data.message : 'Error.', 'is-bad');
					}
					var d = r.data;
					if (d.done) {
						return finish('Finished. Re-included & synced: ' + reincluded + (failures ? ', failed: ' + failures : '') + '. Refreshing page…', failures ? 'is-bad' : 'is-ok');
					}
					if (!total) { total = d.remaining + (d.reincluded || 0) + (d.failures || 0); }
					last = d.last;
					reincluded += (d.reincluded || 0);
					failures += (d.failures || 0);
					$bar.attr('max', Math.max(total, 1)).val(reincluded + failures);
					$out.text('Processed ' + (reincluded + failures) + (total ? ' of ' + total : '') + '…');
					if (d.stop) {
						return finish('Paused: ' + (d.message || 'Rate limit reached.') + ' Click again later to continue.', 'is-bad');
					}
					step();
				})
				.fail(function () {
					finish('The request failed or timed out. Click again to continue.', 'is-bad');
				});
		}
		step();
	});

	$(document).on('click', '.cdnhub-adopt-notice .notice-dismiss', function () {
		$.post(CDNHub.ajax, { action: 'cdnhub_adopt_dismiss', nonce: CDNHub.nonce });
	});

	/* -------------------------------------------------- Cleanup (Tools) */
	var cleaning = false;
	$('#cdnhub-cleanup').on('click', function (e) {
		e.preventDefault();
		if (cleaning) { return; }
		cleaning = true;
		var $btn = $(this).prop('disabled', true),
			$out = $('#cdnhub-cleanup-out').attr('class', 'cdnhub-out').text('Working…'),
			$bar = $('#cdnhub-cleanup-bar').show().attr('max', 1).val(0),
			total = 0, done = 0, deleted = 0, skipped = 0, failures = 0, last = 0;

		function finish(msg, cls) {
			cleaning = false;
			$btn.prop('disabled', false);
			if (msg) { $out.attr('class', 'cdnhub-out' + (cls ? ' ' + cls : '')).text(msg); }
		}
		function step() {
			$.post(CDNHub.ajax, { action: 'cdnhub_cleanup', nonce: CDNHub.nonce, last_id: last })
				.done(function (r) {
					if (!r || !r.success) {
						return finish(r && r.data && r.data.message ? r.data.message : 'Error.', 'is-bad');
					}
					var d = r.data;
					if (d.done) {
						if (!total) { return finish('Nothing to do — no synced images have local copies.', 'is-ok'); }
						return finish(
							'Finished. Checked ' + total + ' synced image(s) — deleted ' + deleted + ' local file(s).'
								+ (skipped ? ' Skipped ' + skipped + ' file(s) not safely on the CDN.' : '')
								+ (failures ? ' Could not delete ' + failures + ' file(s) — check permissions.' : ''),
							failures ? 'is-bad' : 'is-ok');
					}
					last = d.last;
					done = Math.max(total - d.remaining, 0);
					deleted += d.deleted; skipped += d.skipped; failures += d.failures;
					$bar.attr('max', Math.max(total, 1)).val(done);
					$out.text('Checked ' + done + ' of ' + total + ' image(s) — deleted ' + deleted + ' file(s) so far…');
					step();
				})
				.fail(function () {
					finish('The request failed or timed out. Click the button again to continue.', 'is-bad');
				});
		}

		$.post(CDNHub.ajax, { action: 'cdnhub_cleanup', nonce: CDNHub.nonce, last_id: 0 })
			.done(function (r) {
				if (!r || !r.success) { return finish(r && r.data && r.data.message ? r.data.message : 'Error.', 'is-bad'); }
				var d = r.data;
				if (d.done) { return finish('Nothing to do — no synced images have local copies.', 'is-ok'); }
				total = d.total || d.remaining;
				$bar.attr('max', Math.max(total, 1));
				$out.text('Found synced images. Deleting verified local copies…');
				last = d.last;
				deleted += d.deleted; skipped += d.skipped; failures += d.failures;
				step();
			})
			.fail(function () { finish('The request failed. Try again.', 'is-bad'); });
	});
});
