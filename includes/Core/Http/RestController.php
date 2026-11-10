<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Http;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Core\Security\Permissions;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base REST controller.
 *
 * Controllers are deliberately thin: they authorise, coerce request data, hand off to a
 * service, and shape the response. Business logic belongs in the service layer, so that CSV
 * import, the runner and REST all reach the same code.
 *
 * EVERY ROUTE HAS A REAL `permission_callback`, WITHOUT EXCEPTION, AND IT CAN BE JUDGED
 * FROM THE LINE THAT REGISTERS THE ROUTE. A route asks WordPress, in so many words:
 *
 *     'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'exams', 'publish' ) ),
 *
 * {@see Capabilities::ACCESS} is a meta capability answered by the WordPress capability
 * *and* the role matrix together. Where the question needs more than one line it is a
 * named public method of the controller (`array( $this, 'may_…' )`), never a method that
 * RETURNS a callback: until 1.1.3 the routes said `$this->can( 'publish' )`, a closure
 * built in this file, and nobody reading a route could tell what it checked. WordPress.org's
 * review said so twice. Routes a guest must reach — the runner's autosave and submit —
 * authorise on the guest token and attempt ownership; see
 * `Modules/Attempts/Http/RunnerController` for the shape.
 */
abstract class RestController extends WP_REST_Controller {

	/**
	 * REST namespace shared by the whole plugin.
	 *
	 * @var string
	 */
	protected $namespace = 'plugixa-exam/v1';

	/**
	 * The feature this controller serves, from {@see Permissions::FEATURES}.
	 */
	abstract protected function feature(): string;

	/**
	 * Turn the answer to a permission question into what a `permission_callback` returns.
	 *
	 * The question itself stays where the route is registered. This only gives a refusal
	 * the plugin's own error, so a caller is told the same thing by every route: 401 when
	 * nobody is signed in, 403 when somebody is.
	 *
	 * @param bool $allowed What `current_user_can()` answered.
	 *
	 * @return true|WP_Error
	 */
	protected function allow( bool $allowed ): bool|WP_Error {
		return $allowed ? true : $this->forbidden();
	}

	/**
	 * Permission callback for editing ONE existing row, honouring `<feature>: edit.own`.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * `edit.own` WAS IN THE MATRIX, WAS GRANTED TO THE SEEDED AUTHOR ROLE, AND NOTHING
	 * ANYWHERE CHECKED IT.
	 *
	 * Every edit route asked `can( 'edit' )`, so the narrower permission was decorative:
	 * a role granted `edit.own` alone could edit nothing at all, and a role granted `edit`
	 * could edit everybody's work whether or not the site meant that. The Roles screen
	 * offered a choice the API did not implement, which is worse than not offering it —
	 * somebody configuring a multi-author site would have believed they had scoped it.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * `edit` SHORT-CIRCUITS, so this is backward compatible by construction: every existing
	 * role that holds it behaves exactly as before and never reaches the ownership test.
	 *
	 * A ROW NOBODY CREATED IS NOT YOURS. `created_by` is 0 for anything imported, seeded or
	 * written before the column meant anything, and none of it belongs to the person who
	 * happens to be looking. A row that does not exist is refused rather than 404'd for a
	 * related reason: answering "no such row" would let a caller who may edit nothing
	 * enumerate what exists.
	 *
	 * THE `> 0` AND `null !==` TESTS BELOW ARE BELT AND BRACES AND CANNOT CURRENTLY FIRE,
	 * which is worth saying rather than leaving for somebody to discover. `user_can()`
	 * refuses user 0 before the matrix is consulted, so `$user_id` is always a real id here
	 * — and a real id equals neither 0 nor null on its own. They are kept because they state
	 * the intent at the point of decision, and because the day `user_can()` grows a guest
	 * path is the day their absence becomes a hole. The tests around this assert the
	 * BEHAVIOUR (an imported row is not editable by an `edit.own` holder), which is true
	 * either way; they are not evidence that these two clauses do anything.
	 *
	 * NOT A CALLBACK ITSELF. The controller's own named `may_edit_row()` is what the route
	 * registers, and it calls this with the way to find a row's creator.
	 *
	 * @param WP_REST_Request           $request  The request; its `id` names the row.
	 * @param callable(int): (int|null) $owner_of Resolves a row id to its `created_by`, or
	 *                                            null when there is no such row.
	 *
	 * @return true|WP_Error
	 */
	protected function edit_or_own( WP_REST_Request $request, callable $owner_of ): bool|WP_Error {
		$feature = $this->feature();

		if ( current_user_can( Capabilities::ACCESS, $feature, 'edit' ) ) {
			return true;
		}

		if ( ! current_user_can( Capabilities::ACCESS, $feature, 'edit.own' ) ) {
			return $this->forbidden();
		}

		$user_id = get_current_user_id();
		$owner   = $owner_of( (int) $request['id'] );

		return null !== $owner && $owner > 0 && $owner === $user_id
			? true
			: $this->forbidden();
	}

	/**
	 * Permission callback for routes that change how the plugin itself behaves, registered
	 * as `array( $this, 'may_configure' )`.
	 *
	 * Deliberately not expressed through the feature matrix: settings, roles and the App
	 * Manager are not content, and a role that could edit them could grant itself anything
	 * else. `manage_options` is the right bar.
	 *
	 * @return true|WP_Error
	 */
	public function may_configure(): bool|WP_Error {
		// The plugin's capability, or the one it falls back to on a site that never
		// granted it (see Capabilities::FALLBACKS).
		$allowed = current_user_can( Capabilities::SETTINGS ) || current_user_can( 'manage_options' );

		/**
		 * Whether the caller may change how the plugin behaves.
		 *
		 * A VETO, NOT A GRANT — listeners may only narrow, which is why the value is
		 * `&&`-ed rather than replaced. It exists because this path deliberately does
		 * NOT go through `Permissions::user_can()`, so anything that caps a caller
		 * through `plugixa_exam_user_can` is blind to settings, roles and the App
		 * Manager. The ApiTokens module uses it to make configuration unreachable by
		 * any token, whoever the token acts as.
		 *
		 * @param bool $allowed Whether the WordPress capability check passed.
		 */
		$allowed = $allowed && (bool) apply_filters( 'plugixa_exam_can_configure', true );

		return $allowed ? true : $this->forbidden();
	}

	/**
	 * A successful response.
	 *
	 * @param mixed                $data   Payload.
	 * @param array<string, mixed> $meta   Pagination or other metadata.
	 * @param int                  $status HTTP status.
	 */
	protected function ok( mixed $data, array $meta = array(), int $status = 200 ): WP_REST_Response {
		/**
		 * Filter a successful payload before it leaves the building.
		 *
		 * THE ONE PLACE FIELD-LEVEL ACCESS CAN BE ENFORCED. Every route answers through
		 * this method, so a rule applied here cannot be forgotten by a controller — and a
		 * field-level rule one controller forgets is a field quietly readable through one
		 * route, which looks exactly like a field that was allowed.
		 *
		 * @param mixed  $data    The payload.
		 * @param string $feature The controller's feature slug.
		 */
		$data = apply_filters( 'plugixa_exam_filter_output', $data, $this->feature() );

		$body = array(
			'success' => true,
			'data'    => $data,
		);

		if ( array() !== $meta ) {
			$body['meta'] = $meta;
		}

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * An error response.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 */
	protected function fail( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * The standard 403.
	 */
	protected function forbidden(): WP_Error {
		return $this->fail(
			'plugixa_exam_forbidden',
			__( 'You are not allowed to do that.', 'plugixa-exam' ),
			rest_authorization_required_code()
		);
	}

	/**
	 * The standard 404.
	 */
	protected function not_found(): WP_Error {
		return $this->fail(
			'plugixa_exam_not_found',
			__( 'Not found.', 'plugixa-exam' ),
			404
		);
	}

	/**
	 * Pagination and sort arguments, clamped.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array{limit: int, offset: int, orderby: string, order: string, search: string}
	 */
	protected function list_args( WP_REST_Request $request ): array {
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 50 );
		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );

		$per_page = max( 1, min( 200, $per_page ) );
		$page     = max( 1, $page );

		return array(
			'limit'   => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => (string) ( $request->get_param( 'orderby' ) ?? 'id' ),
			'order'   => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
			'search'  => sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) ),
		);
	}

	/**
	 * A paged, searchable, trash-aware list.
	 *
	 * The two genuinely per-entity things stay parameters: which columns a search looks in,
	 * and any extra narrowing. Everything else — the clamped paging, the trash view, the
	 * safe sort column, the total, the page count — is the same everywhere and is decided
	 * here, so no controller can ship a list that quietly lacks paging.
	 *
	 * `$narrow` may return a `WP_Error` to REFUSE the request rather than narrow it. That
	 * matters for a filter that defines what a page is: silently ignoring an unrecognised
	 * value turns a screen showing one kind of record into one showing every kind, which
	 * looks like a working page.
	 *
	 * @param WP_REST_Request $request        The request.
	 * @param Repository      $repository     The entity's repository.
	 * @param string[]        $search_columns Columns a search matches, e.g. `array( 't.title' )`.
	 * @param callable|null   $narrow         fn(QueryBuilder, WP_REST_Request): ?WP_Error.
	 * @param callable|null   $decorate       fn(array $row): array, applied to every row.
	 */
	protected function paged_list(
		WP_REST_Request $request,
		Repository $repository,
		array $search_columns = array(),
		?callable $narrow = null,
		?callable $decorate = null
	): WP_REST_Response|WP_Error {
		$args  = $this->list_args( $request );
		$query = $repository->query();

		if ( 'trash' === $request->get_param( 'view' ) ) {
			$query->only_trashed();
		}

		if ( null !== $narrow ) {
			$refused = $narrow( $query, $request );

			if ( $refused instanceof WP_Error ) {
				return $refused;
			}
		}

		/**
		 * Narrow any paged list.
		 *
		 * ONE SEAM FOR EVERY LIST ROUTE, rather than a hook per controller. A module that
		 * adds a filterable dimension — CustomFields adds a join per filtered field — needs
		 * to reach a query it does not own, and the alternative is editing each controller's
		 * narrowing closure every time a module is added.
		 *
		 * Fired BEFORE `count()`, so a listener's join is reflected in the total as well as
		 * in the page. A listener that joins must not multiply rows; where the join can match
		 * more than once, it is the listener's job to keep it one-to-one.
		 *
		 * @param QueryBuilder    $query   The query being built.
		 * @param WP_REST_Request $request The request.
		 * @param string          $entity  The repository's entity type, e.g. `question`.
		 */
		do_action( 'plugixa_exam_narrow_list', $query, $request, $repository->entity_type() );

		$query->search( $args['search'], $search_columns );

		$total = $query->count();

		$rows = $query
			->order_by( 't.' . $repository->safe_sort_column( $args['orderby'] ), $args['order'] )
			->paginate( $args['limit'], $args['offset'] )
			->get();

		$rows = array_map( array( $repository, 'cast' ), $rows );

		if ( null !== $decorate ) {
			$rows = array_map( $decorate, $rows );
		}

		return $this->ok(
			$rows,
			array(
				'total' => $total,
				'pages' => (int) ceil( $total / max( 1, $args['limit'] ) ),
			)
		);
	}

	/**
	 * The collection query parameters every list route accepts.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'order'    => array(
				'type' => 'string',
				'enum' => array( 'asc', 'desc' ),
			),
			'view'     => array(
				'type' => 'string',
				'enum' => array( 'live', 'trash' ),
			),
		);
	}
}
