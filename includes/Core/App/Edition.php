<?php
/**
 * Which build is installed: free or premium.
 *
 * The premium build is the free one plus a `pro/` folder, so the folder is the answer. No
 * licence check: a licence is Freemius's business, and the free build must work with no
 * account at all. One helper so the router's rewrite signature and the admin app's "show the
 * Pro features page" flag can never disagree.
 *
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The installed edition.
 */
final class Edition {

	/**
	 * The premium plans on plugixa.com: the free build's "See Pro plans" link.
	 */
	public const PRICING_URL = 'https://www.plugixa.com/plugins/plugixa-exam#pricing';

	/**
	 * `premium` when the `pro/` folder is on disk, `free` otherwise.
	 */
	public static function current(): string {
		return is_dir( PLUGIXA_EXAM_PATH . 'pro' ) ? 'premium' : 'free';
	}
}
