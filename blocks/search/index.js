/**
 * The exam search block, editor side.
 *
 * ServerSideRender rather than a placeholder: search output is public and identical for
 * everybody, so the preview an author sees is the page a visitor gets. The form renders with
 * no query and shows its own idle state, which is exactly what a visitor meets first.
 *
 * @package PlugixaExam
 */

( function ( blocks, blockEditor, components, serverSideRender, element, i18n ) {
	'use strict';

	var createElement = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'plugixa-exam/search', {
		/**
		 * @param {Object} props Block props.
		 */
		edit: function ( props ) {
			return createElement(
				element.Fragment,
				null,

				createElement(
					blockEditor.InspectorControls,
					null,
					createElement(
						components.PanelBody,
						{ title: __( 'Exam search', 'plugixa-exam' ) },
						createElement( components.RangeControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Results per page', 'plugixa-exam' ),
							value: props.attributes.per_page,
							min: 1,
							max: 50,
							onChange: function ( value ) {
								props.setAttributes( { per_page: value || 10 } );
							},
						} )
					)
				),

				createElement(
					'div',
					blockEditor.useBlockProps(),
					createElement( serverSideRender, {
						block: 'plugixa-exam/search',
						attributes: props.attributes,
					} )
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.element,
	window.wp.i18n
);
