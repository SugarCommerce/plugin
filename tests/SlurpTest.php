<?php
/**
 * Slurp tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use SugarCommerce\Plugin\Slurp;
use PHPUnit\Framework\TestCase;

/**
 * Covers Slurp.
 */
final class SlurpTest extends TestCase {

	/**
	 * Root of the throwaway tree each test builds.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/sugarcommerce-slurp-' . getmypid() . '-' . uniqid();
		mkdir( $this->root, 0777, true );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->rmdir( $this->root );
		parent::tearDown();
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private function rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rmdir( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}

	/**
	 * Write a PHP file that records the order it was loaded in.
	 *
	 * @param string $relative Path relative to the root.
	 *
	 * @return string Absolute path.
	 */
	private function file( string $relative ): string {
		$path = $this->root . '/' . $relative;
		@mkdir( dirname( $path ), 0777, true );
		file_put_contents( $path, "<?php\n\$GLOBALS['slurp_order'][] = " . var_export( $relative, true ) . ";\n" );

		return $path;
	}

	/**
	 * Files load in name order, whatever order the filesystem returns them in.
	 *
	 * This is the reason the library exists. DirectoryIterator and glob() hand
	 * back whatever the filesystem holds -- creation order on APFS, hashed
	 * elsewhere -- so a class extending another in the same directory loads
	 * cleanly on one machine and fatals on the next. Creating these in reverse
	 * makes the difference visible.
	 *
	 * @return void
	 */
	public function test_files_load_in_name_order(): void {
		foreach ( [ 'zebra.php', 'mango.php', 'apple.php' ] as $name ) {
			$this->file( $name );
		}

		$GLOBALS['slurp_order'] = [];
		Slurp::dir( $this->root );

		$this->assertSame( [ 'apple.php', 'mango.php', 'zebra.php' ], $GLOBALS['slurp_order'] );
	}

	/**
	 * Sorting is by string, so numeric prefixes order the way they are written.
	 *
	 * @return void
	 */
	public function test_numeric_prefixes_sort_as_written(): void {
		foreach ( [ '20-late.php', '10-early.php', '02-first.php' ] as $name ) {
			$this->file( $name );
		}

		$this->assertSame(
			[ '02-first.php', '10-early.php', '20-late.php' ],
			array_map( 'basename', Slurp::files( $this->root ) )
		);
	}

	/**
	 * index.php is skipped.
	 *
	 * @return void
	 */
	public function test_index_is_skipped(): void {
		$this->file( 'index.php' );
		$this->file( 'real.php' );

		$this->assertSame( [ 'real.php' ], array_map( 'basename', Slurp::files( $this->root ) ) );
	}

	/**
	 * Only PHP files are considered.
	 *
	 * @return void
	 */
	public function test_non_php_files_are_ignored(): void {
		$this->file( 'code.php' );
		file_put_contents( $this->root . '/notes.txt', 'hello' );
		file_put_contents( $this->root . '/style.css', 'body{}' );

		$this->assertSame( [ 'code.php' ], array_map( 'basename', Slurp::files( $this->root ) ) );
	}

	/**
	 * Subdirectories are left alone unless asked for.
	 *
	 * @return void
	 */
	public function test_not_recursive_by_default(): void {
		$this->file( 'top.php' );
		$this->file( 'nested/deep.php' );

		$this->assertSame( [ 'top.php' ], array_map( 'basename', Slurp::files( $this->root ) ) );
	}

	/**
	 * Recursing takes a directory's own files before descending, both sorted.
	 *
	 * @return void
	 */
	public function test_recursive_order(): void {
		$this->file( 'b-top.php' );
		$this->file( 'a-top.php' );
		$this->file( 'zebra/z-one.php' );
		$this->file( 'alpha/a-one.php' );

		$GLOBALS['slurp_order'] = [];
		Slurp::dir( $this->root, true );

		$this->assertSame(
			[ 'a-top.php', 'b-top.php', 'alpha/a-one.php', 'zebra/z-one.php' ],
			$GLOBALS['slurp_order']
		);
	}

	/**
	 * A directory that is not there is not an error.
	 *
	 * A bootstrap listing directories that a build might not ship should not
	 * fatal over one of them being absent.
	 *
	 * @return void
	 */
	public function test_missing_directory_is_not_an_error(): void {
		$this->assertSame( [], Slurp::dir( $this->root . '/no-such-directory' ) );
		$this->assertSame( [], Slurp::files( $this->root . '/no-such-directory' ) );
		$this->assertSame( [], Slurp::dir( '' ) );
	}

	/**
	 * An empty directory yields nothing.
	 *
	 * @return void
	 */
	public function test_empty_directory(): void {
		$this->assertSame( [], Slurp::dir( $this->root ) );
	}

	/**
	 * A trailing slash makes no difference.
	 *
	 * @return void
	 */
	public function test_trailing_slash_is_tolerated(): void {
		$this->file( 'one.php' );

		$this->assertSame(
			Slurp::files( $this->root ),
			Slurp::files( $this->root . '/' )
		);
	}

	/**
	 * dir() returns the paths it included, in order.
	 *
	 * @return void
	 */
	public function test_returns_loaded_paths(): void {
		$this->file( 'b.php' );
		$this->file( 'a.php' );

		$loaded = Slurp::dir( $this->root );

		$this->assertSame( [ 'a.php', 'b.php' ], array_map( 'basename', $loaded ) );
		$this->assertTrue( file_exists( $loaded[0] ) );
	}

	/**
	 * A file is never included twice.
	 *
	 * Bootstraps get called more than once in tests and on some CLI paths, and
	 * a second require of a file declaring a class is a fatal.
	 *
	 * @return void
	 */
	public function test_files_are_only_included_once(): void {
		$this->file( 'once.php' );

		$GLOBALS['slurp_order'] = [];
		Slurp::dir( $this->root );
		Slurp::dir( $this->root );

		$this->assertSame( [ 'once.php' ], $GLOBALS['slurp_order'] );
	}

	/**
	 * dirs() loads each directory in the order given, not sorted between them.
	 *
	 * The caller's order is deliberate -- schemas before tables before queries --
	 * so it must survive.
	 *
	 * @return void
	 */
	public function test_dirs_keeps_the_callers_order(): void {
		$this->file( 'zzz/late.php' );
		$this->file( 'aaa/early.php' );

		$GLOBALS['slurp_order'] = [];
		Slurp::dirs( [ $this->root . '/zzz', $this->root . '/aaa' ] );

		$this->assertSame( [ 'zzz/late.php', 'aaa/early.php' ], $GLOBALS['slurp_order'] );
	}

	/**
	 * dirs() skips a missing directory and carries on.
	 *
	 * @return void
	 */
	public function test_dirs_tolerates_a_missing_directory(): void {
		$this->file( 'real/there.php' );

		$loaded = Slurp::dirs( [ $this->root . '/absent', $this->root . '/real' ] );

		$this->assertSame( [ 'there.php' ], array_map( 'basename', $loaded ) );
	}

	/**
	 * files() reports without including, so a bootstrap can be asserted on.
	 *
	 * @return void
	 */
	public function test_files_does_not_include(): void {
		$this->file( 'inert.php' );

		$GLOBALS['slurp_order'] = [];
		$found                  = Slurp::files( $this->root );

		$this->assertCount( 1, $found );
		$this->assertSame( [], $GLOBALS['slurp_order'] );
	}

	/**
	 * A directory whose path contains glob characters is still read.
	 *
	 * glob() reads its pattern, and the directory is part of the pattern. A
	 * path with a bracket in it -- "Sites [old]" -- became a character class,
	 * matched nothing, and the plugin silently loaded no files at all.
	 *
	 * @return void
	 */
	public function test_a_path_with_glob_characters_is_read(): void {
		$this->file( 'weird [dir]/one.php' );
		$this->file( 'weird [dir]/sub*/two.php' );

		$this->assertSame(
			[ 'one.php', 'two.php' ],
			array_map( 'basename', Slurp::files( $this->root . '/weird [dir]', true ) )
		);
	}

	/**
	 * A directory named like a PHP file is not a PHP file.
	 *
	 * @return void
	 */
	public function test_a_directory_named_like_a_php_file_is_ignored(): void {
		mkdir( $this->root . '/trap.php' );
		$this->file( 'real.php' );

		$this->assertSame( [ 'real.php' ], array_map( 'basename', Slurp::files( $this->root ) ) );
	}

	/**
	 * Hidden files and directories are skipped.
	 *
	 * @return void
	 */
	public function test_hidden_entries_are_skipped(): void {
		$this->file( '.hidden.php' );
		$this->file( '.git/hook.php' );
		$this->file( 'shown.php' );

		$this->assertSame( [ 'shown.php' ], array_map( 'basename', Slurp::files( $this->root, true ) ) );
	}

	/**
	 * Within each directory the files come before its subdirectories, at
	 * every depth.
	 *
	 * @return void
	 */
	public function test_subdirectories_come_after_files_at_every_depth(): void {
		$this->file( 'a/z.php' );
		$this->file( 'a/a/one.php' );
		$this->file( 'z.php' );

		$this->assertSame(
			[ 'z.php', 'z.php', 'one.php' ],
			array_map( 'basename', Slurp::files( $this->root, true ) )
		);
	}

	/**
	 * Only a file named exactly index.php is skipped.
	 *
	 * @return void
	 */
	public function test_only_index_php_itself_is_skipped(): void {
		$this->file( 'index.php' );
		$this->file( 'nested/index.php' );
		$this->file( 'my-index.php' );

		$this->assertSame( [ 'my-index.php' ], array_map( 'basename', Slurp::files( $this->root, true ) ) );
	}
}
