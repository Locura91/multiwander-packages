/**
 * MultiWander Packages — offer slider.
 *
 * No library. The track is a scroll-snap flex row that already works with
 * touch, trackpad and keyboard on its own; this only adds the arrow buttons
 * and keeps their disabled state honest. If the script fails to load the
 * slider still scrolls — it just loses the arrows.
 */
( function () {
	'use strict';

	function setup( slider ) {
		var track = slider.querySelector( '.mwp-grid' );
		var prev = slider.querySelector( '.mwp-prev' );
		var next = slider.querySelector( '.mwp-next' );

		if ( ! track || ! prev || ! next ) {
			return;
		}

		function step() {
			var card = track.querySelector( '.mwp-card' );
			if ( ! card ) {
				return track.clientWidth;
			}
			var gap = parseFloat( getComputedStyle( track ).columnGap ) || 24;
			return card.getBoundingClientRect().width + gap;
		}

		function update() {
			// 2px of slack: browsers round sub-pixel scroll positions, and
			// without it the "next" arrow can stay enabled at the very end.
			var max = track.scrollWidth - track.clientWidth - 2;
			prev.disabled = track.scrollLeft <= 2;
			next.disabled = track.scrollLeft >= max;

			// Nothing to scroll — hide both rather than showing dead controls.
			var overflowing = track.scrollWidth > track.clientWidth + 4;
			prev.hidden = ! overflowing;
			next.hidden = ! overflowing;
		}

		prev.addEventListener( 'click', function () {
			track.scrollBy( { left: -step(), behavior: 'smooth' } );
		} );

		next.addEventListener( 'click', function () {
			track.scrollBy( { left: step(), behavior: 'smooth' } );
		} );

		track.addEventListener( 'scroll', function () {
			window.requestAnimationFrame( update );
		}, { passive: true } );

		window.addEventListener( 'resize', function () {
			window.requestAnimationFrame( update );
		}, { passive: true } );

		// Images change the track width as they load.
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( update ).observe( track );
		}

		update();
	}

	function init() {
		var sliders = document.querySelectorAll( '.mwp-slider' );
		for ( var i = 0; i < sliders.length; i++ ) {
			setup( sliders[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
