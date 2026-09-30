/**
 * Kudora — account pages: copy buttons and the gift card balance check form.
 */
( function () {
	'use strict';
	var i18n = window.kudoraAccount || { copied: 'Copied!' };

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.kudora-copy' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var text = btn.getAttribute( 'data-copy' ) || '';
		var done = function () {
			var label = btn.textContent;
			btn.textContent = i18n.copied;
			setTimeout( function () {
				btn.textContent = label;
			}, 1500 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, done );
		} else {
			var input = document.createElement( 'input' );
			input.value = text;
			document.body.appendChild( input );
			input.select();
			try {
				document.execCommand( 'copy' );
			} catch ( err ) {}
			document.body.removeChild( input );
			done();
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.matches || ! form.matches( '[data-kudora-balance]' ) ) {
			return;
		}
		e.preventDefault();
		var cfg = window.kudoraBalance || {};
		var out = form.querySelector( '.kudora-balance-result' );
		var code = form.querySelector( 'input[name="code"]' ).value.trim();
		if ( ! code || ! cfg.url ) {
			return;
		}
		out.textContent = '…';
		out.classList.remove( 'is-error' );
		fetch( cfg.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { code: code } ),
			credentials: 'same-origin',
		} )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					return { ok: r.ok, body: j };
				} );
			} )
			.then( function ( res ) {
				if ( res.ok ) {
					out.textContent = res.body.message + ( res.body.expires_on ? ' · ' + res.body.expires_on : '' );
				} else {
					out.textContent = res.body && res.body.message ? res.body.message : cfg.error;
					out.classList.add( 'is-error' );
				}
			} )
			.catch( function () {
				out.textContent = cfg.error;
				out.classList.add( 'is-error' );
			} );
	} );
} )();
