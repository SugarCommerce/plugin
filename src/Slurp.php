<?php
/**
 * Slurp
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

/**
 * Include every PHP file in a directory, in a fixed order.
 *
 * A plugin that grows past a handful of files ends up with a bootstrap listing
 * every one of them by hand. This replaces that list with the directory.
 * Plugin's `includes` and `early_includes` use it for any entry that is a
 * folder.
 *
 *     Slurp::dir( __DIR__ . '/includes/utils' );
 *
 * The ordering is the point. Directory order is whatever the filesystem hands
 * back -- creation order on APFS, hashed elsewhere -- so a class that extends
 * another in the same folder loads cleanly on one machine and fatals on the
 * next. Files here are always loaded in name order, which you control.
 */
final class Slurp {

	/**
	 * Filenames never included.
	 *
	 * index.php is the silence-is-golden stub that sits in every directory to
	 * stop the server listing it. Including it is harmless but pointless.
	 *
	 * @var string[]
	 */
	public const array SKIP = [ 'index.php' ];

	/**
	 * Include every PHP file in a directory.
	 *
	 * Files are sorted by name before being required. Files are required, so
	 * anything with side effects at file scope runs at the moment this is
	 * called: declare classes and functions here, and hook from a bootstrap
	 * that runs afterwards.
	 *
	 * @param string $dir       Absolute path to the directory.
	 * @param bool   $recursive Descend into subdirectories. Each directory's
	 *                          own files are still loaded in name order, before
	 *                          its subdirectories.
	 *
	 * @return string[] Absolute paths of the files that were included, in order.
	 */
	public static function dir( string $dir, bool $recursive = false ): array {
		$loaded = [];

		foreach ( self::files( $dir, $recursive ) as $file ) {
			require_once $file;
			$loaded[] = $file;
		}

		return $loaded;
	}

	/**
	 * Include every PHP file in several directories, in the order given.
	 *
	 * The caller's order is kept -- schemas before tables before queries --
	 * and only each directory's own files are sorted.
	 *
	 * @param array<int, string> $dirs      Absolute paths.
	 * @param bool               $recursive Descend into subdirectories.
	 *
	 * @return string[] Absolute paths of the files that were included, in order.
	 */
	public static function dirs( array $dirs, bool $recursive = false ): array {
		$loaded = [];

		foreach ( $dirs as $dir ) {
			$loaded = array_merge( $loaded, self::dir( (string) $dir, $recursive ) );
		}

		return $loaded;
	}

	/**
	 * The PHP files a directory would contribute, sorted, without including them.
	 *
	 * Useful for asserting what a bootstrap will load without running it. Dot
	 * files and folders and `index.php` are skipped; a directory that is not
	 * there, or cannot be read, gives nothing rather than an error, so a
	 * bootstrap listing a folder a build might not ship does not fatal.
	 *
	 * @param string $dir       Absolute path to the directory.
	 * @param bool   $recursive Descend into subdirectories.
	 *
	 * @return string[] Absolute paths, sorted.
	 */
	public static function files( string $dir, bool $recursive = false ): array {
		$dir = rtrim( $dir, '/\\' );

		if ( '' === $dir || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return [];
		}

		/*
		 * scandir(), not glob(). glob() reads its pattern, and the directory
		 * is part of the pattern: a path with a bracket in it -- "Sites [old]"
		 * -- becomes a character class, matches nothing, and the plugin
		 * silently loads no files at all. scandir() takes the path literally.
		 */
		$entries = scandir( $dir, SCANDIR_SORT_NONE );

		if ( false === $entries ) {
			return [];
		}

		$files          = [];
		$subdirectories = [];

		foreach ( $entries as $entry ) {
			// Dot entries: '.', '..', and hidden files, which glob() also skipped.
			if ( '' === $entry || '.' === $entry[0] ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				$subdirectories[] = $path;
				continue;
			}

			if ( str_ends_with( $entry, '.php' ) && ! in_array( $entry, self::SKIP, true ) && is_file( $path ) ) {
				$files[] = $path;
			}
		}

		/*
		 * The ordering is the one guarantee this class makes, so it is stated
		 * rather than inherited from whatever the directory listing returns.
		 * scandir() is asked not to sort, so this is the only sort there is.
		 */
		sort( $files, SORT_STRING );

		if ( ! $recursive ) {
			return $files;
		}

		sort( $subdirectories, SORT_STRING );

		foreach ( $subdirectories as $subdirectory ) {
			$files = array_merge( $files, self::files( $subdirectory, true ) );
		}

		return $files;
	}
}
