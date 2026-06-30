<?php
/**
 * PHPStan bootstrap.
 *
 * Defines the plugin-global constants that `mokhai-agent-readiness-kit.php::constants()`
 * defines at runtime. PHPStan does static analysis — it never calls the
 * `constants()` function — so without this file references like
 * \MOKHAI_FILE / \MOKHAI_VERSION analyse as undefined constants.
 *
 * Loaded by phpstan.neon.dist via `parameters.bootstrapFiles`.
 *
 * @package Mokhai
 */

declare(strict_types=1);

if ( ! defined( 'MOKHAI_VERSION' ) ) {
	define( 'MOKHAI_VERSION', '0.1.0' );
}
if ( ! defined( 'MOKHAI_FILE' ) ) {
	define( 'MOKHAI_FILE', __DIR__ . '/../mokhai-agent-readiness-kit.php' );
}
if ( ! defined( 'MOKHAI_DIR' ) ) {
	define( 'MOKHAI_DIR', __DIR__ . '/../' );
}
if ( ! defined( 'MOKHAI_URL' ) ) {
	define( 'MOKHAI_URL', 'https://example.test/wp-content/plugins/mokhai-agent-readiness-kit/' );
}
if ( ! defined( 'MOKHAI_REQUIRES_PHP' ) ) {
	define( 'MOKHAI_REQUIRES_PHP', '7.4' );
}
if ( ! defined( 'MOKHAI_REQUIRES_WP' ) ) {
	define( 'MOKHAI_REQUIRES_WP', '6.9' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
