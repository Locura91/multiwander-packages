/**
 * MultiWander Packages — offer slider.
 *
 * Matches the behaviour of the hand-built slider on the site today: dots,
 * 7-second autoplay, looping, pause on hover, swipe on touch, and 3/2/1 cards
 * across desktop/tablet/mobile.
 *
 * The track is a native scroll-snap row rather than a transformed flex strip.
 * That keeps real touch scrolling, momentum, keyboard support and the browser's
 * own accessibility handling for free — if this script never loads, the slider
 * still scrolls, it just loses the arrows and dots.
 *
 * No library, no jQuery.
 */
( function () {
	'use strict';

	var AUTOPLAY_MS = 7000;

	function reducedMotion() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	function setup( slider ) {
		var track = slider.querySelector( '.mwp-grid' );
		var prev = slider.querySelector( '.mwp-prev' );
		var next = slider.querySelector( '.mwp-next' );
		var dotsBox = slider.querySelector( '.mwp-dots' );

		if ( ! track ) {
			return;
		}

		var timer = null;
		var paused = false;

		function cards() {
			return track.querySelectorAll( '.mwp-card' );
		}

		function perView() {
			var w = window.innerWidth;
			if ( w <= 768 ) {
				return 1;
			}
			if ( w <= 1024 ) {
				return 2;
			}
			return 3;
		}

		function step() {
			var first = track.querySelector( '.mwp-card' );
			if ( ! first ) {
				return track.clientWidth;
			}
			var gap = parseFloat( getComputedStyle( track ).columnGap ) || 24;
			return first.getBoundingClientRect().width + gap;
		}

		function maxScroll() {
			return Math.max( 0, track.scrollWidth - track.clientWidth );
		}

		function pageCount() {
			var total = cards().length;
			return Math.max( 1, total - perView() + 1 );
		}

		function currentPage() {
			var s = step();
			if ( ! s ) {
				return 0;
			}
			return Math.min( pageCount() - 1, Math.round( track.scrollLeft / s ) );
		}

		function scrollToPage( index, smooth ) {
			var s = step();
			var target = Math.min( index * s, maxScroll() );
			if ( smooth === false || reducedMotion() ) {
				track.scrollLeft = target;
			} else {
				track.scrollTo( { left: target, behavior: 'smooth' } );
			}
		}

		function buildDots() {
			if ( ! dotsBox ) {
				return;
			}
			var count = pageCount();

			// Nothing to page through — no dots rather than a single dead dot.
			if ( count < 2 ) {
				dotsBox.innerHTML = '';
				return;
			}

			dotsBox.innerHTML = '';
			for ( var i = 0; i < count; i++ ) {
				var dot = document.createElement( 'button' );
				dot.type = 'button';
				dot.className = 'mwp-dot';
				dot.setAttribute( 'aria-label', 'Slide ' + ( i + 1 ) );
				dot.dataset.index = String( i );
				dot.addEventListener( 'click', function ( e ) {
					scrollToPage( parseInt( e.currentTarget.dataset.index, 10 ) );
					restart();
				} );
				dotsBox.appendChild( dot );
			}
			paint();
		}

		function paint() {
			var page = currentPage();

			if ( dotsBox ) {
				var dots = dotsBox.querySelectorAll( '.mwp-dot' );
				for ( var i = 0; i < dots.length; i++ ) {
					dots[ i ].classList.toggle( 'is-active', i === page );
					dots[ i ].setAttribute( 'aria-current', i === page ? 'true' : 'false' );
				}
			}

			// Arrows loop, so they are never disabled — only hidden when the
			// whole set already fits on screen.
			var overflowing = maxScroll() > 4;
			if ( prev ) {
				prev.hidden = ! overflowing;
			}
			if ( next ) {
				next.hidden = ! overflowing;
			}
			if ( dotsBox ) {
				dotsBox.hidden = ! overflowing;
			}
		}

		function go( delta ) {
			var count = pageCount();
			var page = currentPage() + delta;

			// Loop, the way the existing slider does.
			if ( page < 0 ) {
				page = count - 1;
			} else if ( page > count - 1 ) {
				page = 0;
			}

			scrollToPage( page );
		}

		function start() {
			if ( timer || paused || reducedMotion() || pageCount() < 2 ) {
				return;
			}
			timer = window.setInterval( function () {
				if ( ! document.hidden ) {
					go( 1 );
				}
			}, AUTOPLAY_MS );
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function restart() {
			stop();
			start();
		}

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				go( -1 );
				restart();
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				go( 1 );
				restart();
			} );
		}

		// Pause while the visitor is looking at or interacting with it.
		slider.addEventListener( 'mouseenter', function () {
			paused = true;
			stop();
		} );
		slider.addEventListener( 'mouseleave', function () {
			paused = false;
			start();
		} );
		slider.addEventListener( 'focusin', function () {
			paused = true;
			stop();
		} );
		slider.addEventListener( 'focusout', function () {
			paused = false;
			start();
		} );
		track.addEventListener( 'touchstart', stop, { passive: true } );
		track.addEventListener( 'touchend', restart, { passive: true } );

		track.addEventListener( 'scroll', function () {
			window.requestAnimationFrame( paint );
		}, { passive: true } );

		window.addEventListener( 'resize', function () {
			window.requestAnimationFrame( function () {
				buildDots();
				paint();
			} );
		}, { passive: true } );

		// Images finishing loading changes the track width.
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( function () {
				buildDots();
				paint();
			} ).observe( track );
		}

		buildDots();
		paint();
		start();
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
