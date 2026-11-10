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
 * The runner without JavaScript.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY AN EXAM RUNNER HAS A NO-JS PATH AT ALL.
 *
 * Almost nothing else on a modern site needs one. An exam does, for three reasons that are
 * not hypothetical:
 *
 *  1. It is somebody's certification. If the bundle fails to load — a corporate proxy, a
 *     content blocker, a flaky connection in an exam room — "try again later" is not an
 *     acceptable answer, because the window closes.
 *  2. It is the accessibility floor. A native form with real radio groups and a real submit
 *     button works with every assistive technology ever written, including ones nobody
 *     tests against.
 *  3. It is the honest version of progressive enhancement. The React runner is nicer —
 *     autosave, a countdown, a question navigator — but it is an enhancement over something
 *     that already works, not the only way in.
 *
 * The React runner posts to the REST routes; this posts to `admin-post.php`. Both end up in
 * the same `AttemptService`, so there is one implementation of the rules and two front doors.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class FormHandler {

	private const GATE_ACTION   = 'plugixa_exam_gate';
	private const RUNNER_ACTION = 'plugixa_exam_runner';

	/**
	 * The `pxe_error` code for a form whose nonce no longer verifies.
	 */
	public const EXPIRED = 'plugixa_exam_expired';

	/**
	 * Refusals of a save or a hand-in after which the paper is still open, so the candidate
	 * is sent back to it with the reason. `take.php` has the words for each.
	 */
	public const STAY_ON_PAPER = array( 'plugixa_exam_held', 'plugixa_exam_answers_required' );

	/**
	 * How long a SIGNED-OUT visitor's Start form stays valid. WordPress's own is a day.
	 *
	 * A nonce verifies for between half its life and its whole life, so sixty days guarantees
	 * thirty — longer than any page cache in common use keeps a page (LiteSpeed's default is
	 * seven days; WP Rocket, W3 Total Cache and WP Super Cache keep hours). See
	 * {@see self::guest_gate_nonce_life()} for why that costs nothing.
	 */
	public const GUEST_GATE_NONCE_LIFE = 60 * DAY_IN_SECONDS;

	/**
	 * @param Router          $router  For redirect targets.
	 * @param AnswerSanitizer $answers Cleans posted answers before anything else sees them.
	 */
	public function __construct(
		private readonly Router $router,
		private readonly AnswerSanitizer $answers
	) {}

	public function register_hooks(): void {
		// Both `admin_post` and `admin_post_nopriv`: an examinee may be logged out, and
		// registering only the former is why "nothing happens when I press Start" is the
		// classic symptom of a half-wired public form.
		//
		// One handler per action, so the hook is what chooses it. Nothing in the request is
		// read to decide which form this is, and each handler can name its own nonce.
		$handlers = array(
			self::GATE_ACTION   => 'handle_gate',
			self::RUNNER_ACTION => 'handle_runner',
		);

		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
			add_action( 'admin_post_nopriv_' . $action, array( $this, $method ) );
		}

		add_filter( 'nonce_life', array( $this, 'guest_gate_nonce_life' ), 10, 2 );
	}

	/**
	 * Let a signed-out visitor's Start form outlive the page cache that serves it.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE ONE FORM ON A CACHEABLE PAGE, AND A DAY-OLD COPY OF IT COULD NOT BE SUBMITTED.
	 *
	 * An exam's landing page is public and cached for first-time visitors, and its gate form
	 * carries a nonce. WordPress's nonce verifies for 12 to 24 hours; a page cache keeps the
	 * page for as long as it is configured to — seven days by default on LiteSpeed. So a
	 * visitor served a cached copy more than a day old pressed Start and got WordPress's
	 * "the link you followed has expired" screen, with no way back.
	 *
	 * Lengthening it for THIS action and a SIGNED-OUT visitor weakens nothing. A signed-out
	 * visitor's nonce has no user and no session in it, so it is the same value for every
	 * visitor and is sitting in the cached HTML for anybody to read — it was never a secret a
	 * forged request lacked. The guest cookie the Start button binds an attempt to is
	 * `SameSite=Lax`, which a cross-site POST does not carry. Signed-in users are unchanged:
	 * their pages are never cached, and their nonce is the one that means something.
	 *
	 * `max()`, so a site that has already lengthened nonces everywhere is not shortened here.
	 * WordPress passes the action to this filter since 6.1; the floor is 6.5.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int|mixed  $life   Seconds, as filtered so far.
	 * @param string|int $action The nonce action.
	 */
	public function guest_gate_nonce_life( $life, $action = -1 ): int {
		if ( self::GATE_ACTION !== $action || is_user_logged_in() ) {
			return (int) $life;
		}

		return max( (int) $life, self::GUEST_GATE_NONCE_LIFE );
	}

	/**
	 * The access-code and identity form on an exam's landing page.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE NONCE IS VERIFIED HERE, IN THE FUNCTION THAT READS THE REQUEST, AND FIRST.
	 *
	 * The two handlers used to share one verification in a dispatcher above them, which
	 * was correct and could not be seen: each read `$_POST` in a function containing no
	 * check, behind a comment saying where the check was. Now the proof is on the page —
	 * every field below is read after the `wp_verify_nonce()` call above it, in the same
	 * function, and `NonceScopeTest` fails if a request is read anywhere that cannot say
	 * the same.
	 *
	 * A NONCE HERE, UNLIKE ON THE REST RUNNER ROUTES, AND THE DIFFERENCE IS DELIBERATE.
	 *
	 * This is a browser form post, so the nonce costs nothing and CSRF is a real shape:
	 * a forged POST could submit somebody's paper early. The REST routes cannot require
	 * one, because a logged-out guest has no WordPress session to mint it from — they
	 * authorise on the guest token instead. Here we have both, so we use both.
	 *
	 * NOT `check_admin_referer()`, which ends the request on WordPress's "the link you
	 * followed has expired" screen: a page with nothing on it but a link back to the home
	 * page, in the middle of somebody's exam. An expired form goes back where it came from
	 * with a sentence saying what happened, and nothing is started or saved.
	 *
	 * THE NONCE SAYS THE REQUEST CAME FROM A PAGE THIS SITE RENDERED. IT SAYS NOTHING ABOUT
	 * WHO MAY DO WHAT, and no capability is asked for here because an examinee has none:
	 * they may be a guest. What may be started is decided by the access gate behind
	 * `plugixa_exam_frontend_start`, and whose attempt may be written to by the ownership
	 * check behind `plugixa_exam_frontend_save`.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	public function handle_gate(): void {
		$verified = wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '', self::GATE_ACTION );

		// Read before the verdict is acted on because BOTH outcomes need it: it names the
		// landing page a refused form goes back to. It is only ever looked up, never trusted.
		$exam_id = absint( $_POST['exam_id'] ?? 0 );

		if ( false === $verified ) {
			$this->redirect( $this->expired_destination( self::GATE_ACTION, $exam_id, '' ) );
		}

		$credentials = array(
			/*
			 * `sanitize_text_field`, BECAUSE THAT IS WHAT THE STORED CODE WENT THROUGH.
			 *
			 * This used to be `trim()` alone, on the reasoning that the code is compared with
			 * `hash_equals` and must arrive intact. But the value it is compared WITH is not
			 * intact: `ExamService` stores `sanitize_text_field( $code )`. So a code an
			 * administrator typed with two spaces in it was stored with one, and a candidate
			 * who typed it exactly as they were given it was refused. Cleaning both sides
			 * with the same function is what makes the comparison mean "the same code".
			 */
			'access_code' => isset( $_POST['access_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['access_code'] ) ) : '',
			'guest_name'  => isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['guest_name'] ) ) : '',
			'guest_email' => isset( $_POST['guest_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['guest_email'] ) ) : '',
			// Only ever '1' or '' — a box is ticked or it is not, whatever was posted.
			'guest_consent' => empty( $_POST['guest_consent'] ) ? '' : '1',
		);

		/**
		 * Start an attempt from a posted form.
		 *
		 * @param array<string, mixed>|\WP_Error|null $result  The started attempt.
		 * @param int                                 $exam_id Exam id.
		 * @param array<string, string>               $input   Gate credentials.
		 */
		$result = apply_filters( 'plugixa_exam_frontend_start', null, $exam_id, $credentials );

		/**
		 * The exam this gate belongs to, for the redirect back.
		 *
		 * @param array<string, mixed>|null $exam    The exam row.
		 * @param int                       $exam_id Exam id.
		 */
		$exam = apply_filters( 'plugixa_exam_frontend_exam_by_id', null, $exam_id );

		if ( ! is_array( $exam ) ) {
			/*
			 * AN EXAM THAT DOES NOT EXIST HAS NO LANDING PAGE TO GO BACK TO.
			 *
			 * This used to ask the Router for the page of an exam with no slug, which is
			 * `/exams//?pxe_error=…`: an address with nothing at it, shown to somebody whose
			 * exam was deleted between opening its page and pressing Start. The catalogue is
			 * where {@see self::expired_destination()} already sends the same visitor.
			 */
			$code = $result instanceof \WP_Error ? $result->get_error_code() : 'plugixa_exam_not_found';

			$this->redirect( add_query_arg( 'pxe_error', rawurlencode( $code ), $this->router->catalogue_url() ) );
		}

		$slug = (string) $exam['slug'];

		if ( ! is_array( $result ) || isset( $result['errors'] ) ) {
			$code = $result instanceof \WP_Error ? $result->get_error_code() : 'plugixa_exam_denied';

			// Back to the landing page carrying the reason, so it can say what went wrong
			// rather than silently redisplaying an empty form.
			$this->redirect( add_query_arg( 'pxe_error', rawurlencode( $code ), $this->router->exam_url( $slug ) ) );
		}

		$this->redirect( $this->router->take_url( $slug ) );
	}

	/**
	 * Answers posted from the no-JS runner.
	 *
	 * Verified the same way and for the same reasons as {@see self::handle_gate()}.
	 */
	public function handle_runner(): void {
		$verified = wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '', self::RUNNER_ACTION );

		// Where the visitor was. Read before the verdict is acted on because both outcomes go
		// back there, and only ever as a destination: `wp_safe_redirect()` refuses another host.
		$return_to = isset( $_POST['return_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['return_to'] ) ) : '';

		if ( false === $verified ) {
			$this->redirect( $this->expired_destination( self::RUNNER_ACTION, 0, $return_to ) );
		}

		$attempt_id = absint( $_POST['attempt_id'] ?? 0 );
		$submitting = isset( $_POST['pxe_submit'] );

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * CLEANED IN THE STATEMENT THAT READS THEM, PER QUESTION TYPE, BEFORE ANY HOOK.
		 *
		 * The read is the ARGUMENT of the sanitiser: there is no variable holding what was
		 * posted, so there is nothing raw to pass on by mistake. What comes back holds only
		 * answers to questions on this attempt's paper, each through the sanitiser for its
		 * type — an essay keeps its paragraphs, a short answer is one line, a choice is an
		 * option id. Only the frozen paper knows which is which, which is why this is a call
		 * into the module that owns it and not `sanitize_text_field()` over everything: one
		 * function for every value would mangle the essay or let markup into the id.
		 *
		 * These used to be handed on raw and cleaned in the service. Both hooks below came
		 * first, and a hook gives its value to whatever is attached to it.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the read is the argument of the sanitiser: AnswerSanitizer::sanitize() cleans each answer for its question's type before the value exists anywhere else. Kept to ONE line so the ignore covers the statement — PHPCS reports the interior line, not the assignment.
		$answers = $this->answers->sanitize( $attempt_id, isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? wp_unslash( $_POST['answers'] ) : array() );

		/**
		 * Adjust the answers a posted form is about to save.
		 *
		 * EXISTS FOR ANSWERS THAT ARE NOT VALUES. Every type here posts its answer in
		 * `answers[]` and is done — except one that posts a FILE, which has to be received
		 * and stored before there is an id to answer with. A listener receives the upload
		 * and writes the resulting ids into the answer for that question.
		 *
		 * Fired before the save and after the nonce check, so a listener is looking at a
		 * request this class has already established is genuine. It must still verify that
		 * the attempt is the caller's — `admin-post.php` is reachable by anyone.
		 *
		 * @param array<int, mixed> $answers    Question id => answer, ALREADY CLEANED for its question's type. Never the request.
		 * @param int               $attempt_id Attempt id.
		 */
		$answers = (array) apply_filters( 'plugixa_exam_frontend_answers', $answers, $attempt_id );

		/**
		 * Save or submit an attempt from a posted form.
		 *
		 * The listener MUST verify ownership: `admin-post.php` is reachable by anyone, and
		 * the attempt id is whatever the form said it was.
		 *
		 * @param array<string, mixed>|\WP_Error|null $result     The outcome.
		 * @param int                                 $attempt_id Attempt id.
		 * @param array<int, mixed>                   $answers    Cleaned answers; the service cleans whatever it is given again.
		 * @param bool                                $submitting Whether to finalise.
		 */
		$result = apply_filters( 'plugixa_exam_frontend_save', null, $attempt_id, $answers, $submitting );

		if ( $result instanceof \WP_Error ) {
			$data = (array) $result->get_error_data();

			/*
			 * An owner who has lost the exam goes to its landing page, which states the
			 * refusal itself ("You do not have access to this exam.") — not to a result page
			 * for a paper that is still open.
			 */
			if ( 'plugixa_exam_denied' === $result->get_error_code() && ! empty( $data['exam_id'] ) ) {
				/** This filter is documented above, in `handle_gate()`. */
				$exam = apply_filters( 'plugixa_exam_frontend_exam_by_id', null, (int) $data['exam_id'] );

				if ( is_array( $exam ) ) {
					$this->redirect( $this->router->exam_url( (string) $exam['slug'] ) );
				}
			}

			/*
			 * Two refusals leave the paper OPEN, so the place to say so is the paper: one an
			 * invigilator's hold, the other an exam that takes no blanks. The result page of
			 * an attempt still in progress has nothing on it to explain either.
			 */
			if ( in_array( $result->get_error_code(), self::STAY_ON_PAPER, true ) && '' !== $return_to ) {
				$this->redirect( add_query_arg( 'pxe_error', rawurlencode( $result->get_error_code() ), $return_to ) );
			}

			$this->redirect(
				add_query_arg( 'pxe_error', rawurlencode( $result->get_error_code() ), $this->router->result_url( $attempt_id ) )
			);
		}

		if ( $submitting ) {
			$this->redirect( $this->router->result_url( $attempt_id ) );
		}

		$back = '' !== $return_to ? $return_to : home_url( '/' );

		/*
		 * A QUERY ARG, NOT A FRAGMENT.
		 *
		 * This used to redirect to `…/take/#pxe-saved`, which confirmed nothing: a browser
		 * never sends the fragment to the server, so the banner in `take.php` could not know
		 * the save had happened, and there is no element with that id to scroll to either.
		 * Without JavaScript the page simply reloaded looking identical — the one path where
		 * there is no "Saved" indicator to fall back on, taking an exam, where "did that
		 * work?" is the last question anybody should be left with.
		 */
		$this->redirect( add_query_arg( 'pxe_saved', '1', $back ) );
	}

	/**
	 * Where a form whose nonce did not verify goes: back to the page it was on, carrying
	 * {@see self::EXPIRED} so that page can say what happened.
	 *
	 * The exam id and `return_to` are used ONLY to choose that page, and this method is handed
	 * them rather than reading the request: the nonce is what just failed, so the less of the
	 * request that is touched on this path the better. Nothing is started or saved — the form
	 * is re-shown with a nonce minted now, and pressing its button again is the retry.
	 *
	 * @param string $action    The form's action.
	 * @param int    $exam_id   The exam the gate form named, or 0.
	 * @param string $return_to The page the runner form said it was on, or an empty string.
	 */
	public function expired_destination( string $action, int $exam_id, string $return_to ): string {
		if ( self::GATE_ACTION === $action ) {
			/** This filter is documented in handle_gate() above. */
			$exam = apply_filters( 'plugixa_exam_frontend_exam_by_id', null, $exam_id );

			$target = is_array( $exam )
				? $this->router->exam_url( (string) $exam['slug'] )
				: $this->router->catalogue_url();
		} else {
			$target = '' !== $return_to ? $return_to : home_url( '/' );
		}

		return add_query_arg( 'pxe_error', self::EXPIRED, $target );
	}

	/**
	 * Redirect and stop.
	 *
	 * `wp_safe_redirect`, so a `return_to` a visitor tampered with cannot bounce them to
	 * another host.
	 *
	 * @param string $url Destination.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
