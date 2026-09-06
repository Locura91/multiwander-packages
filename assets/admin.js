/**
 * MultiWander Packages — the package ID fields.
 *
 * One small numeric box per package instead of a free-text area: harder to
 * mistype, and the order of the boxes is the order the offers appear.
 *
 * Everything here is a convenience. With JavaScript off the fields still
 * submit and save correctly — you just can't add or remove rows without
 * saving the page in between.
 */
( function () {
	'use strict';

	var wrap = document.querySelector( '[data-mwp-ids]' );
	if ( ! wrap ) {
		return;
	}

	function rows() {
		return wrap.querySelectorAll( '.mwp-id-row' );
	}

	/** Keep the "1. 2. 3." labels correct after any add or remove. */
	function renumber() {
		var all = rows();
		for ( var i = 0; i < all.length; i++ ) {
			var num = all[ i ].querySelector( '.mwp-id-num' );
			if ( num ) {
				num.textContent = ( i + 1 ) + '.';
			}
		}
	}

	/** Always leave exactly one empty row at the end to type into. */
	function ensureSpare() {
		var all = rows();
		if ( ! all.length ) {
			addRow();
			return;
		}
		var last = all[ all.length - 1 ].querySelector( '.mwp-id-input' );
		if ( last && last.value.trim() !== '' ) {
			addRow();
		}
	}

	function addRow( focus ) {
		var all = rows();
		if ( ! all.length ) {
			return null;
		}

		var clone = all[ all.length - 1 ].cloneNode( true );
		var input = clone.querySelector( '.mwp-id-input' );
		var note = clone.querySelector( '.mwp-id-note' );

		if ( input ) {
			input.value = '';
		}
		if ( note ) {
			note.innerHTML = '';
		}

		wrap.appendChild( clone );
		renumber();

		if ( focus && input ) {
			input.focus();
		}

		return clone;
	}

	/** Strip anything that isn't a digit, pulling the ID out of a pasted URL. */
	function clean( value ) {
		value = String( value ).trim();

		if ( /^\d*$/.test( value ) ) {
			return value;
		}

		var m = value.match( /\/idea\/(\d{4,})/ ) || value.match( /[?&]id=(\d{4,})/ );
		if ( m ) {
			return m[ 1 ];
		}

		// Fall back to the longest run of digits — covers most pasted links.
		var runs = value.match( /\d{4,}/g );
		if ( runs ) {
			runs.sort( function ( a, b ) {
				return b.length - a.length;
			} );
			return runs[ 0 ];
		}

		return value.replace( /\D/g, '' );
	}

	wrap.addEventListener( 'input', function ( e ) {
		if ( ! e.target.classList.contains( 'mwp-id-input' ) ) {
			return;
		}
		var cleaned = clean( e.target.value );
		if ( cleaned !== e.target.value ) {
			e.target.value = cleaned;
		}
		ensureSpare();
	} );

	// Paste often carries a whole URL; normalise it once the value has landed.
	wrap.addEventListener( 'paste', function ( e ) {
		if ( ! e.target.classList.contains( 'mwp-id-input' ) ) {
			return;
		}
		window.setTimeout( function () {
			e.target.value = clean( e.target.value );
			ensureSpare();
		}, 0 );
	} );

	wrap.addEventListener( 'click', function ( e ) {
		if ( ! e.target.classList.contains( 'mwp-id-remove' ) ) {
			return;
		}
		e.preventDefault();

		var row = e.target.closest( '.mwp-id-row' );
		if ( ! row ) {
			return;
		}

		// Never remove the last row — clear it instead, so there is always a
		// field to type into.
		if ( rows().length === 1 ) {
			var only = row.querySelector( '.mwp-id-input' );
			if ( only ) {
				only.value = '';
				only.focus();
			}
			return;
		}

		row.parentNode.removeChild( row );
		renumber();
		ensureSpare();
	} );

	// Enter moves to the next box rather than submitting the whole page.
	wrap.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key || ! e.target.classList.contains( 'mwp-id-input' ) ) {
			return;
		}
		e.preventDefault();
		ensureSpare();

		var all = rows();
		for ( var i = 0; i < all.length; i++ ) {
			if ( all[ i ].contains( e.target ) && all[ i + 1 ] ) {
				var next = all[ i + 1 ].querySelector( '.mwp-id-input' );
				if ( next ) {
					next.focus();
				}
				return;
			}
		}
	} );

	var addButton = document.querySelector( '.mwp-id-add' );
	if ( addButton ) {
		addButton.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			addRow( true );
		} );
	}

	renumber();
	ensureSpare();
}() );
