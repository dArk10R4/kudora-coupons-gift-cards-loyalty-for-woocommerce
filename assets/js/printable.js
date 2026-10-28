/**
 * Kudora — printable gift card: the "Print" button.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( '.kudora-print-button' ) ) {
			e.preventDefault();
			window.print();
		}
	} );
} )();
