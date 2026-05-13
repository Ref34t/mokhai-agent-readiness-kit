<?php
/**
 * Dev-tool: verify PSR-4 autoload resolves every WPContext class.
 *
 * Every includes/*.php opens with `\defined( 'ABSPATH' ) || exit;` for direct-
 * access protection. Outside WordPress, that exits the script silently the
 * moment the autoloader loads the first class — making the tool look like
 * it "passed" with empty output. Fix per #15: define a stub ABSPATH first.
 *
 * @package WPContext
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// Dev-context shim — see file doc-block + ticket #15.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require __DIR__ . '/../vendor/autoload.php';

// helpers.php is loaded by agentready.php (post-version-gate) at runtime, not
// via Composer autoload — load it manually here for the dev-tool check.
require_once __DIR__ . '/../includes/Ai/helpers.php';

$classes = array(
	'WPContext\\Main',
	'WPContext\\Requirements',
	'WPContext\\Asset_Loader',
	'WPContext\\Ai\\Client_Wrapper',
	'WPContext\\Ai\\Result',
	'WPContext\\Ai\\Provider',
	'WPContext\\Ai\\Network_Error',
	'WPContext\\Ai\\Rate_Limit_Error',
);

$exit = 0;
foreach ( $classes as $class ) {
	$ok = class_exists( $class, true ) || interface_exists( $class, true );
	fwrite( STDOUT, sprintf( "%-40s %s\n", $class, $ok ? 'ok' : 'FAIL' ) );
	if ( ! $ok ) {
		$exit = 1;
	}
}

// Also verify the global helper from includes/Ai/helpers.php is loaded.
$helper_ok = function_exists( 'agentready_has_ai_client' );
fwrite( STDOUT, sprintf( "%-40s %s\n", 'agentready_has_ai_client()', $helper_ok ? 'ok' : 'FAIL' ) );
if ( ! $helper_ok ) {
	$exit = 1;
}

exit( $exit );
