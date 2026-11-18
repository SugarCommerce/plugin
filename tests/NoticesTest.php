<?php
/**
 * Notices tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use SugarCommerce\Plugin\Notices;
use SugarCommerce\Plugin\Plugin;
use WP_Die_Exception;

/**
 * A loaded plugin's notices, and dismissing them.
 */
final class NoticesTest extends PluginTestCase {

	/**
	 * Register and load a plugin with notices.
	 *
	 * @param array<string, array<string, mixed>> $notices Its notices.
	 * @param string                              $folder  Its folder.
	 *
	 * @return Plugin
	 */
	private function load( array $notices, string $folder = 'noticed' ): Plugin {
		$plugin = Plugin::register( $this->plugin_file( $folder ), static function () {}, [ 'notices' => $notices ] );
		pl_fire( 'plugins_loaded' );

		return $plugin;
	}

	/**
	 * The notices printed now.
	 *
	 * @return string
	 */
	private function printed(): string {
		return pl_output( static fn() => do_action( 'admin_notices' ) );
	}

	/**
	 * Post a dismissal and return what wp_die() was given.
	 *
	 * @param string $action    The action posted to.
	 * @param string $notice_id The notice.
	 *
	 * @return WP_Die_Exception
	 */
	private function dismiss( string $action, string $notice_id ): WP_Die_Exception {
		$_POST['notice_id'] = $notice_id;

		try {
			do_action( 'wp_ajax_' . $action );
		} catch ( WP_Die_Exception $e ) {
			return $e;
		}

		$this->fail( 'The handler did not stop.' );
	}

	public function test_a_notice_is_printed(): void {
		$this->load(
			[
				'welcome' => [
					'type'    => 'success',
					'message' => static fn() => '<strong>Welcome</strong> <script>x</script>',
				],
			]
		);

		$this->assertSame( '<div class="notice notice-success" data-notice-id="welcome"><p><strong>Welcome</strong> </p></div>', $this->printed() );
	}

	public function test_a_capability_hides_it(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'capability' => 'manage_shop_settings' ] ] );

		$this->assertSame( '', $this->printed() );
	}

	/**
	 * Screen ids are hyphenated, and either spelling matches.
	 */
	public function test_pages_limit_where_it_shows(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'pages' => [ 'plugins', 'edit_download' ] ] ] );

		$GLOBALS['pl']['screen'] = (object) [ 'id' => 'dashboard' ];
		$this->assertSame( '', $this->printed() );

		$GLOBALS['pl']['screen'] = (object) [ 'id' => 'edit-download' ];
		$this->assertStringContainsString( 'Hi', $this->printed() );
	}

	public function test_show_once_shows_once(): void {
		$plugin = $this->load( [ 'welcome' => [ 'message' => 'Hi', 'show_once' => true ] ] );

		$this->assertStringContainsString( 'Hi', $this->printed() );
		$this->assertSame( '', $this->printed() );
		$this->assertTrue( $GLOBALS['pl']['options'][ ( new Notices( $plugin ) )->shown_key( 'welcome' ) ] );
	}

	public function test_show_until_an_option_is_set_or_a_callable_says(): void {
		$this->load(
			[
				'setup' => [ 'message' => 'Set up', 'show_until' => 'option_not_empty:noticed_key' ],
				'other' => [ 'message' => 'Other', 'show_until' => static fn() => empty( $GLOBALS['pl']['options']['other_done'] ) ],
			]
		);

		$this->assertStringContainsString( 'Set up', $this->printed() );
		$this->assertStringContainsString( 'Other', $this->printed() );

		$GLOBALS['pl']['options']['noticed_key'] = 'abc';
		$GLOBALS['pl']['options']['other_done']  = true;

		$this->assertSame( '', $this->printed() );
	}

	public function test_a_dismissible_notice_carries_its_action_and_the_script(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ] ] );

		$output = $this->printed();

		$this->assertStringContainsString( 'class="notice notice-info is-dismissible"', $output );
		$this->assertStringContainsString( 'data-sugarcommerce-dismiss="noticed_dismiss_notice" data-nonce="nonce-noticed_dismiss_notice"', $output );
		$this->assertStringContainsString( 'window.sugarcommerceNoticeDismiss', $output );
	}

	public function test_dismissing_is_remembered_for_the_user(): void {
		$plugin = $this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ] ] );

		$this->assertSame( 1, $this->dismiss( 'noticed_dismiss_notice', 'welcome' )->value );
		$this->assertTrue( $GLOBALS['pl']['user_meta'][1][ ( new Notices( $plugin ) )->dismissed_key( 'welcome' ) ] );
		$this->assertSame( '', $this->printed() );
	}

	/**
	 * Two plugins' `welcome` notices are two notices.
	 *
	 * The old keys were global -- `wp_register_plugin_dismissed_welcome` --
	 * so six EDD extensions that each said welcome shared one dismissal and
	 * one show-once flag, and only the first ever showed.
	 */
	public function test_two_plugins_do_not_share_a_notice(): void {
		$this->load( [ 'welcome' => [ 'message' => 'First', 'dismissible' => true, 'show_once' => true ] ], 'first-plugin' );
		$this->load( [ 'welcome' => [ 'message' => 'Second', 'dismissible' => true, 'show_once' => true ] ], 'second-plugin' );

		$output = $this->printed();

		$this->assertStringContainsString( 'First', $output );
		$this->assertStringContainsString( 'Second', $output );
		$this->assertArrayHasKey( 'first_plugin_notice_shown_welcome', $GLOBALS['pl']['options'] );
		$this->assertArrayHasKey( 'second_plugin_notice_shown_welcome', $GLOBALS['pl']['options'] );
	}

	public function test_dismissing_one_plugins_notice_leaves_anothers(): void {
		$this->load( [ 'welcome' => [ 'message' => 'First', 'dismissible' => true ] ], 'first-plugin' );
		$this->load( [ 'welcome' => [ 'message' => 'Second', 'dismissible' => true ] ], 'second-plugin' );

		$this->dismiss( 'first_plugin_dismiss_notice', 'welcome' );

		$output = $this->printed();

		$this->assertStringNotContainsString( 'First', $output );
		$this->assertStringContainsString( 'Second', $output );
	}

	public function test_a_bad_nonce_is_refused(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ] ] );

		$GLOBALS['pl']['nonce_ok'] = false;

		$this->assertSame( 403, $this->dismiss( 'noticed_dismiss_notice', 'welcome' )->status );
		$this->assertSame( [], $GLOBALS['pl']['user_meta'] );
	}

	public function test_only_its_own_dismissible_notices_can_be_dismissed(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ], 'fixed' => [ 'message' => 'Stays' ] ] );

		$this->assertSame( 400, $this->dismiss( 'noticed_dismiss_notice', 'somebody_elses' )->status );
		$this->assertSame( 400, $this->dismiss( 'noticed_dismiss_notice', 'fixed' )->status );
		$this->assertSame( [], $GLOBALS['pl']['user_meta'] );
	}

	/**
	 * The capability asked is the notice's: a shop manager shown a notice can
	 * dismiss it. The old handler asked for manage_options whatever the
	 * notice said, and answered every shop manager with a 403.
	 */
	public function test_the_capability_asked_is_the_notices(): void {
		$GLOBALS['pl']['caps'] = [ 'manage_shop_settings' => true ];

		$this->load(
			[
				'shop'  => [ 'message' => 'Shop', 'dismissible' => true, 'capability' => 'manage_shop_settings' ],
				'admin' => [ 'message' => 'Admin', 'dismissible' => true ],
			]
		);

		$this->assertSame( 1, $this->dismiss( 'noticed_dismiss_notice', 'shop' )->value );
		$this->assertSame( 403, $this->dismiss( 'noticed_dismiss_notice', 'admin' )->status );
	}
}
