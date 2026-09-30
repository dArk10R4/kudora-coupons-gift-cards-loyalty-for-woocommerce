/**
 * Kudora — gift card purchase form: recipient toggle, message counter and a live
 * preview of the card (amount, recipient, message, design).
 */
( function () {
	'use strict';
	var i18n = window.kudoraGcForm || { left: '%d characters left', to: 'To: %s' };

	function money( form, value ) {
		var decimals = parseInt( form.getAttribute( 'data-decimals' ) || '2', 10 );
		var symbol = form.getAttribute( 'data-currency' ) || '';
		var fmt = form.getAttribute( 'data-price-format' ) || '%1$s%2$s';
		var num = Number( value || 0 ).toFixed( decimals );
		return fmt.replace( '%1$s', symbol ).replace( '%2$s', num ).replace( '&nbsp;', ' ' );
	}

	function init( form ) {
		var preview = form.querySelector( '.kudora-card' );
		var amountOut = form.querySelector( '[data-kudora-preview="amount"]' );
		var toOut = form.querySelector( '[data-kudora-preview="to"]' );
		var msgOut = form.querySelector( '[data-kudora-preview="message"]' );
		var recipient = form.querySelector( '.kudora-gc-recipient' );
		var message = form.querySelector( '#kudora_gc_message' );
		var counter = form.querySelector( '.kudora-gc-count' );
		var custom = form.querySelector( '#kudora_gc_custom_amount' );

		function currentAmount() {
			var checked = form.querySelector( 'input[name="kudora_gc_amount"]:checked' ) || form.querySelector( 'input[type="hidden"][name="kudora_gc_amount"]' );
			if ( checked && 'custom' === checked.value ) {
				return custom ? custom.value : '';
			}
			if ( checked ) {
				return checked.getAttribute( 'data-amount' ) || checked.value;
			}
			return custom && custom.value ? custom.value : '';
		}

		function update() {
			var amount = currentAmount();
			if ( amountOut && amount !== '' ) {
				amountOut.textContent = money( form, amount );
			}
			var sendTo = form.querySelector( 'input[name="kudora_gc_send_to"]:checked' );
			var other = ! sendTo || 'other' === sendTo.value;
			if ( recipient ) {
				recipient.hidden = ! other;
				var email = recipient.querySelector( 'input[type="email"]' );
				if ( email ) {
					email.required = other;
				}
			}
			var name = form.querySelector( '#kudora_gc_recipient_name' );
			if ( toOut ) {
				toOut.textContent = other && name && name.value ? i18n.to.replace( '%s', name.value ) : '';
			}
			if ( msgOut && message ) {
				msgOut.textContent = message.value;
			}
			if ( counter && message ) {
				var left = parseInt( message.getAttribute( 'maxlength' ) || '300', 10 ) - message.value.length;
				counter.textContent = i18n.left.replace( '%d', left );
			}
			var design = form.querySelector( 'input[name="kudora_gc_design"]:checked' );
			if ( preview && design && design.getAttribute( 'data-style' ) ) {
				preview.setAttribute( 'style', design.getAttribute( 'data-style' ) );
			}
			if ( custom ) {
				var isCustom = form.querySelector( 'input[name="kudora_gc_amount"][value="custom"]:checked' );
				custom.closest( '.kudora-gc-custom' ).hidden = ! isCustom && !! form.querySelector( 'input[name="kudora_gc_amount"][type="radio"]' );
				custom.required = !! isCustom;
			}
		}

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		update();
	}

	function boot() {
		document.querySelectorAll( '.kudora-gc-form' ).forEach( init );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
