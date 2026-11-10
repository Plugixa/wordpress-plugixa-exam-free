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
 * What each front-end template needs, resolved once.
 *
 * TEMPLATES ARE OVERRIDABLE BY THEMES, so they must not contain logic worth getting wrong.
 * A theme author copying `exam.php` into their theme to change its markup should inherit
 * every access decision unchanged; if the gate were evaluated in the template, their copy
 * would freeze whatever the rules were on the day they copied it.
 *
 * So templates read from here and render. Nothing else.
 *
 * `includes/Core` may not name a module, so the exam and attempt lookups go through filters
 * that the Exams and Attempts modules answer. With those modules absent every view returns
 * null and the templates render their empty state — which is the right failure for a
 * front end whose whole subject matter has been switched off.
 */
final class ViewData {

	/**
	 * @param Router $router The router.
	 */
	public function __construct( private readonly Router $router ) {}

	/**
	 * The exam this request is about, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function exam(): ?array {
		return $this->exam_by_slug( (string) get_query_var( Router::SLUG ) );
	}

	/**
	 * An exam by slug, for a landing page that is not at a route.
	 *
	 * The twin of {@see self::exam_by_id()}, and it takes the SLUG because that is what a
	 * link carries: `[plugixa_exam_landing]` on a page the site owner built reads
	 * `Router::PARAM_EXAM` from the query string of THEIR page, where no rewrite rule has
	 * set `Router::SLUG`. Same visibility rule — an unpublished exam resolves to null.
	 *
	 * @param string $slug Exam slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function exam_by_slug( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}

		/**
		 * Resolve an exam by slug for the front end.
		 *
		 * @param array<string, mixed>|null $exam The exam row.
		 * @param string                    $slug Exam slug.
		 */
		$exam = apply_filters( 'plugixa_exam_frontend_exam', null, $slug );

		return is_array( $exam ) ? $exam : null;
	}

	/**
	 * The access-gate verdict for the current visitor on the current exam.
	 *
	 * @param array<string, mixed>|null $exam The exam, if already loaded.
	 *
	 * @return array<string, mixed>|null
	 */
	public function gate( ?array $exam = null ): ?array {
		$exam ??= $this->exam();

		if ( null === $exam ) {
			return null;
		}

		/**
		 * Evaluate the access gate for the front end.
		 *
		 * @param array<string, mixed>|null $verdict The verdict.
		 * @param array<string, mixed>      $exam    The exam row.
		 */
		$verdict = apply_filters( 'plugixa_exam_frontend_gate', null, $exam );

		return is_array( $verdict ) ? $verdict : null;
	}

	/**
	 * The attempt this request is about, hydrated for the runner, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function attempt(): ?array {
		$id = absint( get_query_var( Router::ID ) );

		if ( 0 === $id ) {
			return null;
		}

		/**
		 * Load an attempt for the front end, subject to ownership.
		 *
		 * The listener must refuse an attempt the visitor does not own — this is the same
		 * check the REST runner makes, and a template is not the place to re-derive it.
		 *
		 * @param array<string, mixed>|null $attempt The attempt payload.
		 * @param int                       $id      Attempt id.
		 */
		$attempt = apply_filters( 'plugixa_exam_frontend_attempt', null, $id );

		return is_array( $attempt ) ? $attempt : null;
	}

	/**
	 * The result for the attempt in the URL, if the visitor may see it.
	 *
	 * @return array<string, mixed>|null
	 */
	public function result(): ?array {
		return $this->result_by_id( absint( get_query_var( Router::ID ) ) );
	}

	/**
	 * A specific attempt's result, if the visitor may see it.
	 *
	 * The twin of {@see self::attempt_by_id()}, and it exists for the same kind of reason:
	 * a result no longer only ever comes from the URL. `[plugixa_exam_result]` on a page the
	 * site owner built reads the attempt from a query argument on THEIR page, where there is
	 * no rewrite rule to have set `Router::ID`.
	 *
	 * ⚠️ THE OWNERSHIP CHECK IS THE SAME ONE, AND THAT IS THE WHOLE POINT OF ROUTING BOTH
	 * THROUGH THIS FILTER. A shortcode that read the attempt itself would be a second place
	 * for "may this person see this mark" to be answered, on the half of the plugin where
	 * getting it wrong shows somebody else's score.
	 *
	 * @param int $id Attempt id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function result_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		/**
		 * Load an attempt's result for the front end, subject to ownership.
		 *
		 * The listener must refuse an attempt the visitor does not own, and must respect the
		 * exam's release policy — an unreleased score is withheld from the payload entirely
		 * rather than merely hidden by the template.
		 *
		 * @param array<string, mixed>|null $result The result payload.
		 * @param int                       $id     Attempt id.
		 */
		$result = apply_filters( 'plugixa_exam_frontend_result', null, $id );

		return is_array( $result ) ? $result : null;
	}

	/**
	 * A specific attempt, hydrated for the runner, if the visitor owns it.
	 *
	 * The runner reaches an attempt through the gate's `resume` id rather than a URL
	 * parameter, so there is no query var to read — but the ownership check is the same one,
	 * and it still runs. A visitor who somehow supplies another person's id gets null.
	 *
	 * @param int $id Attempt id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function attempt_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		/** This filter is documented in includes/Core/Frontend/ViewData.php */
		$attempt = apply_filters( 'plugixa_exam_frontend_attempt', null, $id );

		return is_array( $attempt ) ? $attempt : null;
	}

	/**
	 * An exam by id, for an embed that names one.
	 *
	 * Same visibility rule as {@see exam()}: unpublished and deleted exams resolve to null,
	 * so a shortcode cannot be used to preview a draft paper by guessing ids.
	 *
	 * @param int $id Exam id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function exam_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		/**
		 * Resolve an exam by id for the front end.
		 *
		 * @param array<string, mixed>|null $exam    The exam row.
		 * @param int                       $exam_id Exam id.
		 */
		$exam = apply_filters( 'plugixa_exam_frontend_exam_by_id', null, $id );

		return is_array( $exam ) ? $exam : null;
	}

	/**
	 * Published, currently-open exams for the catalogue.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function catalogue(): array {
		/**
		 * The exams a visitor may see listed.
		 *
		 * @param array<int, array<string, mixed>> $exams The catalogue.
		 */
		return (array) apply_filters( 'plugixa_exam_frontend_catalogue', array() );
	}

	/**
	 * The router, for building links inside a template.
	 */
	public function router(): Router {
		return $this->router;
	}
}
