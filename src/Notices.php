<?php
/**
 * Notices
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

/**
 * A loaded plugin's admin notices: a welcome, a nudge to finish setting up.
 *
 *     'notices' => [
 *         'welcome' => [
 *             'type'        => 'success',                  // info, success, warning, error
 *             'message'     => static fn() => __( '…', 'my-plugin' ),
 *             'dismissible' => true,                       // remembered per user
 *             'show_once'   => true,                       // shown on one page load, ever
 *             'capability'  => 'manage_options',
 *             'pages'       => [ 'plugins', 'edit-download' ], // screen ids
 *             'show_until'  => 'option_not_empty:my_plugin_api_key', // or a callable: show while true
 *         ],
 *     ],
 *
 * What is remembered is named from the plugin's id, so two plugins that
 * both call their notice `welcome` no longer share one dismissal: the user
 * meta is `{id}_notice_dismissed_{notice}`, the option
 * `{id}_notice_shown_{notice}`, and the AJAX action `{id}_dismiss_notice`.
 */
final class Notices {

	/**
	 * Notices for a loaded plugin.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hook the notices and their dismissal.
	 *
	 * @return void
	 */
	public function hook(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );

		if ( $this->plugin->config()['network'] ) {
			add_action( 'network_admin_notices', [ $this, 'render' ] );
		}

		add_action( 'wp_ajax_' . $this->action(), [ $this, 'dismiss' ] );
	}

	/**
	 * The AJAX action, which is also the nonce action.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->plugin->id() . '_dismiss_notice';
	}

	/**
	 * The user meta that remembers a dismissal.
	 *
	 * @param string $notice The notice's id.
	 *
	 * @return string
	 */
	public function dismissed_key( string $notice ): string {
		return $this->plugin->id() . '_notice_dismissed_' . $notice;
	}

	/**
	 * The option that remembers a show-once notice was shown.
	 *
	 * @param string $notice The notice's id.
	 *
	 * @return string
	 */
	public function shown_key( string $notice ): string {
		return $this->plugin->id() . '_notice_shown_' . $notice;
	}

	/**
	 * Print each notice that should show on this screen, for this user.
	 *
	 * @return void
	 */
	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = is_object( $screen ) ? (string) ( $screen->id ?? '' ) : '';
		$script = false;

		foreach ( $this->notices() as $notice_id => $notice ) {
			if ( ! $this->should_show( $notice_id, $notice, $id ) ) {
				continue;
			}

			$this->print_notice( $notice_id, $notice );

			$script = $script || ! empty( $notice['dismissible'] );
		}

		if ( $script ) {
			self::script();
		}
	}

	/**
	 * Remember that the current user dismissed a notice.
	 *
	 * The capability asked is the notice's own (manage_options when it names
	 * none), so a shop manager who was shown a notice can dismiss it. Only
	 * this plugin's notices can be dismissed through its action.
	 *
	 * @return void
	 */
	public function dismiss(): void {
		// A capability check answers "may this user dismiss notices", not
		// "did this user ask to". Without a nonce, any page an administrator
		// visits can make their browser post here and write user meta.
		check_ajax_referer( $this->action() );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$notice_id = sanitize_key( wp_unslash( (string) ( $_POST['notice_id'] ?? '' ) ) );
		$notices   = $this->notices();

		if ( '' === $notice_id || ! isset( $notices[ $notice_id ] ) || empty( $notices[ $notice_id ]['dismissible'] ) ) {
			wp_die( -1, '', [ 'response' => 400 ] );
		}

		if ( ! current_user_can( (string) ( $notices[ $notice_id ]['capability'] ?? '' ) ?: 'manage_options' ) ) {
			wp_die( -1, '', [ 'response' => 403 ] );
		}

		update_user_meta( get_current_user_id(), $this->dismissed_key( $notice_id ), true );

		wp_die( 1 );
	}

	/**
	 * The configured notices, by sanitised id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function notices(): array {
		$notices = [];

		foreach ( (array) $this->plugin->config()['notices'] as $id => $notice ) {
			if ( is_array( $notice ) ) {
				$notices[ sanitize_key( (string) $id ) ] = $notice;
			}
		}

		return $notices;
	}

	/**
	 * Whether a notice shows here, now, to this user.
	 *
	 * @param string               $notice_id Its id.
	 * @param array<string, mixed> $notice    Its configuration.
	 * @param string               $screen    The current screen's id.
	 *
	 * @return bool
	 */
	private function should_show( string $notice_id, array $notice, string $screen ): bool {
		if ( ! empty( $notice['capability'] ) && ! current_user_can( (string) $notice['capability'] ) ) {
			return false;
		}

		if ( ! empty( $notice['dismissible'] ) && get_user_meta( get_current_user_id(), $this->dismissed_key( $notice_id ), true ) ) {
			return false;
		}

		if ( ! empty( $notice['show_once'] ) && get_option( $this->shown_key( $notice_id ) ) ) {
			return false;
		}

		// Screen ids are hyphenated (edit-download) and some people write
		// them with underscores; either matches.
		if ( ! empty( $notice['pages'] ) && is_array( $notice['pages'] ) ) {
			$wanted = array_map( static fn( $page ): string => str_replace( '_', '-', (string) $page ), $notice['pages'] );

			if ( ! in_array( str_replace( '_', '-', $screen ), $wanted, true ) ) {
				return false;
			}
		}

		$until = $notice['show_until'] ?? null;

		if ( is_string( $until ) && str_starts_with( $until, 'option_not_empty:' ) ) {
			return empty( get_option( substr( $until, strlen( 'option_not_empty:' ) ) ) );
		}

		if ( null !== $until && '' !== $until && is_callable( $until ) ) {
			return (bool) $until();
		}

		return true;
	}

	/**
	 * Print a notice, and remember a show-once notice was shown.
	 *
	 * @param string               $notice_id Its id.
	 * @param array<string, mixed> $notice    Its configuration.
	 *
	 * @return void
	 */
	private function print_notice( string $notice_id, array $notice ): void {
		$message = $notice['message'] ?? '';

		// A closure keeps __() out of the configuration, so the text is
		// translated here, on admin_notices, after init.
		if ( ! is_string( $message ) && is_callable( $message ) ) {
			$message = $message();
		}

		$dismissible = ! empty( $notice['dismissible'] );
		$attributes  = [ 'data-notice-id' => $notice_id ];

		if ( $dismissible ) {
			$attributes['data-sugarcommerce-dismiss'] = $this->action();
			$attributes['data-nonce']                 = wp_create_nonce( $this->action() );
		}

		if ( ! empty( $notice['show_once'] ) ) {
			update_option( $this->shown_key( $notice_id ), true, false );
		}

		// Core's markup, escaping and wp_kses_post(), plus its
		// wp_admin_notice_args / _markup filters. Dismissal is the part core
		// does not do -- its close button only hides the notice until the
		// next page -- so the data attributes carry what script() needs to
		// remember it.
		wp_admin_notice(
			(string) $message,
			[
				'type'        => sanitize_html_class( (string) ( $notice['type'] ?? 'info' ) ),
				'dismissible' => $dismissible,
				'attributes'  => $attributes,
			]
		);
	}

	/**
	 * Post a dismissal when a dismissible notice's close button is pressed.
	 *
	 * Each notice carries its own action and nonce, so one listener serves
	 * every plugin using this library, however many copies Strauss made; the
	 * window flag keeps a second copy from adding a second listener.
	 *
	 * @return void
	 */
	private static function script(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;
		?>
		<script>
			( function () {
				if ( window.sugarcommerceNoticeDismiss ) {
					return;
				}
				window.sugarcommerceNoticeDismiss = true;
				document.addEventListener( 'click', function ( event ) {
					var button = event.target.closest && event.target.closest( '.notice-dismiss' );
					var notice = button && button.closest( '[data-sugarcommerce-dismiss]' );
					if ( ! notice || typeof window.ajaxurl !== 'string' ) {
						return;
					}
					var body = new URLSearchParams();
					body.append( 'action', notice.getAttribute( 'data-sugarcommerce-dismiss' ) );
					body.append( 'notice_id', notice.getAttribute( 'data-notice-id' ) );
					body.append( '_ajax_nonce', notice.getAttribute( 'data-nonce' ) );
					window.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } );
				} );
			}() );
		</script>
		<?php
	}
}
