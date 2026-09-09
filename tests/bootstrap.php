<?php

declare( strict_types=1 );

// No WordPress bootstrap for these unit tests. Define ABSPATH to a dummy
// path so `defined( 'ABSPATH' ) || exit;` guards pass, and let Composer's
// PSR-4 autoloader (src/ + tests/) load the classes under test.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/radish-changelog-tests-abspath/' );
}

require __DIR__ . '/../vendor/autoload.php';
