<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImportExport;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\ImportExport\Http\ImportExportController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import and export of question banks.
 *
 * No tables of its own — it reads files into the Questions module and writes them back out.
 * Switchable off, which removes the screens and the routes; nothing is stored, so there is
 * nothing to lose by doing so.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'importexport';
	}

	public function app(): array {
		return array(
			'category' => 'authoring',
			'setting'  => 'app_importexport',
			'default'  => true,
			// Reading a file only matters if there is a bank to read it into.
			'deps'     => array( 'questions' ),
		);
	}

	public function migrate(): void {
		// Nothing to migrate: this module owns no tables.
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		$container->get( ImportExportController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		unset( $container );

		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge( $defaults, array( 'app_importexport' => true ) );
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
				// The App Manager labels this switch with `t( id, id )`; see `ModuleInterface::id()`.
				'importexport' => __( 'Import & export', 'plugixa-exam' ),
				'importexportDesc' => __( 'Bring question banks in from a file, and take them back out.', 'plugixa-exam' ),
				'importExport'       => __( 'Import & export', 'plugixa-exam' ),
				'fileUnreadable'     => __( 'The file could not be read.', 'plugixa-exam' ),
				'importExportIntro'  => __( 'Bring a question bank in from another system, or take yours out.', 'plugixa-exam' ),
				'import'             => __( 'Import', 'plugixa-exam' ),
				'export'             => __( 'Export', 'plugixa-exam' ),
				'chooseFile'         => __( 'Choose a file', 'plugixa-exam' ),
				'dropFileHere'       => __( 'Drop a file here, or choose one.', 'plugixa-exam' ),
				'detectedFormat'     => __( 'Detected format', 'plugixa-exam' ),
				'formatOverride'     => __( 'Read it as', 'plugixa-exam' ),
				'formatAuto'         => __( 'Detect automatically', 'plugixa-exam' ),
				'importPreview'      => __( 'What the file contains', 'plugixa-exam' ),
				'importProblems'     => __( 'Things to look at', 'plugixa-exam' ),
				'importRun'          => __( 'Import these questions', 'plugixa-exam' ),
				/* translators: %d: how many questions were imported. */
				'importDone'         => __( 'Imported %d questions', 'plugixa-exam' ),
				'importedIntoSubject' => __( 'Put them in', 'plugixa-exam' ),
				'importAsDraft'      => __( 'Import as drafts, so nobody can sit them yet', 'plugixa-exam' ),
				/* translators: %d: how many questions the file contains. */
				'nQuestionsFound'    => __( '%d questions found', 'plugixa-exam' ),
				/* translators: %d: how many of them are previewed. */
				'showingFirstN'      => __( 'Showing the first %d.', 'plugixa-exam' ),
				'exportFormat'       => __( 'Format', 'plugixa-exam' ),
				'exportSubject'      => __( 'Which questions', 'plugixa-exam' ),
				'exportEverything'   => __( 'The whole bank', 'plugixa-exam' ),
				'exportDownload'     => __( 'Download', 'plugixa-exam' ),
				'exportCsvNote'      => __( 'CSV keeps everything, so a file exported here can be edited in a spreadsheet and imported back without losing answer keys.', 'plugixa-exam' ),
				'nothingToExport'    => __( 'There are no questions to export.', 'plugixa-exam' ),
			)
		);
	}
}
