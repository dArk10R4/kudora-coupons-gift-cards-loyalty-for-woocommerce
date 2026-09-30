/**
 * Editor registration for the server-rendered "Gift Card Balance" block.
 */
( function ( blocks, element, serverSideRender, blockEditor ) {
	var el = element.createElement;
	blocks.registerBlockType( 'kudora/gift-card-balance', {
		edit: function () {
			return el(
				'div',
				blockEditor.useBlockProps(),
				el( serverSideRender, { block: 'kudora/gift-card-balance' } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor );
