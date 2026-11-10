<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Admin;

use PlugixaExam\Core\App\Bundle;
use PlugixaExam\Core\Container;
use PlugixaExam\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wp-admin menu entry, and the div the app mounts into.
 *
 * ONE MENU PAGE, NOT ONE PER MODULE. Every screen in this plugin is a hash route inside the
 * single-page app, so a WordPress submenu per module would produce a full page reload — and
 * a fresh WordPress bootstrap, and a fresh React mount — every time somebody moved from
 * Questions to Exams. The `plugixa_exam_admin_submenus` seam exists for the rare case that
 * genuinely wants a separate page.
 */
final class Admin {

	public const MENU_SLUG = 'plugixa-exam';

	/**
	 * The hook suffix WordPress hands back from `add_menu_page`.
	 *
	 * Captured so {@see Assets} can compare against it exactly, rather than matching the
	 * page slug loosely — Freemius registers its own pricing and account pages whose hook
	 * suffixes also contain "plugixa-exam", and loading the whole app onto the pricing page
	 * is both slow and visibly wrong.
	 */
	private string $hook_suffix = '';

	/**
	 * @param Container $container The service container.
	 */
	public function __construct( private readonly Container $container ) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Plugixa Exam', 'plugixa-exam' ),
			__( 'Plugixa Exams', 'plugixa-exam' ),
			Capabilities::MANAGE,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-welcome-learn-more',
			30
		);

		/*
		 * The first entry under the menu keeps the name "Exams". WordPress builds that entry
		 * from the top-level label unless one is registered for the menu's own slug, so
		 * without this the sidebar would read "Plugixa Exams" twice. Registered here, before
		 * Freemius adds Account and Contact Us at its lowest priority, so it stays first and
		 * WordPress does not add a second copy.
		 */
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Plugixa Exam', 'plugixa-exam' ),
			__( 'Exams', 'plugixa-exam' ),
			Capabilities::MANAGE,
			self::MENU_SLUG
		);

		Assets::own_hook( $this->hook_suffix );

		/**
		 * Register an additional admin page under this plugin's menu.
		 *
		 * Nothing uses it yet. It exists because a module that genuinely needs a separate
		 * WordPress page — rather than a hash route — should have somewhere to put it that
		 * does not involve editing this file.
		 *
		 * @param string    $parent_slug The plugin's menu slug.
		 * @param Container $container   The service container.
		 */
		do_action( 'plugixa_exam_admin_submenus', self::MENU_SLUG, $this->container );
	}

	/**
	 * The whole admin screen: an empty div.
	 *
	 * Echoed rather than returned — this is a menu-page callback, which prints.
	 */
	public function render(): void {
		echo wp_kses( Bundle::mount_html(), array( 'div' => array( 'id' => array() ) ) );
	}
}
