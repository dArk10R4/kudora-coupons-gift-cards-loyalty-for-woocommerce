/**
 * Kudora — admin screens: order box actions, confirmations, repeaters, design
 * preview + media picker, and field toggles on the coupon and product screens.
 */
( function ( $ ) {
	'use strict';
	if ( ! $ ) {
		return;
	}
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};
	var cfg = window.kudoraAdmin || {};

	// Confirm destructive links.
	$( document ).on( 'click', '.kudora-confirm', function ( e ) {
		if ( ! window.confirm( $( this ).data( 'confirm' ) || __( 'Are you sure?', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ) {
			e.preventDefault();
		}
	} );

	// Order box: refund to card / store credit.
	$( document ).on( 'click', '.kudora-order-do', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $box = $btn.closest( '.kudora-order-box' );
		var $input = $btn.siblings( 'input' );
		var amount = $input.val() || $input.attr( 'placeholder' ) || '';
		var $status = $box.find( '.kudora-order-status' );
		$btn.prop( 'disabled', true );
		$.post( cfg.ajaxUrl, {
			action: 'kudora_order_action',
			nonce: cfg.nonce,
			order_id: $box.data( 'order' ),
			do: $btn.data( 'do' ),
			card_id: $btn.data( 'card' ) || 0,
			amount: amount,
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$status.text( res.data.message );
					window.location.reload();
				} else {
					$status.text( res && res.data ? res.data.message : __( 'Something went wrong.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
				}
			} )
			.fail( function ( xhr ) {
				var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : __( 'Something went wrong.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
				$status.text( msg );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// Clean a cloned block of select2 leftovers, reset values, re-init enhanced selects.
	function freshClone( $el ) {
		var $clone = $el.clone();
		$clone.find( '.select2-container' ).remove();
		$clone.find( 'select' ).removeClass( 'select2-hidden-accessible enhanced' ).removeAttr( 'data-select2-id tabindex aria-hidden' ).find( 'option' ).removeAttr( 'data-select2-id' );
		$clone.find( 'input[type="text"], input[type="number"], input[type="hidden"]' ).not( '[type="color"]' ).val( '' );
		$clone.find( 'select.wc-product-search' ).empty();
		$clone.find( 'select' ).not( '.wc-product-search' ).prop( 'selectedIndex', 0 );
		return $clone;
	}

	// Table repeaters (bulk ranges, tiers).
	$( document ).on( 'click', '.kudora-add-row', function ( e ) {
		e.preventDefault();
		var $table = $( $( this ).data( 'target' ) ).first();
		var $last = $table.find( 'tbody tr' ).last();
		var $row = freshClone( $last );
		$table.find( 'tbody' ).append( $row );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		$row.find( 'input' ).first().trigger( 'focus' );
	} );
	$( document ).on( 'click', '.kudora-remove-row', function ( e ) {
		e.preventDefault();
		var $rows = $( this ).closest( 'tbody' ).find( 'tr' );
		if ( $rows.length > 1 ) {
			$( this ).closest( 'tr' ).remove();
		} else {
			$rows.find( 'input[type="text"], input[type="number"]' ).val( '' );
		}
	} );

	// Card repeaters (rewards).
	$( document ).on( 'click', '.kudora-add-card', function ( e ) {
		e.preventDefault();
		var $wrap = $( '[data-kudora-repeat-cards]' );
		var $card = freshClone( $wrap.find( '.kudora-reward-card' ).last() );
		$card.find( 'select[name="rewards[status][]"]' ).val( '1' );
		$wrap.append( $card );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		$card.find( 'input[type="text"]' ).first().trigger( 'focus' );
	} );
	$( document ).on( 'click', '.kudora-remove-card', function ( e ) {
		e.preventDefault();
		var $cards = $( '[data-kudora-repeat-cards] .kudora-reward-card' );
		if ( $cards.length > 1 ) {
			$( this ).closest( '.kudora-reward-card' ).remove();
		} else {
			$cards.find( 'input[type="text"], input[type="number"]' ).val( '' );
		}
	} );

	// Design preview + media picker.
	function previewDesign() {
		var $form = $( '.kudora-design-form' );
		if ( ! $form.length ) {
			return;
		}
		var $card = $form.find( '.kudora-design-preview' );
		$card.css( {
			backgroundColor: $form.find( '[data-kudora-preview="bg"]' ).val(),
			color: $form.find( '[data-kudora-preview="fg"]' ).val(),
		} );
	}
	$( document ).on( 'input change', '.kudora-design-form input[type="color"]', previewDesign );
	$( document ).on( 'click', '.kudora-pick-image', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = window.wp.media( { title: __( 'Card background', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			$( '.kudora-image-id' ).val( att.id );
			var url = att.sizes && att.sizes.large ? att.sizes.large.url : att.url;
			$( '.kudora-design-preview' ).css( { backgroundImage: 'url(' + url + ')', backgroundSize: 'cover', backgroundPosition: 'center' } );
		} );
		frame.open();
	} );
	$( document ).on( 'click', '.kudora-clear-image', function ( e ) {
		e.preventDefault();
		$( '.kudora-image-id' ).val( '0' );
		$( '.kudora-design-preview' ).css( { backgroundImage: 'none' } );
	} );

	// BXGY: "reward from the same products" hides the second scope; value only for % / amount.
	function toggleBxgy() {
		$( '.kudora-bxgy-fields' ).each( function () {
			var $f = $( this );
			$f.find( '.kudora-get-scope' ).toggle( ! $f.find( '.kudora-get-same' ).is( ':checked' ) );
			$f.find( '.kudora-bxgy-value' ).toggle( 'free' !== $f.find( '.kudora-bxgy-reward' ).val() );
		} );
	}
	$( document ).on( 'change', '.kudora-get-same, .kudora-bxgy-reward', toggleBxgy );

	// Coupon screen: show fields for the chosen discount type.
	function toggleCouponFields() {
		var type = $( '#discount_type' ).val();
		if ( ! type ) {
			return;
		}
		$( '.kudora-show-percent' ).toggle( 'percent' === type );
		$( '.kudora-show-free-product' ).toggle( 'kudora_free_product' === type );
		$( '.kudora-show-bxgy' ).toggle( 'kudora_bxgy' === type );
		$( '.coupon_amount_field' ).toggle( 'kudora_bxgy' !== type && 'kudora_free_product' !== type );
	}
	$( document ).on( 'change', '#discount_type', toggleCouponFields );

	// Product screen: the "Gift card" tab follows the checkbox.
	function toggleGiftCardTab() {
		var on = $( '#_kudora_gift_card' ).is( ':checked' );
		$( '.show_if_kudora_gift_card' ).toggle( on );
		if ( ! on && $( '.kudora_gift_card_options.active' ).length ) {
			$( '.general_options > a' ).trigger( 'click' );
		}
	}
	$( document ).on( 'change', '#_kudora_gift_card', toggleGiftCardTab );
	$( document.body ).on( 'woocommerce-product-type-change', toggleGiftCardTab );

	$( function () {
		previewDesign();
		toggleBxgy();
		toggleCouponFields();
		toggleGiftCardTab();
	} );
} )( window.jQuery );
