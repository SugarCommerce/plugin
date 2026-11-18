<?php
/**
 * A plugin on disk, and a clean WordPress, for each test.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Plugin\Plugin;

/**
 * Each test gets fresh hooks, options and registrations, and can make a
 * plugin folder under the stubbed wp-content to register.
 */
abstract class PluginTestCase extends TestCase {

	/**
	 * A clean WordPress.
	 */
	protected function setUp(): void {
		parent::setUp();

		pl_reset();
		Plugin::reset();
	}

	/**
	 * Make a plugin folder and its main file, and return the file.
	 *
	 * @param string                $folder The folder, which is also the file's name.
	 * @param array<string, string> $files  Other files, by path within the folder.
	 *
	 * @return string
	 */
	protected function plugin_file( string $folder, array $files = [] ): string {
		$dir = WP_CONTENT_DIR . '/plugins/' . $folder;

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		file_put_contents( $dir . '/' . $folder . '.php', "<?php\n/* Plugin Name: {$folder} */\n" );

		foreach ( $files as $path => $contents ) {
			if ( ! is_dir( dirname( $dir . '/' . $path ) ) ) {
				mkdir( dirname( $dir . '/' . $path ), 0777, true );
			}

			file_put_contents( $dir . '/' . $path, $contents );
		}

		return $dir . '/' . $folder . '.php';
	}

	/**
	 * How many callbacks a hook has.
	 *
	 * @param string $hook The hook.
	 *
	 * @return int
	 */
	protected function callbacks( string $hook ): int {
		$count = 0;

		foreach ( $GLOBALS['pl']['hooks'][ $hook ] ?? [] as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}
}
