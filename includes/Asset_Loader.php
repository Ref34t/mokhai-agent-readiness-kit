<?php
/**
 * Admin asset registration.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers admin scripts and styles.
 *
 * Scaffold-level stub. Real script and style enqueues land with the modules
 * that own them (Context Profile screen in #4, Context Score panel in #10).
 * This class exists so admin modules have a stable surface to register
 * against from day one.
 */
final class Asset_Loader {

	/**
	 * Singleton instance.
	 *
	 * @var Asset_Loader|null
	 */
	private static ?Asset_Loader $instance = null;

	public static function get_instance(): Asset_Loader {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}
}
