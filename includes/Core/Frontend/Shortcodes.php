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
 * Exam pages, embeddable in somebody else's page.
 *
 * WHY SHORTCODES EXIST WHEN THE ROUTES ALREADY DO. The routes in {@see Router} are the
 * canonical URLs and always work. But a site's exam catalogue usually belongs halfway down
 * a page that already has a heading, an explanation and a picture — not at `/exams/`, alone,
 * under a bare `<h1>`. A shortcode is how that page gets it, and it is still the only
 * embedding mechanism that works in a classic editor, a widget, a page builder and a block
 * (through the block editor's shortcode block) without four implementations.
 *
 * EVERY SHORTCODE RENDERS THE SAME TEMPLATE PARTS THE ROUTES DO. `[plugixa_exam_catalogue]`
 * and `/exams/` both `require parts/catalogue-list.php`. That is not a tidiness point: a
 * theme that overrides a part to restyle its cards would otherwise find the restyle applies
 * on one of the two and not the other, with nothing on screen explaining why.
 *
 * WHAT IS DELIBERATELY NOT HERE. The three learner embeds belong to the Portal module and
 * `[plugixa_exam_verify]` to Certificates, each registered by the module that can actually
 * answer it. A tag whose module is absent is not registered-and-disabled: it does not
 * exist, and an unregistered shortcode renders as its own literal text, which is the honest
 * outcome — WordPress has no idea what it is, because in this build nothing does.
 *
 * ESCAPING. Every template escapes at the point of output. This class returns their buffered
 * result unchanged and must not "helpfully" run it through anything: a second pass over
 * finished HTML would double-encode the exam titles.
 */
final class Shortcodes {

	/**
	 * Query-string parameter the search form submits under.
	 *
	 * Not `s`. WordPress owns `s`, and using it would hand the main query a search term for
	 * posts on every exam search — turning a page with this shortcode on it into a search
	 * results page, in some themes visibly.
	 */
	private const SEARCH_PARAM = 'pxe_s';

	/**
	 * Query-string parameter naming the attempt `[plugixa_exam_result]` shows.
	 *
	 * The same shape {@see self::SEARCH_PARAM} already uses, and for the same reason: on a
	 * page the site owner built there is no rewrite rule to have set `Router::ID`, so the
	 * attempt travels in the query string of THEIR page.
	 */
	private const RESULT_PARAM = 'pxe_result';

	/**
	 * Every tag this class owns, and the view it mounts — or '' for markup with no view.
	 *
	 * @var array<string, string>
	 */
	private const TAGS = array(
		'plugixa_exam_catalogue' => '',
		'plugixa_exam'           => '',
		'plugixa_exam_search'    => '',
		'plugixa_exam_result'    => 'result',
		'plugixa_exam_landing'   => 'exam',
	);

	/**
	 * @param ViewData $data   Resolved view data.
	 * @param Router   $router Link builder.
	 */
	public function __construct(
		private readonly ViewData $data,
		private readonly Router $router
	) {}

	public function register_hooks(): void {
		add_shortcode( 'plugixa_exam_catalogue', array( $this, 'catalogue' ) );
		add_shortcode( 'plugixa_exam', array( $this, 'exam' ) );
		add_shortcode( 'plugixa_exam_search', array( $this, 'search' ) );
		add_shortcode( 'plugixa_exam_result', array( $this, 'result' ) );
		add_shortcode( 'plugixa_exam_landing', array( $this, 'landing' ) );

		// The search form submits a GET parameter of its own; `query_vars` is what stops
		// WordPress discarding it before a template can read it.
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// A shortcode is on a page with no rewrite view, so the stylesheet would not
		// otherwise load and the cards would render unstyled.
		add_filter( 'plugixa_exam_needs_frontend_assets', array( $this, 'needs_assets' ), 10, 2 );

		// And the bundle, for the tags that mount a view rather than print a list of cards.
		add_filter( 'plugixa_exam_frontend_entry', array( $this, 'frontend_entry' ), 10, 2 );
	}

	/**
	 * Whether the page being rendered embeds one of these shortcodes.
	 *
	 * ONLY POST CONTENT IS INSPECTED, and that is a real limitation rather than an
	 * oversight: a shortcode placed in a widget, a block template part or a page builder's
	 * own storage is invisible here, because WordPress offers no way to ask "does anything
	 * on this page contain X" before the page is built. Those cases opt in by returning
	 * true from this same filter, which is why it is a filter.
	 *
	 * Enqueuing the stylesheet everywhere instead would be the lazy fix and is the wrong
	 * one — it is a few kilobytes on every page of a site that may have no exams at all.
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

		return Embeds::boots_app( self::TAGS ) ? 'app' : '';
	}

	/**
	 * @param string[] $vars Registered query vars.
	 *
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::SEARCH_PARAM;
		$vars[] = self::RESULT_PARAM;

		return $vars;
	}

	/**
	 * `[plugixa_exam_catalogue]` — every open exam, as cards.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function catalogue( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit' => 0,
				'empty' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'plugixa_exam_catalogue'
		);

		$exams = $this->data->catalogue();
		$limit = (int) $atts['limit'];

		if ( $limit > 0 ) {
			$exams = array_slice( $exams, 0, $limit );
		}

		return $this->wrap(
			TemplateLoader::render(
				'parts/catalogue-list.php',
				array(
					'pxe_exams'  => $exams,
					'pxe_router' => $this->router,
					'pxe_empty'  => '' !== (string) $atts['empty']
						? (string) $atts['empty']
						: __( 'There are no exams available at the moment.', 'plugixa-exam' ),
				)
			),
			/*
			 * ⚠️ THE CATALOGUE BECAME PER-VISITOR THE DAY IT STARTED SHOWING YOUR OWN MARKS.
			 *
			 * Every card now carries a completion badge for the signed-in reader, so this
			 * output is that person's history and a full-page cache would hand it to the
			 * next visitor — "✓ Passed — 92%" on somebody else's screen, with nothing on the
			 * page to say anything went wrong. Same hazard as `my-results`, arrived at from
			 * a page that used to be perfectly public.
			 *
			 * ⚠️ AND IT IS STILL PUBLIC FOR A VISITOR WITH NO ACCOUNT, which is the half
			 * worth keeping. `ExamBridge::with_outcomes()` attaches nothing for user 0, so
			 * the markup is byte for byte what it always was and the page stays cacheable
			 * for exactly the audience a cache is for.
			 */
			is_user_logged_in()
		);
	}

	/**
	 * `[plugixa_exam id="12"]` — one exam, as a card linking to its landing page.
	 *
	 * Takes an id rather than a slug because that is what an author writing the shortcode
	 * has in front of them in the admin list, and because a slug can be edited afterwards
	 * — silently emptying every page that embedded the exam by its old one.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function exam( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			is_array( $atts ) ? $atts : array(),
			'plugixa_exam'
		);

		$exam = $this->data->exam_by_id( (int) $atts['id'] );

		/*
		 * An unknown, unpublished or deleted exam renders nothing at all — not an error, not
		 * a placeholder. The visitor is on somebody's page reading about something else, and
		 * a red box about a missing exam id is a message for the site owner delivered to the
		 * wrong person.
		 */
		if ( null === $exam ) {
			return '';
		}

		return $this->wrap(
			TemplateLoader::render(
				'parts/catalogue-list.php',
				array(
					'pxe_exams'  => array( $exam ),
					'pxe_router' => $this->router,
					'pxe_empty'  => '',
				)
			)
		);
	}

	/**
	 * `[plugixa_exam_search]` — a search box, and its results.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function search( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'per_page' => 10 ),
			is_array( $atts ) ? $atts : array(),
			'plugixa_exam_search'
		);

		// Sanitised in the statement that reads it. `?q[]=x` is an array, and is no search at all.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public read-only search (see parts/search.php for why a nonce would be wrong here).
		$query    = isset( $_GET[ self::SEARCH_PARAM ] ) && is_string( $_GET[ self::SEARCH_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SEARCH_PARAM ] ) ) : '';
		$searched = '' !== $query;

		$exams = array();
		$total = 0;

		if ( $searched ) {
			$result = apply_filters(
				'plugixa_exam_search_exams',
				null,
				array(
					'search'   => $query,
					'page'     => 1,
					'per_page' => max( 1, min( 50, (int) $atts['per_page'] ) ),
				)
			);

			if ( is_array( $result ) ) {
				$total = (int) ( $result['total'] ?? 0 );

				foreach ( (array) ( $result['ids'] ?? array() ) as $id ) {
					$exam = $this->data->exam_by_id( (int) $id );

					if ( null !== $exam ) {
						$exams[] = $exam;
					}
				}
			}
		}

		return $this->wrap(
			TemplateLoader::render(
				'parts/search.php',
				array(
					'pxe_query'    => $query,
					'pxe_exams'    => $exams,
					'pxe_total'    => $total,
					'pxe_searched' => $searched,
					'pxe_router'   => $this->router,
					'pxe_field'    => self::SEARCH_PARAM,
				)
			)
		);
	}

	/**
	 * `[plugixa_exam_result]` — one attempt's result, on a page the site owner built.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE SAME VIEW `/result/{id}/` RENDERS, FROM THE SAME ARRAY AND THE SAME MARKUP.
	 *
	 * `Core\Frontend\ResultState` builds the state and `parts/result-body.php` renders it,
	 * for both callers. That is not tidiness: a shortcode with its own copy of a result page
	 * is a second place for "has this score been released" to be answered, on the half of
	 * the plugin where getting it wrong shows a mark that was meant to be withheld.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * ⚠️ NO ID AT ALL RENDERS NOTHING, and that is a different case from an id that resolves
	 * to nothing. A reader who followed the site's menu to a page called "My result" without
	 * one has not asked about any particular attempt, and "This result is not available" is
	 * a refusal to a question nobody put. Whatever else the owner wrote on that page is
	 * still there. An id that IS given and cannot be shown gets the sentence, because that
	 * reader did ask.
	 *
	 * ⚠️ OWNERSHIP IS NOT DECIDED HERE. `ViewData::result_by_id()` goes through
	 * `plugixa_exam_frontend_result`, which the Attempts module answers with the same check
	 * the REST runner makes. Guessing an id in the query string gets a stranger nothing.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function result( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			is_array( $atts ) ? $atts : array(),
			'plugixa_exam_result'
		);

		$id = (int) $atts['id'];

		if ( 0 === $id ) {
			$id = absint( get_query_var( self::RESULT_PARAM ) );
		}

		if ( 0 === $id ) {
			return '';
		}

		$state = ResultState::of( $this->data, $id );

		if ( null === $state ) {
			return $this->wrap(
				'<p class="pxe__empty">' . esc_html__( 'This result is not available.', 'plugixa-exam' ) . '</p>',
				true
			);
		}

		return $this->wrap(
			ViewRenderer::render(
				'result',
				$state,
				'parts/result-body.php',
				array( 'pxe_state' => $state )
			),
			true
		);
	}

	/**
	 * `[plugixa_exam_landing]` — one exam's landing page, gate and all.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE ONE THAT MAKES A HAND-BUILT MENU COHERENT.
	 *
	 * `[plugixa_exam]` renders a CARD that links away. This renders the page a learner
	 * actually acts on — the intro, the facts, and whichever of the gate's branches applies
	 * to them. Without it, an owner's catalogue page leads to a card which leads back out to
	 * `/exams/algebra/`, and the section their menu built ends at the second click.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * Three ways to name the exam, in order: a `slug` attribute (one exam, permanently, on a
	 * page about that exam), an `id` attribute, or `?pxe_exam=` on the owner's page — which
	 * is what {@see Router::exam_url()} produces once they have mapped a page to this view,
	 * and therefore what every catalogue card links to.
	 *
	 * ⚠️ `slug` IS ACCEPTED EVEN THOUGH {@see self::exam()} TAKES AN ID ON PURPOSE. The
	 * argument there is that a slug can be edited afterwards, silently emptying every page
	 * that embedded the exam by its old one — which is right for a card dropped into an
	 * article. Here the slug is what the LINK carries, so refusing it would mean the routed
	 * URL and the page-hosted URL identified the same exam two different ways.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function landing( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'slug' => '',
				'id'   => 0,
			),
			is_array( $atts ) ? $atts : array(),
			'plugixa_exam_landing'
		);

		$slug = sanitize_title( (string) $atts['slug'] );
		$id   = (int) $atts['id'];

		if ( '' === $slug && 0 === $id ) {
			$slug = sanitize_title( (string) get_query_var( Router::PARAM_EXAM ) );
		}

		/*
		 * Nothing named at all renders nothing — the same distinction `[plugixa_exam_result]`
		 * draws. A reader who followed a menu to a page called "Exam" without naming one has
		 * not asked about any exam, and a refusal answers a question nobody put.
		 */
		if ( '' === $slug && 0 === $id ) {
			return '';
		}

		$exam = '' !== $slug
			? $this->data->exam_by_slug( $slug )
			: $this->data->exam_by_id( $id );

		if ( null === $exam ) {
			return $this->wrap(
				'<p class="pxe__empty">' . esc_html__( 'This exam is not available.', 'plugixa-exam' ) . '</p>'
			);
		}

		$gate = $this->data->gate( $exam ) ?? array(
			'allowed' => false,
			'reason'  => 'denied',
			'message' => '',
		);

		return $this->wrap(
			ViewRenderer::render(
				'exam',
				ExamState::of( $exam, $gate, $this->router ),
				'parts/exam-body.php',
				array(
					'pxe_exam'    => $exam,
					'pxe_gate'    => $gate,
					'pxe_router'  => $this->router,

					// The owner's page title is theirs; the exam still has to name itself.
					'pxe_heading' => true,
				)
			),
			/*
			 * ⚠️ PRIVATE ONLY FOR A VISITOR WITH A HISTORY HERE, AND THE ASYMMETRY IS
			 * DELIBERATE. For a first-time visitor the verdict is the same for everybody —
			 * "sign in", "enter the access code", or Start — and an exam landing page is
			 * exactly the sort of page a site wants cached. A signed-in learner's copy carries
			 * their own attempt history ("you have used 2 of 3").
			 *
			 * So does a GUEST's, once they have started: "resume your attempt", a cooldown, a
			 * paper awaiting review. This used to test `is_user_logged_in()` alone, on the
			 * reasoning that a guest's verdict is everybody's — true only until the guest has
			 * a cookie. {@see PrivateResponse::visitor_is_known()} asks both. A page reached by
			 * an expired form's redirect is fresh too — see {@see PrivateResponse::answers_a_form()}.
			 */
			PrivateResponse::visitor_is_known() || PrivateResponse::answers_a_form()
		);
	}

	/**
	 * Put embedded output inside the plugin's style scope.
	 *
	 * `.pxe` is where every `--pxe-*` custom property is defined, so a card rendered outside
	 * it inherits none of them and comes out unstyled. `pxe--embed` exists for themes that
	 * want to tell an embedded catalogue apart from the full page — the plugin's own
	 * stylesheet does not use it.
	 *
	 * Returns an empty string for empty input rather than an empty wrapper, so a shortcode
	 * that found nothing leaves no stray div behind in the page's markup.
	 *
	 * @param string $html Rendered template output.
	 */
	private function wrap( string $html, bool $private = false ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( $private ) {
			/*
			 * ⚠️ ONE PERSON'S MARKS, SO IT MUST NEVER BE STORED UNDER THE URL. On a site with
			 * full-page caching, output like this served the ordinary way is handed to the
			 * next visitor — somebody else's result, on somebody else's screen, with nothing
			 * on the page to indicate anything went wrong.
			 *
			 * Called at render time, which is late but not too late: headers go out when the
			 * first output is flushed, and a shortcode runs during `the_content`, inside the
			 * buffered template. {@see PrivateResponse} sends the headers a proxy reads and
			 * the constant the caching plugins read; `data-pxe-private` only labels the markup.
			 */
			PrivateResponse::mark();

			return '<div class="pxe pxe--embed" data-pxe-private="1">' . $html . '</div>';
		}

		return '<div class="pxe pxe--embed">' . $html . '</div>';
	}
}
