<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Settings;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 *
 * OWNS NO STORAGE AND NO ROUTES, deliberately. The settings option and its REST endpoints
 * belong to Core, because Core reads settings during boot — before any module has been
 * discovered — and a module that Core depends on is not really a module.
 *
 * What this module is, then, is the SCREEN and its strings: the thing a person opens, which
 * the App Manager can list alongside everything else. It exists so that "every screen in the
 * admin comes from a module" stays true without exception, and so the settings UI has an
 * obvious home when it grows.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'settings';
	}

	public function app(): array {
		return array(
			'category' => 'core',
			'core'     => true,
		);
	}

	public function migrate(): void {
		// Settings live in an autoloaded option, not a table. See Core\Settings for why.
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		unset( $container );
	}

	public function register_hooks( Container $container ): void {
		unset( $container );

		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'settingsGeneral'      => __( 'General', 'plugixa-exam' ),

				/*
				 * THE PAGES TAB. A site owner hosts the exam area on pages of their own, and
				 * every link the plugin builds follows what they choose here.
				 */
				'settingsPages'            => __( 'Pages', 'plugixa-exam' ),
				'settingsPagesIntro'       => __( 'Put the exam area on pages of your own, so it sits in your site’s menu under your own names. Anything you leave unset keeps working at the plugin’s built-in address.', 'plugixa-exam' ),
				'settingsPagesSection'     => __( 'Where each page lives', 'plugixa-exam' ),
				'settingsPagesSectionHelp' => __( 'Every link the plugin builds (catalogue cards, the Start button, results emails) follows whatever you choose here.', 'plugixa-exam' ),

				'pagesCreate'        => __( 'Create the missing pages', 'plugixa-exam' ),
				'pagesCreating'      => __( 'Creating…', 'plugixa-exam' ),
				'pagesCreated'       => __( 'Pages created.', 'plugixa-exam' ),
				'pagesAllSet'        => __( 'All set', 'plugixa-exam' ),
				'pagesNotSet'        => __( 'Not set', 'plugixa-exam' ),
				'pagesUsesRoute'     => __( 'Not set. The plugin’s own address is used.', 'plugixa-exam' ),
				'pagesNoRoute'       => __( 'Not set. This one has no built-in address, so create a page for it.', 'plugixa-exam' ),
				'pagesDraftWarning'  => __( 'This page is not published, so the plugin is using its built-in address instead.', 'plugixa-exam' ),
				'pagesStandalone'    => __( 'On “{page}”. Nothing links here automatically. Add it to your menu.', 'plugixa-exam' ),
				'pagesView'          => __( 'View', 'plugixa-exam' ),
				'pagesEdit'          => __( 'Edit', 'plugixa-exam' ),

				'pagesMenuSection'     => __( 'Putting them in your menu', 'plugixa-exam' ),
				'pagesMenuSectionHelp' => __( 'Creating the pages is all this plugin can do on its own. Where they appear in your navigation is your theme’s business.', 'plugixa-exam' ),
				'pagesMenuBlockTheme'  => __( 'Your theme is a block theme, so it has no menu locations a plugin can fill. Add the pages to the Navigation block in the site editor.', 'plugixa-exam' ),
				'pagesMenuClassic'     => __( 'Your theme uses classic menus. Add the pages to a menu and assign it to a location.', 'plugixa-exam' ),
				'pagesOpenEditor'      => __( 'Open the site editor', 'plugixa-exam' ),
				'pagesOpenMenus'       => __( 'Open the menu editor', 'plugixa-exam' ),

				// Named, not slugged. See `PagesSettings.tsx` for why this is a switch.
				'pageViewDashboard'    => __( 'Dashboard', 'plugixa-exam' ),
				'pageViewCatalogue'    => __( 'Exam catalogue', 'plugixa-exam' ),
				'pageViewPortal'       => __( 'My exams', 'plugixa-exam' ),
				'pageViewResults'      => __( 'My results', 'plugixa-exam' ),
				'pageViewCertificates' => __( 'My certificates', 'plugixa-exam' ),

				'settingsIntro'        => __( 'How this site writes numbers, what colour its exams are, and which features are switched on.', 'plugixa-exam' ),

				'settingsFormatting'     => __( 'Number formatting', 'plugixa-exam' ),
				'settingsFormattingHelp' => __( 'How every mark, total and percentage is written: on these screens, in emails, on certificates and on the result page a candidate sees.', 'plugixa-exam' ),
				'decimalPlaces'          => __( 'Decimal places', 'plugixa-exam' ),
				'decimalPlacesHelp'      => __( 'Zero to six.', 'plugixa-exam' ),
				'decimalSeparator'       => __( 'Decimal separator', 'plugixa-exam' ),
				'decimalSeparatorHelp'   => __( 'Between the whole number and the rest.', 'plugixa-exam' ),
				'thousandsSeparator'     => __( 'Thousands separator', 'plugixa-exam' ),
				'thousandsSeparatorHelp' => __( 'Leave empty for no grouping.', 'plugixa-exam' ),
				'numberPreview'          => __( 'Preview', 'plugixa-exam' ),

				'settingsLogo'        => __( 'Exam logo', 'plugixa-exam' ),
				'settingsLogoHelp'    => __( "Shown in the exam's header band, beside its title.", 'plugixa-exam' ),
				'settingsLogoRemove'  => __( 'Remove logo', 'plugixa-exam' ),
				'settingsColours'     => __( 'Colours', 'plugixa-exam' ),

				/*
				 * ⚠️ THIS SENTENCE USED TO BE WRONG ABOUT THREE OF THE SEVEN COLOURS.
				 *
				 * It read "Used by both the admin theme and the exam pages examinees see, so
				 * the two match." — true of the four brand and marking colours, and false of
				 * `color_bg`, `color_text` and `color_primary_contrast`, which have never
				 * reached wp-admin and must not (see `theme/palette.ts`). Somebody who set a
				 * dark exam background was told the admin would follow and then watched
				 * nothing happen. Each group now states where its own colours land; this is
				 * only the summary above them.
				 */
				'settingsColoursHelp' => __( 'Three separate questions, not seven loose swatches: the brand, the page a candidate sits an exam on, and how right and wrong are shown.', 'plugixa-exam' ),

				'colourGroupBrand'       => __( 'Brand', 'plugixa-exam' ),
				'colourGroupBrandHelp'   => __( 'Used on both these admin screens and the exam pages candidates see. Primary and its contrast are a pair: the contrast colour is what prints on top of the primary one.', 'plugixa-exam' ),
				'colourGroupExam'        => __( 'Exam pages', 'plugixa-exam' ),
				'colourGroupExamHelp'    => __( 'The exam pages only. These do not change wp-admin, which WordPress and your admin colour scheme own.', 'plugixa-exam' ),
				'colourGroupMarking'     => __( 'Marking', 'plugixa-exam' ),
				'colourGroupMarkingHelp' => __( 'Right and wrong, wherever an answer is shown as one or the other: the review screen, the result page, the marking queue.', 'plugixa-exam' ),

				// The live preview panel. Sample content, so it is deliberately generic —
				// nothing here names a real exam, subject or candidate.
				'colourPreview'            => __( 'Preview', 'plugixa-exam' ),
				'colourPreviewExam'        => __( 'Sample exam', 'plugixa-exam' ),
				'colourPreviewProgress'    => __( '2 of 10', 'plugixa-exam' ),
				'colourPreviewQuestion'    => __( 'Which of these is correct?', 'plugixa-exam' ),
				'colourPreviewCorrect'     => __( 'The right answer', 'plugixa-exam' ),
				'colourPreviewIncorrect'   => __( 'The wrong answer', 'plugixa-exam' ),
				'colourPreviewUnanswered'  => __( 'One nobody chose', 'plugixa-exam' ),
				'colourPreviewSubmit'      => __( 'Submit', 'plugixa-exam' ),

				'color_primary'          => __( 'Primary', 'plugixa-exam' ),
				'color_primary_contrast' => __( 'Primary contrast', 'plugixa-exam' ),
				'color_accent'           => __( 'Accent', 'plugixa-exam' ),
				'color_bg'               => __( 'Background', 'plugixa-exam' ),
				'color_text'             => __( 'Text', 'plugixa-exam' ),
				'color_correct'          => __( 'Correct', 'plugixa-exam' ),
				'color_incorrect'        => __( 'Incorrect', 'plugixa-exam' ),

				'appsIntro'    => __( 'Switching a feature off hides it and stops its background work. Nothing is deleted. Turn it back on and your data is exactly as you left it.', 'plugixa-exam' ),
				'appsRejected' => __( 'These cannot be changed', 'plugixa-exam' ),
				'appCore'      => __( 'Always on', 'plugixa-exam' ),
				'settingsSaved' => __( 'Settings saved.', 'plugixa-exam' ),

				/*
				 * The App manager screen (`modules/app-manager/`), which was the Apps tab of this
				 * screen until it took Plugixa Inventory's card layout. Its per-app sentences are
				 * NOT here: each module contributes `'<id>Desc'` beside its own label, so a premium
				 * app's description lives in the premium bundle and nowhere else.
				 */
				'appManager'         => __( 'App manager', 'plugixa-exam' ),
				/* translators: The heading over the apps that cannot be switched off. The middle dot separates two phrases. */
				'appManagerCore'     => __( 'Core · always on', 'plugixa-exam' ),
				'appAll'             => __( 'All apps', 'plugixa-exam' ),
				'appActive'          => __( 'Active', 'plugixa-exam' ),
				'appInactive'        => __( 'Inactive', 'plugixa-exam' ),
				'appActivate'        => __( 'Activate', 'plugixa-exam' ),
				'appDeactivate'      => __( 'Deactivate', 'plugixa-exam' ),
				/* translators: A label followed by a number, e.g. "Questions that use it: 12". */
				'appUsedByQuestions' => __( 'Questions that use it', 'plugixa-exam' ),
				/* translators: {apps} is a comma-separated list of feature names. Keep the braces. */
				'appNeeds'           => __( 'Needs {apps} switched on first.', 'plugixa-exam' ),

				/*
				 * The App Manager group headings, which are `AppRegistry::CATEGORIES` (six since dark mode brought `admin`).
				 * They were printed as the raw slug under a CSS `capitalize` for six phases —
				 * the id is a machine value and had never been given a name a reader was meant
				 * to see. Resolved through a literal `switch` in `settings/appCategories.ts`
				 * for the reason `shell/navGroups.ts` spells out.
				 */
				'appCategoryCore'         => __( 'Core features', 'plugixa-exam' ),
				'appCategoryLearner'      => __( 'For learners', 'plugixa-exam' ),
				'appCategoryAuthoring'    => __( 'Authoring', 'plugixa-exam' ),
				'appCategoryInsight'      => __( 'Insight', 'plugixa-exam' ),
				'appCategoryIntegrations' => __( 'Integrations', 'plugixa-exam' ),
				'appCategoryAdmin'        => __( 'Administration', 'plugixa-exam' ),

				/*
				 * The switch-off warning for a format-owning app. An app contributes its
				 * question formats through a hook, so an app that is off contributes none — and
				 * an answer whose format nobody recognises is discarded and scored zero, in
				 * silence. This is the only toggle on the screen that can break finished work,
				 * so it is the only one that asks first.
				 */
				'appOffTitle'   => __( 'Switch this off?', 'plugixa-exam' ),
				/* translators: 1: number of questions, 2: number of published exams. */
				'appOffBody'    => __( '%1$s questions already use the formats this feature provides, and %2$s published exams ask them.', 'plugixa-exam' ),
				'appOffWarning' => __( 'While it is off those questions cannot be marked. Anybody who sits one of those exams scores zero on them, and that score is kept.', 'plugixa-exam' ),
				'appOffPools'   => __( 'Exams that draw questions at random from a pool may ask them too, and are not counted above.', 'plugixa-exam' ),
				'appOffConfirm' => __( 'Switch off anyway', 'plugixa-exam' ),

				// App labels for the App Manager list, keyed by module id.
				'dashboard'    => __( 'Dashboard', 'plugixa-exam' ),
				'attempts'     => __( 'Attempts', 'plugixa-exam' ),
				'exams'        => __( 'Exams', 'plugixa-exam' ),

				// Shared verbs.
				'actions'      => __( 'Actions', 'plugixa-exam' ),
				'assign'       => __( 'Assign', 'plugixa-exam' ),
				'wpRole'       => __( 'WordPress role', 'plugixa-exam' ),
				'back'         => __( 'Back', 'plugixa-exam' ),
				'deleted'      => __( 'Deleted', 'plugixa-exam' ),
				'duplicated'   => __( 'Duplicated', 'plugixa-exam' ),
				'draft'        => __( 'Draft', 'plugixa-exam' ),
				'published'    => __( 'Published', 'plugixa-exam' ),
				'version'      => __( 'Version', 'plugixa-exam' ),
				'reorder'      => __( 'Reorder', 'plugixa-exam' ),
				'createTag'    => __( 'Create', 'plugixa-exam' ),
			)
		);
	}
}
