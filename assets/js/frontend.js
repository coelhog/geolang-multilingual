/* GeoLang – Frontend JS
 *
 * Architecture: all 3 language values are embedded in the HTML as data attributes.
 * JS reads the cookie and swaps text instantly — no page reload needed.
 * This is cache-safe: the HTML is identical for all visitors.
 */
(function () {
	'use strict';

	var cfg          = window.GeoLang || {};
	var COOKIE_NAME  = cfg.cookieName  || 'geolang_lang';
	var COOKIE_DAYS  = cfg.cookieDays  || 30;
	var DEFAULT_LANG = cfg.defaultLang || 'pt';
	var ACTIVE_LANGS = cfg.activeLangs || ['pt', 'en', 'es'];

	// -----------------------------------------------------------------------
	// Cookie helpers
	// -----------------------------------------------------------------------

	function setCookie(name, value, days) {
		var d = new Date();
		d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
		document.cookie = name + '=' + encodeURIComponent(value)
			+ '; expires=' + d.toUTCString()
			+ '; path=/; SameSite=Lax';
	}

	function getCookie(name) {
		var match = document.cookie.match(
			new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)')
		);
		return match ? decodeURIComponent(match[1]) : null;
	}

	// -----------------------------------------------------------------------
	// Resolve current language
	// -----------------------------------------------------------------------

	function resolveLang() {
		// 1. Cookie (persisted from previous visit or click)
		var fromCookie = getCookie(COOKIE_NAME);
		if (fromCookie && ACTIVE_LANGS.indexOf(fromCookie) !== -1) {
			return fromCookie;
		}
		// 2. GET param ?lang=en
		var params   = new URLSearchParams(window.location.search);
		var fromGet  = params.get('lang');
		if (fromGet && ACTIVE_LANGS.indexOf(fromGet) !== -1) {
			setCookie(COOKIE_NAME, fromGet, COOKIE_DAYS);
			return fromGet;
		}
		// 3. Server default (injected via wp_localize_script)
		return cfg.currentLang || DEFAULT_LANG;
	}

	// -----------------------------------------------------------------------
	// Apply language to all GeoLang fields on the page
	// -----------------------------------------------------------------------

	function applyLang(lang) {
		// Text / HTML fields: <span class="geolang-field" data-lang-pt="..." data-lang-en="..." data-lang-es="...">
		var fields = document.querySelectorAll('.geolang-field');
		fields.forEach(function (el) {
			var value = el.getAttribute('data-lang-' + lang)
				|| el.getAttribute('data-lang-' + DEFAULT_LANG)
				|| '';
			// Use innerHTML to support basic HTML tags in translations.
			el.innerHTML = value;
		});

		// Image fields: <img class="geolang-image" data-lang-pt="url" data-lang-en="url" ...>
		var images = document.querySelectorAll('.geolang-image[data-lang-' + lang + ']');
		images.forEach(function (img) {
			var src = img.getAttribute('data-lang-' + lang)
				|| img.getAttribute('data-lang-' + DEFAULT_LANG)
				|| '';
			if (src) img.src = src;
		});

		// URL fields: <a class="geolang-url-field" data-lang-pt="url" data-lang-en="url" ...>
		var links = document.querySelectorAll('.geolang-url-field[data-lang-' + lang + ']');
		links.forEach(function (a) {
			var href = a.getAttribute('data-lang-' + lang)
				|| a.getAttribute('data-lang-' + DEFAULT_LANG)
				|| '';
			if (href) a.href = href;
		});

		// GeoLang URL Dynamic Tags (Elementor): window.GeoLangURLData is emitted by PHP
		// at wp_footer and contains all registered URL variants per field key.
		// JS finds elements whose href/src/data-src matches any known variant and updates them.
		var urlData = window.GeoLangURLData;
		if (urlData && urlData.length) {
			urlData.forEach(function (item) {
				var newUrl = item[lang] || item[DEFAULT_LANG] || '';
				if (!newUrl) return;

				// Build list of all known URL variants to match against DOM.
				var knownUrls = [item.pt, item.en, item.es].filter(Boolean);

				// Swap <a href>, <iframe src>, elements with data-src.
				document.querySelectorAll('a[href], iframe[src], [data-src]').forEach(function (el) {
					var cur = el.getAttribute('href') || el.getAttribute('src') || el.getAttribute('data-src') || '';
					if (!cur || knownUrls.indexOf(cur) === -1) return;
					if (el.hasAttribute('href'))     el.setAttribute('href', newUrl);
					if (el.hasAttribute('src'))      el.setAttribute('src',  newUrl);
					if (el.hasAttribute('data-src')) el.setAttribute('data-src', newUrl);
				});
			});
		}

		// Expose on window for theme/plugin integrations.
		window.GeoLang = window.GeoLang || {};
		window.GeoLang.currentLang = lang;
	}

	// -----------------------------------------------------------------------
	// Switcher: sync active flag + handle clicks
	// -----------------------------------------------------------------------

	function initSwitchers(lang) {
		var switchers = document.querySelectorAll('.geolang-switcher');

		switchers.forEach(function (switcher) {
			// Sync active class to current lang.
			switcher.querySelectorAll('.geolang-flag').forEach(function (btn) {
				var isActive = btn.getAttribute('data-lang') === lang;
				btn.classList.toggle('geolang-flag--active', isActive);
				btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			});

			// Click: set cookie + apply language instantly (no reload).
			switcher.addEventListener('click', function (e) {
				var btn = e.target.closest('.geolang-flag');
				if (!btn) return;

				var newLang = btn.getAttribute('data-lang');
				if (!newLang || ACTIVE_LANGS.indexOf(newLang) === -1) return;
				if (newLang === window.GeoLang.currentLang) return;

				setCookie(COOKIE_NAME, newLang, COOKIE_DAYS);
				applyLang(newLang);
				initSwitchers(newLang); // re-sync active states
			});
		});
	}

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	function boot() {
		var lang = resolveLang();
		window.GeoLang         = window.GeoLang || {};
		window.GeoLang.currentLang = lang;

		applyLang(lang);
		initSwitchers(lang);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

})();
