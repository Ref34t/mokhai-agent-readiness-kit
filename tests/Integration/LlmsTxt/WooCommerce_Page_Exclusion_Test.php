<?php
/**
 * Integration tests for WooCommerce functional-page exclusion (#243).
 *
 * Cart / Checkout / My-account are session/transactional surfaces with no
 * agent value. They are detected via WooCommerce's own page-ID options
 * (`woocommerce_*_page_id`) — never by title — and denied with reason
 * `excluded`. The Shop archive is deliberately NOT excluded. The behaviour is
 * overridable through the `agentready_woocommerce_excluded_page_options`
 * filter.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\LlmsTxt\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class WooCommerce_Page_Exclusion_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		Markdown_Views_Schema::create();
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'page' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
	}

	protected function tearDown(): void {
		foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_shop_page_id' ) as $opt ) {
			delete_option( $opt );
		}
		remove_all_filters( 'agentready_woocommerce_excluded_page_options' );
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	private function published_page(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Some Page',
			)
		);
	}

	public function test_cart_page_is_excluded(): void {
		$page_id = $this->published_page();
		update_option( 'woocommerce_cart_page_id', $page_id );

		$post = get_post( $page_id );
		self::assertSame( 'excluded', Context_Profile_Settings::get_exposure_reason( $post ) );
		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_checkout_and_myaccount_pages_are_excluded(): void {
		$checkout = $this->published_page();
		$account  = $this->published_page();
		update_option( 'woocommerce_checkout_page_id', $checkout );
		update_option( 'woocommerce_myaccount_page_id', $account );

		self::assertFalse( Context_Profile_Settings::is_url_exposable( get_post( $checkout ) ) );
		self::assertFalse( Context_Profile_Settings::is_url_exposable( get_post( $account ) ) );
	}

	public function test_shop_page_is_not_excluded_by_default(): void {
		$shop_id = $this->published_page();
		update_option( 'woocommerce_shop_page_id', $shop_id );

		// Shop is product-listing content — not on the default exclusion list.
		self::assertTrue( Context_Profile_Settings::is_url_exposable( get_post( $shop_id ) ) );
	}

	public function test_ordinary_page_stays_exposable(): void {
		$page_id = $this->published_page();
		update_option( 'woocommerce_cart_page_id', $page_id + 999 );

		self::assertTrue( Context_Profile_Settings::is_url_exposable( get_post( $page_id ) ) );
	}

	public function test_filter_can_disable_woocommerce_exclusion(): void {
		$page_id = $this->published_page();
		update_option( 'woocommerce_cart_page_id', $page_id );

		add_filter( 'agentready_woocommerce_excluded_page_options', '__return_empty_array' );

		// With the option list emptied, the cart page is exposable again.
		self::assertTrue( Context_Profile_Settings::is_url_exposable( get_post( $page_id ) ) );
	}
}
