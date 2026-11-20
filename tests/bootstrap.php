<?php
/**
 * PHPUnit bootstrap.
 *
 * A library, not a plugin: there is no WordPress to load. The WordPress
 * surface this package touches is stubbed, kept behaviourally honest: hooks
 * really run their callbacks, escaping escapes, options and user meta read
 * and write arrays a test can see, and wp_die() throws so a handler stops
 * where WordPress would stop it.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

define( 'ABSPATH', sys_get_temp_dir() . '/sugarcommerce-plugin-wp/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/FeaturesUtil.php';

/**
 * Thrown by the wp_die() stub.
 */
final class WP_Die_Exception extends RuntimeException {

	/**
	 * What wp_die() was given.
	 *
	 * @var mixed
	 */
	public $value;

	/**
	 * The response code.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * @param mixed $value  What wp_die() was given.
	 * @param int   $status The response code.
	 */
	public function __construct( $value, int $status ) {
		parent::__construct( 'wp_die' );

		$this->value  = $value;
		$this->status = $status;
	}
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function pl_reset(): void {
	$GLOBALS['pl'] = [
		'hooks'       => [],
		'did'         => [],
		'doing'       => [],
		'options'     => [],
		'user_meta'   => [],
		'caps'        => [ 'manage_options' => true ],
		'user'        => 1,
		'active'      => [],
		'deactivated' => [],
		'wp_version'  => '7.1',
		'screen'      => null,
		'textdomains' => [],
		'activation'  => [],
		'scripts'     => [],
		'styles'      => [],
		'enqueued'    => [],
		'i18n'        => [],
		'nonce_ok'    => true,
		'routes'      => [],
		'header'      => [ 'Version' => '2.4.0' ],
		'auto_update' => true,
	];

	unset( $GLOBALS['wp_plugin_paths'] );
	$_POST = [];
}

pl_reset();

/**
 * Run an action the way WordPress does, marking it done.
 *
 * @param string $hook The action.
 * @param mixed  ...$args Its arguments.
 *
 * @return void
 */
function pl_fire( string $hook, ...$args ): void {
	$GLOBALS['pl']['did'][ $hook ] = ( $GLOBALS['pl']['did'][ $hook ] ?? 0 ) + 1;
	$GLOBALS['pl']['doing'][]      = $hook;

	do_action( $hook, ...$args );

	array_pop( $GLOBALS['pl']['doing'] );
}

/**
 * Everything printed while running a callable.
 *
 * @param callable $callback What to run.
 *
 * @return string
 */
function pl_output( callable $callback ): string {
	ob_start();

	try {
		$callback();
	} finally {
		$output = (string) ob_get_clean();
	}

	return $output;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['pl']['hooks'][ $hook ][ $priority ][] = [ $callback, (int) $accepted_args ];
	ksort( $GLOBALS['pl']['hooks'][ $hook ] );

	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function has_filter( $hook ) {
	return ! empty( $GLOBALS['pl']['hooks'][ $hook ] );
}

function has_action( $hook ) {
	return has_filter( $hook );
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['pl']['hooks'][ $hook ] ?? [] as $callbacks ) {
		foreach ( $callbacks as [ $callback, $accepted ] ) {
			$value = $callback( ...array_slice( array_merge( [ $value ], $args ), 0, $accepted ) );
		}
	}

	return $value;
}

function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['pl']['hooks'][ $hook ] ?? [] as $callbacks ) {
		foreach ( $callbacks as [ $callback, $accepted ] ) {
			$callback( ...array_slice( $args, 0, $accepted ) );
		}
	}
}

function did_action( $hook ) {
	return (int) ( $GLOBALS['pl']['did'][ $hook ] ?? 0 );
}

function doing_action( $hook ) {
	return in_array( $hook, $GLOBALS['pl']['doing'], true );
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function content_url( $path = '' ) {
	return 'https://example.test/wp-content' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}

function site_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );

	return (string) preg_replace( '|(?<=.)/+|', '/', $path );
}

function sanitize_key( $key ) {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_html_class( $class ) {
	return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function esc_url( $url ) {
	$url = (string) $url;

	return preg_match( '#^(https?:)?//|^/#', $url ) ? htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) : '';
}

function wp_kses_post( $html ) {
	return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
}

function wpautop( $text ) {
	return '<p>' . $text . "</p>\n";
}

function get_option( $key, $default = false ) {
	return $GLOBALS['pl']['options'][ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['pl']['options'][ $key ] = $value;

	return true;
}

function get_user_meta( $user, $key, $single = false ) {
	return $GLOBALS['pl']['user_meta'][ $user ][ $key ] ?? '';
}

function update_user_meta( $user, $key, $value ) {
	$GLOBALS['pl']['user_meta'][ $user ][ $key ] = $value;

	return true;
}

function get_current_user_id() {
	return $GLOBALS['pl']['user'];
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['pl']['caps'][ $capability ] );
}

function get_current_screen() {
	return $GLOBALS['pl']['screen'];
}

function is_plugin_active( $plugin ) {
	return in_array( $plugin, $GLOBALS['pl']['active'], true );
}

function deactivate_plugins( $plugins ) {
	foreach ( (array) $plugins as $plugin ) {
		$GLOBALS['pl']['deactivated'][] = $plugin;
	}
}

function get_bloginfo( $show = '' ) {
	return 'version' === $show ? $GLOBALS['pl']['wp_version'] : '';
}

function load_plugin_textdomain( $domain, $deprecated = false, $path = false ) {
	$GLOBALS['pl']['textdomains'][] = [ $domain, $path ];

	return true;
}

function get_plugin_data( $file, $markup = true, $translate = true ) {
	return $GLOBALS['pl']['header'];
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['pl']['activation'][ 'activate_' . plugin_basename( $file ) ] = $callback;
}

function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['pl']['activation'][ 'deactivate_' . plugin_basename( $file ) ] = $callback;
}

function wp_is_auto_update_enabled_for_type( $type ) {
	return $GLOBALS['pl']['auto_update'];
}

function register_rest_route( $route_namespace, $route, $args = [] ) {
	$GLOBALS['pl']['routes'][ '/' . $route_namespace . $route ] = $args;

	return true;
}

function rest_authorization_required_code() {
	return 403;
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Enough of WP_REST_Request: its parameters, read as an array.
	 */
	class WP_REST_Request implements ArrayAccess {

		/**
		 * Parameters.
		 *
		 * @var array<string, mixed>
		 */
		private array $params = [];

		public function set_param( $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function offsetExists( mixed $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

function wp_create_nonce( $action ) {
	return 'nonce-' . $action;
}

function check_ajax_referer( $action ) {
	if ( ! $GLOBALS['pl']['nonce_ok'] || ( $_REQUEST['_ajax_nonce'] ?? 'nonce-' . $action ) !== 'nonce-' . $action ) { // phpcs:ignore
		throw new WP_Die_Exception( -1, 403 );
	}

	return 1;
}

function wp_die( $value = '', $title = '', $args = [] ) {
	throw new WP_Die_Exception( $value, (int) ( is_array( $args ) ? ( $args['response'] ?? 200 ) : 200 ) );
}

function wp_register_script( $handle, $src, $deps = [], $ver = false, $args = [] ) {
	$GLOBALS['pl']['scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'args' );

	return true;
}

function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
	$GLOBALS['pl']['styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );

	return true;
}

function wp_enqueue_script( $handle ) {
	$GLOBALS['pl']['enqueued'][] = 'script:' . $handle;
}

function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
	$GLOBALS['pl']['i18n'][ $handle ] = $domain;

	return true;
}

function wp_enqueue_style( $handle ) {
	$GLOBALS['pl']['enqueued'][] = 'style:' . $handle;
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Enough of WP_Error.
	 */
	class WP_Error {

		/**
		 * Messages, by code.
		 *
		 * @var array<string, string[]>
		 */
		private array $errors = [];

		/**
		 * Data, by code.
		 *
		 * @var array<string, mixed>
		 */
		private array $data = [];

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( $code, $message, $data = '' ): void {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			return array_key_first( $this->errors ) ?? '';
		}

		public function get_error_data( $code = '' ) {
			return $this->data[ '' === $code ? $this->get_error_code() : $code ] ?? null;
		}

		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		public function get_error_messages( $code = '' ): array {
			if ( '' === $code ) {
				return array_merge( [], ...array_values( $this->errors ) );
			}

			return $this->errors[ $code ] ?? [];
		}

		public function get_error_message( $code ): string {
			return $this->errors[ $code ][0] ?? '';
		}

		public function has_errors(): bool {
			return [] !== $this->errors;
		}
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		$GLOBALS['pl']['doing_it_wrong'][] = $function_name;
	}
}

/*
 * Core's admin notice functions, verbatim from WordPress 7.1 (wp-includes/functions.php),
 * so a notice is tested against the markup core really prints.
 */
if ( ! function_exists( 'wp_get_admin_notice' ) ) {
function wp_get_admin_notice( $message, $args = array() ) {
	$defaults = array(
		'type'               => '',
		'dismissible'        => false,
		'id'                 => '',
		'additional_classes' => array(),
		'attributes'         => array(),
		'paragraph_wrap'     => true,
	);

	$args = wp_parse_args( $args, $defaults );

	/**
	 * Filters the arguments for an admin notice.
	 *
	 * @since 6.4.0
	 *
	 * @param array  $args    The arguments for the admin notice.
	 * @param string $message The message for the admin notice.
	 */
	$args       = apply_filters( 'wp_admin_notice_args', $args, $message );
	$id         = '';
	$classes    = 'notice';
	$attributes = '';

	if ( is_string( $args['id'] ) ) {
		$trimmed_id = trim( $args['id'] );

		if ( '' !== $trimmed_id ) {
			$id = 'id="' . $trimmed_id . '" ';
		}
	}

	if ( is_string( $args['type'] ) ) {
		$type = trim( $args['type'] );

		if ( str_contains( $type, ' ' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					/* translators: %s: The "type" key. */
					__( 'The %s key must be a string without spaces.' ),
					'<code>type</code>'
				),
				'6.4.0'
			);
		}

		if ( '' !== $type ) {
			$classes .= ' notice-' . $type;
		}
	}

	if ( true === $args['dismissible'] ) {
		$classes .= ' is-dismissible';
	}

	if ( is_array( $args['additional_classes'] ) && ! empty( $args['additional_classes'] ) ) {
		$classes .= ' ' . implode( ' ', $args['additional_classes'] );
	}

	if ( is_array( $args['attributes'] ) && ! empty( $args['attributes'] ) ) {
		$attributes = '';
		foreach ( $args['attributes'] as $attr => $val ) {
			if ( is_bool( $val ) ) {
				$attributes .= $val ? ' ' . $attr : '';
			} elseif ( is_int( $attr ) ) {
				$attributes .= ' ' . esc_attr( trim( $val ) );
			} elseif ( $val ) {
				$attributes .= ' ' . $attr . '="' . esc_attr( trim( $val ) ) . '"';
			}
		}
	}

	if ( false !== $args['paragraph_wrap'] ) {
		$message = "<p>$message</p>";
	}

	$markup = sprintf( '<div %1$sclass="%2$s"%3$s>%4$s</div>', $id, $classes, $attributes, $message );

	/**
	 * Filters the markup for an admin notice.
	 *
	 * @since 6.4.0
	 *
	 * @param string $markup  The HTML markup for the admin notice.
	 * @param string $message The message for the admin notice.
	 * @param array  $args    The arguments for the admin notice.
	 */
	return apply_filters( 'wp_admin_notice_markup', $markup, $message, $args );
}

function wp_admin_notice( $message, $args = array() ) {
	/**
	 * Fires before an admin notice is output.
	 *
	 * @since 6.4.0
	 *
	 * @param string $message The message for the admin notice.
	 * @param array  $args    The arguments for the admin notice.
	 */
	do_action( 'wp_admin_notice', $message, $args );

	echo wp_kses_post( wp_get_admin_notice( $message, $args ) );
}
}
