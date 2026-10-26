<?php
/**
 * Unit test bootstrap: Composer autoloader plus the few WordPress constants the classes use.
 * WordPress functions are stubbed per test with Brain Monkey.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', sys_get_temp_dir() . '/cdnhub-wp/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'MINUTE_IN_SECONDS', 60 );
