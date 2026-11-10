<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams\Frontend;

use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Attempts\Services\Outcome;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers Core's front-end questions about exams.
 *
 * `Core\Frontend\ViewData` asks; this replies. Core may not name a module, so the templates
 * reach exam data through filters and this is where those filters are satisfied. Remove the
 * Exams module and the catalogue renders empty rather than fatalling.
 */
final class ExamBridge {

	/**
	 * @param ExamRepository    $exams    The repository.
	 * @param AttemptRepository $attempts Where a learner's own history is counted.
	 */
	public function __construct(
		private readonly ExamRepository $exams,
		private readonly AttemptRepository $attempts
	) {}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_frontend_exam', array( $this, 'by_slug' ), 10, 2 );
		add_filter( 'plugixa_exam_frontend_exam_by_id', array( $this, 'by_id' ), 10, 2 );
		add_filter( 'plugixa_exam_frontend_catalogue', array( $this, 'catalogue' ) );
		add_filter( 'plugixa_exam_search_exams', array( $this, 'search' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed>|null $exam Current value.
	 * @param string                    $slug Exam slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function by_slug( $exam, string $slug ): ?array {
		unset( $exam );

		$row = $this->exams->find_by_slug( $slug );

		// An unpublished exam is invisible to the public, including by direct URL — the slug
		// is guessable and a draft paper is the one thing that must never leak.
		return null !== $row && ! empty( $row['published'] ) ? $this->with_outcome( $row ) : null;
	}

	/**
	 * @param array<string, mixed>|null $exam    Current value.
	 * @param int                       $exam_id Exam id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function by_id( $exam, int $exam_id ): ?array {
		unset( $exam );

		$row = $this->exams->find( $exam_id );

		return null !== $row && ! empty( $row['published'] ) ? $this->with_outcome( $row ) : null;
	}

	/**
	 * Published exams that are open now, or open later.
	 *
	 * Closed exams are omitted: listing something nobody can start is a support ticket, and
	 * the landing page explains the window for anyone holding a direct link.
	 *
	 * @param array<int, array<string, mixed>> $exams Current value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function catalogue( array $exams ): array {
		unset( $exams );

		$now = current_time( 'mysql', true );

		$rows = $this->exams->query()
			->where( 't.published = %d', array( 1 ) )
			->where( '(t.open_until IS NULL OR t.open_until >= %s)', array( $now ) )
			->order_by( 't.open_from', 'ASC' )
			->order_by( 't.title', 'ASC' )
			->paginate( 100 )
			->get();

		return $this->with_outcomes( array_map( array( $this->exams, 'cast' ), $rows ) );
	}

	/**
	 * Attach where the signed-in learner stands on each exam.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * ⚠️ SIGNED OUT MEANS NOT ONE QUERY, AND THAT IS A CACHING DECISION AS MUCH AS A COST.
	 *
	 * A catalogue showing YOUR history is a per-visitor page. For a visitor with no account
	 * there is no history, so this returns the rows untouched and the markup is byte for
	 * byte what it was before — which keeps a public catalogue page cacheable, the way the
	 * readme says it is. The moment somebody signs in the page is theirs alone, and
	 * `Core\Frontend\Embeds` sends the no-cache headers for exactly those requests.
	 *
	 * ⚠️ AND IT IS ONE QUERY FOR THE WHOLE PAGE, not one per card. See
	 * `AttemptRepository::outcomes_for_user()`.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param array<string, mixed> $exam One cast exam row.
	 *
	 * @return array<string, mixed>
	 */
	private function with_outcome( array $exam ): array {
		/*
		 * ⚠️ THE LANDING PAGE NEEDS THIS TOO, AND IT DOES NOT COME THROUGH THE CATALOGUE.
		 *
		 * `/exams/<slug>/` resolves through `by_slug()`, not through `catalogue()`, so a
		 * learner who reached the Start button from a bookmark or an email would have seen
		 * a plain "Start" with nothing saying they had already passed — which is the exact
		 * moment the question "why am I doing this again?" gets asked.
		 */
		return $this->with_outcomes( array( $exam ) )[0];
	}

	/**
	 * @param array<int, array<string, mixed>> $exams Cast exam rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function with_outcomes( array $exams ): array {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || array() === $exams ) {
			return $exams;
		}

		$aggregates = $this->attempts->outcomes_for_user(
			$user_id,
			array_map( static fn ( array $exam ): int => (int) $exam['id'], $exams )
		);

		foreach ( $exams as $index => $exam ) {
			$exams[ $index ]['outcome'] = Outcome::of( $exam, $aggregates[ (int) $exam['id'] ] ?? null );
		}

		return $exams;
	}

	/**
	 * Exams matching a search phrase.
	 *
	 * Asked by the Ecosystem module, which registers the WordPress search handler and the
	 * `[plugixa_exam_search]` shortcode but is not allowed to know what an exam is.
	 *
	 * WHAT IS SEARCHABLE, AND WHAT IS DELIBERATELY NOT. Title, description and the intro a
	 * candidate reads before starting. NOT the questions: a site search that surfaces the
	 * text of an exam question, with the exam's name next to it, has leaked the paper. That
	 * is the whole reason this method exists rather than the catalogue being handed to a
	 * generic indexer.
	 *
	 * @param array{ids: int[], total: int}|null $result Current value.
	 * @param array<string, mixed>               $args   search, page, per_page, include_upcoming.
	 *
	 * @return array{ids: int[], total: int}
	 */
	public function search( $result, array $args ): array {
		unset( $result );

		global $wpdb;

		$search   = trim( (string) ( $args['search'] ?? '' ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 10 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$now      = current_time( 'mysql', true );

		$query = $this->exams->query()->where( 't.published = %d', array( 1 ) );

		$query->where( '(t.open_until IS NULL OR t.open_until >= %s)', array( $now ) );

		if ( empty( $args['include_upcoming'] ) ) {
			$query->where( '(t.open_from IS NULL OR t.open_from <= %s)', array( $now ) );
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$query->where(
				'(t.title LIKE %s OR t.description LIKE %s OR t.intro LIKE %s)',
				array( $like, $like, $like )
			);
		}

		$total = $query->count();

		$rows = $query
			->order_by( 't.title', 'ASC' )
			->paginate( $per_page, ( $page - 1 ) * $per_page )
			->get();

		return array(
			'ids'   => array_map( static fn( array $row ): int => (int) $row['id'], $rows ),
			'total' => $total,
		);
	}
}
