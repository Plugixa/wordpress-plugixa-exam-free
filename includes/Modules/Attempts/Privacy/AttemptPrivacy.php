<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Privacy;

use PlugixaExam\Core\Database\Schema as Db;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an attempt means to the privacy tools.
 *
 * Registered through `plugixa_exam_personal_data_providers`, which the Ecosystem module
 * asks and WordPress ultimately consumes. This class is the only place that knows an
 * attempt holds a name, an email address and a set of written answers — which is correct,
 * because it is the module that put them there.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────
 * THE ERASURE POLICY, WHICH IS A PRODUCT DECISION AND NOT A TECHNICAL ONE
 * ─────────────────────────────────────────────────────────────────────────────────────
 *
 * An erasure request anonymises the attempt in place. It does not delete it.
 *
 * `user_id`, `guest_token`, `guest_name`, `guest_email`, the grader's comment, the
 * invigilator's note and the integrity signals (which hold an IP address and a user agent)
 * are cleared. Every written answer is deleted outright, because free text is where a
 * person is most likely to have named themselves, and no amount of clearing the columns
 * around it would help.
 *
 * `score`, `max_score`, `percent`, `passed` and the frozen `question_set` survive.
 *
 * WHY NOT SIMPLY DELETE THE ROW. An exam's statistics are computed from its attempts: pass
 * rate, mean score, and — once the premium Analytics module exists — per-question facility
 * and discrimination indices. Deleting attempts moves those numbers retroactively, so a
 * question flagged last month as too hard is quietly no longer flagged and nobody can tell
 * why. It also erases the fact that a certification was ever awarded, which a training
 * provider may be required to be able to state.
 *
 * WHY THIS IS DEFENSIBLE. What remains is a row that nothing this plugin stores can tie to
 * a person: no name, no address, no account, no token, no IP, no writing. It is a score
 * attached to an exam.
 *
 * The eraser reports `items_retained => true` and says plainly what was kept, so a site
 * owner sees the trade-off rather than discovering it later. A site that cannot live with
 * it has two escapes: `plugixa_exam_erase_attempt_columns` adds columns to the wipe list,
 * and `plugixa_exam_erase_attempt_delete` switches the policy to a hard delete.
 */
final class AttemptPrivacy {

	/**
	 * Attempts handled per page of an export or an erasure.
	 *
	 * WordPress calls back repeatedly until `done`, so this only has to be small enough
	 * that one call fits comfortably inside a request. An examinee with hundreds of
	 * attempts is unusual; an export that times out on them is not acceptable.
	 */
	private const PER_PAGE = 20;

	/**
	 * @param AttemptRepository $attempts Attempt repository.
	 */
	public function __construct( private readonly AttemptRepository $attempts ) {}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_personal_data_providers', array( $this, 'provider' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $providers Accumulated providers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function provider( array $providers ): array {
		$providers[] = array(
			'id'     => 'attempts',
			'label'  => __( 'Exam attempts', 'plugixa-exam' ),
			'export' => array( $this, 'export' ),
			'erase'  => array( $this, 'erase' ),
		);

		return $providers;
	}

	/* ------------------------------------------------------------------------ export */

	/**
	 * Everything this plugin holds about one person's sittings.
	 *
	 * @param string $email Email address the request was made for.
	 * @param int    $page  1-based page.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$rows = $this->find( $email, max( 1, $page ) );

		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'plugixa-exam-attempts',
				'group_label' => __( 'Exam attempts', 'plugixa-exam' ),
				'item_id'     => 'plugixa-exam-attempt-' . (int) $row['id'],
				'data'        => $this->export_fields( $row ),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * One attempt, as the name/value pairs WordPress writes into the export file.
	 *
	 * Answers are included. Somebody asking what a site holds on them is entitled to the
	 * essay they wrote, and it is the most personal thing here.
	 *
	 * @param array<string, mixed> $row Attempt row.
	 *
	 * @return array<int, array{name: string, value: string}>
	 */
	private function export_fields( array $row ): array {
		$fields = array(
			array(
				'name'  => __( 'Exam', 'plugixa-exam' ),
				'value' => $this->exam_title( (int) $row['exam_id'] ),
			),
			array(
				'name'  => __( 'Started', 'plugixa-exam' ),
				'value' => (string) ( $row['started'] ?? '' ),
			),
			array(
				'name'  => __( 'Submitted', 'plugixa-exam' ),
				'value' => (string) ( $row['submitted'] ?? '' ),
			),
			array(
				'name'  => __( 'Status', 'plugixa-exam' ),
				'value' => (string) ( $row['status'] ?? '' ),
			),
			array(
				'name'  => __( 'Score', 'plugixa-exam' ),
				'value' => sprintf(
					/* translators: 1: score achieved. 2: maximum available. */
					__( '%1$s out of %2$s', 'plugixa-exam' ),
					(string) ( $row['score'] ?? '' ),
					(string) ( $row['max_score'] ?? '' )
				),
			),
			array(
				'name'  => __( 'Percentage', 'plugixa-exam' ),
				'value' => (string) ( $row['percent'] ?? '' ),
			),
			array(
				'name'  => __( 'Passed', 'plugixa-exam' ),
				'value' => 1 === (int) ( $row['passed'] ?? 0 )
					? __( 'Yes', 'plugixa-exam' )
					: __( 'No', 'plugixa-exam' ),
			),
		);

		$optional = array(
			'guest_name'     => __( 'Name given', 'plugixa-exam' ),
			'guest_email'    => __( 'Email address given', 'plugixa-exam' ),
			'grader_comment' => __( 'Comment from the grader', 'plugixa-exam' ),
			'monitor_note'   => __( 'Note from the invigilator', 'plugixa-exam' ),
		);

		foreach ( $optional as $column => $label ) {
			if ( '' !== (string) ( $row[ $column ] ?? '' ) ) {
				$fields[] = array(
					'name'  => $label,
					'value' => (string) $row[ $column ],
				);
			}
		}

		foreach ( array_values( $this->attempts->responses( (int) $row['id'] ) ) as $index => $response ) {
			$answer = $this->readable( $response['response'] ?? null );

			/*
			 * A question left blank contributes nothing. An empty "Answer 3:" line in an
			 * export file reads like something was withheld, which is the opposite of what
			 * an export is for — and the person reading it has no way to tell the
			 * difference between "you did not answer" and "we are not showing you".
			 */
			if ( '' === $answer ) {
				continue;
			}

			$fields[] = array(
				'name'  => sprintf(
					/* translators: %d: position of the answer within the attempt. */
					__( 'Answer %d', 'plugixa-exam' ),
					$index + 1
				),
				'value' => $answer,
			);
		}

		return $fields;
	}

	/**
	 * One stored answer, as something a person can read.
	 *
	 * Answers are stored JSON-encoded, so the shape depends on the question type: an essay
	 * decodes to a string, a multiple-choice answer to a list of option ids, a matching
	 * question to a map. Handing the raw `[2,5]` to somebody exercising a subject access
	 * request technically discloses it and practically tells them nothing, so lists are
	 * flattened and anything genuinely structured falls back to readable JSON.
	 *
	 * @param mixed $answer The decoded response.
	 */
	private function readable( mixed $answer ): string {
		if ( null === $answer || '' === $answer ) {
			return '';
		}

		if ( is_bool( $answer ) ) {
			return $answer ? __( 'True', 'plugixa-exam' ) : __( 'False', 'plugixa-exam' );
		}

		if ( is_scalar( $answer ) ) {
			/*
			 * ESCAPED, BECAUSE WORDPRESS TREATS AN EXPORTED VALUE AS HTML. The export file is
			 * built by passing each value through `wp_kses()`, which reads `x <= 3 && y >= 4`
			 * as a broken tag and hands the person back `x = 4`. An answer is text; escaped
			 * here, the file shows them what they wrote.
			 */
			return esc_html( trim( (string) $answer ) );
		}

		if ( is_array( $answer ) && $answer === array_values( $answer ) ) {
			$flat = array_filter( $answer, 'is_scalar' );

			if ( count( $flat ) === count( $answer ) ) {
				return implode( ', ', array_map( 'strval', $answer ) );
			}
		}

		return (string) wp_json_encode( $answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/* ------------------------------------------------------------------------- erase */

	/**
	 * Anonymise this person's attempts. See the class docblock for why not delete.
	 *
	 * @param string $email Email address the request was made for.
	 * @param int    $page  1-based page. Unused — see below.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;

		unset( $page );

		/*
		 * ALWAYS THE FIRST PAGE, DELIBERATELY. Anonymising a row removes it from what
		 * `find()` matches on, so the result set shrinks as we work through it. Paging into
		 * it the way the exporter does would step over every second batch and leave half
		 * the attempts un-erased. `done` below is what ends the loop instead.
		 */
		$rows = $this->find( $email, 1 );

		if ( array() === $rows ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		/**
		 * Delete attempts outright instead of anonymising them.
		 *
		 * For sites whose obligations or policy do not allow even an anonymised record to
		 * be kept. Statistics computed from past attempts will change.
		 *
		 * @param bool                 $delete Default false.
		 * @param array<string, mixed> $row    The first attempt in this batch.
		 */
		$hard_delete = (bool) apply_filters( 'plugixa_exam_erase_attempt_delete', false, $rows[0] );

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			$this->attempts->delete_responses( $id );

			if ( $hard_delete ) {
				$this->attempts->delete( $id );

				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->update prepares.
			$wpdb->update( Db::table( 'attempts' ), $this->anonymised_columns( $row ), array( 'id' => $id ) );
		}

		$count    = count( $rows );
		$messages = array();

		if ( ! $hard_delete ) {
			$messages[] = sprintf(
				/* translators: %d: number of exam attempts. */
				_n(
					'%d exam attempt was anonymised. The name, email address, written answers and any grader or invigilator notes were deleted; the score was kept, with nothing left that identifies who achieved it, so the exam statistics remain correct.',
					'%d exam attempts were anonymised. The names, email addresses, written answers and any grader or invigilator notes were deleted; the scores were kept, with nothing left that identifies who achieved them, so the exam statistics remain correct.',
					$count,
					'plugixa-exam'
				),
				$count
			);
		}

		return array(
			'items_removed'  => true,
			'items_retained' => ! $hard_delete,
			'messages'       => $messages,
			/*
			 * A full batch buys one more round trip, so WordPress re-runs `find()` and sees
			 * the set empty for itself. That is cheaper than trusting this pass to have
			 * caught an attempt somebody submitted a second ago.
			 */
			'done'           => $count < self::PER_PAGE,
		);
	}

	/**
	 * The columns an anonymised attempt is left with.
	 *
	 * @param array<string, mixed> $row The attempt being anonymised.
	 *
	 * @return array<string, mixed>
	 */
	private function anonymised_columns( array $row ): array {
		$columns = array(
			'user_id'           => null,
			'guest_token'       => null,
			'guest_name'        => '',
			'guest_email'       => '',
			'grader_comment'    => '',
			'monitor_note'      => '',
			// Holds the IP address and the user agent the attempt was sat from.
			'integrity_signals' => null,
		);

		/**
		 * Columns cleared when an attempt is anonymised.
		 *
		 * Add to this when a site stores something identifying in a column this plugin
		 * treats as neutral — a custom field, say.
		 *
		 * @param array<string, mixed> $columns Column => value to write.
		 * @param array<string, mixed> $row     The attempt being anonymised.
		 */
		return (array) apply_filters( 'plugixa_exam_erase_attempt_columns', $columns, $row );
	}

	/* ------------------------------------------------------------------------ lookup */

	/**
	 * Attempts belonging to an email address, whether sat signed in or as a guest.
	 *
	 * BOTH HALVES MATTER. The same person may have sat one exam signed in and another as a
	 * guest who typed the same address, and an erasure that cleaned up only the account
	 * would leave their name sitting in `guest_name` — visibly, on the admin attempts list.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function find( string $email, int $page ): array {
		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return array();
		}

		$user    = get_user_by( 'email', $email );
		$user_id = $user instanceof WP_User ? (int) $user->ID : 0;

		$query = $this->attempts->query();

		if ( $user_id > 0 ) {
			$query->where( '(t.user_id = %d OR t.guest_email = %s)', array( $user_id, $email ) );
		} else {
			$query->where( 't.guest_email = %s', array( $email ) );
		}

		return $query
			->order_by( 't.id', 'ASC' )
			->paginate( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE )
			->get();
	}

	/**
	 * An exam's title, or a stand-in when the exam has since been deleted.
	 *
	 * Reached through the filter the front end already uses, because this module may not
	 * name the Exams module.
	 *
	 * @param int $exam_id Exam id.
	 */
	private function exam_title( int $exam_id ): string {
		$exam = apply_filters( 'plugixa_exam_frontend_exam_by_id', null, $exam_id );

		if ( is_array( $exam ) && '' !== (string) ( $exam['title'] ?? '' ) ) {
			return (string) $exam['title'];
		}

		return sprintf(
			/* translators: %d: exam id. */
			__( 'Exam #%d (since deleted)', 'plugixa-exam' ),
			$exam_id
		);
	}
}
