/**
 * MultiWander Packages — "See more" for long descriptions.
 *
 * Travel Compositor's destination and tour copy runs to several paragraphs.
 * Shown in full it buries the itinerary; so each block is collapsed to a
 * single teaser line with a button to open it.
 *
 * Progressive enhancement on purpose: the clamp is applied BY THIS SCRIPT, not
 * by the stylesheet. With JavaScript off, or if this file fails to load, the
 * text simply shows in full — never truncated with no way to read the rest.
 */
( function () {
	'use strict';

	function collapse( block ) {
		// Nothing to hide: leave short copy alone rather than adding a button
		// that reveals one extra word.
		var full = block.scrollHeight;
		block.classList.add( 'is-clamped' );

		if ( block.scrollHeight >= full - 4 ) {
			block.classList.remove( 'is-clamped' );
			return;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'mwp-more';
		button.textContent = block.dataset.more || 'See more';
		button.setAttribute( 'aria-expanded', 'false' );

		block.insertAdjacentElement( 'afterend', button );

		button.addEventListener( 'click', function () {
			var open = block.classList.toggle( 'is-clamped' ) === false;

			button.textContent = open
				? ( block.dataset.less || 'Show less' )
				: ( block.dataset.more || 'See more' );

			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			// Closing from far down the page would otherwise leave the reader
			// staring at whatever happens to scroll into view.
			if ( ! open ) {
				var top = block.getBoundingClientRect().top;
				if ( top < 0 ) {
					block.scrollIntoView( { block: 'nearest' } );
				}
			}
		} );
	}

	function init() {
		var blocks = document.querySelectorAll( '[data-mwp-more]' );
		for ( var i = 0; i < blocks.length; i++ ) {
			collapse( blocks[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

/**
 * MultiWander Packages — date and traveller picker.
 *
 * Mirrors the selector the booking engine shows after you arrive there, so the
 * choice is made once, on our page, rather than the visitor landing on a modal
 * asking the same questions again.
 *
 * Limits match the engine: 9 travellers, 4 rooms, at least one adult, and
 * never more rooms than adults.
 *
 * The chosen values are appended to the booking link as query parameters. If
 * the engine ignores them it simply asks for the dates itself, exactly as it
 * does today — nothing is lost.
 */
( function () {
	'use strict';

	function setup( picker ) {
		var url = picker.dataset.url;
		var maxPax = parseInt( picker.dataset.maxPax, 10 ) || 9;
		var maxRooms = parseInt( picker.dataset.maxRooms, 10 ) || 4;

		var dateField = picker.querySelector( '.mwp-date' );
		var toggle = picker.querySelector( '.mwp-party-toggle' );
		var panel = picker.querySelector( '.mwp-party' );
		var summary = picker.querySelector( '.mwp-party-summary' );
		var done = picker.querySelector( '.mwp-party-done' );

		if ( ! url || ! toggle || ! panel ) {
			return;
		}

		var counters = {};
		var nodes = picker.querySelectorAll( '[data-counter]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			counters[ node.dataset.counter ] = {
				node: node,
				out: node.querySelector( '.mwp-value' ),
				value: parseInt( node.querySelector( '.mwp-value' ).textContent, 10 ) || 0
			};
		}

		function value( key ) {
			return counters[ key ] ? counters[ key ].value : 0;
		}

		/** Lower and upper bound for each counter, given the others. */
		function bounds( key ) {
			var adults = value( 'adults' );
			var children = value( 'children' );
			var rooms = value( 'rooms' );

			if ( 'rooms' === key ) {
				// Never more rooms than adults — a room needs someone in it.
				return { min: 1, max: Math.min( maxRooms, Math.max( 1, adults ) ) };
			}
			if ( 'adults' === key ) {
				return { min: Math.max( 1, rooms ), max: maxPax - children };
			}
			return { min: 0, max: maxPax - adults };
		}

		function label() {
			var rooms = value( 'rooms' );
			var adults = value( 'adults' );
			var children = value( 'children' );

			var lang = document.documentElement.lang || '';
			var pl = lang.indexOf( 'pl' ) === 0;

			var parts = [];
			parts.push( rooms + ' ' + ( pl ? ( 1 === rooms ? 'pokój' : 'pokoje' ) : ( 1 === rooms ? 'room' : 'rooms' ) ) );
			parts.push( adults + ' ' + ( pl ? ( 1 === adults ? 'dorosły' : 'dorośli' ) : ( 1 === adults ? 'adult' : 'adults' ) ) );
			if ( children > 0 ) {
				parts.push( children + ' ' + ( pl ? ( 1 === children ? 'dziecko' : 'dzieci' ) : ( 1 === children ? 'child' : 'children' ) ) );
			}
			return parts.join( ', ' );
		}

		function paint() {
			for ( var key in counters ) {
				if ( ! Object.prototype.hasOwnProperty.call( counters, key ) ) {
					continue;
				}
				var c = counters[ key ];
				var b = bounds( key );

				// Keep every counter inside its bounds after any change.
				if ( c.value < b.min ) { c.value = b.min; }
				if ( c.value > b.max ) { c.value = b.max; }

				c.out.textContent = c.value;
				c.node.querySelector( '.mwp-minus' ).disabled = c.value <= b.min;
				c.node.querySelector( '.mwp-plus' ).disabled = c.value >= b.max;
			}

			if ( summary ) {
				summary.textContent = label();
			}

			updateLinks();
		}

		function updateLinks() {
			var params = [];
			if ( dateField && dateField.value ) {
				params.push( 'departureDate=' + encodeURIComponent( dateField.value ) );
			}
			params.push( 'adults=' + value( 'adults' ) );
			params.push( 'children=' + value( 'children' ) );
			params.push( 'rooms=' + value( 'rooms' ) );

			var joiner = url.indexOf( '?' ) === -1 ? '?' : '&';
			var href = url + joiner + params.join( '&' );

			var links = document.querySelectorAll( '.mwp-book' );
			for ( var i = 0; i < links.length; i++ ) {
				links[ i ].href = href;
			}
		}

		picker.addEventListener( 'click', function ( e ) {
			var plus = e.target.classList.contains( 'mwp-plus' );
			var minus = e.target.classList.contains( 'mwp-minus' );
			if ( ! plus && ! minus ) {
				return;
			}
			e.preventDefault();

			var row = e.target.closest( '[data-counter]' );
			if ( ! row ) {
				return;
			}

			var c = counters[ row.dataset.counter ];
			c.value += plus ? 1 : -1;
			paint();
		} );

		toggle.addEventListener( 'click', function () {
			var open = panel.hidden;
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		if ( done ) {
			done.addEventListener( 'click', function () {
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.focus();
			} );
		}

		// Clicking away closes the panel, as it does in the booking engine.
		document.addEventListener( 'click', function ( e ) {
			if ( ! panel.hidden && ! picker.contains( e.target ) ) {
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! panel.hidden ) {
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.focus();
			}
		} );

		if ( dateField ) {
			dateField.addEventListener( 'change', updateLinks );
		}

		paint();
	}

	/**
	 * Opening and closing the overlay.
	 *
	 * Focus moves into the panel on open and back to the button on close, and
	 * the page behind is locked so the modal is the only thing that scrolls —
	 * the small courtesies that separate a dialog from a floating div.
	 */
	function wireModals() {
		var opener = document.querySelectorAll( '.mwp-open-picker' );
		var lastFocus = null;

		function open( modal, trigger ) {
			lastFocus = trigger || null;
			modal.hidden = false;
			document.body.style.overflow = 'hidden';

			var focusable = modal.querySelector( '.mwp-date, button, a' );
			if ( focusable ) {
				focusable.focus();
			}
		}

		function close( modal ) {
			modal.hidden = true;
			document.body.style.overflow = '';
			if ( lastFocus ) {
				lastFocus.focus();
				lastFocus = null;
			}
		}

		for ( var i = 0; i < opener.length; i++ ) {
			opener[ i ].addEventListener( 'click', function ( e ) {
				var modal = document.getElementById( e.currentTarget.dataset.target );
				if ( modal ) {
					open( modal, e.currentTarget );
				}
			} );
		}

		document.addEventListener( 'click', function ( e ) {
			var closer = e.target.closest( '[data-mwp-close]' );
			if ( ! closer ) {
				return;
			}
			var modal = closer.closest( '.mwp-modal' );
			if ( modal ) {
				close( modal );
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' !== e.key ) {
				return;
			}
			var open_modals = document.querySelectorAll( '.mwp-modal:not([hidden])' );
			for ( var j = 0; j < open_modals.length; j++ ) {
				// The traveller panel closes first; a second Escape closes the
				// modal, which is what people expect from nested popovers.
				var party = open_modals[ j ].querySelector( '.mwp-party' );
				if ( party && ! party.hidden ) {
					continue;
				}
				close( open_modals[ j ] );
			}
		} );
	}

	function initPickers() {
		var pickers = document.querySelectorAll( '[data-mwp-picker]' );
		for ( var i = 0; i < pickers.length; i++ ) {
			setup( pickers[ i ] );
		}
		wireModals();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initPickers );
	} else {
		initPickers();
	}
}() );
