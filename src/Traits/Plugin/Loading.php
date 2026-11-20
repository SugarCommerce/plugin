<?php
/**
 * What happens once the requirements are met: translations, the plugin's own files, its constants and the header values they come from.
 *
 * @package   SugarCommerce\Plugin
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Traits\Plugin;

use SugarCommerce\Plugin\Links;
use SugarCommerce\Plugin\Notices;
use SugarCommerce\Plugin\Slurp;

/**
 * Trait Loading
 *
 * What happens once the requirements are met: translations, the plugin's own files, its constants and the header values they come from.
 *
 * @since 1.0.0
 */
trait Loading {

	/**
	 * Load the plugin's translations, and this library's.
	 *
	 * A string is the text domain, loaded from the plugin's `languages`
	 * folder. An array may give `domain`, `path` (default `/languages`) and a
	 * `callback` to do the loading itself, given the plugin's folder.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'sugarcommerce' );

		$textdomain = $this->config['textdomain'];
		$folder     = dirname( $this->basename );

		if ( is_string( $textdomain ) && '' !== $textdomain ) {
			load_plugin_textdomain( $textdomain, false, $folder . '/languages' );

			return;
		}

		if ( ! is_array( $textdomain ) || empty( $textdomain['domain'] ) ) {
			return;
		}

		if ( isset( $textdomain['callback'] ) && is_callable( $textdomain['callback'] ) ) {
			call_user_func( $textdomain['callback'], $this->dir );

			return;
		}

		load_plugin_textdomain( (string) $textdomain['domain'], false, $folder . (string) ( $textdomain['path'] ?? '/languages' ) );
	}

	/**
	 * Require one `includes` entry: a file, or every PHP file in a folder.
	 *
	 * A folder is loaded file by file in name order (Slurp), not its
	 * subfolders. A path that is not there is skipped.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return void
	 */
	private static function require_path( string $path ): void {
		if ( is_dir( $path ) ) {
			Slurp::dir( $path );
		} elseif ( is_file( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Load the plugin: files, constants, bootstrap, hooks, links, notices.
	 *
	 * @return void
	 */
	private function load(): void {
		$this->loaded = true;

		foreach ( (array) $this->config['early_includes'] as $file ) {
			if ( is_string( $file ) ) {
				self::require_path( $file );
			}
		}

		foreach ( (array) $this->config['includes'] as $file ) {
			if ( is_string( $file ) ) {
				self::require_path( $this->dir . ltrim( $file, '/' ) );
			}
		}

		$this->define_constants();

		// The bootstrap waits for init. It typically builds classes whose
		// constructors call __() for labels, and translating before init is
		// a doing_it_wrong notice since WordPress 6.7. Files and constants
		// are in place above, so anything else on init can use them. Inside
		// or after init (registered from another init callback), it runs now.
		if ( did_action( 'init' ) ) {
			call_user_func( $this->bootstrap );
		} else {
			add_action( 'init', $this->bootstrap, 0 );
		}

		$this->setup_hooks();

		if ( is_callable( $this->config['success'] ) ) {
			call_user_func( $this->config['success'] );
		}

		if ( is_callable( $this->config['activation'] ) ) {
			register_activation_hook( $this->file, $this->config['activation'] );
		}

		if ( is_callable( $this->config['deactivation'] ) ) {
			register_deactivation_hook( $this->file, $this->config['deactivation'] );
		}

		if ( ! empty( $this->config['plugin_links'] ) || ! empty( $this->config['plugin_meta'] ) ) {
			( new Links( $this ) )->hook();
		}

		if ( ! empty( $this->config['notices'] ) ) {
			( new Notices( $this ) )->hook();
		}
	}

	/**
	 * Define `{PREFIX}_PLUGIN_VERSION`, `_FILE`, `_BASE`, `_DIR`, `_URL`, `_QUERY_LIMIT`.
	 *
	 * A string is the prefix. An array gives `prefix`, and may give `version`
	 * (default: the plugin header's), `skip` (suffixes not to define) and
	 * `definitions` (any other constants, by name). A constant already
	 * defined is left alone.
	 *
	 * @return void
	 */
	private function define_constants(): void {
		$constants = $this->config['constants'];

		if ( is_string( $constants ) && '' !== $constants ) {
			$constants = [ 'prefix' => $constants ];
		}

		if ( ! is_array( $constants ) || empty( $constants['prefix'] ) ) {
			return;
		}

		$version = (string) ( $constants['version'] ?? '' );
		$skip    = (array) ( $constants['skip'] ?? [] );

		$standard = [
			'VERSION'     => '' !== $version ? $version : null,
			'FILE'        => $this->file,
			'BASE'        => $this->basename,
			'DIR'         => $this->dir,
			'URL'         => null,
			'QUERY_LIMIT' => 9999999,
		];

		foreach ( $standard as $suffix => $value ) {
			$name = $constants['prefix'] . '_PLUGIN_' . $suffix;

			if ( in_array( $suffix, $skip, true ) || defined( $name ) ) {
				continue;
			}

			// Worked out only when wanted: the header read and the URL both
			// cost something, and a skipped constant should cost nothing.
			if ( 'VERSION' === $suffix && null === $value ) {
				$value = $this->header_version();
			} elseif ( 'URL' === $suffix ) {
				$value = plugin_dir_url( $this->file );
			}

			define( $name, $value );
		}

		foreach ( (array) ( $constants['definitions'] ?? [] ) as $name => $value ) {
			if ( ! defined( (string) $name ) ) {
				define( (string) $name, $value );
			}
		}
	}

	/**
	 * The `Requires at least` from a plugin's header.
	 *
	 * Read untranslated and without markup, like the Version below.
	 *
	 * @param string $file     The plugin's main file.
	 * @param string $fallback When the header names no minimum.
	 *
	 * @return string
	 */
	private static function header_requires_wp( string $file, string $fallback ): string {
		if ( ! is_readable( $file ) ) {
			return $fallback;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$requires = trim( (string) ( get_plugin_data( $file, false, false )['RequiresWP'] ?? '' ) );

		return '' !== $requires ? $requires : $fallback;
	}

	/**
	 * The Version from the plugin's header.
	 *
	 * Read untranslated and without markup, so reading it before init does
	 * not load a text domain early.
	 *
	 * @return string
	 */
	private function header_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $this->file, false, false );

		return (string) ( $data['Version'] ?? '' ) !== '' ? (string) $data['Version'] : '1.0.0';
	}
}
