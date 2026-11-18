<?php
/**
 * Links tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use SugarCommerce\Plugin\Plugin;

/**
 * A loaded plugin's links on the plugins screen.
 */
final class LinksTest extends PluginTestCase {

	/**
	 * Register and load a plugin with links.
	 *
	 * @param array<string, mixed> $config Its links.
	 *
	 * @return void
	 */
	private function load( array $config ): void {
		Plugin::register( $this->plugin_file( 'linked' ), static function () {}, $config );
		pl_fire( 'plugins_loaded' );
	}

	public function test_action_links_go_first(): void {
		$this->load(
			[
				'plugin_links' => [
					'settings' => 'https://example.test/settings',
					'docs'     => [
						'url'    => 'https://example.test/docs',
						'text'   => static fn() => 'Documentation',
						'target' => '_blank',
						'style'  => 'color:red',
					],
				],
			]
		);

		$links = apply_filters( 'plugin_action_links_linked/linked.php', [ 'deactivate' => 'Deactivate' ] );

		$this->assertSame( [ 'settings', 'docs', 'deactivate' ], array_keys( $links ) );
		$this->assertSame( '<a href="https://example.test/settings">Settings</a>', $links['settings'] );
		$this->assertSame( '<a href="https://example.test/docs" target="_blank" rel="noopener noreferrer" style="color:red">Documentation</a>', $links['docs'] );
	}

	public function test_a_link_the_user_may_not_use_is_left_out(): void {
		$this->load(
			[
				'plugin_links' => [
					'settings' => [
						'url'        => 'https://example.test/settings',
						'capability' => 'manage_shop_settings',
					],
					'broken'   => [ 'text' => 'No URL' ],
				],
			]
		);

		$this->assertSame( [], apply_filters( 'plugin_action_links_linked/linked.php', [] ) );

		$GLOBALS['pl']['caps']['manage_shop_settings'] = true;

		$this->assertSame( [ 'settings' ], array_keys( apply_filters( 'plugin_action_links_linked/linked.php', [] ) ) );
	}

	public function test_meta_links_go_after_and_open_elsewhere(): void {
		$this->load( [ 'plugin_meta' => [ 'support' => 'https://example.test/support' ] ] );

		$meta = apply_filters( 'plugin_row_meta', [ 'Version 1.0' ], 'linked/linked.php' );

		$this->assertSame(
			[ 'Version 1.0', '<a href="https://example.test/support" target="_blank" rel="noopener noreferrer">Support</a>' ],
			$meta
		);
	}

	public function test_meta_links_are_only_on_the_plugins_own_row(): void {
		$this->load( [ 'plugin_meta' => [ 'support' => 'https://example.test/support' ] ] );

		$this->assertSame( [ 'Version 1.0' ], apply_filters( 'plugin_row_meta', [ 'Version 1.0' ], 'other/other.php' ) );
	}

	public function test_text_is_escaped(): void {
		$this->load( [ 'plugin_links' => [ 'x' => [ 'url' => 'https://example.test', 'text' => '<b>Bold</b>' ] ] ] );

		$this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt;', apply_filters( 'plugin_action_links_linked/linked.php', [] )['x'] );
	}
}
