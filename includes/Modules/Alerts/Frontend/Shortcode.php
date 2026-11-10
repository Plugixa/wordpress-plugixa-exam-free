<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Alerts\Frontend;

use PlugixaExam\Core\Frontend\PrivateResponse;
use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Alerts\Repositories\AlertRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[plugixa_exam_alerts]` — a learner's own feed, on the front of the site.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════════
 * THIS IS PRIVATE OUTPUT AND MUST OPT OUT OF PAGE CACHING.
 *
 * One person's data on a public page. On a site with full-page caching the first visitor's feed
 * would be stored and served to everybody after them, and the failure is silent and total. So
 * this goes through `PrivateResponse::mark()` and carries `data-pxe-private`, exactly as the "my
 * results" embeds do — and it renders NOTHING when signed out, rather than a login prompt, since
 * a login prompt is precisely what a cache would keep in place of somebody's notifications.
 * ════════════════════════════════════════════════════════════════════════════════════════════
 *
 * SERVER-RENDERED, WITH NO JAVASCRIPT AT ALL. The admin bell polls because a marker sits on one
 * screen for an hour; a learner opening their notifications page has just navigated to it, so
 * the markup they need is already in the response and there is nothing to hydrate.
 */
final class Shortcode {

	/**
	 * @param AlertRepository $alerts   The inboxes.
	 * @param Settings        $settings For the App Manager switch.
	 */
	public function __construct(
		private readonly AlertRepository $alerts,
		private readonly Settings $settings
	) {}

	public function register(): void {
		add_shortcode( 'plugixa_exam_alerts', array( $this, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		unset( $atts );

		if ( ! $this->settings->bool( 'app_alerts', true ) || ! is_user_logged_in() ) {
			return '';
		}

		PrivateResponse::mark();

		$rows = $this->alerts->inbox( get_current_user_id() );

		if ( array() === $rows ) {
			return $this->wrap(
				'<p class="pxe-alerts__empty">'
				. esc_html__( 'Nothing to catch up on.', 'plugixa-exam' )
				. '</p>'
			);
		}

		$items = '';

		foreach ( $rows as $row ) {
			$items .= $this->item( $row );
		}

		return $this->wrap( '<ul class="pxe-alerts__list">' . $items . '</ul>' );
	}

	/**
	 * One row.
	 *
	 * @param array<string, mixed> $row Stored row.
	 */
	private function item( array $row ): string {
		$subject = (string) ( $row['subject'] ?? '' );
		$unread  = null === ( $row['read_at'] ?? null );

		/** This filter is documented in includes/Modules/Alerts/Http/AlertsController.php */
		$link = (string) apply_filters(
			'plugixa_exam_alert_link',
			'',
			(string) ( $row['context_type'] ?? '' ),
			(int) ( $row['context_id'] ?? 0 ),
			(string) ( $row['alert_type'] ?? '' )
		);

		$label = $this->label( (string) ( $row['alert_type'] ?? '' ), $subject );

		// A link only when there is somewhere to go. An anchor with an empty href reloads the
		// page the reader is already on, which reads as a broken feed rather than as no link.
		$body = '' === $link
			? '<span class="pxe-alerts__label">' . esc_html( $label ) . '</span>'
			: '<a class="pxe-alerts__label" href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';

		return '<li class="pxe-alerts__item' . ( $unread ? ' is-unread' : '' ) . '">'
			. $body
			. '<time class="pxe-alerts__when" datetime="' . esc_attr( (string) ( $row['created_at'] ?? '' ) ) . '">'
			. esc_html( $this->when( $row['created_at'] ?? null ) )
			. '</time>'
			. '</li>';
	}

	/**
	 * What a row says.
	 *
	 * An unknown type falls back to the snapshot on its own rather than to a generic
	 * "Notification" — a premium module firing `plugixa_exam_alert` with a type this build has
	 * never heard of should still produce a readable line.
	 *
	 * @param string $type    Alert type.
	 * @param string $subject Snapshot label.
	 */
	private function label( string $type, string $subject ): string {
		switch ( $type ) {
			case 'result_ready':
				/* translators: %s is the exam title. */
				return sprintf( __( 'Your result is ready: %s', 'plugixa-exam' ), $subject );

			case 'needs_marking':
				/* translators: %s is the exam title. */
				return sprintf( __( 'A paper needs marking: %s', 'plugixa-exam' ), $subject );

			default:
				return $subject;
		}
	}

	/**
	 * A stored UTC datetime in the site's timezone and format.
	 *
	 * @param mixed $value Stored value.
	 */
	private function when( mixed $value ): string {
		$timestamp = plugixa_exam_timestamp( $value );

		if ( 0 === $timestamp ) {
			return '';
		}

		return (string) wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * @param string $html Already-escaped inner markup.
	 */
	private function wrap( string $html ): string {
		return '<div class="pxe pxe--embed pxe-alerts" data-pxe-private="1">' . $html . '</div>';
	}
}
