/**
 * The My exams block, editor side.
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

	blocks.registerBlockType( 'plugixa-exam/my-exams', {
		/**
		 * @param {Object} props Block props.
		 */
		edit: function ( props ) {
			return createElement(
				element.Fragment,
				null,

				createElement(
					'div',
					blockEditor.useBlockProps(),
					createElement(
						components.Placeholder,
						{
							icon: 'clipboard',
							label: __( 'My exams', 'plugixa-exam' ),
							instructions: __(
								'Nothing has been assigned to you yet appears here for a learner who has none.',
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
