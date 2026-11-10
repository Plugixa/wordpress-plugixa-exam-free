<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Repositories;

use PlugixaExam\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate designs.
 */
final class TemplateRepository extends Repository {

	public function table(): string {
		// The BARE name — `Repository::full_table()` applies the prefix.
		return 'certificate_templates';
	}

	public function entity_type(): string {
		return 'certificate_template';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'title', 'description', 'design_json', 'page_size', 'orientation',
			'background_image', 'valid_days', 'number_format', 'published',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'title', 'created_at' );
	}

	/**
	 * Give a site the certificate it has before anybody designs one.
	 *
	 * ONLY WHEN THE TABLE IS EMPTY, and that is the whole rule. `migrate()` runs on every
	 * version bump and every change to the set of installed modules, so a seed that asked
	 * "is MY design still there?" would put it back for an administrator who deleted it on
	 * purpose. Asking "are there any at all?" reseeds only a site that has none, which is a
	 * site awarding nothing.
	 *
	 * The deleted ones count. A design is trashed rather than purged — certificates issued
	 * from it stay explicable — and a site whose only design is in the trash chose that.
	 */
	public function seed(): void {
		global $wpdb;

		$table = $this->full_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counting rows in the plugin's own table during a migration, before any cache exists.
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		if ( $existing > 0 ) {
			return;
		}

		$this->insert( \PlugixaExam\Modules\Certificates\Services\DefaultDesign::row() );
	}

	/**
	 * The design an exam should use, falling back to the first published one.
	 *
	 * THE FALLBACK MATTERS MORE THAN IT LOOKS. An exam that awards certificates but names no
	 * template — because the template was deleted, or because the exam predates the module —
	 * would otherwise silently award nothing to somebody who passed. Falling back to any
	 * published design produces a certificate that may not be the intended one, which an
	 * administrator can see and fix; awarding none produces a support ticket from the
	 * examinee.
	 *
	 * @param int $template_id The exam's `certificate_template_id`, or 0.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve( int $template_id ): ?array {
		if ( $template_id > 0 ) {
			$row = $this->find( $template_id );

			if ( null !== $row && ! empty( $row['published'] ) ) {
				return $row;
			}
		}

		return $this->query()
			->where( 't.published = %d', array( 1 ) )
			->order_by( 't.id', 'ASC' )
			->paginate( 1 )
			->first();
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		if ( isset( $row['valid_days'] ) ) {
			$row['valid_days'] = (int) $row['valid_days'];
		}

		if ( isset( $row['published'] ) ) {
			$row['published'] = (bool) (int) $row['published'];
		}

		return $row;
	}
}
