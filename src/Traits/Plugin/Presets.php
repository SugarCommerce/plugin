<?php
/**
 * Registering an add-on for EDD or WooCommerce, or a network-wide plugin, with that platform's requirements and hooks already set.
 *
 * @package   SugarCommerce\Plugin
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Traits\Plugin;


/**
 * Trait Presets
 *
 * Registering an add-on for EDD or WooCommerce, or a network-wide plugin, with that platform's requirements and hooks already set.
 *
 * @since 1.0.0
 */
trait Presets {

	/**
	 * Register an Easy Digital Downloads extension.
	 *
	 * Requires EDD 3.6.1, and checks at `plugins_loaded` 99, after EDD's own
	 * extension loader (98). The WordPress minimum is the plugin header's
	 * `Requires at least`, so the header stays the one source; 7.1 when the
	 * header names none.
	 *
	 * @param string               $file      The plugin's main file.
	 * @param callable             $bootstrap What starts the plugin.
	 * @param array<string, mixed> $config    Merged over the defaults.
	 *
	 * @return Plugin|null
	 */
	public static function edd( string $file, callable $bootstrap, array $config = [] ): ?self {
		return self::register(
			$file,
			$bootstrap,
			self::merge_config(
				[
					'requirements' => [
						'php'                    => '8.3',
						'wp'                     => self::header_requires_wp( $file, '7.1' ),
						'easy-digital-downloads' => '3.6.1',
					],
					'priority'     => 99,
				],
				$config
			)
		);
	}

	/**
	 * Register a WooCommerce extension.
	 *
	 * Requires WooCommerce 10.3.5, checks at `plugins_loaded` 20 (after
	 * WooCommerce's 10), and declares the plugin compatible with order
	 * storage in custom tables.
	 *
	 * @param string               $file      The plugin's main file.
	 * @param callable             $bootstrap What starts the plugin.
	 * @param array<string, mixed> $config    Merged over the defaults.
	 *
	 * @return Plugin|null
	 */
	public static function woocommerce( string $file, callable $bootstrap, array $config = [] ): ?self {
		return self::register(
			$file,
			$bootstrap,
			self::merge_config(
				[
					'requirements' => [
						'php'         => '8.3',
						'wp'          => '7.1',
						'woocommerce' => '10.3.5',
					],
					'setup_hooks'  => [
						'woocommerce_compatibility' => [
							'features'   => [ 'custom_order_tables' ],
							'compatible' => true,
						],
					],
					'priority'     => 20,
				],
				$config
			)
		);
	}

	/**
	 * Register a plugin meant to be network-activated.
	 *
	 * The same as register(), with its links on the network plugins screen
	 * and its notices in the network admin as well.
	 *
	 * @param string               $file      The plugin's main file.
	 * @param callable             $bootstrap What starts the plugin.
	 * @param array<string, mixed> $config    See the README.
	 *
	 * @return Plugin|null
	 */
	public static function network( string $file, callable $bootstrap, array $config = [] ): ?self {
		$config['network'] = true;

		return self::register( $file, $bootstrap, $config );
	}

	/**
	 * Merge a caller's config over a preset's defaults.
	 *
	 * array_merge_recursive() cannot do this: given the same string key in
	 * both arrays it keeps *both* values as a list, so a plugin asking for
	 * PHP 8.3 over a default of 7.4 got [ '7.4', '8.3' ]. That is an array,
	 * so the requirement stopped being a version and the floor the plugin
	 * declared was never enforced.
	 *
	 * Scalars are overridden. Arrays one level down -- requirements,
	 * setup_hooks -- are merged key by key, so a caller can change one
	 * requirement without restating the rest.
	 *
	 * @param array<string, mixed> $defaults Preset defaults.
	 * @param array<string, mixed> $config   Caller configuration, which wins.
	 *
	 * @return array<string, mixed>
	 */
	public static function merge_config( array $defaults, array $config ): array {
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $config ) ) {
				$config[ $key ] = $value;

				continue;
			}

			if ( is_array( $value ) && is_array( $config[ $key ] ) ) {
				$config[ $key ] = array_merge( $value, $config[ $key ] );
			}
		}

		return $config;
	}
}
