/**
 * Pop-ups - Frontend JavaScript
 *
 * Displays enabled pop-ups as modals on the frontend.
 * Uses localStorage with timestamps to respect dismissal durations.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'apppresser_pop_ups_dismissed';

	var popUps = window.apppresserPopUpsFrontend.popUps || [];
	var currentPage = window.apppresserPopUpsFrontend.currentPage || 0;

	if (!popUps.length) {
		return;
	}

	// Duration map: value -> milliseconds.
	var DURATIONS = {
		'12_hours': 12 * 60 * 60 * 1000,
		daily: 24 * 60 * 60 * 1000,
		weekly: 7 * 24 * 60 * 60 * 1000,
		monthly: 30 * 24 * 60 * 60 * 1000,
	};

	var dismissed = loadDismissed();
	var now = Date.now();

	popUps.forEach(function (popUp) {
		if (!popUp.enabled) {
			return;
		}

		// Check trigger conditions.
		if (popUp.trigger === 'on_page') {
			var pages = (popUp.pages || []).map(Number);
			var pageId = Number(currentPage);
			// If no pages selected or current page doesn't match, skip.
			if (!pages.length || pages.indexOf(pageId) === -1) {
				return;
			}
		}

		// 'every_page_load' means always show — never check dismissal.
		if (popUp.dismissal !== 'every_page_load') {
			var dismissedAt = dismissed[popUp.id];
			var duration = DURATIONS[popUp.dismissal];

			// If we have a valid duration and the dismissal hasn't expired, skip.
			if (duration && dismissedAt && now - dismissedAt < duration) {
				return;
			}
		}

		showPopUp(popUp);
	});

	/**
	 * Load dismissed timestamps from localStorage.
	 *
	 * @return {Object} Map of pop-up ID -> timestamp.
	 */
	function loadDismissed() {
		try {
			var raw = localStorage.getItem(STORAGE_KEY);
			return raw ? JSON.parse(raw) : {};
		} catch (e) {
			return {};
		}
	}

	/**
	 * Save a pop-up dismissal timestamp.
	 *
	 * @param {string} id Pop-up ID.
	 */
	function saveDismissed(id) {
		var dismissed = loadDismissed();
		dismissed[id] = Date.now();
		try {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(dismissed));
		} catch (e) {
			// Storage full or unavailable.
		}
	}

	/**
	 * Build and show a pop-up modal.
	 *
	 * @param {Object} popUp Pop-up data.
	 */
	function showPopUp(popUp) {
		var overlay = document.createElement('div');
		overlay.className = 'appp-popup-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-label', 'Pop-up');

		overlay.innerHTML =
			'<div class="appp-popup">' +
				'<button class="appp-popup__close" aria-label="Close pop-up">&times;</button>' +
				'<div class="appp-popup__content">' + popUp.content + '</div>' +
			'</div>';

		document.body.appendChild(overlay);

		// Fade in.
		requestAnimationFrame(function () {
			overlay.classList.add('is-visible');
		});

		// Close button.
		var closeBtn = overlay.querySelector('.appp-popup__close');
		closeBtn.addEventListener('click', function () {
			closePopUp(overlay, popUp);
		});

		// Close on overlay click.
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				closePopUp(overlay, popUp);
			}
		});

		// Close on Escape key.
		document.addEventListener('keydown', function handleEsc(e) {
			if (e.key === 'Escape') {
				closePopUp(overlay, popUp);
				document.removeEventListener('keydown', handleEsc);
			}
		});
	}

	/**
	 * Close a pop-up and record the dismissal timestamp.
	 *
	 * @param {HTMLElement} overlay The overlay element.
	 * @param {Object}      popUp   Pop-up data.
	 */
	function closePopUp(overlay, popUp) {
		overlay.classList.remove('is-visible');
		overlay.addEventListener('transitionend', function () {
			if (overlay.parentNode) {
				overlay.parentNode.removeChild(overlay);
			}
		});

		// Only record dismissal if not set to show every page load.
		if (popUp.dismissal !== 'every_page_load') {
			saveDismissed(popUp.id);
		}
	}
})();
