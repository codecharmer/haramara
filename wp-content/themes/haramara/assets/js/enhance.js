/**
 * Haramara — progressive enhancement.
 *
 * Dependency-free. Three behaviours, all gated on prefers-reduced-motion:
 *   1. Scroll reveal — adds `.is-inview` to `.hm-reveal` elements (and to
 *      `.hm-hero` / cover figures for the scale-settle) as they enter the
 *      viewport, then stops observing.
 *   2. Header scrolled-state — toggles `.is-scrolled` on `.hm-header` past a
 *      small threshold, for the transparent→noche transition.
 *   3. The daylight arc — maps document scroll progress to `--hm-warmth`
 *      (0 → 1), driving the candle-glow veil that warms the page as the
 *      visitor moves through the café's day. Front page only.
 *
 * @package Haramara
 */
(function () {
	'use strict';

	var prefersReducedMotion =
		window.matchMedia &&
		window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ----------------------------------------------------------------------
	 * 1. Scroll reveal
	 * ------------------------------------------------------------------- */
	var revealEls = Array.prototype.slice.call(
		document.querySelectorAll('.hm-reveal, .hm-hero, .hm-cueva__foto, .hm-visita-cover')
	);

	if (revealEls.length) {
		if (prefersReducedMotion || !('IntersectionObserver' in window)) {
			revealEls.forEach(function (el) {
				el.classList.add('is-inview');
			});
		} else {
			// Anything already on screen at load reveals immediately — the
			// observer's bottom margin would otherwise leave first-viewport
			// elements (hero CTA, hours) invisible until the first scroll.
			var vh = window.innerHeight || document.documentElement.clientHeight;
			revealEls.forEach(function (el) {
				var rect = el.getBoundingClientRect();
				if (rect.top < vh && rect.bottom > 0) {
					el.classList.add('is-inview');
				}
			});

			var observer = new IntersectionObserver(
				function (entries, obs) {
					entries.forEach(function (entry) {
						if (entry.isIntersecting) {
							entry.target.classList.add('is-inview');
							obs.unobserve(entry.target);
						}
					});
				},
				{ rootMargin: '0px 0px -10% 0px', threshold: 0.12 }
			);

			revealEls.forEach(function (el) {
				observer.observe(el);
			});
		}
	}

	/* ----------------------------------------------------------------------
	 * 2. Header scrolled state
	 * ------------------------------------------------------------------- */
	var header = document.querySelector('.hm-header');
	var THRESHOLD = 8;

	/* ----------------------------------------------------------------------
	 * 3. Daylight arc (front page)
	 * ------------------------------------------------------------------- */
	var dia = document.querySelector('.hm-main--dia');
	var root = document.documentElement;

	var ticking = false;

	var applyState = function () {
		if (header) {
			header.classList.toggle('is-scrolled', window.scrollY > THRESHOLD);
		}

		if (dia && !prefersReducedMotion) {
			var max = document.documentElement.scrollHeight - window.innerHeight;
			var progress = max > 0 ? Math.min(1, Math.max(0, window.scrollY / max)) : 0;
			// Ease in gently: the warmth belongs to the second half of the day.
			root.style.setProperty('--hm-warmth', Math.pow(progress, 1.5).toFixed(3));
		}

		ticking = false;
	};

	applyState();

	window.addEventListener(
		'scroll',
		function () {
			if (!ticking) {
				window.requestAnimationFrame(applyState);
				ticking = true;
			}
		},
		{ passive: true }
	);
})();
