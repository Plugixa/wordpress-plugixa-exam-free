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
 * Gutenberg blocks, registered from their manifests.
 *
 * ONE GLOB, NO LIST. `blocks/*​/block.json` is the registry, exactly as `includes/Modules/*`
 * is the module registry. Adding a block means adding a folder — and, more to the point, a
 * premium block dropped into `pro/blocks/` registers itself without this file learning its
 * name, which is what keeps the free build free of any string naming it.
 *
 * A BLOCK IS A PROMISE THAT SOMETHING WILL RENDER, which is why the split is where it is.
 * The five free blocks all show data a free install has: the catalogue, and the four
 * learner embeds the Portal module answers. A block whose module is absent would appear in
 * the inserter and then render nothing, which reads as a broken plugin rather than as a
 * locked feature — so premium blocks ship with the premium build and not before.
 *
 * `My certificates` is the deliberate exception and is free anyway: it renders whatever
 * answers `plugixa_exam_portal_certificates`, so on a free site it renders the empty state
 * rather than nothing at all. A learner with no certificates and a learner on a site that
 * cannot issue them see the same true sentence.
 */
final class Blocks {

	/**
	 * Where block folders live, and where the script catalogues for the blocks in each one
	 * live — both relative to the plugin root.
	 *
	 * A PREMIUM BLOCK'S STRINGS ARE IN THE PREMIUM CATALOGUE. The two halves are extracted
	 * separately (`tools/make-catalogues.ps1`), so the leaderboard's five labels are in
	 * `pro/languages/*.po` and nowhere else. Every block used to be pointed at `languages/`,
	 * and no `.json` was ever made from the premium half, so that block's sidebar stayed
	 * English in every language — the defect described on {@see self::register()}, left in
	 * place for the one block that lives under `pro/`.
	 *
	 * @var array<string, string>
	 */
	private const ROOTS = array(
		'blocks'     => 'languages',
		'pro/blocks' => 'pro/languages',
	);

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );

		// A block on a page has no rewrite view, so the front-end stylesheet would not
		// otherwise load and the cards would render unstyled.
		add_filter( 'plugixa_exam_needs_frontend_assets', array( $this, 'needs_assets' ), 10, 2 );
	}

	/**
	 * Register every block found on disk, and give its editor script its translations.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE STRINGS WERE BEING TRANSLATED AND THEN THROWN AWAY.
	 *
	 * Each `index.js` calls `wp.i18n.__()`, and `wp i18n make-pot` extracts those calls, so
	 * they have been sitting in the `.pot` for translators to work on. Nothing ever
	 * attached a catalogue to them: `wp.i18n.__()` returns its input verbatim unless a
	 * `.json` catalogue has been registered for that script handle, and none was. Every
	 * block label rendered in English on every site, in every language, while the
	 * translation existed.
	 *
	 * `wp_set_script_translations()` is the right tool HERE even though
	 * {@see \PlugixaExam\Core\App\Strings} argues against it for the React apps, and the
	 * difference is the filename. That objection is that a catalogue is keyed to a script
	 * handle whose file carries a Vite content hash, so a rebuild silently un-translates
	 * the app. These blocks have no build step at all — `index.js` is the file that ships,
	 * its name never changes, and the objection simply does not apply.
	 *
	 * The handle comes from the registration rather than being reconstructed: WordPress
	 * derives it from the block name and the field, and guessing at that format is how this
	 * silently stops working after a core release.
	 *
	 * ⚠️ THIS OVERLAPS WITH THE `index.asset.php` FILES, AND BOTH ARE KEPT ON PURPOSE.
	 * `register_block_script_handle()` already calls `wp_set_script_translations()` for a
	 * block whose `block.json` names a `textdomain` — but only when `wp-i18n` is among the
	 * declared dependencies, which is what those asset files now provide. Measured: remove
	 * either one and the strings still translate; remove BOTH and the handle has no
	 * dependencies and no catalogue, which is what shipped. The difference between them is
	 * the PATH — core's call takes none and relies on the textdomain registry having been
	 * pointed at this plugin, while this one names the folder outright ({@see self::ROOTS}:
	 * `languages/` for a free block, `pro/languages/` for a premium one). Deleting this as
	 * a duplicate would make a bundled catalogue depend on a registry entry that WordPress
	 * only populates from the plugin header since 6.7, below the floor in `Requires at
	 * least` — and that entry names ONE folder, so a premium block could never be reached
	 * by it.
	 *
	 * Naming a folder does not tie a block to a bundled catalogue. The WordPress.org
	 * build ships none (see {@see \PlugixaExam\Core\I18n}), and `load_script_textdomain()`
	 * looks in the path it is given first and in `wp-content/languages/plugins/` second,
	 * under the same md5 file name — which is where a language pack puts it. Measured: with
	 * no file in `languages/`, a pack's catalogue reaches all five blocks.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	public function register(): void {
		foreach ( $this->manifests() as $dir => $languages ) {
			$type = register_block_type( $dir, $this->rendering( $dir . '/render.php' ) );

			if ( ! $type instanceof \WP_Block_Type ) {
				continue;
			}

			foreach ( (array) $type->editor_script_handles as $handle ) {
				wp_set_script_translations( (string) $handle, 'plugixa-exam', PLUGIXA_EXAM_PATH . $languages );
			}
		}
	}

	/**
	 * The render callback for a block that has a `render.php`.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A BLOCK RETURNS ITS MARKUP. IT DOES NOT ECHO IT.
	 *
	 * Each `block.json` used to name its file under `render`, which makes WordPress include
	 * it inside an output buffer — so the file had to PRINT, and what it printed was a
	 * string assembled from core's wrapper attributes and a template's output:
	 * `echo '<div ' . $wrapper . '>' . $markup . '</div>'`, under a `phpcs:ignore` saying
	 * both halves were already escaped. They were. It was also an unescaped echo of two
	 * variables with a comment asking to be believed, six times over, and WordPress.org
	 * reviews do not take the comment's word for it.
	 *
	 * The files are the same files and say the same thing with `return`. This is the shape
	 * every dynamic block in core has, and every shortcode here: the callback hands
	 * WordPress a string, and there is no echo of a composed value anywhere in a block. The
	 * `render` key is gone from each `block.json` so that nothing but this includes them —
	 * core's own callback would buffer a file that now prints nothing and show an empty
	 * block.
	 *
	 * The file is included INSIDE the closure on purpose. `$attributes`, `$content` and
	 * `$block` are its local variables that way, exactly as they were under core's
	 * callback, and every `$pxe_*` it assigns disappears when it returns.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $file The block's `render.php`.
	 *
	 * @return array<string, callable> Arguments for `register_block_type()`.
	 */
	private function rendering( string $file ): array {
		if ( ! is_file( $file ) ) {
			return array();
		}

		return array(
			'render_callback' => static function ( $attributes, $content, $block ) use ( $file ): string {
				$markup = require $file;

				// A file that forgets to return yields `1`, which is not something to print.
				return is_string( $markup ) ? $markup : '';
			},
		);
	}

	/**
	 * Whether the page being rendered contains one of this plugin's blocks.
	 *
	 * `has_block()` is asked per block name rather than by prefix because WordPress offers
	 * no prefix form. The list comes from the same glob that registered them, so a block
	 * added later is covered without this method changing.
	 *
	 * @param bool   $needed Whether the assets are already known to be needed.
	 * @param string $view   The resolved rewrite view, or an empty string.
	 */
	public function needs_assets( bool $needed, string $view ): bool {
		unset( $view );

		if ( $needed ) {
			return true;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || ! has_blocks( $post->post_content ) ) {
			return false;
		}

		foreach ( $this->names() as $name ) {
			if ( has_block( $name, $post ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Directories holding a `block.json`, each with the folder its script catalogues are in.
	 *
	 * @return array<string, string> Block directory => catalogue folder, relative to the plugin root.
	 */
	private function manifests(): array {
		$found = array();

		foreach ( self::ROOTS as $root => $languages ) {
			$matches = glob( PLUGIXA_EXAM_PATH . $root . '/*/block.json' );

			foreach ( $matches ? $matches : array() as $manifest ) {
				$found[ dirname( $manifest ) ] = $languages;
			}
		}

		return $found;
	}

	/**
	 * Registered block names, read from the manifests.
	 *
	 * Read from disk rather than from `WP_Block_Type_Registry`, because this runs on
	 * `wp_enqueue_scripts` where the registry is populated but filtering it by prefix would
	 * mean trusting every third party not to have registered `plugixa-exam/…` themselves.
	 *
	 * @return string[]
	 */
	private function names(): array {
		$names = array();

		foreach ( array_keys( $this->manifests() ) as $dir ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own manifest, not a remote resource.
			$json = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );

			if ( is_array( $json ) && ! empty( $json['name'] ) ) {
				$names[] = (string) $json['name'];
			}
		}

		return $names;
	}
}
