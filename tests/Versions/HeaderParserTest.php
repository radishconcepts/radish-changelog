<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Versions;

use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Versions\Header_Parser;

final class HeaderParserTest extends TestCase {

	public function test_parses_gravity_forms_header(): void {
		$source = <<<'PHP'
<?php
/*
Plugin Name: Gravity Forms
Plugin URI: https://gravityforms.com
Description: Easily create web forms and manage form entries within the WordPress admin.
Version: 3.1.0.2
Requires at least: 6.5
Requires PHP: 7.4
Author: Gravity Forms
Author URI: https://gravityforms.com
License: GPL-2.0+
Text Domain: gravityforms
Domain Path: /languages
*/
PHP;

		$header = Header_Parser::parse( $source );

		self::assertSame( [
			'Name'    => 'Gravity Forms',
			'Version' => '3.1.0.2',
			'Author'  => 'Gravity Forms',
		], $header );
	}

	public function test_parses_kfm_header_with_tabs_after_author(): void {
		// Author line uses literal tabs before the value, as in the real
		// kfm.php header: "Author:\t\t    Radish Concepts".
		$source = "<?php\n\n/**\n"
			. " * Plugin Name:     KFM\n"
			. " * Author:\t\t    Radish Concepts\n"
			. " * Author URI:      https://radishconcepts.com\n"
			. " * Description:    The Kinderfonds MAMAS plugin\n"
			. " * Version:         1.0\n"
			. " * Text Domain:     kfm\n"
			. " * Domain Path:     /languages\n"
			. " */\n";

		$header = Header_Parser::parse( $source );

		self::assertSame( [
			'Name'    => 'KFM',
			'Version' => '1.0',
			'Author'  => 'Radish Concepts',
		], $header );
	}

	public function test_file_without_plugin_name_returns_null(): void {
		$source = <<<'PHP'
<?php
/**
 * A helper file with no plugin header at all.
 */

function helper_function() {
	return true;
}
PHP;

		self::assertNull( Header_Parser::parse( $source ) );
	}

	public function test_header_outside_first_8kb_is_ignored(): void {
		// Pad the source past the 8 KB boundary get_file_data() reads
		// before the header line appears.
		$padding = str_repeat( "// padding line to push the header out of range\n", 300 );
		self::assertGreaterThan( 8 * 1024, strlen( $padding ) );

		$source = "<?php\n" . $padding . "/**\n * Plugin Name: Too Late\n * Version: 9.9.9\n */\n";

		self::assertNull( Header_Parser::parse( $source ) );
	}
}
