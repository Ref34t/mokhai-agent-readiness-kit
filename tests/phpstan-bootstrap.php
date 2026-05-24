<?php
/**
 * PHPStan bootstrap.
 *
 * Defines the plugin-global constants that `ai-readiness-kit.php::constants()`
 * defines at runtime. PHPStan does static analysis — it never calls the
 * `constants()` function — so without this file references like
 * \WPCTX_FILE / \WPCTX_VERSION analyse as undefined constants.
 *
 * Loaded by phpstan.neon.dist via `parameters.bootstrapFiles`.
 *
 * @package WPContext
 */

declare(strict_types=1);

if ( ! defined( 'WPCTX_VERSION' ) ) {
	define( 'WPCTX_VERSION', '0.1.0' );
}
if ( ! defined( 'WPCTX_FILE' ) ) {
	define( 'WPCTX_FILE', __DIR__ . '/../ai-readiness-kit.php' );
}
if ( ! defined( 'WPCTX_DIR' ) ) {
	define( 'WPCTX_DIR', __DIR__ . '/../' );
}
if ( ! defined( 'WPCTX_URL' ) ) {
	define( 'WPCTX_URL', 'https://example.test/wp-content/plugins/ai-readiness-kit/' );
}
if ( ! defined( 'WPCTX_REQUIRES_PHP' ) ) {
	define( 'WPCTX_REQUIRES_PHP', '7.4' );
}
if ( ! defined( 'WPCTX_REQUIRES_WP' ) ) {
	define( 'WPCTX_REQUIRES_WP', '6.9' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
