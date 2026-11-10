/**
 * The My results block, editor side.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * A PLACEHOLDER IN THE EDITOR, NOT ServerSideRender.
 *
 * The free catalogue block previews itself by rendering the real thing, which is right for
 * public output: the author sees the page a visitor will get. This block renders ONE
 * PERSON'S data, and in the editor that person is the author — so a live preview would put
 * an administrator's own results inside a page they are building for somebody else, and
 * would teach them nothing about what a learner will see.
 *
 * So it describes itself instead. The honest preview of private output is a description of
 * it.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * @package PlugixaExam
 */

( function ( blocks, blockEditor, components, element, i18n ) {
	'use strict';

	var createElement = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'plugixa-exam/my-results', {
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
						{ title: __( 'My results', 'plugixa-exam' ) },
						createElement( components.RangeControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'How many to show', 'plugixa-exam' ),
							value: props.attributes.limit,
							min: 1,
							max: 100,
							onChange: function ( value ) {
								props.setAttributes( { limit: value || 10 } );
							},
						} )
					)
				),

				createElement(
					'div',
					blockEditor.useBlockProps(),
					createElement(
						components.Placeholder,
						{
							icon: 'chart-bar',
							label: __( 'My results', 'plugixa-exam' ),
							instructions: __(
								'A learner sees their own attempts here; unreleased scores are withheld.',
								'plugixa-exam'
							),
						}
					)
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
	window.wp.element,
	window.wp.i18n
);
