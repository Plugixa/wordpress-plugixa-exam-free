<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Media\Services;

use PlugixaExam\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Getting an image into the library, past a gate narrower than WordPress's own.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS AT ALL, WHEN WORDPRESS ALREADY HAS AN UPLOADER.
 *
 * `wp_handle_upload()` honours the SITE's allowed MIME types, and any number of plugins
 * widen those — SVG support is one of the most-installed things in the directory. NFR-SEC-7
 * says an exam image may be PNG, JPG, GIF or WebP and explicitly not SVG, because an SVG is
 * a script container that an examinee's browser will happily execute.
 *
 * A site-wide setting cannot express "SVG is fine for the marketing team and not for exam
 * questions". So this gate is the plugin's own, it is narrower than the site's by
 * construction, and it can only ever reject things the site would have accepted — never the
 * reverse.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE EXTENSION AND THE CONTENT ARE BOTH CHECKED, AND NEITHER ALONE IS ENOUGH.
 *
 * An extension is a claim made by whoever named the file: `payload.svg` renamed to
 * `photo.png` passes an extension check and is still an SVG. A content sniff alone is not
 * enough either — a file whose bytes look like a GIF but which is served from a `.php`
 * URL is the oldest upload exploit there is.
 *
 * So: the extension must be on the list, `wp_check_filetype_and_ext()` must agree that the
 * name and the content describe the same type, and `getimagesize()` must be able to decode
 * it as one of the four permitted formats. A file has to pass all three.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Uploader {

	/**
	 * What an exam image may be.
	 *
	 * NO SVG, and it is not an oversight to be helpfully fixed later. See the class docblock.
	 *
	 * @var array<string, string> Extension => expected MIME type.
	 */
	public const ALLOWED = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * The `IMAGETYPE_*` constants matching {@see ALLOWED}.
	 *
	 * `getimagesize()` returns one of these, and it returns it by DECODING the file rather
	 * than by reading its name — which is what makes it the check the other two cannot fake.
	 *
	 * @var int[]
	 */
	private const DECODABLE = array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP );

	/** NFR-SEC-7. Ten megabytes, whatever the site's own limit happens to be. */
	private const MAX_BYTES = 10 * MB_IN_BYTES;

	/** The meta key that marks an attachment as this plugin's. */
	public const OWNED = '_plugixa_exam_media';

	/**
	 * @param Settings $settings For `media_alt_required`.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Accept an uploaded file into the media library.
	 *
	 * @param array<string, mixed> $file One entry from `$_FILES`.
	 * @param string               $alt  Alternative text.
	 *
	 * @return array<string, mixed>|WP_Error The attachment, as the picker wants it.
	 */
	public function accept( array $file, string $alt ): array|WP_Error {
		$alt = sanitize_text_field( $alt );

		if ( $this->settings->bool( 'media_alt_required', true ) && '' === trim( $alt ) ) {
			/*
			 * REFUSED BEFORE THE FILE IS WRITTEN, not after. Accepting the upload and then
			 * complaining would leave an image in the library that the author believes was
			 * rejected — and the next thing they do is upload it again.
			 */
			return new WP_Error(
				'plugixa_exam_media_needs_alt',
				__( 'Describe the image for somebody who cannot see it. This site requires it.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * THE BROWSER NAMES THE FILE AND CLAIMS ITS TYPE, AND NEITHER CLAIM IS PASSED ON.
		 *
		 * The name is cleaned ONCE, here, so every check below and `wp_handle_upload()` judge
		 * the same string. The type is overwritten after the checks, with the MIME `validate()`
		 * has just proved from the bytes — never the one the request declared.
		 */
		$file['name'] = sanitize_file_name( (string) ( $file['name'] ?? '' ) );

		$refused = $this->refuse( $file );

		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		// `validate()` has established that the extension is on the list AND that the bytes
		// agree with it, so this is the derived type rather than the declared one.
		$file['type'] = self::ALLOWED[ strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ];

		/*
		 * WORDPRESS'S UPLOADER LIVES IN A FILE ONLY wp-admin LOADS, and this runs in a REST
		 * request, where it is not there: measured in a request with nothing else active,
		 * `wp_handle_upload()` does not exist until this line. Loaded here, for the call on
		 * the next line, and nothing else from wp-admin is.
		 */
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$handled = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				/*
				 * Passed EXPLICITLY, so the uploader uses this module's list rather than
				 * the site's. Without it a site that permits SVG would accept one here,
				 * having already passed our checks — because the checks above are ours
				 * and this call is WordPress's.
				 */
				'mimes'     => self::ALLOWED,
			)
		);

		if ( ! is_array( $handled ) || isset( $handled['error'] ) ) {
			return new WP_Error(
				'plugixa_exam_media_upload_failed',
				is_array( $handled ) ? (string) $handled['error'] : __( 'The image could not be saved.', 'plugixa-exam' ),
				array( 'status' => 500 )
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $handled['type'],
				'post_title'     => sanitize_text_field( pathinfo( $handled['file'], PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$handled['file']
		);

		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			// The file is on disk but nothing points at it. Removed, rather than left as an
			// orphan nobody will ever find in the media manager.
			wp_delete_file( $handled['file'] );

			return new WP_Error(
				'plugixa_exam_media_upload_failed',
				__( 'The image could not be saved.', 'plugixa-exam' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * The sizes and dimensions. Same reason as the uploader above, a different file.
		 * WordPress's admin media file used to be loaded beside these two, and nothing here
		 * calls into it: every type on {@see ALLOWED} is an image, and an image's metadata
		 * is made by the image file alone (the media one is where audio and video are read).
		 */
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $handled['file'] );

		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		/*
		 * Marked as ours, so the picker can show exam images rather than the whole site
		 * library. It is a LABEL, not a fence — the attachment is an ordinary one, it appears
		 * in the media manager, and WordPress's own tools clean it up.
		 */
		update_post_meta( $attachment_id, self::OWNED, 1 );

		return $this->present( $attachment_id );
	}

	/**
	 * Why this file may not be uploaded, or null if it may.
	 *
	 * TWO STEPS, SPLIT SO THE SECOND CAN BE TESTED. The origin check below can only ever be
	 * true for a file PHP itself received, so a test can never satisfy it — and a gate whose
	 * interesting half is unreachable from a test is a gate nobody can prove. {@see validate()}
	 * holds everything that inspects the file, and this holds the guard that must run in
	 * production and cannot run anywhere else.
	 *
	 * @param array<string, mixed> $file One entry from `$_FILES`.
	 */
	private function refuse( array $file ): ?WP_Error {
		$name = (string) ( $file['name'] ?? '' );
		$path = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $name || '' === $path || ! is_uploaded_file( $path ) ) {
			/*
			 * `is_uploaded_file()` is what stops a crafted request naming a path that is
			 * already on the server — `/etc/passwd` as `tmp_name` — and having it copied into
			 * the public uploads folder.
			 */
			return new WP_Error(
				'plugixa_exam_media_no_file',
				__( 'No image arrived.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		return $this->validate( $name, $path, (int) ( $file['size'] ?? 0 ) );
	}

	/**
	 * Everything that can be decided by looking at the file.
	 *
	 * Public so the gate is testable with real bytes on disk — see `refuse()` for the one
	 * check that stays private because no test can satisfy it.
	 *
	 * @param string $name The name it arrived under.
	 * @param string $path Where it currently is.
	 * @param int    $size Its declared size in bytes.
	 */
	public function validate( string $name, string $path, int $size ): ?WP_Error {

		if ( $size < 1 || $size > self::MAX_BYTES ) {
			return new WP_Error(
				'plugixa_exam_media_too_large',
				sprintf(
					/* translators: %d: maximum size in megabytes. */
					__( 'Images must be smaller than %d MB.', 'plugixa-exam' ),
					self::MAX_BYTES / MB_IN_BYTES
				),
				array( 'status' => 400 )
			);
		}

		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! array_key_exists( $extension, self::ALLOWED ) ) {
			return $this->wrong_kind();
		}

		// CHECK TWO: does the NAME agree with the CONTENT. WordPress reads the file's magic
		// bytes here, so `payload.svg` renamed to `photo.png` is caught.
		$checked = wp_check_filetype_and_ext( $path, $name, self::ALLOWED );

		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			return $this->wrong_kind();
		}

		if ( self::ALLOWED[ $extension ] !== $checked['type'] ) {
			return $this->wrong_kind();
		}

		// CHECK THREE: is it one of the four formats. `getimagesize()` answers by reading the
		// header, which is enough to establish the TYPE and — see below — not enough to
		// establish that the file is really an image.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- `getimagesize()` warns on anything it cannot read, which is exactly the case being tested for; the return value is the answer.
		$image = @getimagesize( $path );

		if ( false === $image || ! in_array( (int) ( $image[2] ?? 0 ), self::DECODABLE, true ) ) {
			return $this->wrong_kind();
		}

		// CHECK FOUR: does it actually DECODE.
		if ( ! $this->decodes( $path ) ) {
			return $this->wrong_kind();
		}

		return null;
	}

	/**
	 * Whether the bytes really are a picture.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * `getimagesize()` IS A HEADER READ, NOT A DECODE, AND THE DIFFERENCE IS EXPLOITABLE.
	 *
	 * `GIF89a` followed by absolutely anything satisfies it — it takes the dimensions from
	 * the next four bytes and returns `IMAGETYPE_GIF` without looking further. So a file
	 * that is six bytes of header and then a payload passes the extension check, passes the
	 * type check, and passes `getimagesize()`. The suite proved exactly that against an
	 * earlier version of this class, which is why this method exists.
	 *
	 * `imagecreatefromstring()` decompresses the image data, so a forgery fails.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * WITHOUT GD, THIS PASSES RATHER THAN REFUSES. `getimagesize()` has already pinned the
	 * type to one of four, and refusing every upload on a host with no image library would
	 * break the module completely to close a gap that needs a header-only forgery AND a
	 * server willing to execute the result.
	 *
	 * @param string $path The file.
	 */
	private function decodes( string $path ): bool {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return true;
		}

		// Bounded by the size check above, so this cannot be asked to read a huge file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a just-uploaded temp file, not a remote fetch.
		$bytes = file_get_contents( $path );

		if ( false === $bytes ) {
			return false;
		}

		/*
		 * A HANDLER, NOT `@`. Rejecting a forgery is the SUCCESS path here, and
		 * `imagecreatefromstring()` warns on its way to returning false. `@` lowers
		 * `error_reporting` but does not stop a custom error handler from running — PHPUnit
		 * installs one and turns the warning into a test failure, and so does any site with a
		 * logging plugin. Replacing the handler for the length of one call is what actually
		 * silences it.
		 */
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- restored three lines down. See the docblock: `@` cannot silence a CUSTOM handler, and PHPUnit installs one.
		set_error_handler( static fn(): bool => true );

		$image = imagecreatefromstring( $bytes );

		restore_error_handler();

		if ( false === $image ) {
			return false;
		}

		imagedestroy( $image );

		return true;
	}

	/**
	 * The one refusal every failed check gives.
	 *
	 * DELIBERATELY THE SAME MESSAGE for a wrong extension, a mismatched type and an
	 * undecodable file. Telling somebody which of the three they tripped is telling them
	 * how to try the next thing.
	 */
	private function wrong_kind(): WP_Error {
		return new WP_Error(
			'plugixa_exam_media_wrong_kind',
			__( 'Images must be PNG, JPG, GIF or WebP.', 'plugixa-exam' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * An attachment as the picker wants it.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return array<string, mixed>
	 */
	public function present( int $attachment_id ): array {
		$full  = wp_get_attachment_image_src( $attachment_id, 'full' );
		$thumb = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );

		return array(
			'id'        => $attachment_id,
			'url'       => is_array( $full ) ? $full[0] : (string) wp_get_attachment_url( $attachment_id ),
			'width'     => is_array( $full ) ? (int) $full[1] : 0,
			'height'    => is_array( $full ) ? (int) $full[2] : 0,
			'thumbnail' => is_array( $thumb ) ? $thumb[0] : (string) wp_get_attachment_url( $attachment_id ),
			'alt'       => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'title'     => get_the_title( $attachment_id ),
			'mime'      => (string) get_post_mime_type( $attachment_id ),
		);
	}
}
