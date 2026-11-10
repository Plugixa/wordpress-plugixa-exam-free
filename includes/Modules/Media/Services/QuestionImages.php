<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Media\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pictures attached to a question's options: stored by position, published by option id.
 *
 * Shared by every type whose options are pictures — picture choice in the free build, image
 * matching in premium — and by hotspot for its one picture's URL. It lives here, in the free
 * Media module, because a premium class may use a free one and never the reverse; a copy in
 * each type would fork the two fixes below, each of which cost a real bug to learn.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * STORED BY 1-BASED OPTION POSITION, NEVER BY OPTION ID.
 *
 * Option ids change on every save — `QuestionRepository::replace_options()` deletes and
 * re-inserts the lot — so a picture stored against an id is a picture pointing at nothing
 * after the next edit, with no error and no symptom except that the question renders blank.
 * Position survives, because `replace_options()` writes `ordering` from the order it is given.
 *
 * READ BACK BY `ordering`, NEVER BY ARRAY INDEX. `AttemptService` has ALREADY SHUFFLED the
 * options by the time a runner payload is built, so the third row in the array is not the
 * third option the author wrote — reading the index would attach every picture to the wrong
 * caption, but only for the candidates whose paper was shuffled.
 * ────────────────────────────────────────────────────────────────────────────────────────
 */
final class QuestionImages {

	/** Most pictures one option list may hold. */
	public const MAX_OPTIONS = 50;

	/**
	 * An answer key's `images`, cleaned: `{ position: attachmentId }`.
	 *
	 * An attachment that no longer exists is DROPPED rather than kept as a dead id. The option
	 * survives without its picture, which renders as a caption-only choice — legible, and
	 * obviously wrong to the author on the next edit. Keeping the id would render a broken
	 * image icon to the examinee instead, mid-exam.
	 *
	 * @param array<string, mixed> $key The raw answer key.
	 *
	 * @return array<int, int>
	 */
	public function normalize( array $key ): array {
		$out = array();

		foreach ( (array) ( $key['images'] ?? array() ) as $position => $attachment ) {
			$position   = absint( $position );
			$attachment = absint( $attachment );

			if ( $position < 1 || $position > self::MAX_OPTIONS || 0 === $attachment ) {
				continue;
			}

			if ( 'attachment' !== get_post_type( $attachment ) ) {
				continue;
			}

			$out[ $position ] = $attachment;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Pictures the examinee must see, keyed by the option id they belong to.
	 *
	 * Keyed by id on the way OUT because that is what the client has — the payload's own
	 * `options` array carries ids and text and nothing else. Read by `ordering` on the way IN
	 * because that is the author's position, which survives shuffling. See the class note.
	 *
	 * @param array<string, mixed>             $key     The answer key.
	 * @param array<int, array<string, mixed>> $options The full option rows.
	 *
	 * @return array<int, string>
	 */
	public function by_option( array $key, array $options ): array {
		$images = (array) ( $key['images'] ?? array() );
		$out    = array();

		foreach ( $options as $option ) {
			$position = (int) ( $option['ordering'] ?? 0 );

			if ( ! isset( $images[ $position ] ) ) {
				continue;
			}

			$url = $this->url( absint( $images[ $position ] ) );

			if ( '' !== $url ) {
				$out[ (int) $option['id'] ] = $url;
			}
		}

		return $out;
	}

	/**
	 * An attachment's URL, or an empty string if it has gone.
	 *
	 * `large` rather than the full original: an exam paper with eight photographs straight
	 * off a phone is forty megabytes, and the examinee is often the person with the worst
	 * connection in the building.
	 *
	 * THE FALLBACK IS NOT BELT AND BRACES. `wp_get_attachment_image_url()` answers FALSE for
	 * any attachment whose sized metadata is missing — an image restored from a backup, one
	 * imported by a migration plugin, one whose regeneration was interrupted. The attachment
	 * is perfectly good and its file is right there; only the derived sizes are absent. A
	 * bare cast of that FALSE is an empty `src`, which is a question with no picture in it,
	 * discovered by an examinee mid-exam.
	 *
	 * @param int $attachment Attachment id.
	 */
	public function url( int $attachment ): string {
		if ( 0 === $attachment ) {
			return '';
		}

		$sized = wp_get_attachment_image_url( $attachment, 'large' );

		return is_string( $sized ) && '' !== $sized
			? $sized
			: (string) wp_get_attachment_url( $attachment );
	}
}
