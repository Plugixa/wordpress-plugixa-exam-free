<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Exceptions;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is registered under this id and it is not a class.
 */
final class NotFoundException extends RuntimeException {}
