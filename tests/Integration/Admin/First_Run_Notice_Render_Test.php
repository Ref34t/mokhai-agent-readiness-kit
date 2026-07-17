<?php
/**
 * Integration tests for Mokhai\Admin\First_Run_Notice — render matrix.
 *
 * Pins the show/hide contract (#251 / AgDR-0071): the notice renders only
 * for `manage_options` users on target screens while exposure is empty and
 * the user hasn't dismissed; it yields the moment content is exposed or the
 * user dismisses. Also pins the `mokhai_first_run_actions` filter — the
 * documented seam the 1.0 Mokhai Agent extends.
 *
 * The AJAX endpoints live in the sibling `First_Run_Notice_Test`.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Admin;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Admin\First_Run_Notice;

final class First_Run_Notice_Render_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		delete_option( Context_Profile_Settings::OPTION_KEY );
	}

	protected function tearDown(): void {
		delete_option( Context_Profile_Settings::OPTION_KEY );
		$GLOBALS['current_screen'] = null;
		remove_all_filters( 'mokhai_first_run_actions' );

		global $wpdb;
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => First_Run_Notice::USER_META_KEY ) );

		parent::tearDown();
	}

	/**
	 * Arrange an admin on the Dashboard screen — the baseline "should show"
	 * state every test starts from before varying one factor.
	 */
	private function arrange_admin_on_dashboard(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		return $user_id;
	}

	private function captured_render(): string {
		ob_start();
		First_Run_Notice::maybe_render();

		return (string) ob_get_clean();
	}

	/**
	 * Fresh install + admin + target screen → the notice shows, carries the
	 * primary expose CTA, the manual link, and the safe-by-default copy.
	 */
	public function test_renders_on_fresh_install_for_admin_on_dashboard(): void {
		$this->arrange_admin_on_dashboard();

		$html = $this->captured_render();

		$this->assertStringContainsString( 'mokhai-first-run-notice', $html );
		$this->assertStringContainsString( 'data-mokhai-first-run-expose', $html );
		$this->assertStringContainsString( 'data-mokhai-first-run-dismiss', $html );
		$this->assertStringContainsString( 'tools.php?page=mokhai-context', $html );
		$this->assertStringContainsString( 'safe default', $html );
	}

	/**
	 * Once ANY content is exposed — by this notice, Tools → Context, REST, or
	 * a future agent — the render condition yields for every user.
	 */
	public function test_hidden_once_content_is_exposed(): void {
		$this->arrange_admin_on_dashboard();

		Context_Profile_Settings::set_exposure( array( 'post' ), array( 'publish' ) );

		$this->assertSame( '', $this->captured_render() );
	}

	/**
	 * Per-user dismissal hides the notice for that admin only.
	 */
	public function test_hidden_after_dismissal_for_that_user_only(): void {
		$user_id = $this->arrange_admin_on_dashboard();

		update_user_meta( $user_id, First_Run_Notice::USER_META_KEY, '1' );
		$this->assertSame( '', $this->captured_render() );

		// A second admin still sees it.
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		$this->assertStringContainsString( 'mokhai-first-run-notice', $this->captured_render() );
	}

	/**
	 * Non-admin users never see the notice, regardless of state.
	 */
	public function test_hidden_for_non_admin_users(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		$this->assertSame( '', $this->captured_render() );
	}

	/**
	 * Off-target screens stay clean — the nudge lives on Dashboard, Plugins,
	 * and Tools → Context only.
	 */
	public function test_hidden_on_non_target_screen(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		$this->assertSame( '', $this->captured_render() );
	}

	/**
	 * The `mokhai_first_run_actions` filter (the 1.0 agent seam) — an added
	 * action renders as a link button; malformed entries are dropped.
	 */
	public function test_first_run_actions_filter_adds_and_validates_actions(): void {
		$this->arrange_admin_on_dashboard();

		add_filter(
			'mokhai_first_run_actions',
			static function ( array $actions ): array {
				$actions['agent']  = array(
					'label' => 'Set up with the Mokhai Agent',
					'url'   => 'https://example.test/wp-admin/admin.php?page=mokhai-agent-setup',
				);
				$actions['broken'] = array( 'label' => 'No URL here' );

				return $actions;
			}
		);

		$html = $this->captured_render();

		$this->assertStringContainsString( 'Set up with the Mokhai Agent', $html );
		$this->assertStringContainsString( 'mokhai-agent-setup', $html );
		$this->assertStringNotContainsString( 'No URL here', $html );

		// The default manual action survives alongside the filtered addition.
		$this->assertStringContainsString( 'tools.php?page=mokhai-context', $html );
	}
}
