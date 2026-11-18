<?php
/**
 * Links
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

/**
 * A loaded plugin's links on the plugins screen.
 *
 * `plugin_links` go beside Deactivate (Settings, Docs); `plugin_meta` go
 * under the description, beside the version (Support, GitHub). Each is either
 * a URL, with the key as its text, or an array:
 *
 *     'settings' => [
 *         'url'        => admin_url( 'options-general.php?page=my-plugin' ),
 *         'text'       => static fn() => __( 'Settings', 'my-plugin' ),
 *         'capability' => 'manage_options',
 *         'target'     => '_blank',
 *         'style'      => 'color:#2271b1',
 *     ],
 *
 * `text` may be a closure so that `__()` waits until the screen is drawn,
 * after init, rather than running when the plugin registers.
 */
final class Links {

	/**
	 * Links for a loaded plugin.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hook onto the plugins screen, and the network one for a network plugin.
	 *
	 * @return void
	 */
	public function hook(): void {
		$config   = $this->plugin->config();
		$basename = $this->plugin->basename();

		if ( ! empty( $config['plugin_links'] ) ) {
			add_filter( "plugin_action_links_{$basename}", [ $this, 'action_links' ] );

			if ( $config['network'] ) {
				add_filter( "network_admin_plugin_action_links_{$basename}", [ $this, 'action_links' ] );
			}
		}

		if ( ! empty( $config['plugin_meta'] ) ) {
			add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
		}
	}

	/**
	 * The links beside Deactivate, placed first.
	 *
	 * @param array<string, string> $links The row's links so far.
	 *
	 * @return array<string, string>
	 */
	public function action_links( $links ): array {
		return array_merge( $this->build( (array) $this->plugin->config()['plugin_links'], '' ), (array) $links );
	}

	/**
	 * The links under the description, placed after core's.
	 *
	 * @param string[] $links       The row's meta links so far.
	 * @param string   $plugin_file The row's plugin.
	 *
	 * @return string[]
	 */
	public function row_meta( $links, $plugin_file ): array {
		$links = (array) $links;

		if ( $plugin_file !== $this->plugin->basename() ) {
			return $links;
		}

		return array_merge( $links, array_values( $this->build( (array) $this->plugin->config()['plugin_meta'], '_blank' ) ) );
	}

	/**
	 * Turn configured links into anchors, leaving out any the user may not see.
	 *
	 * @param array<string, string|array<string, mixed>> $configured The links.
	 * @param string                                     $target     The default target.
	 *
	 * @return array<string, string>
	 */
	private function build( array $configured, string $target ): array {
		$links = [];

		foreach ( $configured as $key => $link ) {
			$key = (string) $key;

			if ( is_string( $link ) ) {
				$link = [ 'url' => $link ];
			}

			if ( ! is_array( $link ) || empty( $link['url'] ) ) {
				continue;
			}

			if ( ! empty( $link['capability'] ) && ! current_user_can( (string) $link['capability'] ) ) {
				continue;
			}

			$links[ $key ] = self::anchor(
				self::text( $link['text'] ?? null, $key ),
				(string) $link['url'],
				(string) ( $link['target'] ?? $target ),
				(string) ( $link['style'] ?? '' )
			);
		}

		return $links;
	}

	/**
	 * A link's text: as given, from a closure, or the key capitalised.
	 *
	 * @param mixed  $text The configured text.
	 * @param string $key  The link's key.
	 *
	 * @return string
	 */
	private static function text( mixed $text, string $key ): string {
		if ( null === $text || '' === $text ) {
			return ucfirst( $key );
		}

		return ! is_string( $text ) && is_callable( $text ) ? (string) $text() : (string) $text;
	}

	/**
	 * An anchor.
	 *
	 * @param string $text   What it says.
	 * @param string $url    Where it goes.
	 * @param string $target Its target, if any.
	 * @param string $style  Inline styles, if any.
	 *
	 * @return string
	 */
	private static function anchor( string $text, string $url, string $target, string $style ): string {
		$attributes = '';

		if ( '' !== $target ) {
			$attributes .= sprintf( ' target="%s"', esc_attr( $target ) );

			if ( '_blank' === $target ) {
				$attributes .= ' rel="noopener noreferrer"';
			}
		}

		if ( '' !== $style ) {
			$attributes .= sprintf( ' style="%s"', esc_attr( $style ) );
		}

		return sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $attributes, esc_html( $text ) );
	}
}
