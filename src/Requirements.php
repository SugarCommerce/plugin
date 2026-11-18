<?php
/**
 * Requirements
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

use WP_Error;

/**
 * What a plugin needs, what it cannot run beside, and whether both hold.
 *
 * A requirement is something that must be there, usually at a version: PHP,
 * WordPress, the plugin being extended. A conflict is something that must
 * not: the free edition of a plugin whose pro edition is loading, say.
 *
 * This file runs before the PHP version has been checked, so it is written
 * to parse on PHP 7.4: no match, no nullsafe operator, no union types, no
 * str_starts_with(). A host too old for the plugin then sees the notice
 * naming the version it needs, instead of a parse error and a white screen.
 * tests/BootPathTest.php holds the line.
 */
final class Requirements {

	/**
	 * Requirements, by id, as configured.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $requirements = [];

	/**
	 * Conflicts, by id, as configured.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $conflicts = [];

	/**
	 * What the last check found, by id: exists, current, met.
	 *
	 * Kept apart from the configuration. Writing the answers back over it
	 * replaced an `exists` callable with the boolean it returned, so a
	 * second check had no callable to ask and reported the dependency gone.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $requirement_results = [];

	/**
	 * What the last check found for each conflict: exists, current, conflict.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $conflict_results = [];

	/**
	 * Requirements and conflicts, each by id.
	 *
	 *     new Requirements(
	 *         [ 'php' => '8.3', 'wp' => '7.1', 'easy-digital-downloads' => '3.6.1' ],
	 *         [ 'my-plugin-lite' => 'my-plugin-lite/my-plugin-lite.php' ]
	 *     );
	 *
	 * @param array<string, string|array<string, mixed>> $requirements A minimum version, or a configuration.
	 * @param array<string, string|array<string, mixed>> $conflicts    A plugin file, or a configuration.
	 */
	public function __construct( array $requirements = [], array $conflicts = [] ) {
		foreach ( $requirements as $id => $requirement ) {
			$this->add_requirement( (string) $id, $requirement );
		}

		foreach ( $conflicts as $id => $conflict ) {
			$this->add_conflict( (string) $id, $conflict );
		}
	}

	/**
	 * The dependencies known by id, and how each is found.
	 *
	 * Name one of these and only the minimum is needed: `'woocommerce' =>
	 * '10.3'`. Anything else needs a `check` and a `type` of its own.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function known(): array {
		return [
			'php'                    => [
				'name'  => 'PHP',
				'check' => 'phpversion',
				'type'  => 'function',
			],
			'wp'                     => [
				'name'  => 'WordPress',
				'check' => [ 'get_bloginfo', 'version' ],
				'type'  => 'function_with_args',
			],
			'easy-digital-downloads' => [
				'name'  => 'Easy Digital Downloads',
				'check' => 'EDD_VERSION',
				'type'  => 'constant',
			],
			'woocommerce'            => [
				'name'  => 'WooCommerce',
				'check' => 'WC_VERSION',
				'type'  => 'constant',
			],
			'elementor'              => [
				'name'  => 'Elementor',
				'check' => 'ELEMENTOR_VERSION',
				'type'  => 'constant',
			],
			'sugarcart'              => [
				'name'  => 'SugarCart',
				'check' => 'SUGARCART_VERSION',
				'type'  => 'constant',
			],
			'advanced-custom-fields' => [
				'name'  => 'Advanced Custom Fields',
				'check' => static function () {
					return class_exists( 'ACF' ) ? get_option( 'acf_version', false ) : false;
				},
				'type'  => 'callback',
			],
		];
	}

	/**
	 * Add a requirement.
	 *
	 * @param string                      $id   A known id, or one of your own.
	 * @param string|array<string, mixed> $args A minimum version, or name, minimum, check, type, exists, current.
	 *
	 * @return void
	 */
	public function add_requirement( string $id, $args ): void {
		if ( ! is_array( $args ) ) {
			$args = [ 'minimum' => (string) $args ];
		}

		$known = self::known()[ $id ] ?? [];

		$this->requirements[ sanitize_key( $id ) ] = array_merge(
			[
				// No minimum unless one is asked for. Defaulting to '1.0' made
				// every check that cannot report a version -- a class, an active
				// plugin -- permanently unmet, since there was nothing to compare.
				'minimum' => '',
				'name'    => $known['name'] ?? $id,
				'exists'  => null,
				'current' => null,
				'check'   => $known['check'] ?? null,
				'type'    => $known['type'] ?? 'auto',
			],
			$args
		);

		unset( $this->requirement_results[ sanitize_key( $id ) ] );
	}

	/**
	 * Add a conflict.
	 *
	 * Given a `plugin` (a plugin file, `folder/file.php`), the conflict is that
	 * plugin being active, and the default `action` is to deactivate it. Any
	 * other `action` refuses to load instead and says why.
	 *
	 * @param string                      $id   A known id, or one of your own.
	 * @param string|array<string, mixed> $args A plugin file, or name, plugin, check, type, message, action, condition.
	 *
	 * @return void
	 */
	public function add_conflict( string $id, $args ): void {
		if ( ! is_array( $args ) ) {
			$args = [ 'plugin' => (string) $args ];
		}

		if ( isset( $args['plugin'] ) && ! isset( $args['check'] ) ) {
			$args['check'] = $args['plugin'];
			$args['type']  = 'plugin_active';
		}

		$known = self::known()[ $id ] ?? [];

		$this->conflicts[ sanitize_key( $id ) ] = array_merge(
			[
				'name'      => $known['name'] ?? $id,
				'exists'    => null,
				'current'   => null,
				'check'     => $known['check'] ?? null,
				'type'      => $known['type'] ?? 'auto',
				'message'   => null,
				'action'    => 'auto_deactivate',
				'condition' => null,
			],
			$args
		);

		unset( $this->conflict_results[ sanitize_key( $id ) ] );
	}

	/**
	 * Whether every requirement is met and nothing conflicts.
	 *
	 * Checks afresh each time. A conflict whose action is `auto_deactivate`
	 * and which names a plugin is resolved by deactivating that plugin, where
	 * WordPress can (in the admin); anywhere else it blocks loading.
	 *
	 * @return bool
	 */
	public function met(): bool {
		$this->check();

		foreach ( $this->requirement_results as $result ) {
			if ( ! $result['met'] ) {
				return false;
			}
		}

		foreach ( $this->conflict_results as $id => $result ) {
			if ( ! $result['conflict'] ) {
				continue;
			}

			$conflict = $this->conflicts[ $id ];

			if ( 'auto_deactivate' === $conflict['action'] && isset( $conflict['plugin'] ) && function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( (string) $conflict['plugin'] );

				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Every unmet requirement and every conflict, as messages.
	 *
	 * Codes are `requirement_{id}` and `conflict_{id}`. The messages carry
	 * `<strong>` around the name and versions, escaped already.
	 *
	 * @return WP_Error
	 */
	public function errors(): WP_Error {
		if ( count( $this->requirement_results ) !== count( $this->requirements ) || count( $this->conflict_results ) !== count( $this->conflicts ) ) {
			$this->check();
		}

		$error = new WP_Error();

		foreach ( $this->requirement_results as $id => $result ) {
			if ( ! $result['met'] ) {
				$error->add( "requirement_{$id}", $this->unmet_description( $this->requirements[ $id ], $result ) );
			}
		}

		foreach ( $this->conflict_results as $id => $result ) {
			if ( $result['conflict'] ) {
				$error->add( "conflict_{$id}", $this->conflict_description( $this->conflicts[ $id ] ) );
			}
		}

		return $error;
	}

	/**
	 * What the last check found for each requirement.
	 *
	 * @return array<string, array{name: string, minimum: string, exists: bool, current: string|false, met: bool}>
	 */
	public function requirements(): array {
		$this->check();

		$out = [];

		foreach ( $this->requirement_results as $id => $result ) {
			$out[ $id ] = [
				'name'    => (string) self::value( $this->requirements[ $id ]['name'] ),
				'minimum' => (string) self::value( $this->requirements[ $id ]['minimum'] ),
			] + $result;
		}

		return $out;
	}

	/**
	 * What the last check found for each conflict.
	 *
	 * @return array<string, array{name: string, exists: bool, current: string|false, conflict: bool}>
	 */
	public function conflicts(): array {
		$this->check();

		$out = [];

		foreach ( $this->conflict_results as $id => $result ) {
			$out[ $id ] = [ 'name' => (string) self::value( $this->conflicts[ $id ]['name'] ) ] + $result;
		}

		return $out;
	}

	/**
	 * Check every requirement and conflict.
	 *
	 * @return void
	 */
	private function check(): void {
		$this->requirement_results = [];
		$this->conflict_results    = [];

		foreach ( $this->requirements as $id => $requirement ) {
			$found = $this->find( $requirement );

			$this->requirement_results[ $id ] = [
				'exists'  => $found['exists'],
				'current' => $found['version'],
				'met'     => $found['exists'] && self::minimum_met( $found['version'], (string) self::value( $requirement['minimum'] ) ),
			];
		}

		foreach ( $this->conflicts as $id => $conflict ) {
			$found    = $this->find( $conflict );
			$conflict = $found['exists'];

			// A condition narrows a conflict: the other plugin is there, but
			// only matters below a version, say.
			if ( $conflict && is_callable( $this->conflicts[ $id ]['condition'] ) ) {
				$conflict = (bool) call_user_func( $this->conflicts[ $id ]['condition'], $found );
			}

			$this->conflict_results[ $id ] = [
				'exists'   => $found['exists'],
				'current'  => $found['version'],
				'conflict' => $conflict,
			];
		}
	}

	/**
	 * Whether a dependency is there, and at what version.
	 *
	 * @param array<string, mixed> $properties Its configuration.
	 *
	 * @return array{exists: bool, version: string|false}
	 */
	private function find( array $properties ): array {
		$exists  = false;
		$version = false;

		// Callables of your own take precedence over the check.
		if ( is_callable( $properties['exists'] ) ) {
			$exists = (bool) call_user_func( $properties['exists'] );
		}

		if ( is_callable( $properties['current'] ) ) {
			$version = call_user_func( $properties['current'] );
		}

		if ( ! $exists && ! $version && null !== $properties['check'] ) {
			$found   = self::find_by_type( $properties['check'], (string) $properties['type'] );
			$exists  = $found['exists'];
			$version = $found['version'];
		}

		// A version means it is there, whichever callable said so.
		if ( ! $exists && is_string( $version ) && '' !== $version ) {
			$exists = true;
		}

		return [
			'exists'  => $exists,
			'version' => is_scalar( $version ) && false !== $version ? (string) $version : false,
		];
	}

	/**
	 * Look for a dependency the way its type says.
	 *
	 * @param mixed  $check What to look for.
	 * @param string $type  constant, function, function_with_args, class, plugin_active, callback or auto.
	 *
	 * @return array{exists: bool, version: mixed}
	 */
	private static function find_by_type( $check, string $type ): array {
		$exists  = false;
		$version = false;

		switch ( $type ) {
			case 'constant':
				if ( is_string( $check ) && defined( $check ) ) {
					$exists  = true;
					$version = constant( $check );
				}
				break;

			case 'function':
				if ( is_string( $check ) && function_exists( $check ) ) {
					$exists  = true;
					$version = call_user_func( $check );
				}
				break;

			case 'function_with_args':
				if ( is_array( $check ) && isset( $check[0] ) && is_string( $check[0] ) && function_exists( $check[0] ) ) {
					$exists  = true;
					$version = call_user_func_array( $check[0], array_slice( $check, 1 ) );
				}
				break;

			case 'class':
				$exists = is_string( $check ) && class_exists( $check );
				break;

			case 'plugin_active':
				if ( is_string( $check ) ) {
					if ( ! function_exists( 'is_plugin_active' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}

					$exists = is_plugin_active( $check );
				}
				break;

			case 'callback':
				if ( is_callable( $check ) ) {
					$result = call_user_func( $check );

					if ( is_array( $result ) ) {
						$exists  = (bool) ( $result['exists'] ?? false );
						$version = $result['version'] ?? false;
					} else {
						$exists  = ! empty( $result );
						$version = $result;
					}
				}
				break;

			case 'auto':
				if ( is_string( $check ) && defined( $check ) ) {
					$exists  = true;
					$version = constant( $check );
				} elseif ( is_string( $check ) && function_exists( $check ) ) {
					$exists  = true;
					$version = call_user_func( $check );
				} elseif ( is_string( $check ) && class_exists( $check ) ) {
					$exists = true;
				}
				break;
		}

		return [
			'exists'  => $exists,
			'version' => $version,
		];
	}

	/**
	 * A configured value: as given, or what a closure returns.
	 *
	 * Only a closure or an array callable is called. A plain string never is,
	 * because a name like "Date" or a message is also, by accident, the name
	 * of a PHP function, and calling it is a fatal error on the plugins screen.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function value( $value ) {
		return ! is_string( $value ) && is_callable( $value ) ? call_user_func( $value ) : $value;
	}

	/**
	 * Whether a version reaches a minimum.
	 *
	 * @param string|false $current What was found.
	 * @param string       $minimum What was asked for.
	 *
	 * @return bool
	 */
	private static function minimum_met( $current, string $minimum ): bool {
		// Nothing was asked for, so presence is the whole requirement. This is
		// the normal case for a 'class' or 'plugin_active' check, neither of
		// which can report a version at all.
		if ( '' === trim( $minimum ) ) {
			return true;
		}

		// A version was asked for and none could be read. Reporting that as
		// met would claim a check happened that did not.
		if ( ! is_string( $current ) || '' === $current ) {
			return false;
		}

		return version_compare( $current, $minimum, '>=' );
	}

	/**
	 * What to say about a requirement that is not met.
	 *
	 * @param array<string, mixed> $requirement Its configuration.
	 * @param array<string, mixed> $result      What the check found.
	 *
	 * @return string
	 */
	private function unmet_description( array $requirement, array $result ): string {
		$name    = esc_html( (string) self::value( $requirement['name'] ) );
		$minimum = (string) self::value( $requirement['minimum'] );

		if ( $result['exists'] && is_string( $result['current'] ) && '' !== $result['current'] ) {
			return sprintf(
				/* translators: 1: requirement name, 2: minimum version, 3: version installed */
				esc_html__( '%1$s: minimum required %2$s (you have %3$s)', 'sugarcommerce' ),
				'<strong>' . $name . '</strong>',
				'<strong>' . esc_html( $minimum ) . '</strong>',
				'<strong>' . esc_html( $result['current'] ) . '</strong>'
			);
		}

		// Naming a minimum that was never asked for reads as though the
		// dependency is the wrong version, when it is simply absent.
		if ( '' === $minimum ) {
			return sprintf(
				/* translators: %s: name of the missing requirement */
				esc_html__( 'Missing %s', 'sugarcommerce' ),
				'<strong>' . $name . '</strong>'
			);
		}

		return sprintf(
			/* translators: 1: name of the missing requirement, 2: minimum version required */
			esc_html__( 'Missing %1$s: minimum required %2$s', 'sugarcommerce' ),
			'<strong>' . $name . '</strong>',
			'<strong>' . esc_html( $minimum ) . '</strong>'
		);
	}

	/**
	 * What to say about a conflict.
	 *
	 * @param array<string, mixed> $conflict Its configuration.
	 *
	 * @return string
	 */
	private function conflict_description( array $conflict ): string {
		$message = self::value( $conflict['message'] );

		if ( is_string( $message ) && '' !== $message ) {
			return $message;
		}

		return sprintf(
			/* translators: %s: name of the conflicting plugin */
			esc_html__( 'Conflict detected: %s is active and conflicts with this plugin.', 'sugarcommerce' ),
			'<strong>' . esc_html( (string) self::value( $conflict['name'] ) ) . '</strong>'
		);
	}
}
