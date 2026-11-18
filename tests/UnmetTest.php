<?php
/**
 * Unmet tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use SugarCommerce\Plugin\Plugin;
use SugarCommerce\Plugin\Unmet;

/**
 * What the plugins screen says about a plugin that did not load.
 */
final class UnmetTest extends PluginTestCase {

	/**
	 * A plugin that cannot load.
	 *
	 * @param array<string, mixed> $config More configuration.
	 *
	 * @return Plugin
	 */
	private function unmet( array $config = [] ): Plugin {
		$plugin = Plugin::register( $this->plugin_file( 'blocked' ), static function () {}, $config + [ 'requirements' => [ 'php' => '99.0' ] ] );

		pl_fire( 'plugins_loaded' );

		return $plugin;
	}

	public function test_the_row_lists_what_failed(): void {
		$this->unmet( [ 'requirements' => [ 'php' => '99.0', 'wp' => '99.0' ] ] );

		$row = pl_output( static fn() => do_action( 'after_plugin_row_blocked/blocked.php' ) );

		$this->assertStringContainsString( '<tr class="active sugarcommerce-blocked-row">', $row );
		$this->assertStringContainsString( 'This plugin is not fully active.', $row );
		$this->assertStringContainsString( '<p><strong>PHP</strong>: minimum required <strong>99.0</strong>', $row );
		$this->assertStringContainsString( '<p><strong>WordPress</strong>: minimum required <strong>99.0</strong>', $row );
		$this->assertStringContainsString( 'colspan="2"', $row );
	}

	/**
	 * Without the auto-update column, the description spans one.
	 */
	public function test_the_row_spans_the_columns_there_are(): void {
		$GLOBALS['pl']['auto_update'] = false;

		$this->unmet();

		$this->assertStringContainsString( 'colspan="1"', pl_output( static fn() => do_action( 'after_plugin_row_blocked/blocked.php' ) ) );
	}

	/**
	 * The message may be a closure, so it is translated on the plugins
	 * screen and not when the plugin registers, before init.
	 */
	public function test_the_message_can_wait_to_be_translated(): void {
		$this->unmet( [ 'error_message' => static fn() => 'Needs <newer> PHP.' ] );

		$this->assertStringContainsString( 'Needs &lt;newer&gt; PHP.', pl_output( static fn() => do_action( 'after_plugin_row_blocked/blocked.php' ) ) );
	}

	public function test_requirements_and_support_links(): void {
		$this->unmet(
			[
				'requirements_url' => 'https://example.test/requirements',
				'support_url'      => 'https://example.test/support',
			]
		);

		$links = apply_filters( 'plugin_action_links_blocked/blocked.php', [ 'deactivate' => 'x' ] );

		$this->assertSame( [ 'deactivate', 'requirements', 'support' ], array_keys( $links ) );
		$this->assertStringContainsString( 'href="https://example.test/requirements"', $links['requirements'] );
		$this->assertStringContainsString( '>Support</a>', $links['support'] );
	}

	public function test_no_links_when_no_addresses(): void {
		$this->unmet();

		$this->assertSame( [ 'deactivate' => 'x' ], apply_filters( 'plugin_action_links_blocked/blocked.php', [ 'deactivate' => 'x' ] ) );
	}

	/**
	 * The styles are for the plugins screen, and only printed there.
	 */
	public function test_the_styles_are_only_on_the_plugins_screen(): void {
		$this->unmet( [ 'css_class_prefix' => 'acme' ] );

		$GLOBALS['pl']['screen'] = (object) [ 'id' => 'dashboard' ];

		$this->assertSame( '', pl_output( static fn() => do_action( 'admin_head' ) ) );

		$GLOBALS['pl']['screen'] = (object) [ 'id' => 'plugins' ];

		$styles = pl_output( static fn() => do_action( 'admin_head' ) );

		$this->assertStringContainsString( '<style id="acme-blocked">', $styles );
		$this->assertStringContainsString( 'tr[data-plugin="blocked/blocked.php"]', $styles );
		$this->assertStringContainsString( '.plugins .acme-blocked-row', $styles );
	}

	public function test_the_css_class(): void {
		$this->assertSame( 'sugarcommerce-blocked', ( new Unmet( $this->unmet() ) )->css_class() );
	}
}
