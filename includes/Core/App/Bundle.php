<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\App;

use PlugixaExam\Core\Admin\Hmr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The compiled React application, wherever it is being mounted.
 *
 * Separate from `Core\Admin\Assets` because the app is not admin-only: the same bundle will
 * mount on a front-end page for staff, and a second copy of the manifest reader is the kind
 * of duplicate that stays right for one release and then drifts — one caller learns about a
 * new Vite output and the other does not.
 *
 * The split is: what the bundle IS lives here; what a particular PAGE has to survive lives
 * with that page.
 */
final class Bundle {

	public const HANDLE = 'plugixa-exam-app';

	/**
	 * The element `main.tsx` renders into.
	 *
	 * Three places must agree on this string — here, the admin page renderer, and
	 * `main.tsx` — and none of them is where you would think to look for the others, so it
	 * is a constant rather than a literal.
	 */
	public const ROOT_ID = 'plugixa-exam-root';

	/**
	 * The mount point as markup, for callers that must RETURN a string rather than print
	 * one — which is every shortcode, because echoing from a shortcode puts its output at
	 * the top of the page instead of where the tag is.
	 */
	public static function mount_html(): string {
		return '<div id="' . esc_attr( self::ROOT_ID ) . '"></div>';
	}

	/**
	 * Put the application on the page: the dev server if it is available, the build
	 * otherwise.
	 */
	public static function enqueue(): void {
		if ( self::hmr_available() ) {
			Hmr::enqueue();

			return;
		}

		self::enqueue_build();
	}

	/**
	 * Whether to load from the Vite dev server.
	 *
	 * THE FILE CHECK COMES FIRST IN THE `&&`, AND THAT ORDER IS CORRECTNESS, NOT STYLE.
	 * The release script deletes `includes/Core/Admin/Hmr.php` from the staged plugin and
	 * asserts no ZIP contains it, so in a shipped build the class is not there to call.
	 * Short-circuiting on the file means production never reaches `Hmr::server_is_up()` —
	 * it costs a customer nothing, and cannot fatal on a missing class.
	 *
	 * Tested with `file_exists` rather than `class_exists` for the same reason: touching
	 * the class name would send the autoloader looking for a file that is no longer there.
	 *
	 * NO PORT AND NO HOST APPEAR IN THIS FILE. `Bundle.php` ships; `Hmr.php` does not.
	 * Keeping every dev-origin literal inside the file that gets stripped is what makes
	 * "the released plugin contains no reference to localhost" a property of the layout
	 * rather than a thing to remember — and the release script greps for exactly that.
	 */
	private static function hmr_available(): bool {
		if ( ! file_exists( PLUGIXA_EXAM_PATH . 'includes/Core/Admin/Hmr.php' ) ) {
			return false;
		}

		/*
		 * An explicit constant wins, in both directions.
		 *
		 * Its real job is the test suite: once this method reads a socket, `enqueue()`
		 * takes a different branch depending on whether somebody happens to have
		 * `npm run dev` running in another window — a flake with no visible cause.
		 */
		if ( defined( 'PLUGIXA_EXAM_HMR' ) ) {
			return (bool) PLUGIXA_EXAM_HMR;
		}

		return Hmr::server_is_up();
	}

	/**
	 * Enqueue the compiled bundle, resolving the hashed entry through Vite's manifest.
	 */
	private static function enqueue_build(): void {
		$tree = self::tree( 'backend/build/' );

		$manifest_path = $tree['path'] . '.vite/manifest.json';

		if ( ! file_exists( $manifest_path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a build artefact from the plugin's own directory, not a remote resource.
		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		$entry    = is_array( $manifest ) ? ( $manifest['src/main.tsx'] ?? null ) : null;

		if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
			return;
		}

		/*
		 * NO VERSION QUERY ON THE MODULE. THE FILENAME HASH IS THE CACHE-BUSTER.
		 *
		 * The entry is `js/main-<hash>.js` (see vite.config.js), and a `?ver=` on top of
		 * that would be worse than redundant — it would be fatal. A code-split chunk
		 * imports the entry back by its RELATIVE path, with no query string, so
		 * `main-<hash>.js` and `main-<hash>.js?ver=123` are two different URLs to the
		 * browser, and it loads the module twice: two module graphs, two copies of React,
		 * every side effect run twice. React then throws #321 the first time a lazily
		 * loaded screen calls a hook, and the whole admin white-screens.
		 *
		 * The content hash already changes whenever the bundle does, which is the entire
		 * job `?ver` was doing. The stylesheet below keeps its mtime version: it is not a
		 * module and nothing imports it.
		 */
		wp_enqueue_script_module(
			self::HANDLE,
			$tree['url'] . $entry['file'],
			array(),
			null
		);

		// cssCodeSplit is off, so the stylesheet has a stable name and is not in the manifest.
		$css = $tree['path'] . 'css/main.css';

		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$tree['url'] . 'css/main.css',
				array(),
				self::asset_version( $css )
			);
		}
	}

	/**
	 * Cache-busting version for a built asset.
	 *
	 * The file's own mtime, not the plugin version. The version only moves on release, so
	 * a rebuilt bundle would keep the same URL and the browser would go on serving the old
	 * one — which costs a confusing debugging session every single time. The mtime also
	 * covers the case the version misses in production: a corrective rebuild shipped
	 * without a version bump.
	 *
	 * @param string $path Absolute path to the built file.
	 */
	private static function asset_version( string $path ): string {
		$mtime = filemtime( $path );

		return false !== $mtime ? (string) $mtime : PLUGIXA_EXAM_VERSION;
	}

	/**
	 * Which compiled tree to serve: the premium one when this build has it.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * TWO TREES, BECAUSE ONE WOULD PUT PREMIUM CODE IN THE FREE DOWNLOAD.
	 *
	 * A premium build inlines the premium modules into its chunks, so the two targets
	 * cannot share an output directory — the second build would overwrite the first and
	 * the free ZIP would ship a bundle naming every premium screen. WordPress.org rejects
	 * that as trialware under Guideline 5, and the point of `pro/` is that the free build
	 * does not CONTAIN premium code rather than merely hiding it.
	 *
	 * So the premium bundle lives under `pro/`, which Freemius strips from the free
	 * download exactly as it strips the premium PHP. `is_dir()` is the same question
	 * `ModuleRegistry` asks, answered the same way: in a free build the directory is not
	 * there, and this falls back to the free tree without a licence check.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $tail Path under the build root, e.g. `backend/build/`.
	 *
	 * @return array{path: string, url: string}
	 */
	public static function tree( string $tail ): array {
		$premium = 'pro/assets/' . $tail;

		if ( is_dir( PLUGIXA_EXAM_PATH . $premium ) ) {
			return array(
				'path' => PLUGIXA_EXAM_PATH . $premium,
				'url'  => PLUGIXA_EXAM_URL . $premium,
			);
		}

		return array(
			'path' => PLUGIXA_EXAM_PATH . 'assets/' . $tail,
			'url'  => PLUGIXA_EXAM_URL . 'assets/' . $tail,
		);
	}
}
