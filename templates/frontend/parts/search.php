<?php
/**
 * Exam search: the form, and its results.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/search.php`.
 *
 * A PLAIN GET FORM, WITH NO NONCE, ON PURPOSE. Searching changes nothing, so there is
 * nothing for a nonce to protect — and a nonce in this markup would make the page
 * uncacheable and expire after twelve hours, which is exactly the failure NFR-PERF-5 exists
 * to prevent. The query string is also what makes a result page linkable and bookmarkable.
 *
 * @package PlugixaExam
 *
 * @var string                            $pxe_query   The phrase searched for.
 * @var array<int, array<string, mixed>>  $pxe_exams   Matching exams.
 * @var int                               $pxe_total   Total matches.
 * @var bool                              $pxe_searched Whether a search was actually run.
 * @var \PlugixaExam\Core\Frontend\Router $pxe_router  Link builder.
 * @var string                            $pxe_field   Query-string parameter name.
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
 * NO `action` ATTRIBUTE, DELIBERATELY. Omitting it submits to the current document URL,
 * which is the only thing that is correct in every context this part renders in: the
 * `/exams/` route, a page holding the shortcode, and a site installed in a subdirectory.
 * Rebuilding the URL by hand is where the subdirectory case goes wrong.
 */
?>
<form class="pxe-search" role="search" method="get">
	<label class="pxe-field" for="pxe-search-<?php echo esc_attr( $pxe_field ); ?>">
		<span class="pxe-field__label"><?php esc_html_e( 'Search exams', 'plugixa-exam' ); ?></span>

		<input
			class="pxe-field__input"
			type="search"
			id="pxe-search-<?php echo esc_attr( $pxe_field ); ?>"
			name="<?php echo esc_attr( $pxe_field ); ?>"
			value="<?php echo esc_attr( $pxe_query ); ?>"
			placeholder="<?php esc_attr_e( 'Exam name or subject', 'plugixa-exam' ); ?>"
		/>
	</label>

	<button class="pxe-button pxe-button--primary" type="submit">
		<?php esc_html_e( 'Search', 'plugixa-exam' ); ?>
	</button>
</form>

<?php if ( $pxe_searched ) : ?>

	<p class="pxe-search__count">
		<?php
		printf(
			/* translators: 1: number of exams found. 2: the phrase searched for. */
			esc_html( _n( '%1$d exam matches %2$s', '%1$d exams match %2$s', $pxe_total, 'plugixa-exam' ) ),
			(int) $pxe_total,
			// Curly quotes, not `&#8220;` — `esc_html()` would escape the ampersand of an
			// entity and print it literally.
			esc_html( '“' . $pxe_query . '”' )
		);
		?>
	</p>

	<?php
	$pxe_empty = __( 'No exams match that search.', 'plugixa-exam' );

	require plugixa_exam_get_template( 'parts/catalogue-list.php' );
	?>

<?php endif; ?>
