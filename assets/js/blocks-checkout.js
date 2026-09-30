/**
 * Kudora — Cart & Checkout blocks integration (no build step).
 *
 * Renders inside WooCommerce's order summary slots:
 *  - gift card code field + applied cards (after-tax rows),
 *  - "use your points" box and "you will earn N points",
 *  - promotion notices ("Add $X more to get …").
 * Actions go through `extensionCartUpdate( { namespace: 'kudora', data } )`.
 */
( function () {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	if ( ! wc.blocksCheckout || ! wp.plugins || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var bc = wc.blocksCheckout;
	var settings = ( wc.wcSettings && wc.wcSettings.getSetting ) ? wc.wcSettings.getSetting( 'kudora_data', {} ) : {};
	var TD = 'kudora-coupons-gift-cards-loyalty-for-woocommerce';

	function kudora( extensions ) {
		if ( extensions && extensions.kudora ) {
			return extensions.kudora;
		}
		// Some filters (e.g. `coupons`) are applied without extensions: read the cart store.
		try {
			var cart = wp.data.select( 'wc/store/cart' ).getCartData();
			return ( cart && cart.extensions && cart.extensions.kudora ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function currencyOf( cart ) {
		var totals = cart && cart.cartTotals ? cart.cartTotals : {};
		if ( wc.priceFormat && wc.priceFormat.getCurrencyFromPriceResponse ) {
			return wc.priceFormat.getCurrencyFromPriceResponse( totals );
		}
		return {
			code: totals.currency_code,
			symbol: totals.currency_symbol,
			minorUnit: totals.currency_minor_unit,
			decimalSeparator: totals.currency_decimal_separator,
			thousandSeparator: totals.currency_thousand_separator,
			prefix: totals.currency_prefix,
			suffix: totals.currency_suffix,
		};
	}

	function formatMinor( minor, currency ) {
		if ( wc.priceFormat && wc.priceFormat.formatPrice ) {
			return wc.priceFormat.formatPrice( minor, currency );
		}
		return String( minor );
	}

	function send( data ) {
		return bc.extensionCartUpdate( { namespace: 'kudora', data: data } );
	}

	function errorText( err ) {
		if ( err && err.message ) {
			return err.message;
		}
		return __( 'Something went wrong. Please try again.', TD );
	}

	/* ------------------------------------------------------------------
	 * Gift cards
	 * ---------------------------------------------------------------- */

	function GiftCards( props ) {
		var data = kudora( props.extensions ).gift_cards || { applied: [], notices: [] };
		var open = useState( false );
		var code = useState( '' );
		var busy = useState( false );
		var msg = useState( '' );
		var currency = currencyOf( props.cart );
		var uid = 'kudora-gc-' + ( props.context || 'cart' ).replace( /[^a-z]/g, '' );

		function apply( e ) {
			if ( e ) {
				e.preventDefault();
			}
			if ( ! code[ 0 ].trim() ) {
				msg[ 1 ]( __( 'Please enter a gift card code.', TD ) );
				return;
			}
			busy[ 1 ]( true );
			msg[ 1 ]( '' );
			send( { action: 'gc_apply', code: code[ 0 ] } )
				.then( function () {
					code[ 1 ]( '' );
					open[ 1 ]( false );
					msg[ 1 ]( __( 'Gift card applied.', TD ) );
				} )
				.catch( function ( err ) {
					msg[ 1 ]( errorText( err ) );
				} )
				.finally( function () {
					busy[ 1 ]( false );
				} );
		}

		function remove( c ) {
			busy[ 1 ]( true );
			send( { action: 'gc_remove', code: c } )
				.then( function () {
					msg[ 1 ]( __( 'Gift card removed.', TD ) );
				} )
				.catch( function ( err ) {
					msg[ 1 ]( errorText( err ) );
				} )
				.finally( function () {
					busy[ 1 ]( false );
				} );
		}

		var rows = ( data.applied || [] ).map( function ( card ) {
			return el( bc.TotalsItem, {
				key: card.code,
				className: 'kudora-block-gc-row',
				currency: currency,
				/* translators: %s: masked gift card code */
				label: sprintf( __( 'Gift card %s', TD ), card.masked ),
				value: -card.minor,
				description: el(
					'button',
					{
						type: 'button',
						className: 'kudora-link-button',
						disabled: busy[ 0 ],
						onClick: function () {
							remove( card.code );
						},
						/* translators: %s: masked gift card code */
						'aria-label': sprintf( __( 'Remove gift card %s', TD ), card.masked ),
					},
					__( 'Remove', TD )
				),
			} );
		} );

		var notices = ( data.notices || [] ).map( function ( n, i ) {
			return el( 'p', { key: 'n' + i, className: 'kudora-block-notice' }, n );
		} );

		var form = open[ 0 ]
			? el(
					'form',
					{ className: 'kudora-block-gc-form', onSubmit: apply },
					el( 'label', { htmlFor: uid, className: 'kudora-block-label' }, __( 'Gift card code', TD ) ),
					el(
						'div',
						{ className: 'kudora-block-row' },
						el( 'input', {
							id: uid,
							type: 'text',
							className: 'kudora-block-input',
							value: code[ 0 ],
							autoComplete: 'off',
							spellCheck: false,
							disabled: busy[ 0 ],
							onChange: function ( e ) {
								code[ 1 ]( e.target.value );
							},
						} ),
						el(
							'button',
							{ type: 'submit', className: 'kudora-block-button wc-block-components-button wp-element-button', disabled: busy[ 0 ] },
							__( 'Apply', TD )
						)
					)
			  )
			: el(
					'button',
					{
						type: 'button',
						className: 'kudora-link-button kudora-block-toggle',
						'aria-expanded': 'false',
						onClick: function () {
							open[ 1 ]( true );
						},
					},
					__( 'Have a gift card?', TD )
			  );

		return el(
			'div',
			{ className: 'kudora-block kudora-block-gift-cards' },
			rows.length ? el( bc.TotalsWrapper, null, rows ) : null,
			notices,
			el( 'div', { className: 'kudora-block-pad' }, form ),
			el( 'p', { className: 'kudora-block-status', role: 'status', 'aria-live': 'polite' }, msg[ 0 ] )
		);
	}

	/* ------------------------------------------------------------------
	 * Loyalty points
	 * ---------------------------------------------------------------- */

	function formatPoints( n ) {
		/* translators: %s: number of points */
		return sprintf( _n( '%s point', '%s points', n, TD ), Number( n ).toLocaleString() );
	}

	function Points( props ) {
		var data = kudora( props.extensions ).points;
		var input = useState( '' );
		var busy = useState( false );
		var msg = useState( '' );
		var uid = 'kudora-pts-' + ( props.context || 'cart' ).replace( /[^a-z]/g, '' );
		if ( ! data || ! data.enabled ) {
			return null;
		}

		var earn = data.earn > 0
			? el( 'p', { className: 'kudora-block-earn' },
					/* translators: %s: points */
					sprintf( __( 'You will earn %s with this order.', TD ), formatPoints( data.earn ) ) )
			: null;

		if ( ! data.logged_in ) {
			return null;
		}
		if ( data.balance <= 0 && ! data.applied.points ) {
			return el( 'div', { className: 'kudora-block kudora-block-points' }, earn );
		}

		function run( payload, ok ) {
			busy[ 1 ]( true );
			msg[ 1 ]( '' );
			send( payload )
				.then( function () {
					msg[ 1 ]( ok );
					input[ 1 ]( '' );
				} )
				.catch( function ( err ) {
					msg[ 1 ]( errorText( err ) );
				} )
				.finally( function () {
					busy[ 1 ]( false );
				} );
		}

		var body;
		if ( data.applied.points > 0 ) {
			body = el(
				'div',
				{ className: 'kudora-block-row' },
				el( 'span', null,
					/* translators: %s: points */
					sprintf( __( 'Using %s', TD ), formatPoints( data.applied.points ) ) ),
				el( 'button', {
					type: 'button',
					className: 'kudora-link-button',
					disabled: busy[ 0 ],
					onClick: function () {
						run( { action: 'points_remove' }, __( 'Points removed.', TD ) );
					},
				}, __( 'Remove', TD ) )
			);
		} else if ( data.max_points > 0 ) {
			if ( 'auto' === data.mode ) {
				body = el( 'button', {
					type: 'button',
					className: 'kudora-block-button wc-block-components-button wp-element-button',
					disabled: busy[ 0 ],
					onClick: function () {
						run( { action: 'points_auto' }, __( 'Points applied.', TD ) );
					},
				}, __( 'Use my points', TD ) );
			} else {
				body = el(
					'form',
					{
						className: 'kudora-block-row',
						onSubmit: function ( e ) {
							e.preventDefault();
							run( { action: 'points_apply', points: parseInt( input[ 0 ] || data.max_points, 10 ) }, __( 'Points applied.', TD ) );
						},
					},
					el( 'label', { htmlFor: uid, className: 'screen-reader-text' }, __( 'Points to use', TD ) ),
					el( 'input', {
						id: uid,
						type: 'number',
						className: 'kudora-block-input',
						min: Math.max( 1, data.min_points || 1 ),
						max: data.max_points,
						step: 1,
						placeholder: String( data.max_points ),
						value: input[ 0 ],
						disabled: busy[ 0 ],
						onChange: function ( e ) {
							input[ 1 ]( e.target.value );
						},
					} ),
					el( 'button', { type: 'submit', className: 'kudora-block-button wc-block-components-button wp-element-button', disabled: busy[ 0 ] }, __( 'Use points', TD ) )
				);
			}
		}

		return el(
			'div',
			{ className: 'kudora-block kudora-block-points' },
			el( 'p', { className: 'kudora-block-balance' },
				el( 'strong', null, data.label ), ' ',
				/* translators: %s: points balance */
				sprintf( __( 'You have %s.', TD ), formatPoints( data.balance ) ),
				data.max_points > 0 && ! data.applied.points
					? ' ' + sprintf(
							/* translators: %s: points */
							__( 'Up to %s can be used on this order.', TD ),
							formatPoints( data.max_points )
					  )
					: ''
			),
			body,
			earn,
			el( 'p', { className: 'kudora-block-status', role: 'status', 'aria-live': 'polite' }, msg[ 0 ] )
		);
	}

	/* ------------------------------------------------------------------
	 * Promotions
	 * ---------------------------------------------------------------- */

	function Promos( props ) {
		var promos = kudora( props.extensions ).promos || [];
		if ( ! promos.length ) {
			return null;
		}
		return el(
			'div',
			{ className: 'kudora-block kudora-block-promos', role: 'status' },
			promos.map( function ( p, i ) {
				return el( 'p', { key: i, className: 'kudora-block-promo' }, p );
			} )
		);
	}

	function DiscountsFill( props ) {
		return el(
			Fragment,
			null,
			settings.giftCards ? el( GiftCards, props ) : null,
			el( Points, props )
		);
	}

	function OrderMetaFill( props ) {
		return el( Promos, props );
	}

	wp.plugins.registerPlugin( 'kudora-checkout', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el(
				Fragment,
				null,
				el( bc.ExperimentalDiscountsMeta, null, el( DiscountsFill ) ),
				el( bc.ExperimentalOrderMeta, null, el( OrderMetaFill ) )
			);
		},
	} );

	// Friendly labels for Kudora's discounts, and no generic "coupon applied" toast
	// for a gift card code typed into the coupon field.
	if ( bc.registerCheckoutFilters ) {
		bc.registerCheckoutFilters( 'kudora', {
			coupons: function ( coupons, extensions ) {
				var labels = kudora( extensions ).labels || {};
				return coupons.map( function ( c ) {
					return labels[ c.code ] ? Object.assign( {}, c, { label: labels[ c.code ] } ) : c;
				} );
			},
			showApplyCouponNotice: function ( value, extensions, args ) {
				var gc = kudora( extensions ).gift_cards || {};
				var code = args && args.couponCode ? String( args.couponCode ).toUpperCase() : '';
				return ( gc.codes || [] ).indexOf( code ) === -1 ? value : false;
			},
		} );
	}
} )();
