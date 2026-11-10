<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImportExport\Services\Parsers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe XML loading for the import parsers.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE ONLY PLACE THIS PLUGIN PARSES XML, SO IT IS THE ONLY PLACE XXE CAN HAPPEN.
 *
 * An uploaded question bank is a file from outside. XML that declares an external entity —
 *
 *     <!DOCTYPE q [ <!ENTITY x SYSTEM "file:///etc/passwd"> ]>
 *     <question><stem>&x;</stem></question>
 *
 * — becomes, if the parser resolves it, a question whose text is the contents of a server
 * file, sitting in the question bank for anyone with `questions.view` to read. The same trick
 * against an `http://` URL turns the site into a request proxy for an internal network.
 *
 * Three defences, because each covers a case the others do not:
 *
 *   1. `LIBXML_NONET` — no network fetches, so a remote entity cannot resolve at all.
 *   2. `LIBXML_NOENT` is NOT passed. Despite the name it ENABLES substitution; omitting it
 *      leaves entity references unexpanded.
 *   3. A DOCTYPE is refused outright before parsing. Neither Moodle XML nor QTI needs one,
 *      so rejecting it costs nothing and closes billion-laughs expansion as well.
 *
 * `libxml_use_internal_errors` keeps a malformed upload from emitting warnings into the REST
 * response, which would corrupt the JSON body.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Xml {

	/**
	 * Load a document, or return null with a reason.
	 *
	 * @param string      $content The file's contents.
	 * @param string|null $problem Set to why it failed.
	 */
	public static function load( string $content, ?string &$problem = null ): ?\SimpleXMLElement {
		$problem = null;

		if ( preg_match( '/<!DOCTYPE/i', $content ) ) {
			$problem = __( 'The file declares a DOCTYPE, which is not accepted in an upload.', 'plugixa-exam' );

			return null;
		}

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		// No LIBXML_NOENT. See the class docblock — the flag's name is the opposite of what
		// it does, and passing it is precisely the bug this class exists to prevent.
		$xml = simplexml_load_string( $content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );

		$errors = libxml_get_errors();

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			$first = $errors[0] ?? null;

			$problem = null === $first
				? __( 'The file is not valid XML.', 'plugixa-exam' )
				: sprintf(
					/* translators: 1: line number, 2: parser message. */
					__( 'Line %1$d: %2$s', 'plugixa-exam' ),
					(int) $first->line,
					trim( (string) $first->message )
				);

			return null;
		}

		return $xml;
	}

	/**
	 * The text of a node, with Moodle's `<text>` wrapper unwrapped.
	 *
	 * Both Moodle XML and QTI put content one level deeper than feels natural, and reading
	 * the wrapper rather than its child yields an empty string with no error.
	 *
	 * @param \SimpleXMLElement|null $node The node.
	 */
	public static function text( ?\SimpleXMLElement $node ): string {
		if ( null === $node ) {
			return '';
		}

		if ( isset( $node->text ) ) {
			return trim( (string) $node->text );
		}

		return trim( (string) $node );
	}
}
