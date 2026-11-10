<?php

/**
 * Plugin Name: Plugixa Exam – Online Exams, Live Monitoring, Quizzes & Certificates
 * Plugin URI: https://www.plugixa.com/plugins/plugixa-exam
 * Description: Exams, quizzes and assessments for WordPress — a question bank, an exam builder with sections and random pools, a timed runner with autosave, and automatic grading.
 * Version: 1.3.0
 * Author: Plugixa
 * Author URI: https://plugixa.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plugixa-exam
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.2
 *
 * @package PlugixaExam
 * @copyright 2026 Plugixa (https://plugixa.com)
 *
 * All premium code lives under pro/: PHP modules in pro/includes/Modules/, premium
 * libraries in pro/vendor/ with their own autoloader, and the premium app source in
 * assets/backend/app/src/pro/ and assets/frontend/app/src/pro/. Freemius strips these paths
 * from the auto-generated free version, so the free build ships no premium code — and
 * because ModuleRegistry discovers modules by globbing the filesystem, a premium module in
 * a free build does not exist rather than existing-and-locked. There is no licence check
 * anywhere in this plugin.
 *
 * BOTH app source directories have to be listed, and only one of them used to be. The
 * release script strips the pair, so every locally-built free ZIP was clean and the
 * omission was invisible here — but the ZIP that ships to WordPress.org is the one FREEMIUS
 * generates, from this directive and nothing else. It was carrying the premium runner
 * source: the four advanced question inputs, the integrity recorder and the maths renderer.
 * A local build agreeing with itself is not evidence about the build somebody downloads.
 *
 */
declare (strict_types = 1);
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/*
 * ONE COPY RUNS. The free build lives in `plugixa-exam/` and the premium one in
 * `plugixa-exam-premium/`, and for one request both are loaded every time somebody upgrades:
 * WordPress includes the plugin being activated while the other is still active. Both can
 * also simply be left active, which WP-CLI allows. This file used to carry on regardless —
 * four "Constant already defined" warnings on that request (on every request with both
 * active), a second autoloader, and a second `plugins_loaded` boot of the same classes.
 * Seen on Windows and on Linux in the release E2E of 10 Oct 2026.
 *
 * So the copy that loads second stands down here, before it defines or hooks anything. The
 * first is the premium one whenever both are active (WordPress loads active plugins in the
 * order of their paths, and `plugixa-exam-premium/` sorts first). Nothing is lost on the
 * upgrade request: the tables a build is missing are made on the next request it serves
 * alone, which is what already created them.
 */
if ( !function_exists( 'plugixa_exam_uninstall_copy' ) ) {
    /**
     * Delete the plugin's data, unless another copy of the plugin is still installed.
     *
     * BOTH BUILDS OWN THE SAME TABLES, SO THE DATA GOES WHEN THE LAST COPY DOES. Somebody
     * going back to the free version deletes "Plugixa Exam Pro" and expects their exams to
     * be there afterwards; so does somebody removing the free copy once the premium one is
     * in. The Freemius SDK only spares the data when the other build is ACTIVE at that
     * moment: with it installed but not yet activated, deleting the premium plugin dropped
     * every table (measured, 10 Oct 2026).
     *
     * Counted on disk, not asked of either copy, because this can be called from whichever
     * of the two files was loaded first. WordPress runs the uninstall before it removes the
     * folder, so the copy being deleted is still one of the two.
     */
    function plugixa_exam_uninstall_copy() : void {
        $copies = 0;
        foreach ( array('plugixa-exam/plugixa-exam.php', 'plugixa-exam-premium/plugixa-exam.php') as $copy ) {
            if ( file_exists( WP_PLUGIN_DIR . '/' . $copy ) ) {
                ++$copies;
            }
        }
        if ( $copies > 1 ) {
            return;
        }
        \PlugixaExam\Core\Uninstaller::uninstall();
    }

}
if ( defined( 'PLUGIXA_EXAM_VERSION' ) ) {
    /*
     * Standing down must not cost this copy its uninstall. Freemius registers a plugin's
     * uninstall when that plugin is deactivated, from the SDK instance this file would have
     * made further down. A copy switched off while it is standing down has no instance, so
     * nothing was registered, and deleting it later as the last copy left every table
     * behind. So it registers the same routine itself. If it is activated again by itself,
     * Freemius registers its own in the usual way and that one replaces this.
     */
    register_deactivation_hook( __FILE__, static function () : void {
        register_uninstall_hook( __FILE__, 'plugixa_exam_uninstall_copy' );
    } );
    return;
}
define( 'PLUGIXA_EXAM_VERSION', '1.3.0' );
define( 'PLUGIXA_EXAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUGIXA_EXAM_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIXA_EXAM_BASENAME', plugin_basename( __FILE__ ) );
/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * Registered directly rather than leaning on Composer for two reasons. It works before
 * `composer install` has ever run, which matters on a fresh checkout; and it is a plain
 * one-to-one namespace-to-directory map, unlike plugixa-faq's regex autoloader, which
 * cannot resolve a brand name (`WordPressContentController` → `class-word-press-…`) and
 * therefore made that plugin's Composer classmap load-bearing in a way nobody expected.
 *
 * Two roots, premium first — but note that "premium first" is only about resolution
 * order. Nothing here makes a premium class appear: `pro/` is absent from the free ZIP,
 * so the second `file_exists` simply never finds one.
 */
spl_autoload_register( static function ( string $class_name ) : void {
    if ( !str_starts_with( $class_name, 'PlugixaExam\\' ) ) {
        return;
    }
    $relative = substr( $class_name, strlen( 'PlugixaExam\\' ) );
    // PlugixaExam\Pro\Modules\Monitoring\Module → pro/includes/Modules/Monitoring/Module.php
    if ( str_starts_with( $relative, 'Pro\\' ) ) {
        $file = PLUGIXA_EXAM_PATH . 'pro/includes/' . str_replace( '\\', '/', substr( $relative, 4 ) ) . '.php';
    } else {
        $file = PLUGIXA_EXAM_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    }
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );
// Composer: the Freemius SDK, and mPDF + chillerlan/php-qrcode for certificates. Absent on
// a fresh checkout, which is fine until a release build — the autoloader above already
// covers the plugin's own classes.
$plugixa_exam_autoload = PLUGIXA_EXAM_PATH . 'vendor/autoload.php';
if ( file_exists( $plugixa_exam_autoload ) ) {
    require_once $plugixa_exam_autoload;
}
// firebase/php-jwt, which only LTI 1.3 uses, lives in pro/vendor behind its own autoloader
// and is absent from the free build. mPDF and the QR encoder moved to the root vendor when
// certificates became free — the free build is no longer Freemius-only.
$plugixa_exam_pro_autoload = PLUGIXA_EXAM_PATH . 'pro/vendor/autoload.php';
if ( file_exists( $plugixa_exam_pro_autoload ) ) {
    require_once $plugixa_exam_pro_autoload;
}
require_once PLUGIXA_EXAM_PATH . 'includes/helpers.php';
/**
 * Freemius, when the SDK is installed.
 *
 * Guarded on the SDK file rather than assumed, so the plugin is usable from a checkout
 * with no vendor directory. Nothing in the plugin asks Freemius whether the user has a
 * licence — see the header docblock.
 *
 * THE DEPLOYMENT SECRET IS NOT NAMED IN THIS COMMENT ON PURPOSE. It appears exactly once
 * in this file, on its own line in the array below, because the release packager strips
 * that line from the free variant and then content-scans what is left — publishing a
 * deployment secret in plain text on the public WordPress.org SVN is unrecoverable, and
 * the scan cannot tell a mention in a comment from the real thing. It was this very
 * comment that first tripped it. Keep the line single-quoted and on one line: the strip
 * is a regular expression, and a value broken across lines would survive it.
 */
if ( !function_exists( 'plugixa_exam_fs' ) ) {
    /**
     * The Freemius SDK instance, or null when the SDK is not installed.
     */
    function plugixa_exam_fs() {
        global $plugixa_exam_fs;
        if ( isset( $plugixa_exam_fs ) ) {
            return $plugixa_exam_fs;
        }
        $sdk = PLUGIXA_EXAM_PATH . 'vendor/freemius/wordpress-sdk/start.php';
        if ( !file_exists( $sdk ) ) {
            return null;
        }
        require_once $sdk;
        $plugixa_exam_fs = fs_dynamic_init( array(
            'id'               => '37528',
            'slug'             => 'plugixa-exam',
            'type'             => 'plugin',
            'public_key'       => 'pk_be0238c6f90913e84c312e09c3f1a',
            'is_premium'       => false,
            'premium_suffix'   => 'Pro',
            'has_addons'       => false,
            'has_paid_plans'   => true,
            'is_org_compliant' => true,
            'menu'             => array(
                'slug'    => 'plugixa-exam',
                'support' => false,
            ),
            'is_live'          => true,
        ) );
        // No trial is offered, so suppress every Freemius trial promotion — the banner,
        // the menu item and the pricing CTA — leaving only "Upgrade".
        $plugixa_exam_fs->add_filter( 'show_trial', '__return_false' );
        return $plugixa_exam_fs;
    }

    if ( null !== plugixa_exam_fs() ) {
        do_action( 'plugixa_exam_fs_loaded' );
        /*
         * Uninstall runs through Freemius rather than a root uninstall.php: WordPress only
         * runs uninstall.php from the active plugin folder, which breaks under Freemius's
         * free/premium version switching, and Freemius rejects plugins that ship one.
         */
        plugixa_exam_fs()->add_action( 'after_uninstall', static function () : void {
            // Only when this is the last copy installed: see the function, at the top.
            plugixa_exam_uninstall_copy();
        } );
    }
}
register_activation_hook( __FILE__, static function () : void {
    \PlugixaExam\Core\Activator::activate();
} );
register_deactivation_hook( __FILE__, static function () : void {
    \PlugixaExam\Core\Deactivator::deactivate();
} );
add_action( 'plugins_loaded', static function () : void {
    \PlugixaExam\Core\Plugin::instance()->boot();
} );