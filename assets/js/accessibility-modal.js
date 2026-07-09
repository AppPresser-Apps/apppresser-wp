/**
 * Accessibility Modal - Frontend JavaScript
 *
 * Handles the accessibility trigger button, modal, toggle switches,
 * and localStorage persistence.
 */
(function () {
	'use strict';

	const STORAGE_KEY = 'apppresser_accessibility_settings';

	const trigger = document.getElementById('appp-a11y-trigger');
	if (!trigger) return;

	// Build modal HTML dynamically.
	const modalHTML = buildModalHTML();
	document.body.insertAdjacentHTML('beforeend', modalHTML);

	const overlay = document.getElementById('appp-a11y-overlay');
	const modal = document.getElementById('appp-a11y-modal');
	const closeBtn = document.getElementById('appp-a11y-close-btn');
	const saveBtn = document.getElementById('appp-a11y-save-btn');
	const resetBtn = document.getElementById('appp-a11y-reset-btn');

	// Load saved settings or use defaults (all off).
	const savedSettings = loadSettings();
	applySettings(savedSettings);
	syncToggles(savedSettings);

	/**
	 * Build the modal HTML string.
	 *
	 * @return {string} Modal HTML.
	 */
	function buildModalHTML() {
		var options = window.apppresserAccessibilityFrontend.options || {};
		var settings = window.apppresserAccessibilityFrontend.settings || {};
		var keys = Object.keys(options);
		var itemsHTML = '';

		keys.forEach(function (key) {
			// Only show options that are enabled in the admin settings.
			if (!settings[key]) {
				return;
			}

			var opt = options[key];
			itemsHTML +=
				'<div class="appp-a11y-setting">' +
					'<div class="appp-a11y-setting__header">' +
						'<h4 class="appp-a11y-setting__label">' + escHTML(opt.label) + '</h4>' +
						'<label class="appp-a11y-toggle">' +
							'<input type="checkbox" id="appp-a11y-' + escHTML(key) + '" data-key="' + escHTML(key) + '">' +
							'<span class="appp-a11y-toggle__slider"></span>' +
						'</label>' +
					'</div>' +
					'<p class="appp-a11y-setting__help">' + escHTML(opt.help) + '</p>' +
				'</div>';
		});

		return (
			'<div class="appp-a11y-overlay" id="appp-a11y-overlay" aria-hidden="true">' +
				'<div class="appp-a11y-modal" id="appp-a11y-modal" role="dialog" aria-modal="true" aria-labelledby="appp-a11y-modal-title">' +
					'<div class="appp-a11y-modal__header">' +
						'<h2 class="appp-a11y-modal__title" id="appp-a11y-modal-title">Accessibility Settings</h2>' +
						'<button class="appp-a11y-modal__close" id="appp-a11y-close-btn" aria-label="Close accessibility settings">&times;</button>' +
					'</div>' +
					'<div class="appp-a11y-modal__body">' +
						'<p class="appp-a11y-modal__intro">Adjust the settings below to improve your browsing experience. Your preferences are saved to this browser only.</p>' +
						'<div class="appp-a11y-settings">' +
							itemsHTML +
						'</div>' +
					'</div>' +
					'<div class="appp-a11y-modal__actions">' +
						'<button class="appp-a11y-modal__btn appp-a11y-modal__btn--save" id="appp-a11y-save-btn">Save Settings</button>' +
						'<button class="appp-a11y-modal__btn appp-a11y-modal__btn--reset" id="appp-a11y-reset-btn">Reset All</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string.
	 */
	function escHTML(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	/**
	 * Load settings from localStorage.
	 *
	 * @return {Object} Settings keyed by option key.
	 */
	function loadSettings() {
		try {
			var raw = localStorage.getItem(STORAGE_KEY);
			return raw ? JSON.parse(raw) : {};
		} catch (e) {
			return {};
		}
	}

	/**
	 * Save settings to localStorage.
	 *
	 * @param {Object} settings Settings keyed by option key.
	 */
	function saveSettings(settings) {
		try {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
		} catch (e) {
			// Storage full or unavailable.
		}
	}

	/**
	 * Sync toggle checkboxes to match a settings object.
	 *
	 * @param {Object} settings Settings keyed by option key.
	 */
	function syncToggles(settings) {
		var checkboxes = document.querySelectorAll('.appp-a11y-toggle input[data-key]');
		checkboxes.forEach(function (cb) {
			cb.checked = !!settings[cb.getAttribute('data-key')];
		});
	}

	/**
	 * Read current toggle states into a settings object.
	 *
	 * @return {Object} Settings keyed by option key.
	 */
	function readToggles() {
		var settings = {};
		var checkboxes = document.querySelectorAll('.appp-a11y-toggle input[data-key]');
		checkboxes.forEach(function (cb) {
			settings[cb.getAttribute('data-key')] = cb.checked;
		});
		return settings;
	}

	/**
	 * Apply accessibility settings to the document.
	 *
	 * @param {Object} settings Settings keyed by option key.
	 */
	function applySettings(settings) {
		var classes = document.documentElement.classList;

		Object.keys(settings).forEach(function (key) {
			var cls = 'appp-a11y-' + key.replace(/_/g, '-');
			if (settings[key]) {
				classes.add(cls);
			} else {
				classes.remove(cls);
			}
		});
	}

	/**
	 * Open the modal.
	 */
	function openModal() {
		overlay.classList.add('is-visible');
		overlay.setAttribute('aria-hidden', 'false');
		modal.focus();

		// Trap focus inside modal.
		document.addEventListener('keydown', handleKeyDown);
	}

	/**
	 * Close the modal.
	 */
	function closeModal() {
		overlay.classList.remove('is-visible');
		overlay.setAttribute('aria-hidden', 'true');
		trigger.focus();

		document.removeEventListener('keydown', handleKeyDown);
	}

	/**
	 * Handle keyboard events for focus trapping and Escape.
	 *
	 * @param {KeyboardEvent} e
	 */
	function handleKeyDown(e) {
		if (e.key === 'Escape') {
			closeModal();
			return;
		}

		if (e.key === 'Tab') {
			var focusable = modal.querySelectorAll(
				'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
			);
			var first = focusable[0];
			var last = focusable[focusable.length - 1];

			if (e.shiftKey) {
				if (document.activeElement === first) {
					e.preventDefault();
					last.focus();
				}
			} else {
				if (document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			}
		}
	}

	/**
	 * Save current toggle states and apply them.
	 */
	function handleSave() {
		var settings = readToggles();
		saveSettings(settings);
		applySettings(settings);
		closeModal();
	}

	/**
	 * Reset all toggles to off, clear storage, and remove classes.
	 */
	function handleReset() {
		var emptySettings = {};
		var checkboxes = document.querySelectorAll('.appp-a11y-toggle input[data-key]');
		checkboxes.forEach(function (cb) {
			cb.checked = false;
			emptySettings[cb.getAttribute('data-key')] = false;
		});

		saveSettings(emptySettings);
		applySettings(emptySettings);
		closeModal();
	}

	// Event listeners.
	trigger.addEventListener('click', openModal);

	closeBtn.addEventListener('click', closeModal);

	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) {
			closeModal();
		}
	});

	saveBtn.addEventListener('click', handleSave);
	resetBtn.addEventListener('click', handleReset);

})();
