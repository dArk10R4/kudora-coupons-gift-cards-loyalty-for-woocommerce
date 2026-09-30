/**
 * Kudora — classic (shortcode) cart & checkout: gift card form and points box.
 */
( function ( $ ) {
	'use strict';
	if ( ! $ || typeof window.kudoraClassic === 'undefined' ) {
		return;
	}
	var cfg = window.kudoraClassic;

	function url( endpoint ) {
		return cfg.ajaxUrl.toString().replace( '%%endpoint%%', endpoint );
	}

	function refresh() {
		if ( $( 'form.woocommerce-checkout' ).length ) {
			$( document.body ).trigger( 'update_checkout' );
		} else {
			// Classic cart: WooCommerce's cart.js reloads the cart on this event.
			$( document ).trigger( 'wc_update_cart' );
		}
	}

	function showNotices( html, $target ) {
		var $wrap = $target && $target.length ? $target : $( '.woocommerce-notices-wrapper' ).first();
		$wrap.html( html );
	}

	function post( endpoint, data, $target ) {
		return $.post( url( endpoint ), data ).done( function ( html ) {
			showNotices( html, $target );
			refresh();
		} );
	}

	// Toggle on checkout.
	$( document.body ).on( 'click', '.kudora-show-gift-card', function ( e ) {
		e.preventDefault();
		var $box = $( '#kudora-gc-apply-checkout' );
		var open = $box.prop( 'hidden' );
		$box.prop( 'hidden', ! open );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			$box.find( 'input' ).trigger( 'focus' );
		}
	} );

	function applyGiftCard( $box ) {
		var code = $.trim( $box.find( '.kudora-gc-code' ).val() );
		if ( ! code ) {
			return;
		}
		$box.addClass( 'processing' );
		post( 'kudora_apply_gift_card', { security: cfg.gcNonce, code: code }, $box.find( '.kudora-gc-apply-notices' ) ).always( function () {
			$box.removeClass( 'processing' );
			$box.find( '.kudora-gc-code' ).val( '' );
		} );
	}

	$( document.body ).on( 'click', '.kudora-gc-apply-button', function ( e ) {
		e.preventDefault();
		applyGiftCard( $( this ).closest( '.kudora-gc-apply' ) );
	} );
	$( document.body ).on( 'keydown', '.kudora-gc-code', function ( e ) {
		if ( 13 === e.which ) {
			e.preventDefault();
			applyGiftCard( $( this ).closest( '.kudora-gc-apply' ) );
		}
	} );
	$( document.body ).on( 'click', '.kudora-remove-gift-card', function ( e ) {
		e.preventDefault();
		post( 'kudora_remove_gift_card', { security: cfg.gcNonce, code: $( this ).data( 'code' ) } );
	} );

	// Points.
	$( document.body ).on( 'click', '.kudora-apply-points', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( '.kudora-points-row' );
		post( 'kudora_apply_points', {
			security: cfg.pointsNonce,
			mode: $( this ).data( 'mode' ),
			points: $row.find( '.kudora-points-input' ).val() || 0,
		} );
	} );
	$( document.body ).on( 'keydown', '.kudora-points-input', function ( e ) {
		if ( 13 === e.which ) {
			e.preventDefault();
			$( this ).closest( '.kudora-points-row' ).find( '.kudora-apply-points' ).trigger( 'click' );
		}
	} );
	$( document.body ).on( 'click', '.kudora-remove-points', function ( e ) {
		e.preventDefault();
		post( 'kudora_remove_points', { security: cfg.pointsNonce } );
	} );
} )( window.jQuery );
