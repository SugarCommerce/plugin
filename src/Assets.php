<?php
/**
 * Assets
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

/**
 * CSS, JavaScript and other files that ship inside a Composer package.
 *
 * `plugins_url()` works from a plugin's file. It does not work from a library
 * in `vendor/`, which cannot know whether it was installed there, prefixed
 * into `vendor-prefixed/` by Strauss, symlinked from a path repository, or
 * bundled somewhere else again. Every library that ships an asset used to
 * write its own path arithmetic for this; this is the one shared way.
 *
 *     Assets::enqueue_script( 'my-lib', __FILE__, 'js/my-lib.js', [ 'jquery' ] );
 *
 * `__FILE__` is the whole trick: the package root is the nearest folder above
 * it holding a composer.json, and the files are in that root's `assets/`.
 * A package whose files live elsewhere asks path_to_url() for any path.
 *
 * Not core's `plugins_url( $file, __FILE__ )`, which is right for a plugin's
 * own files: it cannot serve a package bundled into a theme, and its caller
 * has to know how deep below the package root it sits, which a Strauss copy
 * changes. Every SugarCommerce package asks this instead of keeping a copy.
 */
final class Assets {

	/**
	 * How far up to look for a package root before giving up.
	 *
	 * A Composer package is never this deeply nested; the limit exists only so
	 * a pathological path cannot walk to the filesystem root.
	 */
	private const MAX_DEPTH = 12;

	/**
	 * The assets folder found for each calling file: path and url, or null.
	 *
	 * A null is a cached miss: the package has no assets folder, and there is
	 * no point walking the filesystem again to rediscover that.
	 *
	 * @var array<string, array{path: string, url: string}|null>
	 */
	private static array $cache = [];

	/**
	 * Register and enqueue a script from the calling package's assets.
	 *
	 * @param string            $handle       The handle.
	 * @param string            $calling_file __FILE__ of any file in the package.
	 * @param string            $file         The path within `assets/`.
	 * @param string[]          $deps         Dependencies.
	 * @param string|bool|null  $ver          A version, or false for the file's modification time.
	 * @param array<string, mixed>|bool $args `in_footer` as a bool, or `in_footer` and `strategy` ('defer', 'async').
	 *
	 * @return bool False when the file is not there.
	 */
	public static function enqueue_script( string $handle, string $calling_file, string $file, array $deps = [], string|bool|null $ver = false, array|bool $args = true ): bool {
		if ( ! self::register_script( $handle, $calling_file, $file, $deps, $ver, $args ) ) {
			return false;
		}

		wp_enqueue_script( $handle );

		return true;
	}

	/**
	 * Register and enqueue a stylesheet from the calling package's assets.
	 *
	 * @param string           $handle       The handle.
	 * @param string           $calling_file __FILE__ of any file in the package.
	 * @param string           $file         The path within `assets/`.
	 * @param string[]         $deps         Dependencies.
	 * @param string|bool|null $ver          A version, or false for the file's modification time.
	 * @param string           $media        The media it is for.
	 *
	 * @return bool False when the file is not there.
	 */
	public static function enqueue_style( string $handle, string $calling_file, string $file, array $deps = [], string|bool|null $ver = false, string $media = 'all' ): bool {
		if ( ! self::register_style( $handle, $calling_file, $file, $deps, $ver, $media ) ) {
			return false;
		}

		wp_enqueue_style( $handle );

		return true;
	}

	/**
	 * Register a script from the calling package's assets, to enqueue later.
	 *
	 * @param string                    $handle       The handle.
	 * @param string                    $calling_file __FILE__ of any file in the package.
	 * @param string                    $file         The path within `assets/`.
	 * @param string[]                  $deps         Dependencies.
	 * @param string|bool|null          $ver          A version, or false for the file's modification time.
	 * @param array<string, mixed>|bool $args         `in_footer` as a bool, or `in_footer` and `strategy`.
	 *
	 * @return bool False when the file is not there.
	 */
	public static function register_script( string $handle, string $calling_file, string $file, array $deps = [], string|bool|null $ver = false, array|bool $args = true ): bool {
		$asset = self::resolve( $calling_file, $file );

		if ( null === $asset ) {
			return false;
		}

		wp_register_script( $handle, $asset['file_url'], $deps, self::version( $asset['file_path'], $ver ), self::script_args( $args ) );

		return true;
	}

	/**
	 * What @wordpress/scripts says a built entry needs.
	 *
	 * Each entry is `assets/build/{entry}.js` with an `{entry}.asset.php`
	 * beside it naming the WordPress scripts it imports (`wp-api-fetch`,
	 * `wp-i18n`, …) and a version that changes when its contents do. Reading
	 * that file, rather than writing the dependencies out again in PHP, is
	 * what keeps the two from drifting: an import added to a module is a
	 * dependency on the next build.
	 *
	 * @param string $calling_file __FILE__ of any file in the package.
	 * @param string $entry        The entry's name, as the build was given it.
	 *
	 * @return array{dependencies: string[], version: string|bool}|null Null when the entry was never built.
	 */
	public static function built_manifest( string $calling_file, string $entry ): ?array {
		$path = self::path( $calling_file, 'build/' . sanitize_key( $entry ) . '.asset.php' );

		if ( null === $path ) {
			return null;
		}

		$asset = require $path;

		if ( ! is_array( $asset ) ) {
			return null;
		}

		return [
			'dependencies' => array_values( array_map( 'strval', (array) ( $asset['dependencies'] ?? [] ) ) ),
			'version'      => isset( $asset['version'] ) ? (string) $asset['version'] : false,
		];
	}

	/**
	 * Register a script @wordpress/scripts built into the package's `assets/build/`.
	 *
	 * The dependencies and version come from the entry's `.asset.php`; `$deps`
	 * adds handles it needs without importing them (a configuration script,
	 * media-views). An entry that imports `@wordpress/i18n` has its strings
	 * translated under the `sugarcommerce` text domain.
	 *
	 * The build is committed, because Composer and Strauss copy a package as
	 * it is, with no install step to run one.
	 *
	 * @param string                    $handle       The handle.
	 * @param string                    $calling_file __FILE__ of any file in the package.
	 * @param string                    $entry        The entry's name.
	 * @param string[]                  $deps         Handles it needs that it does not import.
	 * @param array<string, mixed>|bool $args         `in_footer` as a bool, or `in_footer` and `strategy`.
	 *
	 * @return bool False when the entry was never built.
	 */
	public static function register_built_script( string $handle, string $calling_file, string $entry, array $deps = [], array|bool $args = true ): bool {
		$manifest = self::built_manifest( $calling_file, $entry );

		if ( null === $manifest ) {
			return false;
		}

		$registered = self::register_script(
			$handle,
			$calling_file,
			'build/' . sanitize_key( $entry ) . '.js',
			array_values( array_unique( array_merge( $manifest['dependencies'], $deps ) ) ),
			$manifest['version'],
			$args
		);

		// Strings written with __() in the scripts are translated from the
		// plugin's language files, under this text domain.
		if ( $registered && in_array( 'wp-i18n', $manifest['dependencies'], true ) ) {
			wp_set_script_translations( $handle, 'sugarcommerce' );
		}

		return $registered;
	}

	/**
	 * Register and enqueue a script @wordpress/scripts built into `assets/build/`.
	 *
	 *     Assets::enqueue_built_script( 'my-lib', __FILE__, 'admin', [ 'my-lib-config' ] );
	 *
	 * @param string                    $handle       The handle.
	 * @param string                    $calling_file __FILE__ of any file in the package.
	 * @param string                    $entry        The entry's name.
	 * @param string[]                  $deps         Handles it needs that it does not import.
	 * @param array<string, mixed>|bool $args         `in_footer` as a bool, or `in_footer` and `strategy`.
	 *
	 * @return bool False when the entry was never built.
	 */
	public static function enqueue_built_script( string $handle, string $calling_file, string $entry, array $deps = [], array|bool $args = true ): bool {
		if ( ! self::register_built_script( $handle, $calling_file, $entry, $deps, $args ) ) {
			return false;
		}

		wp_enqueue_script( $handle );

		return true;
	}

	/**
	 * Register a stylesheet from the calling package's assets, to enqueue later.
	 *
	 * @param string           $handle       The handle.
	 * @param string           $calling_file __FILE__ of any file in the package.
	 * @param string           $file         The path within `assets/`.
	 * @param string[]         $deps         Dependencies.
	 * @param string|bool|null $ver          A version, or false for the file's modification time.
	 * @param string           $media        The media it is for.
	 *
	 * @return bool False when the file is not there.
	 */
	public static function register_style( string $handle, string $calling_file, string $file, array $deps = [], string|bool|null $ver = false, string $media = 'all' ): bool {
		$asset = self::resolve( $calling_file, $file );

		if ( null === $asset ) {
			return false;
		}

		wp_register_style( $handle, $asset['file_url'], $deps, self::version( $asset['file_path'], $ver ), $media );

		return true;
	}

	/**
	 * The URL of a file in the calling package's assets.
	 *
	 * @param string $calling_file __FILE__ of any file in the package.
	 * @param string $file         The path within `assets/`.
	 *
	 * @return string|null Null when the file is not there or has no URL.
	 */
	public static function url( string $calling_file, string $file ): ?string {
		return self::resolve( $calling_file, $file )['file_url'] ?? null;
	}

	/**
	 * The absolute path of a file in the calling package's assets.
	 *
	 * @param string $calling_file __FILE__ of any file in the package.
	 * @param string $file         The path within `assets/`.
	 *
	 * @return string|null Null when the file is not there.
	 */
	public static function path( string $calling_file, string $file ): ?string {
		return self::resolve( $calling_file, $file )['file_path'] ?? null;
	}

	/**
	 * The contents of a file in the calling package's assets: an SVG, some JSON.
	 *
	 * Files come from inside a Composer package -- shipped by the developer,
	 * not supplied by a user -- so the contents are as trusted as the rest of
	 * the package's code, and nothing is sanitised. Untrusted SVG wants a real
	 * DOM-based sanitiser, not anything here.
	 *
	 * @param string $calling_file __FILE__ of any file in the package.
	 * @param string $file         The path within `assets/`.
	 *
	 * @return string|false False when the file is not there.
	 */
	public static function contents( string $calling_file, string $file ): string|false {
		$path = self::path( $calling_file, $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the package.
		return null === $path ? false : file_get_contents( $path );
	}

	/**
	 * A file's path and URL, with the assets folder's.
	 *
	 * @param string $calling_file __FILE__ of any file in the package.
	 * @param string $file         The path within `assets/`.
	 *
	 * @return array{path: string, url: string, file_path: string, file_url: string}|null
	 */
	public static function resolve( string $calling_file, string $file ): ?array {
		// The path is meant to come from the developer, but a plugin that
		// builds it from a setting -- an icon name, say -- has handed this to
		// the user, and "../../wp-config.php" reads as an asset path to
		// everything below. Nothing legitimate leaves the assets folder.
		if ( ! self::stays_within( $file ) ) {
			return null;
		}

		$assets = self::locate( $calling_file );

		if ( null === $assets ) {
			return null;
		}

		$relative = ltrim( $file, '/' );
		$path     = $assets['path'] . '/' . $relative;

		if ( ! file_exists( $path ) ) {
			return null;
		}

		return $assets + [
			'file_path' => $path,
			'file_url'  => $assets['url'] . '/' . $relative,
		];
	}

	/**
	 * The calling package's assets folder, and its URL.
	 *
	 * @param string $calling_file __FILE__ of any file in the package.
	 *
	 * @return array{path: string, url: string}|null Null when the package has no assets folder, or it has no URL.
	 */
	public static function locate( string $calling_file ): ?array {
		if ( array_key_exists( $calling_file, self::$cache ) ) {
			return self::$cache[ $calling_file ];
		}

		$found = null;
		$root  = self::package_root( $calling_file );

		if ( null !== $root && is_dir( $root . '/assets' ) ) {
			$url = self::path_to_url( $root . '/assets' );

			if ( null !== $url ) {
				$found = [
					'path' => $root . '/assets',
					'url'  => $url,
				];
			}
		}

		// Misses are cached too. Without that, a package that legitimately
		// has no assets folder repeats the filesystem walk on every call.
		self::$cache[ $calling_file ] = $found;

		return $found;
	}

	/**
	 * The URL of any file or folder under WordPress.
	 *
	 * The content folder first, then the WordPress root. A path under neither
	 * is usually a symlinked plugin: __FILE__ resolves symlinks, so the real
	 * path can be anywhere on disk. Core keeps a map from each plugin's path
	 * under wp-content to its real path for exactly this -- plugins_url()
	 * reads it -- and it is read here too.
	 *
	 * @param string $path An absolute path.
	 *
	 * @return string|null Null when the path is not anywhere WordPress serves.
	 */
	public static function path_to_url( string $path ): ?string {
		$path = wp_normalize_path( $path );

		$url = self::url_within( $path, wp_normalize_path( WP_CONTENT_DIR ), content_url() )
			?? self::url_within( $path, wp_normalize_path( ABSPATH ), site_url( '/' ) );

		if ( null !== $url ) {
			return $url;
		}

		foreach ( (array) ( $GLOBALS['wp_plugin_paths'] ?? [] ) as $plugin_path => $real_path ) {
			$real_path = rtrim( wp_normalize_path( (string) $real_path ), '/' );

			if ( '' === $real_path || ! str_starts_with( $path, $real_path . '/' ) ) {
				continue;
			}

			$mapped = rtrim( wp_normalize_path( (string) $plugin_path ), '/' ) . substr( $path, strlen( $real_path ) );

			// The map only holds paths that differ, but one that mapped to
			// itself would recurse for ever, so it is checked anyway.
			if ( $mapped !== $path ) {
				return self::path_to_url( $mapped );
			}
		}

		return null;
	}

	/**
	 * Forget every folder found. For tests, and after moving things on disk.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		self::$cache = [];
	}

	/**
	 * Find the root of the Composer package holding a file.
	 *
	 * Walks up to the nearest composer.json, which by definition sits at a
	 * package's root -- including in a Strauss-prefixed build, which copies
	 * it alongside the rewritten source.
	 *
	 * This replaced a fixed list of relative paths ('/assets', '/../assets',
	 * ...) that took the first folder named "assets" it found. That could
	 * walk straight out of the package: a package laid out as
	 * <package>/src/File.php with no assets of its own climbed past vendor/
	 * and served every URL from the host plugin's assets folder.
	 *
	 * @param string $calling_file A file in the package.
	 *
	 * @return string|null
	 */
	private static function package_root( string $calling_file ): ?string {
		$dir = dirname( $calling_file );

		for ( $depth = 0; $depth < self::MAX_DEPTH; $depth++ ) {
			if ( is_file( $dir . '/composer.json' ) ) {
				return $dir;
			}

			$parent = dirname( $dir );

			// dirname() is its own fixed point at the filesystem root.
			if ( $parent === $dir ) {
				break;
			}

			$dir = $parent;
		}

		return null;
	}

	/**
	 * The URL of a path, if it sits under a base folder with a known URL.
	 *
	 * A prefix replacement, not str_replace(): that replaces every
	 * occurrence, so a base folder whose name recurred further down the path
	 * was rewritten twice.
	 *
	 * @param string $path     Normalised absolute path.
	 * @param string $base     Normalised base folder.
	 * @param string $base_url The base folder's URL.
	 *
	 * @return string|null
	 */
	private static function url_within( string $path, string $base, string $base_url ): ?string {
		$base = rtrim( $base, '/' );

		if ( '' === $base || ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}

		return rtrim( $base_url, '/' ) . substr( $path, strlen( $base ) );
	}

	/**
	 * Whether a relative path stays inside the assets folder.
	 *
	 * @param string $file The path within `assets/`.
	 *
	 * @return bool
	 */
	private static function stays_within( string $file ): bool {
		if ( str_contains( $file, "\0" ) ) {
			return false;
		}

		return ! in_array( '..', explode( '/', wp_normalize_path( $file ) ), true );
	}

	/**
	 * The version to register with: as given, or the file's modification time.
	 *
	 * @param string           $path The file.
	 * @param string|bool|null $ver  What was asked for.
	 *
	 * @return string|bool|null
	 */
	private static function version( string $path, string|bool|null $ver ): string|bool|null {
		if ( false !== $ver ) {
			return $ver;
		}

		$time = filemtime( $path );

		return false === $time ? '1.0.0' : (string) $time;
	}

	/**
	 * The script arguments WordPress takes since 6.3.
	 *
	 * A bool is `in_footer`. An array may also carry a loading `strategy`;
	 * one WordPress does not know is dropped rather than forwarded, since
	 * WordPress answers it with a notice on every page.
	 *
	 * @param array<string, mixed>|bool $args What was asked for.
	 *
	 * @return array<string, mixed>
	 */
	private static function script_args( array|bool $args ): array {
		if ( is_bool( $args ) ) {
			return [ 'in_footer' => $args ];
		}

		$normalized = [ 'in_footer' => (bool) ( $args['in_footer'] ?? true ) ];

		if ( isset( $args['strategy'] ) && in_array( $args['strategy'], [ 'defer', 'async' ], true ) ) {
			$normalized['strategy'] = $args['strategy'];
		}

		return $normalized;
	}
}
