/**
 * Cookie Consent Banner - Frontend JavaScript
 */
(function () {
	'use strict';

	const COOKIE_NAME = apppresserCookieConsent.cookieName || 'apppresser_cookie_consent';
	const PREFS_COOKIE = apppresserCookieConsent.prefsCookieName || 'apppresser_cookie_prefs';
	const DURATION = parseInt(apppresserCookieConsent.duration, 10) || 30;

	const banner = document.getElementById('apppresser-cookie-banner');
	if (!banner) return;

	// Elements.
	const closeBtn = document.getElementById('apppresser-cookie-close-btn');
	const acceptBtn = document.getElementById('apppresser-cookie-accept-btn');
	const rejectBtn = document.getElementById('apppresser-cookie-reject-btn');
	const settingsBtn = document.getElementById('apppresser-cookie-settings-btn');
	const prefsPanel = document.getElementById('apppresser-cookie-preferences');
	const rejectAllBtn = document.getElementById('apppresser-cookie-reject-all-btn');
	const savePrefsBtn = document.getElementById('apppresser-cookie-save-prefs-btn');
	const analyticsCheck = document.getElementById('apppresser-cookie-analytics');
	const marketingCheck = document.getElementById('apppresser-cookie-marketing');

	// Already consented? Don't auto-show, but keep event listeners alive
	// so the banner can be re-opened from the cookie policy page.
	var alreadyConsented = !!getCookie(COOKIE_NAME);

	if (!alreadyConsented) {
		// Show the banner.
		banner.classList.add('is-visible');
		banner.setAttribute('aria-hidden', 'false');
	}

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
	 * Hide the banner and preferences panel.
	 */
	function hideBanner() {
		banner.classList.remove('is-visible');
		banner.setAttribute('aria-hidden', 'true');
		if (prefsPanel) {
			prefsPanel.classList.remove('is-visible');
			prefsPanel.setAttribute('aria-hidden', 'true');
		}
		if (settingsBtn && settingsBtn.getAttribute('data-original-text')) {
			settingsBtn.textContent = settingsBtn.getAttribute('data-original-text');
		}
	}

	/**
	 * Accept all cookies.
	 */
	function acceptAll() {
		setCookie(COOKIE_NAME, 'accepted', DURATION);
		setCookie(PREFS_COOKIE, JSON.stringify({ analytics: true, marketing: true }), DURATION);
		hideBanner();
	}

	/**
	 * Reject all non-essential cookies.
	 */
	function rejectAll() {
		setCookie(COOKIE_NAME, 'rejected', DURATION);
		setCookie(PREFS_COOKIE, JSON.stringify({ analytics: false, marketing: false }), DURATION);
		hideBanner();
	}

	/**
	 * Save custom preferences.
	 */
	function savePreferences() {
		const prefs = {
			analytics: analyticsCheck ? analyticsCheck.checked : false,
			marketing: marketingCheck ? marketingCheck.checked : false,
		};
		setCookie(COOKIE_NAME, 'custom', DURATION);
		setCookie(PREFS_COOKIE, JSON.stringify(prefs), DURATION);
		hideBanner();
	}

	/**
	 * Toggle preferences panel.
	 */
	function togglePreferences() {
		const isVisible = prefsPanel.classList.contains('is-visible');
		if (isVisible) {
			prefsPanel.classList.remove('is-visible');
			prefsPanel.setAttribute('aria-hidden', 'true');
			settingsBtn.textContent = settingsBtn.getAttribute('data-original-text') || settingsBtn.textContent;
		} else {
			prefsPanel.classList.add('is-visible');
			prefsPanel.setAttribute('aria-hidden', 'false');
			// Store original text so we can restore it.
			if (!settingsBtn.getAttribute('data-original-text')) {
				settingsBtn.setAttribute('data-original-text', settingsBtn.textContent);
			}
			settingsBtn.textContent = '✕ ' + (settingsBtn.getAttribute('data-original-text') || 'Close');
		}
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
		settingsBtn.addEventListener('click', togglePreferences);
	}

	if (rejectAllBtn) {
		rejectAllBtn.addEventListener('click', rejectAll);
	}

	if (savePrefsBtn) {
		savePrefsBtn.addEventListener('click', savePreferences);
	}

})();
