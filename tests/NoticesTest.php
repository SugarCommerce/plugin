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
use WP_Error;
use WP_REST_Request;

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
	 * POST a dismissal to the plugin's route, as the REST server would:
	 * the permission check, then the callback.
	 *
	 * @param string $plugin_id The plugin's id.
	 * @param string $notice_id The notice.
	 *
	 * @return int The status the server would answer with.
	 */
	private function dismiss( string $plugin_id, string $notice_id ): int {
		pl_fire( 'rest_api_init' );

		$route = $GLOBALS['pl']['routes'][ '/' . Notices::rest_namespace() . '/' . $plugin_id . '/notices/(?P<notice>[a-z0-9_-]+)/dismissal' ] ?? null;

		$this->assertNotNull( $route, 'no dismissal route for ' . $plugin_id );
		$this->assertSame( 'POST', $route['methods'] );

		$request = new WP_REST_Request();
		$request->set_param( 'notice', $notice_id );

		$allowed = call_user_func( $route['permission_callback'], $request );

		if ( $allowed instanceof WP_Error ) {
			return (int) $allowed->get_error_data()['status'];
		}

		$this->assertTrue( $allowed );
		$this->assertSame( [ 'dismissed' => true, 'notice' => $notice_id ], call_user_func( $route['callback'], $request ) );

		return 200;
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

	/**
	 * The notice is core's: wp_admin_notice() prints it, so another plugin's
	 * wp_admin_notice_args filter reaches it like any core notice.
	 */
	public function test_a_notice_goes_through_core(): void {
		$this->load( [ 'welcome' => [ 'type' => 'warning', 'message' => 'Hi' ] ] );

		add_filter(
			'wp_admin_notice_args',
			static function ( $args ) {
				$args['additional_classes'] = [ 'inline' ];

				return $args;
			}
		);

		$this->assertSame( '<div class="notice notice-warning inline" data-notice-id="welcome"><p>Hi</p></div>', $this->printed() );
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

	public function test_a_dismissible_notice_carries_its_route_and_the_script(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ] ] );

		$output = $this->printed();

		$this->assertStringContainsString( 'class="notice notice-info is-dismissible"', $output );
		$this->assertStringContainsString( 'data-sugarcommerce-dismiss="/sugarcommerce-plugin/v1/noticed/notices/welcome/dismissal"', $output );
		$this->assertStringContainsString( 'window.wp.apiFetch', $output );

		// Its listener is this build's, answering this build's notices only.
		$this->assertStringContainsString( '}( "sugarcommerce-plugin\\/v1" ) );', $output );
		$this->assertStringContainsString( 'window.SugarCommerceNotices', $output );
		$this->assertStringContainsString( "0 !== path.indexOf( '/' + build + '/' )", $output );
		$this->assertStringNotContainsString( 'sugarcommerceNoticeDismiss', $output );
		$this->assertStringNotContainsString( 'admin-ajax', $output );
		$this->assertContains( 'script:wp-api-fetch', $GLOBALS['pl']['enqueued'] );
	}

	/**
	 * Through REST, not admin-ajax: nothing listens there any more.
	 */
	public function test_there_is_no_ajax_action(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ] ] );

		$this->assertFalse( has_action( 'wp_ajax_noticed_dismiss_notice' ) );
		$this->assertTrue( (bool) has_action( 'rest_api_init' ) );
	}

	public function test_dismissing_is_remembered_for_the_user(): void {
		$plugin = $this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ] ] );

		$this->assertSame( 200, $this->dismiss( 'noticed', 'welcome' ) );
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

		$this->dismiss( 'first_plugin', 'welcome' );

		$output = $this->printed();

		$this->assertStringNotContainsString( 'First', $output );
		$this->assertStringContainsString( 'Second', $output );
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ] ] );

		$GLOBALS['pl']['caps'] = [];

		$this->assertSame( 403, $this->dismiss( 'noticed', 'welcome' ) );
		$this->assertSame( [], $GLOBALS['pl']['user_meta'] );
	}

	public function test_only_its_own_dismissible_notices_can_be_dismissed(): void {
		$this->load( [ 'welcome' => [ 'message' => 'Hi', 'dismissible' => true ], 'fixed' => [ 'message' => 'Stays' ] ] );

		$this->assertSame( 404, $this->dismiss( 'noticed', 'somebody_elses' ) );
		$this->assertSame( 404, $this->dismiss( 'noticed', 'fixed' ) );
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

		$this->assertSame( 200, $this->dismiss( 'noticed', 'shop' ) );
		$this->assertSame( 403, $this->dismiss( 'noticed', 'admin' ) );
	}
}
