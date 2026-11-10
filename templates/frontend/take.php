<?php
/**
 * The runner.
 *
 * Override in a theme at `yourtheme/plugixa-exam/take.php`.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS RENDERS A COMPLETE, WORKING EXAM WITHOUT ANY JAVASCRIPT.
 *
 * The React runner mounts into `#pxe-runner` and replaces what is below with a nicer version
 * — a question navigator, a countdown, autosave, a review step. If the bundle never arrives,
 * the form underneath still works: real radio groups, a real textarea, a real submit button.
 *
 * That is not belt-and-braces for its own sake. An exam is somebody's certification and the
 * window closes; "try again later" is not an answer when a corporate proxy has eaten the
 * bundle. It is also the accessibility floor — a native form works with every assistive
 * technology ever written, including the ones nobody tests against.
 *
 * Both paths end in the same `AttemptService`, so there is one implementation of the rules.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * @package PlugixaExam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * TEMPLATE-SCOPE RENDER VARIABLES, NOT GLOBALS — measured, not assumed.
 *
 * PHPCS reads each file standalone, sees assignments at file scope and concludes they are
 * globals. They are not: `TemplateLoader::render()` includes a template INSIDE A CLOSURE
 * (and WordPress includes a block's `render.php` inside `WP_Block::render()`), so every
 * `$pxe_*` here is function-scope and disappears when the template returns. Probed on a
 * live site by assigning a marker in a template and checking `$GLOBALS` afterwards: absent.
 *
 * The names are already prefixed `pxe_`, which is three characters — one under this
 * sniff's minimum — and lengthening it would rename a hundred and twenty-six variables
 * across twenty-two files to satisfy a rule about a scope they never reach.
 */

/*
 * ⚠️ FIRST, BEFORE ANY OUTPUT OR REDIRECT. THIS PAGE IS ONE PERSON'S PAPER AT A SHARED URL.
 *
 * `/exams/<slug>/take/` is the same address for everybody sitting that exam; whose attempt it
 * shows is decided by the session or the guest cookie. It sent no cache instruction at all,
 * so a page cache that ignores a cookie it does not recognise — WP Super Cache's default —
 * could store one guest's questions and saved answers and serve them to the next visitor. The
 * redirects below are per-visitor answers too, so they are covered by the same mark.
 */
\PlugixaExam\Core\Frontend\PrivateResponse::mark();

$pxe_data   = \PlugixaExam\Core\Plugin::instance()->container()->get( \PlugixaExam\Core\Frontend\ViewData::class );
$pxe_router = $pxe_data->router();
$pxe_settings = \PlugixaExam\Core\Plugin::instance()->container()->get( \PlugixaExam\Core\Settings::class );
$pxe_exam   = $pxe_data->exam();

if ( null === $pxe_exam ) {
	wp_safe_redirect( $pxe_router->catalogue_url() );
	exit;
}

$pxe_gate = $pxe_data->gate( $pxe_exam ) ?? array();

/*
 * No open attempt means somebody typed this URL, or came back after finishing. Starting one
 * here would be a state change on a GET — a refresh, a prefetch or a link-preview crawler
 * would each burn one of their attempts. Send them to the landing page, which has the
 * Start button.
 */
if ( empty( $pxe_gate['resume'] ) ) {
	wp_safe_redirect( $pxe_router->exam_url( (string) $pxe_exam['slug'] ) );
	exit;
}

$pxe_attempt = $pxe_data->attempt_by_id( (int) $pxe_gate['resume'] );

if ( null === $pxe_attempt ) {
	wp_safe_redirect( $pxe_router->exam_url( (string) $pxe_exam['slug'] ) );
	exit;
}

$pxe_paper     = $pxe_attempt['paper'];
$pxe_questions = $pxe_attempt['questions'];
$pxe_id        = (int) $pxe_attempt['attempt']['id'];

$pxe_title    = (string) $pxe_exam['title'];
$pxe_modifier = 'pxe--runner';

require plugixa_exam_get_template( 'parts/header.php' );
?>

<div
	id="pxe-runner"
	class="pxe-runner"
	data-attempt="<?php echo esc_attr( (string) $pxe_id ); ?>"
	data-result-url="<?php echo esc_url( $pxe_router->result_url( $pxe_id ) ); ?>"
	<?php /* The React header band prints the exam name; the frozen paper in the state tag carries no title, so it comes across here beside the other page-supplied values. */ ?>
	data-exam-title="<?php echo esc_attr( $pxe_title ); ?>"
	<?php /* Where to go when somebody leaves without submitting. The runner has no site chrome, so this is the only way out of it. */ ?>
	data-exit-url="<?php echo esc_url( $pxe_router->exam_url( (string) $pxe_exam['slug'] ) ); ?>"
	<?php /* Read from the page rather than assumed, because a site with plain permalinks serves REST from `?rest_route=` and a hard-coded `/wp-json/` 404s there. */ ?>
	data-rest-url="<?php echo esc_url( rest_url( 'plugixa-exam/v1/' ) ); ?>"
	<?php
	/*
	 * THE NONCE IS REQUIRED FOR A LOGGED-IN EXAMINEE, AND HARMLESS FOR A GUEST.
	 *
	 * WordPress only authenticates a cookie-based REST request when `X-WP-Nonce` is present
	 * and valid. Without it `determine_current_user` returns 0 — so a logged-in examinee's
	 * autosave arrived at the runner routes looking anonymous, failed the ownership check
	 * against their own attempt, and every save came back 401 while the browser was plainly
	 * signed in.
	 *
	 * A guest has no session for the nonce to authenticate, so for them it changes nothing
	 * and the signed guest cookie still does the work. Sending it either way keeps one code
	 * path and gives logged-in users CSRF protection for free.
	 */
	?>
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	<?php
	/*
	 * WHERE A REPLACEMENT NONCE COMES FROM, BECAUSE THIS PAGE CAN OUTLIVE THE ONE ABOVE.
	 *
	 * A paper can be paused overnight, and a nonce verifies for 12 to 24 hours. Past that every
	 * autosave from a signed-in examinee came back 403 `rest_cookie_invalid_nonce` and the
	 * runner said "Couldn't save — will retry" for ever, re-sending the same dead nonce —
	 * reproduced on the dev site. The client asks WordPress core's own `rest-nonce` AJAX action
	 * for a fresh one and retries once. A guest never gets that error: a signed-out REST request
	 * is not nonce-checked.
	 */
	?>
	data-nonce-url="<?php echo esc_url( admin_url( 'admin-ajax.php?action=rest-nonce' ) ); ?>"
	<?php
	/*
	 * A LIVE SETTING, NOT A FROZEN ONE. Rendering maths is a presentation choice a site can
	 * change today and have take effect on papers already in progress — unlike `mode` and
	 * `practice_reveal`, which are frozen into the paper because changing them mid-sitting
	 * would change the rules of an exam somebody is already sitting.
	 *
	 * Read from the page rather than fetched, because the decorator that needs it registers
	 * at import time, before anything has asked the server anything.
	 */
	?>
	data-math="<?php echo esc_attr( $pxe_settings->bool( 'math_rendering_enabled', true ) ? '1' : '0' ); ?>"
>
	<?php
	/*
	 * The attempt, inlined so the React runner paints without a round trip. It is the same
	 * payload `/runner/state/{id}` returns, and the server has just loaded it anyway.
	 *
	 * PRINTED THROUGH THE ENQUEUE API, NOT AS A TAG IN THIS TEMPLATE. WordPress.org's review
	 * lists every script element a plugin writes by hand, whatever its type, and PHPCS cannot
	 * see one that has no `src`. `wp_print_inline_script_tag()` is core's own API for data
	 * printed at a point no enqueued handle can reach. The payload is JSON typed
	 * `application/json`, so nothing executes it; `JSON_HEX_TAG` keeps a closing tag out of
	 * the data, and core's HTML API refuses to print one regardless.
	 */
	wp_print_inline_script_tag(
		(string) wp_json_encode( $pxe_attempt, JSON_HEX_TAG | JSON_HEX_AMP ),
		array(
			'type' => 'application/json',
			'id'   => 'pxe-runner-state',
		)
	);
	?>

	<?php if ( isset( $_GET['pxe_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only marker after a redirect. ?>
		<p class="pxe-runner__saved" role="status"><?php esc_html_e( 'Your answers have been saved.', 'plugixa-exam' ); ?></p>
	<?php endif; ?>

	<?php
	/*
	 * The no-JS form came back unverified: this page had been open longer than its form's nonce
	 * lives, which a paper paused overnight can manage. What was posted was NOT saved, and the
	 * answers shown below are the ones saved before — so say exactly that.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only marker after a redirect.
	if ( \PlugixaExam\Core\Frontend\FormHandler::EXPIRED === ( isset( $_GET['pxe_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['pxe_error'] ) ) : '' ) ) :
		?>
		<div class="pxe-notice pxe-notice--error" role="alert">
			<p class="pxe-notice__title"><?php esc_html_e( 'Your last changes were not saved.', 'plugixa-exam' ); ?></p>
			<p class="pxe-notice__detail"><?php esc_html_e( 'This page had been open for a long time. The answers below are the ones saved before that — please check them, then save again.', 'plugixa-exam' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	/*
	 * The paper was not saved or not taken, and is still open: an invigilator holds it, or the
	 * exam takes no blank answers. The same sentences the REST runner is given, chosen from the
	 * code the form handler came back with.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only marker after a redirect.
	$pxe_refusal = isset( $_GET['pxe_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['pxe_error'] ) ) : '';

	if ( in_array( $pxe_refusal, \PlugixaExam\Core\Frontend\FormHandler::STAY_ON_PAPER, true ) ) :
		?>
		<div class="pxe-notice pxe-notice--error" role="alert">
			<p class="pxe-notice__title">
				<?php
				echo esc_html(
					'plugixa_exam_held' === $pxe_refusal
						? __( 'An invigilator has paused this attempt. Please wait for them to release it.', 'plugixa-exam' )
						: __( 'Every question needs an answer before this exam can be handed in.', 'plugixa-exam' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<form
		class="pxe-runner__form"
		method="post"
		action="<?php echo esc_url( $pxe_router->form_url() ); ?>"
	>
		<?php wp_nonce_field( 'plugixa_exam_runner' ); ?>
		<input type="hidden" name="action" value="plugixa_exam_runner">
		<input type="hidden" name="attempt_id" value="<?php echo esc_attr( (string) $pxe_id ); ?>">
		<input type="hidden" name="return_to" value="<?php echo esc_url( $pxe_router->take_url( (string) $pxe_exam['slug'] ) ); ?>">

		<ol class="pxe-questions">
			<?php foreach ( $pxe_questions as $pxe_index => $pxe_question ) : ?>
				<?php
				$pxe_name     = 'answers[' . (int) $pxe_question['id'] . ']';
				$pxe_field_id = 'pxe-q-' . (int) $pxe_question['id'];
				$pxe_response = $pxe_question['response'];
				?>
				<li class="pxe-question" id="<?php echo esc_attr( $pxe_field_id ); ?>">
					<div class="pxe-question__head">
						<span class="pxe-question__number">
							<?php
							printf(
								/* translators: %d: question number. */
								esc_html__( 'Question %d', 'plugixa-exam' ),
								(int) $pxe_index + 1
							);
							?>
						</span>
						<span class="pxe-question__points">
							<?php
							printf(
								/* translators: %s: points available. */
								esc_html__( '%s points', 'plugixa-exam' ),
								esc_html( (string) round( (float) $pxe_question['points'], 2 ) )
							);
							?>
						</span>
					</div>

					<div class="pxe-question__stem pxe-prose">
						<?php echo wp_kses( (string) $pxe_question['stem'], plugixa_exam_allowed_html( 'stem' ) ); ?>
					</div>

					<?php
					switch ( (string) $pxe_question['type'] ) :
						case 'mcq_single':
							?>
							<fieldset class="pxe-choices">
								<legend class="pxe-choices__legend screen-reader-text">
									<?php esc_html_e( 'Choose one answer', 'plugixa-exam' ); ?>
								</legend>
								<?php foreach ( $pxe_question['options'] as $pxe_option ) : ?>
									<label class="pxe-choice">
										<input
											class="pxe-choice__input"
											type="radio"
											name="<?php echo esc_attr( $pxe_name ); ?>"
											value="<?php echo esc_attr( (string) $pxe_option['id'] ); ?>"
											<?php checked( (int) $pxe_response, (int) $pxe_option['id'] ); ?>
										>
										<span class="pxe-choice__text"><?php echo wp_kses( (string) $pxe_option['text'], plugixa_exam_allowed_html() ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<?php
							break;

						case 'mcq_multiple':
							$pxe_selected = array_map( 'intval', (array) $pxe_response );
							?>
							<fieldset class="pxe-choices">
								<legend class="pxe-choices__legend screen-reader-text">
									<?php esc_html_e( 'Choose all that apply', 'plugixa-exam' ); ?>
								</legend>
								<?php foreach ( $pxe_question['options'] as $pxe_option ) : ?>
									<label class="pxe-choice">
										<input
											class="pxe-choice__input"
											type="checkbox"
											name="<?php echo esc_attr( $pxe_name ); ?>[]"
											value="<?php echo esc_attr( (string) $pxe_option['id'] ); ?>"
											<?php checked( in_array( (int) $pxe_option['id'], $pxe_selected, true ) ); ?>
										>
										<span class="pxe-choice__text"><?php echo wp_kses( (string) $pxe_option['text'], plugixa_exam_allowed_html() ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<?php
							break;

						case 'true_false':
							?>
							<fieldset class="pxe-choices">
								<legend class="pxe-choices__legend screen-reader-text">
									<?php esc_html_e( 'True or false', 'plugixa-exam' ); ?>
								</legend>
								<?php
								/*
								 * A THREE-STATE CONTROL, not a checkbox. An unanswered
								 * true/false must stay unanswered: a checkbox posts nothing
								 * when unticked, which is indistinguishable from "False" —
								 * and the grader would score a blank paper as a full set of
								 * False answers.
								 */
								$pxe_given = null === $pxe_response ? null : (bool) $pxe_response;
								?>
								<label class="pxe-choice">
									<input class="pxe-choice__input" type="radio" name="<?php echo esc_attr( $pxe_name ); ?>" value="1" <?php checked( true === $pxe_given ); ?>>
									<span class="pxe-choice__text"><?php esc_html_e( 'True', 'plugixa-exam' ); ?></span>
								</label>
								<label class="pxe-choice">
									<input class="pxe-choice__input" type="radio" name="<?php echo esc_attr( $pxe_name ); ?>" value="0" <?php checked( false === $pxe_given ); ?>>
									<span class="pxe-choice__text"><?php esc_html_e( 'False', 'plugixa-exam' ); ?></span>
								</label>
							</fieldset>
							<?php
							break;

						case 'essay':
							?>
							<label class="pxe-field">
								<span class="pxe-field__label screen-reader-text"><?php esc_html_e( 'Your answer', 'plugixa-exam' ); ?></span>
								<textarea class="pxe-field__textarea" name="<?php echo esc_attr( $pxe_name ); ?>" rows="10"><?php echo esc_textarea( (string) $pxe_response ); ?></textarea>
							</label>
							<?php
							break;

						case 'short_answer':
						default:
							/**
							 * Markup for a type this build does not know natively.
							 *
							 * A premium type has to draw its own controls here — a cloze
							 * passage needs one box per gap, matching needs a select per row
							 * — and this template cannot know their shape. Return HTML to
							 * take over, or an empty string to accept the text box below.
							 *
							 * The result is escaped on output through the `form` allow-list,
							 * so a module cannot inject anything a form does not need.
							 *
							 * @param string               $html     Markup, empty by default.
							 * @param array<string, mixed> $question The question being rendered.
							 * @param string               $name     The field name to submit under.
							 * @param mixed                $response The saved answer, if any.
							 */
							$pxe_custom = (string) apply_filters(
								'plugixa_exam_no_js_input',
								'',
								$pxe_question,
								$pxe_name,
								$pxe_response
							);

							if ( '' !== $pxe_custom ) {
								echo wp_kses( $pxe_custom, plugixa_exam_allowed_html( 'form' ) );

								break;
							}
							?>
							<label class="pxe-field">
								<span class="pxe-field__label screen-reader-text"><?php esc_html_e( 'Your answer', 'plugixa-exam' ); ?></span>
								<input class="pxe-field__input" type="text" name="<?php echo esc_attr( $pxe_name ); ?>" value="<?php echo esc_attr( is_scalar( $pxe_response ) ? (string) $pxe_response : '' ); ?>" autocomplete="off">
							</label>
							<?php
							break;
					endswitch;
					?>
				</li>
			<?php endforeach; ?>
		</ol>

		<div class="pxe-runner__actions">
			<button type="submit" name="pxe_save" class="pxe-button">
				<?php esc_html_e( 'Save and continue later', 'plugixa-exam' ); ?>
			</button>

			<button type="submit" name="pxe_submit" class="pxe-button pxe-button--primary">
				<?php esc_html_e( 'Submit exam', 'plugixa-exam' ); ?>
			</button>
		</div>
	</form>
</div>

<?php
require plugixa_exam_get_template( 'parts/footer.php' );
