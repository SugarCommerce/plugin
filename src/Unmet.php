<?php
/**
 * Unmet
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

/**
 * The plugins screen, when a plugin's requirements are not met.
 *
 * The plugin's row turns red, a row beneath it lists each requirement that
 * failed and each conflict, and its action links gain Requirements and
 * Support when the plugin gave those addresses. Nothing else of the plugin
 * loads, so this is the only thing it shows.
 *
 * Parses on PHP 7.4, like Plugin and Requirements: it is what an old host
 * is meant to see.
 */
final class Unmet {

	/**
	 * The plugin whose requirements failed.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Explain a plugin that did not load.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook onto the plugins screen.
	 *
	 * @return void
	 */
	public function hook(): void {
		$basename = $this->plugin->basename();

		add_action( 'admin_head', [ $this, 'styles' ] );
		add_filter( "plugin_action_links_{$basename}", [ $this, 'action_links' ] );
		add_filter( "network_admin_plugin_action_links_{$basename}", [ $this, 'action_links' ] );
		add_action( "after_plugin_row_{$basename}", [ $this, 'row' ] );
	}

	/**
	 * Colour the plugin's row and the one beneath it.
	 *
	 * Only on the plugins screen, which is the only place the rows are.
	 *
	 * @return void
	 */
	public function styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && ! in_array( $screen->id ?? '', [ 'plugins', 'plugins-network' ], true ) ) {
			return;
		}

		$row   = '.plugins tr[data-plugin="' . esc_attr( $this->plugin->basename() ) . '"]';
		$class = '.plugins .' . esc_attr( $this->css_class() ) . '-row';

		printf(
			'<style id="%1$s">%2$s th,%2$s td,%3$s th,%3$s td{background:#fff5f5}%2$s th,%2$s td{box-shadow:none}%3$s th span{margin-left:6px;color:#dc3232}%2$s th,%3$s th.check-column{border-left:4px solid #dc3232 !important}%3$s .column-description p{margin:0;padding:0}%3$s .column-description p:not(:last-of-type){margin-bottom:8px}</style>',
			esc_attr( $this->css_class() ),
			$row, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$class // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	/**
	 * The row beneath the plugin's, listing what failed.
	 *
	 * @return void
	 */
	public function row(): void {
		$config  = $this->plugin->config();
		$message = $config['error_message'];

		// Translated here, on the plugins screen, rather than as a default
		// when the plugin registered: that was before init, which WordPress
		// 6.7 and later report as loading a text domain too early.
		if ( ! is_string( $message ) && is_callable( $message ) ) {
			$message = call_user_func( $message );
		}

		if ( ! is_string( $message ) || '' === $message ) {
			$message = __( 'This plugin is not fully active.', 'sugarcommerce' );
		}

		$colspan = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' ) ? 2 : 1;

		printf(
			'<tr class="active %1$s-row"><th class="check-column"><span class="dashicons dashicons-warning"></span></th><td class="column-primary">%2$s</td><td class="column-description" colspan="%3$d">',
			esc_attr( $this->css_class() ),
			esc_html( $message ),
			(int) $colspan
		);

		foreach ( $this->plugin->requirements()->errors()->get_error_messages() as $error ) {
			// The messages carry deliberate markup naming the dependency in
			// bold; wp_kses_post() keeps that and nothing worse.
			echo wpautop( wp_kses_post( $error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post().
		}

		echo '</td></tr>';
	}

	/**
	 * Requirements and Support links, when the plugin gave their addresses.
	 *
	 * @param array<string, string> $links The row's links so far.
	 *
	 * @return array<string, string>
	 */
	public function action_links( $links ): array {
		$links  = (array) $links;
		$config = $this->plugin->config();

		if ( '' !== (string) $config['requirements_url'] ) {
			$links['requirements'] = sprintf(
				'<a href="%s" target="_blank" aria-label="%s">%s</a>',
				esc_url( (string) $config['requirements_url'] ),
				esc_attr__( 'View plugin requirements', 'sugarcommerce' ),
				esc_html__( 'Requirements', 'sugarcommerce' )
			);
		}

		if ( '' !== (string) $config['support_url'] ) {
			$links['support'] = sprintf(
				'<a href="%s" target="_blank" aria-label="%s">%s</a>',
				esc_url( (string) $config['support_url'] ),
				esc_attr__( 'Get plugin support', 'sugarcommerce' ),
				esc_html__( 'Support', 'sugarcommerce' )
			);
		}

		return $links;
	}

	/**
	 * The class the rows are styled by: `{css_class_prefix}-{folder}`.
	 *
	 * @return string
	 */
	public function css_class(): string {
		return sanitize_html_class( $this->plugin->config()['css_class_prefix'] . '-' . dirname( $this->plugin->basename() ) );
	}
}
