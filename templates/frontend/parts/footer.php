<?php
/**
 * Shared closing markup for every exam page.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/footer.php`.
 *
 * @package PlugixaExam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
	<?php /* Closes `.pxe__shell`, then the `.pxe` scope root — both opened in `parts/header.php`. */ ?>
	</div>
</div>
<?php
/*
 * ⚠️ THIS FILE USED TO HAVE TO AGREE WITH `parts/header.php`, AND NOW IT ONLY HAS TO ASK.
 *
 * It once tested `'pxe--runner' === $pxe_modifier`, and then a `$pxe_standalone` flag that
 * `parts/header.php` set and this file read out of the shared template scope. Both were the
 * same hazard: whoever opened the document and whoever closed it had to reach the same
 * conclusion from separate code, and a view that opened its own `<html>` and then called
 * `get_footer()` emitted the THEME's closing markup, a second `</body></html>`, and no
 * `wp_footer()` — which is silent, and takes the frontend bundle down with it.
 *
 * {@see \PlugixaExam\Core\Frontend\Chrome} decides once and both ends call it, so there is
 * no longer a second opinion to get wrong. A theme that overrides only ONE of these two
 * files now still closes what was opened.
 */
\PlugixaExam\Core\Frontend\Chrome::close();
