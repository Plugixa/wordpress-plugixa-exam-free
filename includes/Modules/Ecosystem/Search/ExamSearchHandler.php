<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Ecosystem\Search;

use PlugixaExam\Core\Frontend\Router;
use PlugixaExam\Core\Settings;
use WP_REST_Request;
use WP_REST_Search_Controller;
use WP_REST_Search_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exams as a source the one WordPress search endpoint consults.
 *
 * THE JOOMLA ORIGINAL SHIPPED A SMART SEARCH ADAPTER, which pushed exam records into
 * Joomla's Finder index. The instinct when porting is to build the equivalent: a second
 * index, a re-index button, a cron job to keep it fresh, and a support burden when it drifts
 * out of date. WordPress does not need any of it. `/wp/v2/search` is extensible by design,
 * and a handler registered here answers live from the exams table — so there is nothing to
 * re-index, nothing to drift, and an exam renamed a second ago is already renamed in search.
 *
 * WHAT THIS ACTUALLY BUYS THE SITE OWNER, which is easy to underrate:
 *
 * - Typing an exam name in the block editor's link dialog finds it, so linking to an exam
 *   from a post no longer means going and copying the URL.
 * - The Navigation block's link picker finds it too.
 * - Anything built on `/wp/v2/search` — a headless front end, a site-search block, another
 *   plugin — gets exams for free, without knowing this plugin exists.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: index question text. See
 * `Modules\Exams\Frontend\ExamBridge::search()` for why that is a leak rather than a
 * feature.
 *
 * WHY IT NAMES NO MODULE. This class asks `plugixa_exam_search_exams` and
 * `plugixa_exam_frontend_exam_by_id`. With the Exams module absent — or switched off — both
 * go unanswered, `search_items()` returns nothing, and `/wp/v2/search?type=plugixa-exam`
 * politely finds no results instead of fatalling.
 */
final class ExamSearchHandler extends WP_REST_Search_Handler {

	/**
	 * The `type` this handler answers to: `/wp/v2/search?type=plugixa-exam`.
	 *
	 * @var string
	 */
	protected $type = 'plugixa-exam';

	/**
	 * @var string[]
	 */
	protected $subtypes = array( 'exam' );

	private readonly Router $router;

	/**
	 * @param Router|null   $router   Link builder. Constructed here when absent: WordPress
	 *                                instantiates search handlers itself in some code paths
	 *                                and cannot pass a dependency.
	 * @param Settings|null $settings Plugin settings, for the upcoming-exams preference.
	 *                                Absent means "use the documented default".
	 */
	public function __construct( ?Router $router = null, private readonly ?Settings $settings = null ) {
		$this->router = $router ?? new Router();
	}

	/**
	 * Ids of the exams matching this request.
	 *
	 * @param WP_REST_Request $request Full REST request.
	 *
	 * @return array{ids: int[], total: int}
	 */
	public function search_items( WP_REST_Request $request ): array {
		/**
		 * Exams matching a search phrase.
		 *
		 * Answered by the Exams module. Unanswered — module absent or switched off — this
		 * returns no results rather than failing.
		 *
		 * @param array{ids: int[], total: int}|null $result Current value.
		 * @param array<string, mixed>               $args   Search arguments.
		 */
		$result = apply_filters(
			'plugixa_exam_search_exams',
			null,
			array(
				'search'           => (string) $request['search'],
				'page'             => (int) ( $request['page'] ?? 1 ),
				'per_page'         => (int) ( $request['per_page'] ?? 10 ),
				'include_upcoming' => null !== $this->settings && $this->settings->bool( 'search_include_upcoming', false ),
			)
		);

		if ( ! is_array( $result ) || ! isset( $result['ids'] ) ) {
			return array(
				self::RESULT_IDS   => array(),
				self::RESULT_TOTAL => 0,
			);
		}

		return array(
			self::RESULT_IDS   => array_map( 'intval', (array) $result['ids'] ),
			self::RESULT_TOTAL => (int) ( $result['total'] ?? 0 ),
		);
	}

	/**
	 * One exam, in the shape `/wp/v2/search` returns.
	 *
	 * Only the fields asked for are computed. That is not micro-optimisation: the link
	 * picker asks for id, title and url, and building the rest would mean a lookup per
	 * result for data nothing displays.
	 *
	 * @param int|string $id     Exam id.
	 * @param string[]   $fields Fields to include.
	 *
	 * @return array<string, mixed>
	 */
	public function prepare_item( $id, array $fields ): array {
		$id   = (int) $id;
		$exam = apply_filters( 'plugixa_exam_frontend_exam_by_id', null, $id );
		$data = array();

		if ( in_array( WP_REST_Search_Controller::PROP_ID, $fields, true ) ) {
			$data[ WP_REST_Search_Controller::PROP_ID ] = $id;
		}

		if ( in_array( WP_REST_Search_Controller::PROP_TITLE, $fields, true ) ) {
			$data[ WP_REST_Search_Controller::PROP_TITLE ] = is_array( $exam )
				? (string) ( $exam['title'] ?? '' )
				: '';
		}

		if ( in_array( WP_REST_Search_Controller::PROP_URL, $fields, true ) ) {
			$data[ WP_REST_Search_Controller::PROP_URL ] = is_array( $exam ) && '' !== (string) ( $exam['slug'] ?? '' )
				? $this->router->exam_url( (string) $exam['slug'] )
				: '';
		}

		if ( in_array( WP_REST_Search_Controller::PROP_TYPE, $fields, true ) ) {
			$data[ WP_REST_Search_Controller::PROP_TYPE ] = $this->type;
		}

		if ( in_array( WP_REST_Search_Controller::PROP_SUBTYPE, $fields, true ) ) {
			$data[ WP_REST_Search_Controller::PROP_SUBTYPE ] = 'exam';
		}

		return $data;
	}

	/**
	 * Links for a result.
	 *
	 * Exams are not posts, so there is no REST route WordPress could `self`-link to here.
	 * An empty set is the correct answer, and the search controller handles it.
	 *
	 * @param int|string $id Exam id.
	 *
	 * @return array<string, mixed>
	 */
	public function prepare_item_links( $id ): array {
		unset( $id );

		return array();
	}
}
