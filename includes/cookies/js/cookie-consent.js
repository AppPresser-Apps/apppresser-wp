/**
 * Cookie Consent Banner - Frontend JavaScript
 */
(function () {
	'use strict';

	const config = window.apppresserCookieConsent || {};
	const COOKIE_NAME = config.cookieName || 'apppresser_cookie_consent';
	const PREFS_COOKIE = config.prefsCookieName || 'apppresser_cookie_prefs';
	const DURATION = parseInt(config.duration, 10) || 30;
	const GOOGLE_TAG_ID = (config.googleTagId || '').trim();
	const MARKETING_PIXEL = (config.marketingPixel || '').trim();

	// Cookies set by third-party trackers on this domain, per consent
	// category. Cleared when the visitor rejects or withdraws that consent.
	const TRACKER_COOKIES = {
		analytics: /^(_ga|_gid|_gat|_hj|_clck|_clsk)/,
		marketing: /^(_fbp|_fbc|_gcl|_uet|_ttp|_pin_|li_|hubspotutk|__hs)/,
	};

	const banner = document.getElementById('apppresser-cookie-banner');
	if (!banner) return;

	// Elements.
	const closeBtn = document.getElementById('apppresser-cookie-close-btn');
	const acceptBtn = document.getElementById('apppresser-cookie-accept-btn');
	const rejectBtn = document.getElementById('apppresser-cookie-reject-btn');
	const settingsBtn = document.getElementById('apppresser-cookie-settings-btn');
	const prefsPanel = document.getElementById('apppresser-cookie-preferences');
	const savePrefsBtn = document.getElementById('apppresser-cookie-save-prefs-btn');
	const analyticsCheck = document.getElementById('apppresser-cookie-analytics');
	const marketingCheck = document.getElementById('apppresser-cookie-marketing');

	let googleTagLoaded = false;
	let marketingPixelLoaded = false;

	// The marketing category is only offered when a pixel is configured.
	const hasMarketing = !!marketingCheck;

	/**
	 * Set a cookie.
	 */
	function setCookie(name, value, days) {
		const date = new Date();
		date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
		document.cookie = name + '=' + encodeURIComponent(value)
			+ ';expires=' + date.toUTCString()
			+ ';path=/;SameSite=Lax';
	}

	/**
	 * Get a cookie value.
	 */
	function getCookie(name) {
		const match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
		return match ? decodeURIComponent(match[2]) : null;
	}

	/**
	 * Expire a cookie on every domain scope it could have been set for
	 * (third-party tags usually set cookies on the root domain).
	 */
	function deleteCookie(name) {
		const expired = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
		const parts = location.hostname.split('.');
		const domains = [''];

		for (let i = 0; i < parts.length - 1; i++) {
			const domain = parts.slice(i).join('.');
			domains.push(domain, '.' + domain);
		}

		domains.forEach(function (domain) {
			document.cookie = expired + (domain ? ';domain=' + domain : '');
		});
	}

	/**
	 * Read saved preferences from the prefs cookie.
	 */
	function getPreferences() {
		const consent = getCookie(COOKIE_NAME);
		const prefs = { analytics: false, marketing: false };

		if (consent === 'accepted') {
			prefs.analytics = true;
			prefs.marketing = hasMarketing;
		}

		try {
			const saved = JSON.parse(getCookie(PREFS_COOKIE) || '');
			if (saved && typeof saved === 'object') {
				prefs.analytics = !!saved.analytics;
				prefs.marketing = hasMarketing && !!saved.marketing;
			}
		} catch (e) {
			// No saved prefs or invalid JSON — fall back to consent value.
		}

		return prefs;
	}

	/**
	 * Push a Google Consent Mode update so an already-loaded tag stops
	 * (or starts) storing cookies without a page reload.
	 */
	function updateGoogleConsent(prefs) {
		window.dataLayer = window.dataLayer || [];
		function gtag() { window.dataLayer.push(arguments); }
		gtag('consent', 'update', {
			analytics_storage: prefs.analytics ? 'granted' : 'denied',
			ad_storage: prefs.marketing ? 'granted' : 'denied',
			ad_user_data: prefs.marketing ? 'granted' : 'denied',
			ad_personalization: prefs.marketing ? 'granted' : 'denied',
		});
	}

	/**
	 * Inject the Google tag. Only ever called after analytics consent.
	 */
	function loadGoogleTag() {
		if (!GOOGLE_TAG_ID || googleTagLoaded) return;
		googleTagLoaded = true;

		window.dataLayer = window.dataLayer || [];
		window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
		window.gtag('js', new Date());
		window.gtag('config', GOOGLE_TAG_ID);

		const script = document.createElement('script');
		script.async = true;
		script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GOOGLE_TAG_ID);
		document.head.appendChild(script);
	}

	/**
	 * Inject an HTML snippet (e.g. a tracking pixel) so that its <script>
	 * tags actually execute. Only ever called after marketing consent.
	 */
	function loadMarketingPixel() {
		if (!MARKETING_PIXEL || marketingPixelLoaded) return;
		marketingPixelLoaded = true;

		const container = document.createElement('div');
		container.innerHTML = MARKETING_PIXEL;

		Array.prototype.slice.call(container.childNodes).forEach(function (node) {
			if (node.nodeName === 'SCRIPT') {
				// Scripts added via innerHTML never run; rebuild them.
				const script = document.createElement('script');
				Array.prototype.forEach.call(node.attributes, function (attr) {
					script.setAttribute(attr.name, attr.value);
				});
				script.text = node.text;
				document.head.appendChild(script);
			} else if (node.nodeName !== 'NOSCRIPT') {
				document.body.appendChild(node);
			}
		});
	}

	/**
	 * Remove cookies belonging to categories the visitor has not consented to.
	 */
	function clearRejectedCookies(prefs) {
		document.cookie.split(';').forEach(function (cookie) {
			const name = cookie.split('=')[0].trim();
			if (!name) return;

			Object.keys(TRACKER_COOKIES).forEach(function (category) {
				if (!prefs[category] && TRACKER_COOKIES[category].test(name)) {
					deleteCookie(name);
				}
			});
		});
	}

	/**
	 * Apply a set of preferences: load or block trackers accordingly.
	 */
	function applyPreferences(prefs) {
		updateGoogleConsent(prefs);
		clearRejectedCookies(prefs);

		if (prefs.analytics) {
			loadGoogleTag();
		}

		if (prefs.marketing) {
			loadMarketingPixel();
		}

		document.dispatchEvent(new CustomEvent('apppresser:cookie-consent', { detail: prefs }));
	}

	/**
	 * Reflect saved preferences in the preferences panel toggles.
	 */
	function syncCheckboxes(prefs) {
		if (analyticsCheck) analyticsCheck.checked = prefs.analytics;
		if (marketingCheck) marketingCheck.checked = prefs.marketing;
	}

	/**
	 * Persist a consent decision and apply it.
	 */
	function saveConsent(status, prefs) {
		setCookie(COOKIE_NAME, status, DURATION);
		setCookie(PREFS_COOKIE, JSON.stringify(prefs), DURATION);
		syncCheckboxes(prefs);
		applyPreferences(prefs);
		hideBanner();
	}

	/**
	 * Hide the banner and preferences panel.
	 */
	function hideBanner() {
		banner.classList.remove('is-visible', 'has-preferences-open');
		banner.setAttribute('aria-hidden', 'true');
		if (prefsPanel) {
			prefsPanel.classList.remove('is-visible');
			prefsPanel.setAttribute('aria-hidden', 'true');
		}
	}

	/**
	 * Accept all cookies.
	 */
	function acceptAll() {
		// Marketing can only be accepted when the category is offered.
		saveConsent('accepted', { analytics: true, marketing: hasMarketing });
	}

	/**
	 * Reject all non-essential cookies.
	 */
	function rejectAll() {
		saveConsent('rejected', { analytics: false, marketing: false });
	}

	/**
	 * Save custom preferences.
	 */
	function savePreferences() {
		saveConsent('custom', {
			analytics: analyticsCheck ? analyticsCheck.checked : false,
			marketing: marketingCheck ? marketingCheck.checked : false,
		});
	}

	/**
	 * Open the preferences panel. The Preferences button hides while the
	 * panel is open; the banner's Reject All / Accept All / close still apply.
	 */
	function openPreferences() {
		prefsPanel.classList.add('is-visible');
		prefsPanel.setAttribute('aria-hidden', 'false');
		banner.classList.add('has-preferences-open');
	}

	// Initial state. Trackers stay unloaded until the visitor has decided;
	// a previous decision is re-applied on every page load. Event listeners
	// are always attached so the banner can be re-opened from the policy page.
	if (getCookie(COOKIE_NAME)) {
		const prefs = getPreferences();
		syncCheckboxes(prefs);
		applyPreferences(prefs);
	} else {
		banner.classList.add('is-visible');
		banner.setAttribute('aria-hidden', 'false');
	}

	// Event listeners.
	if (acceptBtn) {
		acceptBtn.addEventListener('click', acceptAll);
	}

	if (rejectBtn) {
		rejectBtn.addEventListener('click', rejectAll);
	}

	if (closeBtn) {
		closeBtn.addEventListener('click', function () {
			// Close without setting consent — banner will show again next visit.
			hideBanner();
		});
	}

	if (settingsBtn) {
		settingsBtn.addEventListener('click', openPreferences);
	}

	if (savePrefsBtn) {
		savePrefsBtn.addEventListener('click', savePreferences);
	}

})();
