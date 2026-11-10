<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site owner's own pages, standing in for the plugin's routes.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY PAGES, WHEN {@see Router} ALREADY ARGUES FOR ROUTES.
 *
 * Both are right, for different halves of the same product. A certificate's QR code encodes
 * `/verify/<token>/` and is printed on paper; a results email links `/result/54840/` and may
 * be opened months later. Those cannot depend on somebody having created a page and left it
 * there, which is exactly what `Router`'s opening note says.
 *
 * But the pages a LEARNER navigates between are a different matter. They belong in the site
 * owner's own menu, with the owner's own names, in the owner's own information architecture
 * — the way every plugin that owns a section of a WordPress site has always worked. A plugin
 * that instead hands over fixed URLs and its own navigation reads as a plugin that does not
 * fit in the site it was installed on.
 *
 * So the routes always work and always exist, and a mapped page wins where one is named.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ A VIEW IS MAPPABLE ONLY IF A SHORTCODE CAN RENDER IT. That is what {@see self::VIEWS}
 * is: view => the tag that draws it. Letting the settings store a page for a view no tag can
 * render would point every link in the plugin at a page that renders nothing — and the site
 * owner would have configured it exactly as instructed.
 */
final class Pages {

	/** The settings key holding the map. */
	public const SETTING = 'pages';

	/**
	 * Every view that can live on a page, and the shortcode that draws it there.
	 *
	 * ⚠️ `take` AND `verify` WILL NEVER BE HERE, and neither is an oversight — see
	 * `Router::page_url()`. Everything else a learner navigates to is mappable.
	 *
	 * `portal` is the dashboard AND "my exams": one view, one template, a `sections`
	 * attribute deciding how much of it is drawn.
	 *
	 * @var array<string, string>
	 */
	public const VIEWS = array(
		'catalogue'    => 'plugixa_exam_catalogue',
		'exam'         => 'plugixa_exam_landing',
		'result'       => 'plugixa_exam_result',
		'portal'       => 'plugixa_exam_my_exams',
		'results'      => 'plugixa_exam_my_results',
		'certificates' => 'plugixa_exam_my_certificates',
	);

	/**
	 * The destination that is REMEMBERED but never LINKED TO.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * ⚠️ THIS EXISTS BECAUSE TRACKING A PAGE BY ITS SLUG LOST IT, AND THEN MADE ANOTHER.
	 *
	 * `dashboard` is deliberately absent from {@see self::VIEWS} — nothing in the plugin
	 * builds a link to a dashboard, and putting it there would hand `Router::link()` a
	 * target it must never choose. But "not a link target" was read as "needs no id", and
	 * the only thing identifying the page became the literal slug `my-learning`.
	 *
	 * Measured on a real site: the owner's dashboard sat at `/learning/`, the setup button
	 * looked for `/my-learning/`, found nothing, and published a SECOND page with the same
	 * title and the same shortcode. Both then appeared in the theme's Page List, and both
	 * appeared in this panel's page dropdown, indistinguishable. Renaming a permalink,
	 * translating a slug or nesting the page under a parent all do the same thing.
	 *
	 * So the id is stored like any other, and `VIEWS` still says what may be linked to.
	 * The two questions were never the same question.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	public const STANDALONE = 'dashboard';

	/**
	 * Every key the stored map may carry — what may be LINKED TO, plus what is REMEMBERED.
	 *
	 * @return array<int, string>
	 */
	public static function tracked(): array {
		return array_merge( array_keys( self::VIEWS ), array( self::STANDALONE ) );
	}

	/**
	 * The pages the setup button offers to create, in the order a menu would list them.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * ⚠️ FIVE, NOT SEVEN, AND THE TWO MISSING ONES ARE THE INTERESTING PART.
	 *
	 * `exam` and `result` are LINK TARGETS, not destinations. They render nothing without
	 * `?pxe_exam=` or `?pxe_result=`, so a menu item pointing at either shows a visitor an
	 * empty page — and their built-in routes are better addresses anyway: `/exams/algebra/`
	 * reads better than `/exam/?pxe_exam=algebra`, and `/result/54840/` is what a results
	 * email has been linking to for as long as the plugin has sent them.
	 *
	 * Measured on a block theme, whose header lists every published page: creating all seven
	 * put "Exam" and "Result" into the site's navigation beside the real destinations. They
	 * remain mappable in {@see self::VIEWS} for an owner who deliberately wants them hosted;
	 * they are simply not part of the default setup.
	 *
	 * `dashboard` is the other way round — it has a tag but NO view, because nothing in the
	 * plugin links to a dashboard. It is the page an owner puts at the top of their learner
	 * area and reaches from their own menu, so it is created and never mapped.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function setup(): array {
		return array(
			'dashboard'    => array(
				'title' => __( 'My learning', 'plugixa-exam' ),
				'slug'  => 'my-learning',
				'tag'   => 'plugixa_exam_dashboard',
				'view'  => '',
			),
			'catalogue'    => array(
				'title' => __( 'Exams', 'plugixa-exam' ),
				'slug'  => 'exams-list',
				'tag'   => 'plugixa_exam_catalogue',
				'view'  => 'catalogue',
			),
			'portal'       => array(
				'title' => __( 'My exams', 'plugixa-exam' ),
				'slug'  => 'my-exams-page',
				'tag'   => 'plugixa_exam_my_exams',
				'view'  => 'portal',
			),
			'results'      => array(
				'title' => __( 'My results', 'plugixa-exam' ),
				'slug'  => 'my-results-page',
				'tag'   => 'plugixa_exam_my_results',
				'view'  => 'results',
			),
			'certificates' => array(
				'title' => __( 'My certificates', 'plugixa-exam' ),
				'slug'  => 'my-certificates-page',
				'tag'   => 'plugixa_exam_my_certificates',
				'view'  => 'certificates',
			),
		);
	}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );
		add_filter( 'plugixa_exam_sanitize_settings', array( $this, 'sanitize_settings' ) );
	}

	/** Whether a link context can be answered by a page at all. */
	public static function supports( string $view ): bool {
		return isset( self::VIEWS[ $view ] );
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge( $defaults, array( self::SETTING => array() ) );
	}

	/**
	 * @param array<string, mixed> $clean The whitelisted input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $clean ): array {
		if ( ! array_key_exists( self::SETTING, $clean ) ) {
			return $clean;
		}

		/*
		 * ⚠️ THIS IS THE ONLY THING BETWEEN A REQUEST AND THIS OPTION, AND `Settings::save()`
		 * DOES NOT HELP. Its generic pass runs `sanitize_text_field()` over scalars — an
		 * array is not a scalar, so an unclaimed array key is written to the option exactly
		 * as it arrived, with whatever keys and whatever values were in it.
		 *
		 * So the map is rebuilt rather than filtered: only views this plugin knows survive,
		 * every value becomes a positive integer, and anything else is simply not copied
		 * across.
		 */
		$input = is_array( $clean[ self::SETTING ] ) ? $clean[ self::SETTING ] : array();
		$map   = array();

		/*
		 * ⚠️ `tracked()`, NOT `array_keys( self::VIEWS )`. The dashboard is not a link
		 * target and never will be, but it IS remembered — and iterating the link targets
		 * silently dropped its id on every save, which is what sent the setup button back
		 * to looking the page up by slug. See {@see self::STANDALONE}.
		 */
		foreach ( self::tracked() as $view ) {
			/*
			 * ⚠️ NOT `absint()`, WHICH IS THE OBVIOUS CHOICE AND THE WRONG ONE. It takes the
			 * ABSOLUTE value, so `-5` arrives as page 5 — a real post id, belonging to
			 * something else entirely, saved without complaint. Measured: a test passing -5
			 * got `array( 'results' => 5 )` back out of the option.
			 */
			$id = (int) ( $input[ $view ] ?? 0 );

			if ( $id > 0 ) {
				$map[ $view ] = $id;
			}
		}

		$clean[ self::SETTING ] = $map;

		return $clean;
	}
}
