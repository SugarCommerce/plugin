<?php
/**
 * Notices
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

use WP_Error;
use WP_REST_Request;

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
 * `{id}_notice_shown_{notice}`, and the REST route that dismisses one
 * `POST {namespace}/{id}/notices/{notice}/dismissal` -- its namespace named
 * from the build, `sugarcommerce-plugin/v1` unprefixed, so two bundled
 * copies never answer each other's.
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

		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	/**
	 * The REST namespace, named from the build this copy belongs to.
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		$build = Build::name( __NAMESPACE__, 2 );

		return ( '' === $build ? '' : $build . '-' ) . 'sugarcommerce-plugin/v1';
	}

	/**
	 * Where a notice of this plugin's is dismissed, for apiFetch's `path`.
	 *
	 * @param string $notice The notice's id.
	 *
	 * @return string
	 */
	public function path( string $notice ): string {
		return '/' . self::rest_namespace() . '/' . $this->plugin->id() . '/notices/' . sanitize_key( $notice ) . '/dismissal';
	}

	/**
	 * Register the dismissal route. Hooked on rest_api_init.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::rest_namespace(),
			'/' . $this->plugin->id() . '/notices/(?P<notice>[a-z0-9_-]+)/dismissal',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'dismiss' ],
				'permission_callback' => [ $this, 'may_dismiss' ],
				'args'                => [
					'notice' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
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
	 * Whether the current user may dismiss the notice a request names.
	 *
	 * Only this plugin's own dismissible notices, and only for a user the
	 * notice is for: its capability, or manage_options when it names none.
	 * The REST nonce has been checked by then -- WordPress does it for a
	 * cookie-authenticated request before any permission callback -- so a
	 * page an administrator visits cannot make their browser dismiss one.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function may_dismiss( WP_REST_Request $request ): true|WP_Error {
		$notice_id = sanitize_key( (string) $request['notice'] );
		$notices   = $this->notices();

		if ( '' === $notice_id || ! isset( $notices[ $notice_id ] ) || empty( $notices[ $notice_id ]['dismissible'] ) ) {
			return new WP_Error( 'rest_notice_not_found', __( 'There is no such notice to dismiss.', 'sugarcommerce' ), [ 'status' => 404 ] );
		}

		if ( ! current_user_can( (string) ( $notices[ $notice_id ]['capability'] ?? '' ) ?: 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot dismiss that notice.', 'sugarcommerce' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * Remember that the current user dismissed a notice.
	 *
	 * @param WP_REST_Request $request The request, allowed by may_dismiss().
	 *
	 * @return array{dismissed: bool, notice: string}
	 */
	public function dismiss( WP_REST_Request $request ): array {
		$notice_id = sanitize_key( (string) $request['notice'] );

		update_user_meta( get_current_user_id(), $this->dismissed_key( $notice_id ), true );

		return [
			'dismissed' => true,
			'notice'    => $notice_id,
		];
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
			$attributes['data-sugarcommerce-dismiss'] = $this->path( $notice_id );
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
	 * One listener per copy of this library, for that copy's notices only:
	 * each notice's route starts with its build's namespace, and the
	 * listener answers only those. Another plugin's copy -- another version
	 * -- never handles this one's, and the flag that keeps a page from
	 * listening twice is the build's own entry in
	 * `window.SugarCommerceNotices`, never a name two copies share.
	 *
	 * @return void
	 */
	private static function script(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;

		// The REST client, with core's nonce middleware; in the footer, and
		// asked for only when the click comes.
		wp_enqueue_script( 'wp-api-fetch' );
		?>
		<script>
			( function ( build ) {
				var seen = ( window.SugarCommerceNotices = window.SugarCommerceNotices || {} );
				if ( seen[ build ] ) {
					return;
				}
				seen[ build ] = true;
				document.addEventListener( 'click', function ( event ) {
					var button = event.target.closest && event.target.closest( '.notice-dismiss' );
					var notice = button && button.closest( '[data-sugarcommerce-dismiss]' );
					var path   = notice ? notice.getAttribute( 'data-sugarcommerce-dismiss' ) : '';
					if ( 0 !== path.indexOf( '/' + build + '/' ) || ! window.wp || ! window.wp.apiFetch ) {
						return;
					}
					window.wp.apiFetch( { path: path, method: 'POST' } ).catch( function () {} );
				} );
			}( <?php echo wp_json_encode( self::rest_namespace() ); ?> ) );
		</script>
		<?php
	}
}
