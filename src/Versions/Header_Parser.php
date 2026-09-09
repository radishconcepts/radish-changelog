<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Versions;

defined( 'ABSPATH' ) || exit;

/**
 * Parses a WordPress plugin header from raw PHP source the same way core's
 * get_file_data() does (see wp-includes/functions.php): only the first
 * 8 KB is inspected, and each value is read with
 * `/^[ \t\/*#@]*<Label>:(.*)$/mi` then trimmed of a trailing comment or
 * closing PHP tag, and any surrounding whitespace.
 *
 * Pure PHP, no WordPress functions: git blob content is untrusted input,
 * so values are also length-bounded (trust boundary).
 */
final class Header_Parser {

	private const HEADER_BYTES = 8 * 1024;
	private const MAX_VALUE_LENGTH = 255;

	private const FIELDS = [
		'Name'    => 'Plugin Name',
		'Version' => 'Version',
		'Author'  => 'Author',
	];

	/**
	 * @return array{Name: string, Version: string, Author: string}|null Null when there is no "Plugin Name" header.
	 */
	public static function parse( string $php_source ): ?array {
		$data = substr( $php_source, 0, self::HEADER_BYTES );
		$data = str_replace( "\r", "\n", $data );

		$headers = [];

		foreach ( self::FIELDS as $key => $label ) {
			$headers[ $key ] = self::read_field( $data, $label );
		}

		if ( '' === $headers['Name'] ) {
			return null;
		}

		return $headers;
	}

	private static function read_field( string $data, string $label ): string {
		$pattern = '/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi';

		if ( 1 !== preg_match( $pattern, $data, $matches ) || '' === trim( $matches[1] ) ) {
			return '';
		}

		return self::clean( $matches[1] );
	}

	private static function clean( string $value ): string {
		$stripped = preg_replace( '/\s*(?:\*\/|\?>).*/', '', $value );
		$trimmed  = trim( $stripped ?? $value );

		return mb_substr( $trimmed, 0, self::MAX_VALUE_LENGTH );
	}

	private function __construct() {}
}
