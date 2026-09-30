/**
 * Editor registration for the server-rendered "Loyalty Points Card" block.
 */
( function ( blocks, element, serverSideRender, blockEditor ) {
	var el = element.createElement;
	blocks.registerBlockType( 'kudora/loyalty-card', {
		edit: function () {
			return el(
				'div',
				blockEditor.useBlockProps(),
				el( serverSideRender, { block: 'kudora/loyalty-card' } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor );
