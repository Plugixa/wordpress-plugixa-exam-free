<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Database;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A table this plugin asked for is not there.
 *
 * Thrown by {@see Schema::delta()}, which is the only thing in the plugin that knows:
 * `dbDelta()` reports nothing when a CREATE TABLE is refused, so without this a database
 * user who may not create tables produces a migration that "succeeds".
 *
 * CARRIES FACTS, NOT A SENTENCE. A migration can run on `plugins_loaded`, before WordPress
 * will load a translation, so nothing here may call `__()`. The table names and whatever the
 * database said are kept as they are and put into words where they are shown, by which time
 * the reader's language is known. The message below is for a log, in English.
 */
final class MigrationFailed extends RuntimeException {

	/**
	 * @param string[] $tables Full names of the tables that could not be created.
	 * @param string   $reason What the database said, or an empty string if it said nothing.
	 */
	public function __construct( private readonly array $tables, private readonly string $reason ) {
		parent::__construct(
			'Could not create ' . implode( ', ', $tables ) . ( '' === $reason ? '.' : ': ' . $reason )
		);
	}

	/**
	 * Full names of the tables that are missing.
	 *
	 * @return string[]
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * What the database said about it.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
