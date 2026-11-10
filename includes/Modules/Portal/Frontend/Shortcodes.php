<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Portal\Frontend;

use PlugixaExam\Core\Frontend\Embeds;
use PlugixaExam\Core\Frontend\PrivateResponse;
use PlugixaExam\Core\Frontend\TemplateLoader;
use PlugixaExam\Core\Frontend\ViewRenderer;
use PlugixaExam\Modules\Portal\Services\Portal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three learner-facing embeds: what I have been given, what I scored, what I earned.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * NONE OF THESE MAY EVER BE CACHED, AND THAT IS THE WHOLE DESIGN OF THIS CLASS.
 *
 * Each one renders a single named person's data. On a site with full-page caching, output
 * like this served the ordinary way is stored under the URL and handed to the next visitor
 * — somebody else's results, on somebody else's screen, with nothing to indicate anything
 * went wrong. So every method here goes through {@see wrap()}, which marks the response
 * with {@see PrivateResponse} — the no-cache headers a proxy reads AND the `DONOTCACHEPAGE`
 * constant the caching plugins read — and labels the wrapper `data-pxe-private`. (That
 * attribute used to be described here as something caching plugins honour. None does; the
 * constant is what they share.)
 *
 * AND THEY RENDER NOTHING WHEN SIGNED OUT — not a login prompt. A prompt is precisely the
 * thing a page cache would be delighted to store in place of a dashboard, and it would then
 * be shown to the signed-in learner it was cached over.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHY SHORTCODES AND BLOCKS BOTH. The blocks in `blocks/` render through these same
 * methods, so a theme that overrides `parts/my-results.php` changes both. Three templates
 * with two callers each is the arrangement that cannot drift.
 */
final class Shortcodes {

	/**
	 * Every tag this class owns, and the method that renders it.
	 *
	 * @var array<string, string>
	 */
	private const TAGS = array(
		'plugixa_exam_dashboard'       => 'dashboard',
		'plugixa_exam_my_exams'        => 'my_exams',
		'plugixa_exam_my_results'      => 'my_results',
		'plugixa_exam_my_certificates' => 'my_certificates',
	);

	/**
	 * Which routed view each tag is the embedded form of.
	 *
	 * ⚠️ THE DASHBOARD AND "MY EXAMS" ARE THE SAME VIEW, WHICH IS NOT AN OVERSIGHT. Both
	 * render `portal`; the difference is how much of it. `/my-exams/` has always shown
	 * assignments alone, and the dashboard is the overview a site owner puts at the top of
	 * their learner area — one component, one state shape, a `sections` attribute deciding
	 * what is drawn. A second view name would mean a second template and a second entry on
	 * the rewrite whitelist for a page that is the same page.
	 *
	 * @var array<string, string>
	 */
	private const VIEWS = array(
		'plugixa_exam_dashboard'       => 'portal',
		'plugixa_exam_my_exams'        => 'portal',
		'plugixa_exam_my_results'      => 'results',
		'plugixa_exam_my_certificates' => 'certificates',
	);

	/**
	 * @param Portal $portal The learner's own data.
	 */
	public function __construct( private readonly Portal $portal ) {}

	public function register_hooks(): void {
		foreach ( self::TAGS as $tag => $method ) {
			add_shortcode( $tag, array( $this, $method ) );
		}

		add_filter( 'plugixa_exam_needs_frontend_assets', array( $this, 'needs_assets' ), 10, 2 );
		add_filter( 'plugixa_exam_frontend_entry', array( $this, 'frontend_entry' ), 10, 2 );
	}

	/**
	 * Whether the page being rendered embeds one of these.
	 *
	 * Only post content is inspected, the same limitation the rest of the front end
	 * documents: a shortcode inside a widget or a page builder's own storage is invisible
	 * before the page is built, and those cases opt in through this same filter.
	 *
	 * @param bool   $needed Whether the assets are already known to be needed.
	 * @param string $view   The resolved rewrite view, or an empty string.
	 */
	public function needs_assets( bool $needed, string $view ): bool {
		unset( $view );

		return $needed || Embeds::has_any( array_keys( self::TAGS ) );
	}

	/**
	 * The bundle a page carrying one of these tags needs, if any.
	 *
	 * @param string $entry The entry already decided on, or ''.
	 * @param string $view  The resolved rewrite view, or an empty string.
	 */
	public function frontend_entry( string $entry, string $view ): string {
		unset( $view );

		if ( '' !== $entry ) {
			return $entry;
		}

		return Embeds::boots_app( self::VIEWS ) ? 'app' : '';
	}

	/**
	 * `[plugixa_exam_dashboard sections="exams,results,certificates" limit="10"]`
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE ONE PAGE A LEARNER CAN BE SENT TO, RATHER THAN THREE THEY HAVE TO VISIT.
	 *
	 * `/my-exams/`, `/my-results/` and `/my-certificates/` are three destinations, which is
	 * right for a menu and wrong for a landing. A site owner who wants "your learning, all
	 * of it" had to build that page out of three shortcodes and hope the headings lined up.
	 *
	 * One `Portal::overview()` call answers all three sections — the same array the three
	 * separate pages each read one third of — so the whole dashboard costs one pass, not
	 * three.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function dashboard( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'sections' => 'exams,results,certificates',
				'limit'    => 10,
			),
			is_array( $atts ) ? $atts : array(),
			'plugixa_exam_dashboard'
		);

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$wanted = array_filter( array_map( 'trim', explode( ',', (string) $atts['sections'] ) ) );
		$limit  = max( 1, min( 200, (int) $atts['limit'] ) );

		$overview = $this->portal->overview( $user_id );
		$markup   = '';

		/*
		 * ⚠️ THE SECTIONS ARE HEADED HERE AND NOWHERE ELSE, WHICH IS WHY IT LOOKS ASYMMETRIC.
		 *
		 * `[plugixa_exam_my_results]` on its own sits under a page called "My results" — the
		 * owner already named it, and a heading repeating that is the commonest way a page
		 * ends up saying the same thing twice. Stack all three on ONE page and the opposite
		 * is true: without headings a learner gets an assignments list, a scores list and a
		 * certificates list run together with nothing saying where one ends.
		 *
		 * Caught on screen. Every assertion passed against three unlabelled lists.
		 */
		$heading = static fn ( string $text ): string =>
			'<h2 class="pxe-section-title">' . esc_html( $text ) . '</h2>';

		if ( in_array( 'exams', $wanted, true ) ) {
			$markup .= $heading( __( 'My exams', 'plugixa-exam' ) );
			$markup .= TemplateLoader::render(
				'parts/my-exams.php',
				array( 'pxe_groups' => $overview['exams'] )
			);
		}

		if ( in_array( 'results', $wanted, true ) ) {
			$markup .= $heading( __( 'My results', 'plugixa-exam' ) );
			$markup .= TemplateLoader::render(
				'parts/my-results.php',
				array( 'pxe_results' => array_slice( $overview['results'], 0, $limit ) )
			);
		}

		if ( in_array( 'certificates', $wanted, true ) ) {
			$markup .= $heading( __( 'My certificates', 'plugixa-exam' ) );
			$markup .= TemplateLoader::render(
				'parts/my-certificates.php',
				array( 'pxe_certificates' => $overview['certificates'] )
			);
		}

		return $this->wrap( ViewRenderer::around( 'portal', $overview, $markup ) );
	}

	/**
	 * `[plugixa_exam_my_exams]`
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function my_exams( $atts = array() ): string {
		unset( $atts );

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$groups = $this->portal->assignments( $user_id );

		return $this->wrap(
			ViewRenderer::render(
				'portal',
				array( 'exams' => $groups ),
				'parts/my-exams.php',
				array( 'pxe_groups' => $groups )
			)
		);
	}

	/**
	 * `[plugixa_exam_my_results limit="10"]`
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function my_results( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'limit' => 10 ),
			is_array( $atts ) ? $atts : array(),
			'plugixa_exam_my_results'
		);

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$results = $this->portal->results( $user_id, (int) $atts['limit'] );

		return $this->wrap(
			ViewRenderer::render(
				'results',
				array( 'results' => $results ),
				'parts/my-results.php',
				array( 'pxe_results' => $results )
			)
		);
	}

	/**
	 * `[plugixa_exam_my_certificates]`
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function my_certificates( $atts = array() ): string {
		unset( $atts );

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$certificates = $this->portal->certificates( $user_id );

		return $this->wrap(
			ViewRenderer::render(
				'certificates',
				array( 'certificates' => $certificates ),
				'parts/my-certificates.php',
				array( 'pxe_certificates' => $certificates )
			)
		);
	}

	/**
	 * Wrap rendered output, and keep it out of a page cache.
	 *
	 * @param string $html Rendered template output.
	 */
	private function wrap( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		/*
		 * Called at RENDER time, which is late but not too late: WordPress sends headers
		 * when the first output is flushed, and a shortcode runs during `the_content`,
		 * inside the buffered template. A caching plugin that ignores headers reads the
		 * constant this also defines.
		 */
		PrivateResponse::mark();

		return '<div class="pxe pxe--embed" data-pxe-private="1">' . $html . '</div>';
	}
}
