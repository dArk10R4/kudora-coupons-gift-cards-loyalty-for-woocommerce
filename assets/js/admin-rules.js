/**
 * Kudora — condition builder (rules, earning rules, coupons) and rule editor
 * sections. Conditions are kept as JSON in a hidden input:
 * [ { type, operator, value } ].
 */
( function ( $ ) {
	'use strict';
	if ( ! $ || ! window.kudoraRules ) {
		return;
	}
	var data = window.kudoraRules;
	var schema = data.schema;
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};
	var TD = 'kudora-coupons-gift-cards-loyalty-for-woocommerce';
	var uid = 0;

	var groups = {
		cart: __( 'Cart', TD ),
		customer: __( 'Customer', TD ),
		date: __( 'Date & time', TD ),
		loyalty: __( 'Loyalty', TD ),
	};

	function esc( s ) {
		return $( '<div/>' ).text( s == null ? '' : String( s ) ).html();
	}

	function optionsFrom( map, selected, multiple ) {
		var sel = [].concat( selected || [] ).map( String );
		var html = '';
		$.each( map, function ( k, label ) {
			html += '<option value="' + esc( k ) + '"' + ( sel.indexOf( String( k ) ) > -1 ? ' selected' : '' ) + '>' + esc( label ) + '</option>';
		} );
		return html;
	}

	function typeSelect( current ) {
		var html = '<select class="kudora-c-type" aria-label="' + esc( __( 'Condition', TD ) ) + '">';
		var byGroup = {};
		$.each( schema.types, function ( key, t ) {
			( byGroup[ t.group ] = byGroup[ t.group ] || [] ).push( [ key, t.label ] );
		} );
		$.each( byGroup, function ( g, list ) {
			html += '<optgroup label="' + esc( groups[ g ] || g ) + '">';
			list.forEach( function ( pair ) {
				html += '<option value="' + esc( pair[ 0 ] ) + '"' + ( pair[ 0 ] === current ? ' selected' : '' ) + '>' + esc( pair[ 1 ] ) + '</option>';
			} );
			html += '</optgroup>';
		} );
		return html + '</select>';
	}

	function operatorSelect( type, current ) {
		var t = schema.types[ type ];
		var html = '<select class="kudora-c-op" aria-label="' + esc( __( 'Operator', TD ) ) + '">';
		( t ? t.operators : [] ).forEach( function ( op ) {
			html += '<option value="' + esc( op ) + '"' + ( op === current ? ' selected' : '' ) + '>' + esc( schema.operators[ op ] || op ) + '</option>';
		} );
		return html + '</select>';
	}

	function valueInput( type, op, value ) {
		var t = schema.types[ type ];
		var id = 'kudora-c-' + ++uid;
		var label = esc( __( 'Value', TD ) );
		if ( ! t ) {
			return '';
		}
		switch ( t.input ) {
			case 'money':
			case 'number':
				if ( 'between' === op ) {
					var v = [].concat( value || [] );
					return '<input type="number" step="any" class="small-text kudora-c-v1" aria-label="' + esc( __( 'From', TD ) ) + '" value="' + esc( v[ 0 ] ) + '"> – <input type="number" step="any" class="small-text kudora-c-v2" aria-label="' + esc( __( 'To', TD ) ) + '" value="' + esc( v[ 1 ] ) + '">';
				}
				return '<input type="number" step="any" class="small-text kudora-c-v" aria-label="' + label + '" value="' + esc( value ) + '">' + ( 'money' === t.input ? ' <span>' + esc( data.currency ) + '</span>' : '' );
			case 'products':
				var opts = '';
				[].concat( value || [] ).forEach( function ( idv ) {
					opts += '<option value="' + esc( idv ) + '" selected>' + esc( data.productNames[ idv ] || '#' + idv ) + '</option>';
				} );
				return '<select multiple class="wc-product-search kudora-c-v" style="min-width:260px" id="' + id + '" aria-label="' + label + '" data-placeholder="' + esc( __( 'Search for a product…', TD ) ) + '" data-action="woocommerce_json_search_products_and_variations">' + opts + '</select>';
			case 'categories':
				return '<select multiple class="wc-enhanced-select kudora-c-v" style="min-width:260px" aria-label="' + label + '">' + optionsFrom( data.categories, value ) + '</select>';
			case 'tags':
				return '<select multiple class="wc-enhanced-select kudora-c-v" style="min-width:260px" aria-label="' + label + '">' + optionsFrom( data.tags, value ) + '</select>';
			case 'roles':
				return '<select multiple class="wc-enhanced-select kudora-c-v" style="min-width:260px" aria-label="' + label + '">' + optionsFrom( data.roles, value ) + '</select>';
			case 'countries':
				return '<select multiple class="wc-enhanced-select kudora-c-v" style="min-width:260px" aria-label="' + label + '">' + optionsFrom( data.countries, value ) + '</select>';
			case 'tiers':
				return '<select multiple class="wc-enhanced-select kudora-c-v" style="min-width:200px" aria-label="' + label + '">' + optionsFrom( data.tiers, value ) + '</select>';
			case 'weekdays':
				return '<select multiple class="wc-enhanced-select kudora-c-v" style="min-width:260px" aria-label="' + label + '">' + optionsFrom( data.weekdays, value ) + '</select>';
			case 'yesno':
				return '<select class="kudora-c-v" aria-label="' + label + '"><option value="yes"' + ( 'no' !== value ? ' selected' : '' ) + '>' + esc( __( 'Yes', TD ) ) + '</option><option value="no"' + ( 'no' === value ? ' selected' : '' ) + '>' + esc( __( 'No', TD ) ) + '</option></select>';
			case 'text_list':
				return '<input type="text" class="regular-text kudora-c-v" aria-label="' + label + '" placeholder="' + esc( __( 'Comma-separated', TD ) ) + '" value="' + esc( [].concat( value || [] ).join( ', ' ) ) + '">';
			case 'time_range':
				var tv = [].concat( value || [] );
				return '<input type="time" class="kudora-c-v1" aria-label="' + esc( __( 'From', TD ) ) + '" value="' + esc( tv[ 0 ] ) + '"> – <input type="time" class="kudora-c-v2" aria-label="' + esc( __( 'To', TD ) ) + '" value="' + esc( tv[ 1 ] ) + '">';
			case 'date_range':
				var dv = [].concat( value || [] );
				return '<input type="date" class="kudora-c-v1" aria-label="' + esc( __( 'From', TD ) ) + '" value="' + esc( dv[ 0 ] ) + '"> – <input type="date" class="kudora-c-v2" aria-label="' + esc( __( 'To', TD ) ) + '" value="' + esc( dv[ 1 ] ) + '">';
		}
		return '';
	}

	function rowHtml( c ) {
		return (
			'<div class="kudora-c-row">' +
			typeSelect( c.type ) +
			' <span class="kudora-c-opwrap">' + operatorSelect( c.type, c.operator ) + '</span>' +
			' <span class="kudora-c-valwrap">' + valueInput( c.type, c.operator, c.value ) + '</span>' +
			' <button type="button" class="button-link kudora-c-remove" aria-label="' + esc( __( 'Remove condition', TD ) ) + '">&times;</button>' +
			'</div>'
		);
	}

	function readRow( $row ) {
		var type = $row.find( '.kudora-c-type' ).val();
		var op = $row.find( '.kudora-c-op' ).val();
		var t = schema.types[ type ] || {};
		var value;
		if ( $row.find( '.kudora-c-v1' ).length ) {
			value = [ $row.find( '.kudora-c-v1' ).val(), $row.find( '.kudora-c-v2' ).val() ];
		} else {
			value = $row.find( '.kudora-c-v' ).val();
			if ( 'text_list' === t.input ) {
				value = String( value || '' ).split( ',' ).map( function ( s ) {
					return s.trim();
				} ).filter( Boolean );
			}
		}
		return { type: type, operator: op, value: value };
	}

	function sync( $b ) {
		var list = [];
		$b.find( '.kudora-c-row' ).each( function () {
			list.push( readRow( $( this ) ) );
		} );
		$( '#' + $b.data( 'input' ) ).val( JSON.stringify( list ) );
	}

	function initBuilder( $b ) {
		var raw = $( '#' + $b.data( 'input' ) ).val();
		var list = [];
		try {
			list = JSON.parse( raw || '[]' ) || [];
		} catch ( e ) {
			list = [];
		}
		var html = '<div class="kudora-c-rows">';
		list.forEach( function ( c ) {
			if ( schema.types[ c.type ] ) {
				html += rowHtml( c );
			}
		} );
		html += '</div><p><button type="button" class="button kudora-c-add">' + esc( __( 'Add condition', TD ) ) + '</button></p>';
		$b.html( html );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	}

	$( document ).on( 'click', '.kudora-c-add', function ( e ) {
		e.preventDefault();
		var $b = $( this ).closest( '[data-kudora-conditions]' );
		var first = Object.keys( schema.types )[ 0 ];
		$b.find( '.kudora-c-rows' ).append( rowHtml( { type: first, operator: schema.types[ first ].operators[ 0 ], value: '' } ) );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		sync( $b );
	} );
	$( document ).on( 'click', '.kudora-c-remove', function ( e ) {
		e.preventDefault();
		var $b = $( this ).closest( '[data-kudora-conditions]' );
		$( this ).closest( '.kudora-c-row' ).remove();
		sync( $b );
	} );
	$( document ).on( 'change', '.kudora-c-type', function () {
		var $row = $( this ).closest( '.kudora-c-row' );
		var type = $( this ).val();
		var op = schema.types[ type ].operators[ 0 ];
		$row.find( '.kudora-c-opwrap' ).html( operatorSelect( type, op ) );
		$row.find( '.kudora-c-valwrap' ).html( valueInput( type, op, '' ) );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		sync( $row.closest( '[data-kudora-conditions]' ) );
	} );
	$( document ).on( 'change', '.kudora-c-op', function () {
		var $row = $( this ).closest( '.kudora-c-row' );
		var type = $row.find( '.kudora-c-type' ).val();
		var t = schema.types[ type ];
		if ( t && ( 'money' === t.input || 'number' === t.input ) ) {
			var cur = readRow( $row ).value;
			$row.find( '.kudora-c-valwrap' ).html( valueInput( type, $( this ).val(), cur ) );
		}
		sync( $row.closest( '[data-kudora-conditions]' ) );
	} );
	$( document ).on( 'change input', '.kudora-c-valwrap :input', function () {
		sync( $( this ).closest( '[data-kudora-conditions]' ) );
	} );
	$( document ).on( 'submit', 'form', function () {
		$( this ).find( '[data-kudora-conditions]' ).each( function () {
			sync( $( this ) );
		} );
	} );

	// Rule editor: show the sections that belong to the chosen type.
	function toggleTypes() {
		var $sel = $( '[data-kudora-type]' );
		if ( ! $sel.length ) {
			return;
		}
		var type = $sel.val();
		$( '[data-show-types]' ).each( function () {
			var show = String( $( this ).data( 'show-types' ) ).split( ' ' ).indexOf( type ) > -1;
			$( this ).toggle( show );
			$( this ).find( ':input' ).prop( 'disabled', ! show );
			if ( this.tagName === 'OPTION' ) {
				$( this ).prop( 'disabled', ! show );
			}
		} );
	}
	$( document ).on( 'change', '[data-kudora-type]', toggleTypes );

	$( function () {
		$( '[data-kudora-conditions]' ).each( function () {
			initBuilder( $( this ) );
		} );
		toggleTypes();
	} );
} )( window.jQuery );
