<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Http;

use PlugixaExam\Core\Frontend\Pages;
use PlugixaExam\Core\Settings;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pages a site owner hosts the exam area on.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THERE IS NO SAVE ROUTE HERE, AND THAT IS NOT AN OMISSION.
 *
 * {@see Pages} declares `pages` through `plugixa_exam_settings_defaults` and rebuilds it
 * through `plugixa_exam_sanitize_settings`, so the key is already in `Settings::defaults()`
 * — which means `GET /settings` already returns the map and `POST /settings` already
 * accepts and sanitises it. A second endpoint writing the same option would be a second
 * place for "is this a page id we know" to be answered.
 *
 * What is genuinely new is the two things the settings round trip cannot do: tell the admin
 * screen which pages EXIST on this site, and create the missing ones.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ FIVE PAGES ARE CREATED, NOT SEVEN, AND THE TWO LEFT OUT ARE THE POINT. The exam landing
 * and the single result are LINK TARGETS: they render nothing without `?pxe_exam=` or
 * `?pxe_result=`, so a menu item pointing at either shows a visitor an empty page. They also
 * already have better URLs than a page could give them — `/exams/algebra/` reads better than
 * `/exam/?pxe_exam=algebra` — so they keep their routes unless an owner deliberately maps
 * them. Measured on a block theme, where the header lists every published page: creating all
 * seven put "Exam" and "Result" in the site's navigation next to the real destinations.
 */
final class PagesController extends RestController {

	/**
	 * @param Settings $settings The settings store.
	 */
	public function __construct( private readonly Settings $settings ) {}

	protected function feature(): string {
		return 'configuration';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/pages',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_pages' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/pages/create',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_pages' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);
	}

	/**
	 * What the Pages panel needs to draw itself.
	 *
	 * `may_configure()` rather than a feature check: this lists every page on the site, and
	 * a role that may read exams has no business enumerating somebody's private drafts.
	 */
	public function get_pages( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok(
			array(
				'pages'    => $this->page_list(),
				'setup'    => $this->setup_state(),
				'canBuild' => $this->missing() !== array(),
				'menu'     => $this->menu_advice(),
			)
		);
	}

	/**
	 * Create a page for every destination that has not got one, and map it.
	 *
	 * ⚠️ IT ADOPTS RATHER THAN DUPLICATES, AND IT USED TO GET THAT WRONG FOR THE DASHBOARD.
	 *
	 * A destination already mapped to a published page is left alone. Otherwise adoption is
	 * tried in order — the id this plugin stored, then the exact slug, then ANY page whose
	 * content already contains the destination's shortcode — before anything is inserted.
	 *
	 * The third step is not belt-and-braces. The dashboard had no stored id at all, so a
	 * site whose dashboard lived at `/learning/` instead of `/my-learning/` failed the slug
	 * test and was handed a second page carrying the same title and the same shortcode. The
	 * shortcode is the strongest statement of what a page is FOR, and it survives every
	 * rename the slug does not.
	 */
	public function create_pages( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$map     = $this->map();
		$created = array();
		$adopted = array();

		/*
		 * Fetched once for the whole loop: the shortcode search reads post content, and
		 * doing it per destination would walk the site's pages five times over.
		 */
		$candidates = $this->all_pages();

		foreach ( $this->missing() as $key => $page ) {
			$existing = $this->adoptable( $page, $candidates );

			if ( $existing instanceof WP_Post ) {
				$id = (int) $existing->ID;

				/*
				 * ⚠️ AN ADOPTED DRAFT MUST BE PUBLISHED, OR THE BUTTON NEVER GOES AWAY.
				 *
				 * `missing()` requires `publish`, so a draft sitting at the target slug was
				 * adopted on every press, reported missing again on the next read, and left
				 * "Create the missing pages" on screen for ever with nothing changing. The
				 * owner pressed a button that says it creates the page; publishing the one
				 * that is already there is the smaller surprise.
				 */
				if ( 'publish' !== $existing->post_status ) {
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'publish',
						)
					);
				}

				$adopted[] = $key;
			} else {
				$id = wp_insert_post(
					array(
						'post_title'   => $page['title'],
						'post_name'    => $page['slug'],
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_content' => '[' . $page['tag'] . ']',
					),
					true
				);

				if ( is_wp_error( $id ) || 0 === (int) $id ) {
					continue;
				}

				$id        = (int) $id;
				$created[] = $key;
			}

			/*
			 * ⚠️ EVERY DESTINATION IS REMEMBERED, INCLUDING THE ONE NOTHING LINKS TO. The
			 * dashboard's key is its setup key rather than a view, because it has no view —
			 * that is the whole point of it, and it is also what used to lose it.
			 */
			$map[ '' !== $page['view'] ? $page['view'] : $key ] = $id;
		}

		if ( array() !== $created || array() !== $adopted ) {
			$this->settings->save( array( Pages::SETTING => $map ) );
			$this->settings->flush();
		}

		return $this->ok(
			array(
				'created' => $created,
				'adopted' => $adopted,
				'pages'   => $this->page_list(),
				'setup'   => $this->setup_state(),
				'menu'    => $this->menu_advice(),
			)
		);
	}

	/* ------------------------------------------------------------------ internals */

	/**
	 * The stored map, as ints.
	 *
	 * @return array<string, int>
	 */
	private function map(): array {
		$stored = $this->settings->get( Pages::SETTING );

		return is_array( $stored ) ? array_map( 'intval', $stored ) : array();
	}

	/**
	 * Every page on the site the admin screen may offer.
	 *
	 * Drafts are included and LABELLED rather than hidden: an owner who mapped a page and
	 * then unpublished it needs to see why their menu item stopped working.
	 * {@see \PlugixaExam\Core\Frontend\Router::page_url()} falls back to the built-in route
	 * for anything that is not published, silently — this is where that becomes visible.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page_list(): array {
		$out = array();

		foreach ( $this->all_pages() as $post ) {
			$out[] = array(
				'id'        => (int) $post->ID,
				'title'     => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'plugixa-exam' ),
				/*
				 * ⚠️ THE SLUG IS SENT BECAUSE TITLES ARE NOT UNIQUE AND THE DROPDOWN SHOWS
				 * TITLES. Two pages called "My learning" — or "Contact", on any site that
				 * has ever reorganised — render as two identical options, and choosing
				 * between them is guesswork. This is what makes them tell apart.
				 */
				'slug'      => (string) $post->post_name,
				'status'    => (string) $post->post_status,
				'published' => 'publish' === $post->post_status,
				'url'       => (string) get_permalink( $post ),
				'editUrl'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $out;
	}

	/**
	 * Every page the admin screen may offer, as posts.
	 *
	 * @return array<int, WP_Post>
	 */
	private function all_pages(): array {
		$posts = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		return array_values( array_filter( $posts, static fn( $post ) => $post instanceof WP_Post ) );
	}

	/**
	 * The page a destination should take over rather than duplicate, if there is one.
	 *
	 * Slug first — it is an indexed lookup and it is what the setup button itself wrote.
	 * Then the shortcode, which is what the page is FOR and survives any rename.
	 *
	 * @param array<string, string> $page       One entry from {@see Pages::setup()}.
	 * @param array<int, WP_Post>   $candidates Every page on the site.
	 */
	private function adoptable( array $page, array $candidates ): ?WP_Post {
		$by_slug = get_page_by_path( $page['slug'] );

		if ( $by_slug instanceof WP_Post ) {
			return $by_slug;
		}

		/*
		 * ⚠️ OLDEST WINS, AND THE ORDER HAD TO BE SAID OUT LOUD.
		 *
		 * A shortcode may legitimately appear on more than one page — an owner who puts the
		 * catalogue on their front page as well as on a dedicated one has done nothing
		 * wrong. `$candidates` arrives sorted by TITLE, because that is what the dropdown
		 * wants, so adopting the first match made the winner depend on alphabetical order:
		 * "My learning" beat "Page pxe-test-elsewhere" for no reason anybody could state.
		 * Caught by a test that expected its own fixture and got the site's real page.
		 *
		 * The oldest page carrying the shortcode is the one the owner has had longest, which
		 * is the closest thing to "the one they meant" that a machine can know.
		 */
		$by_age = $candidates;
		usort( $by_age, static fn ( WP_Post $a, WP_Post $b ): int => $a->ID <=> $b->ID );

		foreach ( $by_age as $post ) {
			if ( $this->holds_shortcode( (string) $post->post_content, $page['tag'] ) ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * Whether a page's content carries a shortcode — asked WITHOUT consulting the registry.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * ⚠️ THIS IS NOT `has_shortcode()`, AND THE DIFFERENCE IS THE WHOLE POINT.
	 *
	 * `has_shortcode()` opens with `if ( shortcode_exists( $tag ) )` and answers FALSE for
	 * a tag nothing has registered. That is right for "should I run this?" and wrong for
	 * "what is this page for?" — a page does not stop being the dashboard because the
	 * module that draws it is switched off.
	 *
	 * Not hypothetical. Switch Portal off in the App Manager and `plugixa_exam_dashboard`
	 * is no longer registered; adoption would find nothing, and the setup button would
	 * publish a second dashboard beside the owner's existing one — the exact bug this
	 * method exists to stop, returning through a side door. Caught by the suite: the case
	 * passed alone and failed in a full run, because an earlier test had left the registry
	 * in a different state.
	 *
	 * `get_shortcode_regex()` given explicit tag names builds its pattern from those names
	 * and never reads the registry, so it keeps WordPress's own parsing — attributes,
	 * closing tags, `[[escaped]]` — without its "is it live?" question.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	private function holds_shortcode( string $content, string $tag ): bool {
		if ( ! str_contains( $content, '[' ) ) {
			return false;
		}

		return 1 === preg_match( '/' . get_shortcode_regex( array( $tag ) ) . '/', $content );
	}

	/**
	 * What a destination currently points at.
	 *
	 * ⚠️ THE SLUG LOOKUP IS A MIGRATION PATH, NOT THE ANSWER. Every destination now stores
	 * an id, the dashboard included. The fallback exists so a site configured before that
	 * was true adopts the page it already has instead of being handed a second one, and it
	 * stops being reached the moment {@see self::create_pages()} writes the id.
	 *
	 * @param array<string, string> $page One entry from {@see Pages::setup()}.
	 * @param array<string, int>    $map  The stored map.
	 */
	private function map_id( string $key, array $page, array $map ): int {
		if ( '' !== $page['view'] ) {
			return (int) ( $map[ $page['view'] ] ?? 0 );
		}

		$stored = (int) ( $map[ $key ] ?? 0 );

		return $stored > 0 ? $stored : $this->page_id_by_slug( $page['slug'] );
	}

	/**
	 * The destinations, and what each one currently points at.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function setup_state(): array {
		$map = $this->map();
		$out = array();

		foreach ( Pages::setup() as $key => $page ) {
			$id = $this->map_id( $key, $page, $map );

			$out[] = array(
				'key'       => $key,
				'view'      => $page['view'],
				'tag'       => $page['tag'],
				'shortcode' => '[' . $page['tag'] . ']',
				'slug'      => $page['slug'],
				'pageId'    => $id,
				'mappable'  => '' !== $page['view'],
				'published' => $id > 0 && 'publish' === get_post_status( $id ),
			);
		}

		return $out;
	}

	/** A page id by slug, or 0 — reached only by {@see self::map_id()}'s migration path. */
	private function page_id_by_slug( string $slug ): int {
		$page = get_page_by_path( $slug );

		return $page instanceof WP_Post ? (int) $page->ID : 0;
	}

	/**
	 * The destinations that have no page yet.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function missing(): array {
		$map = $this->map();
		$out = array();

		foreach ( Pages::setup() as $key => $page ) {
			$id = $this->map_id( $key, $page, $map );

			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				continue;
			}

			$out[ $key ] = $page;
		}

		return $out;
	}

	/**
	 * What this site's theme can and cannot be told about menus.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * ⚠️ THE HONEST ANSWER IS "IT DEPENDS", AND PRETENDING OTHERWISE WASTES SOMEBODY'S HOUR.
	 *
	 * On a CLASSIC theme, creating a menu and assigning it to a registered location puts the
	 * pages in the site's navigation, and this can offer to do that.
	 *
	 * On a BLOCK theme there are no registered locations at all — navigation is a block
	 * inside a template, edited in the site editor, and a plugin cannot put anything into it
	 * without rewriting the owner's template. Measured on Twenty Twenty-Five:
	 * `get_registered_nav_menus()` is empty, and a classic menu created through
	 * `wp_create_nav_menu()` is orphaned and invisible. What DID make the pages appear was
	 * the theme's own Navigation block falling back to a Page List — which lists every
	 * published page, and is exactly why creating pages for the two link targets was wrong.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return array<string, mixed>
	 */
	private function menu_advice(): array {
		$locations = get_registered_nav_menus();

		return array(
			'blockTheme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'locations'  => array_keys( $locations ),
			'editUrl'    => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme()
				? admin_url( 'site-editor.php' )
				: admin_url( 'nav-menus.php' ),
		);
	}
}
