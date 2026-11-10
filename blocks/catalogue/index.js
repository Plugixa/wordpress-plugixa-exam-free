/**
 * The exam catalogue block, editor side.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * NO BUILD STEP, AND THAT IS DELIBERATE.
 *
 * This file is plain ES5-compatible JavaScript against the `wp.*` globals WordPress already
 * puts on the editor page. It is not JSX, it is not bundled, and it is not minified — what
 * ships is what is written here.
 *
 * The admin application is a different matter: it is a large React app and it earns its
 * Vite pipeline. A block with one number field does not. Adding `@wordpress/scripts` for it
 * would mean a second toolchain, a second lockfile, a second thing to keep on a supported
 * Node version, and a build artefact the WordPress.org reviewers then need the source for
 * (Guideline: human-readable source must ship). All of that to avoid writing
 * `createElement` a dozen times.
 *
 * The cost is honest: `createElement` is wordier than JSX. That is the whole cost.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * @package PlugixaExam
 */

( function ( blocks, blockEditor, components, serverSideRender, element, i18n ) {
	'use strict';

	var createElement = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'plugixa-exam/catalogue', {
		/**
		 * WHY ServerSideRender RATHER THAN A DRAWING OF A CATALOGUE.
		 *
		 * The alternative — a static placeholder saying "Exam catalogue" — is less work and
		 * quietly worse: an author cannot tell whether the block will show three exams or
		 * none until they publish and look. Rendering through the same PHP the front end
		 * uses means the preview is the page, including its empty state, so "there are no
		 * exams yet" is discovered in the editor rather than after publishing.
		 *
		 * @param {Object} props Block props.
		 */
		edit: function ( props ) {
			var limit = props.attributes.limit;

			return createElement(
				element.Fragment,
				null,

				createElement(
					blockEditor.InspectorControls,
					null,
					createElement(
						components.PanelBody,
						{ title: __( 'Catalogue', 'plugixa-exam' ) },
						createElement( components.RangeControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'How many to show', 'plugixa-exam' ),
							help: __( 'Zero shows every exam that is open or opening soon.', 'plugixa-exam' ),
							value: limit,
							min: 0,
							max: 50,
							onChange: function ( value ) {
								props.setAttributes( { limit: value || 0 } );
							},
						} )
					)
				),

				createElement(
					'div',
					blockEditor.useBlockProps(),
					createElement( serverSideRender, {
						block: 'plugixa-exam/catalogue',
						attributes: props.attributes,
					} )
				)
			);
		},

		/**
		 * Nothing is saved to post content: the catalogue is different every time it is
		 * looked at, so it is rendered on request. `render` in `block.json` points at the
		 * PHP that does it.
		 */
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
