<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Media;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Media\Http\MediaController;
use PlugixaExam\Modules\Media\Services\Uploader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pictures in questions, through a gate narrower than the site's.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * NO TABLES. THE IMAGES ARE ORDINARY WORDPRESS ATTACHMENTS.
 *
 * Rolling a private store would mean losing thumbnails, the media manager, every CDN
 * offload plugin, and WordPress's own cleanup — to gain nothing except a folder this plugin
 * controls. So an exam image is an attachment like any other, marked with one meta key so
 * the picker can show exam images rather than the whole library.
 *
 * What the module DOES add is a gate: `wp_handle_upload()` honours the SITE's allowed MIME
 * types, and a site that permits SVG would otherwise accept a script container into a
 * question an examinee's browser renders. NFR-SEC-7 names four formats and excludes SVG, and
 * a site-wide setting cannot express "not for exam questions". See {@see Uploader}.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * `wp.media` IS NOT USED, AND THAT IS DELIBERATE. WordPress's own picker needs
 * `upload_files` — which an exam Author may not hold — and it is a jQuery modal that fights
 * a React admin for CSS and focus. The picker here is the plugin's own, talking to the
 * plugin's own route.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'media';
	}

	public function app(): array {
		return array(
			'category' => 'authoring',
			'setting'  => 'media_enabled',
			'default'  => true,
			// Images go into questions, which is where the picker lives.
			'deps'     => array( 'questions' ),
		);
	}

	public function migrate(): void {
		// Nothing. See the class docblock — the images are WordPress attachments.
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		$container->get( MediaController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		unset( $container );

		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );
		add_filter( 'plugixa_exam_sanitize_settings', array( $this, 'sanitize_settings' ) );
		add_filter( 'plugixa_exam_public_settings', array( $this, 'public_settings' ), 10, 2 );
	}

	/**
	 * The picker needs to know whether to insist on alt text before it lets somebody insert.
	 *
	 * @param array<string, mixed> $public The public subset so far.
	 * @param array<string, mixed> $all    Every setting.
	 *
	 * @return array<string, mixed>
	 */
	public function public_settings( array $public, array $all ): array {
		if ( array_key_exists( 'media_alt_required', $all ) ) {
			$public['media_alt_required'] = $all['media_alt_required'];
		}

		return $public;
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge(
			$defaults,
			array(
				'media_enabled' => true,

				/*
				 * ON by default, which is the unusual choice and the right one. An exam image
				 * carrying information — a diagram, a graph, a photograph of an artefact —
				 * is unanswerable to somebody using a screen reader if nobody described it,
				 * and unlike most accessibility failures this one changes the SCORE.
				 *
				 * A site can turn it off; it should have to decide to.
				 */
				'media_alt_required' => true,
			)
		);
	}

	/**
	 * @param array<string, mixed> $clean The whitelisted input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $clean ): array {
		foreach ( array( 'media_enabled', 'media_alt_required' ) as $key ) {
			if ( array_key_exists( $key, $clean ) ) {
				$clean[ $key ] = (bool) $clean[ $key ] && '0' !== $clean[ $key ];
			}
		}

		return $clean;
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'media'             => __( 'Images', 'plugixa-exam' ),
				'mediaDesc' => __( 'Pictures in questions and answer options.', 'plugixa-exam' ),
				'mediaInsert'       => __( 'Insert image', 'plugixa-exam' ),
				'mediaPick'         => __( 'Choose an image', 'plugixa-exam' ),
				'mediaUpload'       => __( 'Upload', 'plugixa-exam' ),
				'mediaUploading'    => __( 'Uploading…', 'plugixa-exam' ),
				'mediaEmpty'        => __( 'No images have been uploaded yet.', 'plugixa-exam' ),
				'mediaAlt'          => __( 'Describe the image', 'plugixa-exam' ),
				'mediaAltHelp'      => __( 'What somebody who cannot see it needs to know. If the picture carries information the question depends on, say what it shows.', 'plugixa-exam' ),
				'mediaAltRequired'  => __( 'This site requires a description.', 'plugixa-exam' ),
				'mediaAllowed'      => __( 'PNG, JPG, GIF or WebP, up to 10 MB.', 'plugixa-exam' ),
				'mediaChooseFile'   => __( 'Choose a file', 'plugixa-exam' ),
				'mediaInsertThis'   => __( 'Insert this image', 'plugixa-exam' ),
				'mediaSearch'       => __( 'Search images', 'plugixa-exam' ),

				// The picture-as-answer pickers (`AttachmentPicker`, `PerOptionImages`), shared
				// by picture choice and the premium visual types. Keys kept from when they lived
				// in VisualTypes, so no caller changed.
				'visualChooseImage'    => __( 'Choose a picture', 'plugixa-exam' ),
				'visualNoImage'        => __( 'No picture chosen yet.', 'plugixa-exam' ),
				'visualImageChosen'    => __( 'A picture is set.', 'plugixa-exam' ),
				'visualImagesHelp'     => __( 'Every option needs a picture. Reordering the options moves the pictures with them.', 'plugixa-exam' ),
				'visualNeedsOptions'   => __( 'Add the options above first. There is nothing to illustrate until then.', 'plugixa-exam' ),
				'visualUntitledOption' => __( 'Option {n}', 'plugixa-exam' ),
			)
		);
	}
}
