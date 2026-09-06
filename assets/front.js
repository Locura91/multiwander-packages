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
