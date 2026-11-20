<?php
/**
 * Assets tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Plugin\Assets;

/**
 * Finding a package's assets, and their URLs.
 *
 * Built against a real folder tree rather than mocks, because the whole
 * question is what the filesystem walk does when the tree is shaped awkwardly.
 */
final class AssetsTest extends TestCase {

	/**
	 * The host plugin.
	 *
	 * @var string
	 */
	private static string $root;

	/**
	 * A plugin with packages in vendor/, some with assets and some without.
	 */
	public static function setUpBeforeClass(): void {
		self::$root = WP_CONTENT_DIR . '/plugins/host-plugin';

		foreach ( [ '/assets/css', '/vendor/acme/with-assets/assets/js', '/vendor/acme/with-assets/assets/build', '/vendor/acme/with-assets/src/Deep', '/vendor/acme/no-assets/src', '/vendor/acme/not-a-package/src' ] as $dir ) {
			if ( ! is_dir( self::$root . $dir ) ) {
				mkdir( self::$root . $dir, 0777, true );
			}
		}

		$files = [
			'/composer.json'                                => '{"name":"acme/host-plugin"}',
			'/assets/css/host.css'                          => 'body{}',
			'/vendor/acme/with-assets/composer.json'        => '{"name":"acme/with-assets"}',
			'/vendor/acme/with-assets/assets/js/thing.js'   => '// x',
			'/vendor/acme/with-assets/assets/icon.svg'      => '<svg/>',
			'/vendor/acme/with-assets/assets/build/admin.js' => '(()=>{})();',
			'/vendor/acme/with-assets/assets/build/admin.asset.php' => "<?php return array('dependencies' => array('wp-api-fetch', 'wp-i18n'), 'version' => 'abc123');",
			'/vendor/acme/with-assets/assets/build/plain.js' => '(()=>{})();',
			'/vendor/acme/with-assets/assets/build/plain.asset.php' => "<?php return array('dependencies' => array(), 'version' => 'def456');",
			'/vendor/acme/with-assets/assets/build/orphan.js' => '(()=>{})();',
			'/vendor/acme/with-assets/assets/build/gone.asset.php' => "<?php return array('dependencies' => array('wp-i18n'), 'version' => '1');",
			'/vendor/acme/with-assets/src/Deep/Nested.php'  => '<?php',
			'/vendor/acme/no-assets/composer.json'          => '{"name":"acme/no-assets"}',
			'/vendor/acme/no-assets/src/Thing.php'          => '<?php',
			'/vendor/acme/not-a-package/src/Loose.php'      => '<?php',
		];

		foreach ( $files as $path => $contents ) {
			file_put_contents( self::$root . $path, $contents );
		}

		touch( self::$root . '/vendor/acme/with-assets/assets/js/thing.js', 1700000000 );
	}

	/**
	 * Nothing remembered between tests.
	 */
	protected function setUp(): void {
		pl_reset();
		Assets::clear_cache();
	}

	/**
	 * A file in the package with assets.
	 *
	 * @return string
	 */
	private static function nested(): string {
		return self::$root . '/vendor/acme/with-assets/src/Deep/Nested.php';
	}

	/**
	 * A package that lives outside WordPress altogether, the way a symlinked
	 * plugin's real path does.
	 *
	 * @return string
	 */
	private static function elsewhere(): string {
		$root = sys_get_temp_dir() . '/sugarcommerce-plugin-elsewhere/real-plugin';

		if ( ! is_dir( $root . '/assets/css' ) ) {
			mkdir( $root . '/assets/css', 0777, true );
		}

		file_put_contents( $root . '/composer.json', '{"name":"acme/real-plugin"}' );
		file_put_contents( $root . '/assets/css/real.css', 'body{}' );
		file_put_contents( $root . '/real-plugin.php', '<?php' );

		return $root;
	}

	public function test_a_handle_is_named_for_the_copy_of_the_package(): void {
		$this->assertSame( 'sugarcommerce-breadcrumbs', Assets::handle( 'SugarCommerce\\Breadcrumbs', 'breadcrumbs' ) );
		$this->assertSame( 'myshop-vendor-sugarcommerce-status-badge', Assets::handle( 'MyShop\\Vendor\\SugarCommerce\\StatusBadge', 'status-badge' ) );
		$this->assertSame( 'playground-lib-breadcrumbs', Assets::handle( 'Playground\\Lib\\Breadcrumbs', 'breadcrumbs' ) );
		$this->assertSame( 'breadcrumbs', Assets::handle( 'Breadcrumbs', 'Breadcrumbs' ) );
	}

	public function test_a_packages_own_assets_folder_is_found(): void {
		$this->assertSame(
			[
				'path' => self::$root . '/vendor/acme/with-assets/assets',
				'url'  => 'https://example.test/wp-content/plugins/host-plugin/vendor/acme/with-assets/assets',
			],
			Assets::locate( self::nested() )
		);
	}

	/**
	 * The reason for walking up to composer.json.
	 *
	 * The old lookup tried a fixed list of relative paths and took the first
	 * folder named "assets", so a package with none of its own climbed past
	 * vendor/ and served the host plugin's.
	 */
	public function test_a_package_without_assets_does_not_borrow_the_hosts(): void {
		$this->assertNull( Assets::locate( self::$root . '/vendor/acme/no-assets/src/Thing.php' ) );
	}

	public function test_a_folder_without_composer_json_belongs_to_the_package_above(): void {
		$this->assertSame( self::$root . '/assets', Assets::locate( self::$root . '/vendor/acme/not-a-package/src/Loose.php' )['path'] );
	}

	public function test_a_files_path_and_url(): void {
		$asset = Assets::resolve( self::nested(), 'js/thing.js' );

		$this->assertSame( self::$root . '/vendor/acme/with-assets/assets/js/thing.js', $asset['file_path'] );
		$this->assertSame( 'https://example.test/wp-content/plugins/host-plugin/vendor/acme/with-assets/assets/js/thing.js', $asset['file_url'] );
		$this->assertSame( $asset['file_url'], Assets::url( self::nested(), '/js/thing.js' ) );
		$this->assertSame( $asset['file_path'], Assets::path( self::nested(), 'js/thing.js' ) );
		$this->assertSame( '<svg/>', Assets::contents( self::nested(), 'icon.svg' ) );
	}

	public function test_a_missing_file_is_nothing(): void {
		$this->assertNull( Assets::resolve( self::nested(), 'js/missing.js' ) );
		$this->assertNull( Assets::url( self::nested(), 'js/missing.js' ) );
		$this->assertFalse( Assets::contents( self::nested(), 'js/missing.js' ) );
		$this->assertFalse( Assets::enqueue_script( 'missing', self::nested(), 'js/missing.js' ) );
		$this->assertSame( [], $GLOBALS['pl']['scripts'] );
	}

	/**
	 * A path that climbs out of the assets folder is refused. composer.json
	 * one level up does exist, so a null here is the guard, not a miss.
	 */
	public function test_a_path_out_of_the_assets_folder_is_refused(): void {
		$this->assertNull( Assets::resolve( self::nested(), '../composer.json' ) );
		$this->assertNull( Assets::resolve( self::nested(), 'js/../../composer.json' ) );
		$this->assertNull( Assets::resolve( self::nested(), "js/thing.js\0" ) );
		$this->assertFalse( Assets::contents( self::nested(), '..\\composer.json' ) );
	}

	public function test_misses_are_remembered_until_cleared(): void {
		$file = self::$root . '/vendor/acme/later/src/Thing.php';

		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}

		if ( is_dir( self::$root . '/vendor/acme/later/assets' ) ) {
			rmdir( self::$root . '/vendor/acme/later/assets' );
		}

		file_put_contents( self::$root . '/vendor/acme/later/composer.json', '{}' );

		$this->assertNull( Assets::locate( $file ) );

		mkdir( self::$root . '/vendor/acme/later/assets' );

		$this->assertNull( Assets::locate( $file ), 'The miss was not remembered.' );

		Assets::clear_cache();

		$this->assertNotNull( Assets::locate( $file ) );
	}

	public function test_a_path_outside_wordpress_has_no_url(): void {
		$this->assertNull( Assets::locate( self::elsewhere() . '/real-plugin.php' ) );
		$this->assertNull( Assets::path_to_url( '/nowhere/at/all' ) );
	}

	/**
	 * A symlinked plugin is found through core's real-path map.
	 *
	 * __FILE__ resolves symlinks, so a plugin symlinked into wp-content
	 * reports a real path nowhere under WordPress. Core keeps a map from the
	 * path under wp-content to the real one -- plugins_url() reads it -- and
	 * the same map answers here.
	 */
	public function test_a_symlinked_plugin_resolves_through_the_map(): void {
		$real = self::elsewhere();

		$GLOBALS['wp_plugin_paths'] = [ WP_CONTENT_DIR . '/plugins/linked-plugin' => $real ];

		$this->assertSame(
			[
				'path' => $real . '/assets',
				'url'  => 'https://example.test/wp-content/plugins/linked-plugin/assets',
			],
			Assets::locate( $real . '/real-plugin.php' )
		);
	}

	/**
	 * Only the leading content folder is replaced. str_replace() rewrote
	 * every occurrence, so a path in which it recurred came out wrong.
	 */
	public function test_the_url_replaces_the_prefix_only(): void {
		$path = WP_CONTENT_DIR . '/plugins/x' . WP_CONTENT_DIR . '/file.css';

		$this->assertSame( 'https://example.test/wp-content/plugins/x' . WP_CONTENT_DIR . '/file.css', Assets::path_to_url( $path ) );
	}

	public function test_a_path_under_the_wordpress_root(): void {
		$this->assertSame( 'https://example.test/wp-includes/js/x.js', Assets::path_to_url( ABSPATH . 'wp-includes/js/x.js' ) );
	}

	public function test_a_script_is_registered_and_enqueued(): void {
		$this->assertTrue( Assets::enqueue_script( 'thing', self::nested(), 'js/thing.js', [ 'jquery' ] ) );

		$this->assertSame(
			[
				'src'  => 'https://example.test/wp-content/plugins/host-plugin/vendor/acme/with-assets/assets/js/thing.js',
				'deps' => [ 'jquery' ],
				'ver'  => '1700000000',
				'args' => [ 'in_footer' => true ],
			],
			$GLOBALS['pl']['scripts']['thing']
		);
		$this->assertSame( [ 'script:thing' ], $GLOBALS['pl']['enqueued'] );
	}

	/**
	 * A strategy WordPress knows is passed on; one it does not is dropped,
	 * since WordPress answers it with a notice on every page.
	 */
	public function test_script_arguments(): void {
		Assets::register_script( 'a', self::nested(), 'js/thing.js', [], '1.2', false );
		Assets::register_script( 'b', self::nested(), 'js/thing.js', [], null, [ 'strategy' => 'defer', 'in_footer' => false ] );
		Assets::register_script( 'c', self::nested(), 'js/thing.js', [], '1', [ 'strategy' => 'eager' ] );

		$this->assertSame( [ 'in_footer' => false ], $GLOBALS['pl']['scripts']['a']['args'] );
		$this->assertSame( '1.2', $GLOBALS['pl']['scripts']['a']['ver'] );
		$this->assertSame( [ 'in_footer' => false, 'strategy' => 'defer' ], $GLOBALS['pl']['scripts']['b']['args'] );
		$this->assertNull( $GLOBALS['pl']['scripts']['b']['ver'], 'A null version (no query string) was replaced.' );
		$this->assertSame( [ 'in_footer' => true ], $GLOBALS['pl']['scripts']['c']['args'] );
		$this->assertSame( [], $GLOBALS['pl']['enqueued'] );
	}

	public function test_a_style_is_registered_and_enqueued(): void {
		$this->assertTrue( Assets::enqueue_style( 'host', self::$root . '/host-plugin.php', 'css/host.css', [], '3', 'print' ) );

		$this->assertSame( 'https://example.test/wp-content/plugins/host-plugin/assets/css/host.css', $GLOBALS['pl']['styles']['host']['src'] );
		$this->assertSame( 'print', $GLOBALS['pl']['styles']['host']['media'] );
		$this->assertSame( [ 'style:host' ], $GLOBALS['pl']['enqueued'] );
	}

	/**
	 * A built entry takes its dependencies and version from the manifest
	 * @wordpress/scripts wrote beside it, adds what it needs without
	 * importing, and is translated when it imports @wordpress/i18n.
	 */
	public function test_a_built_entry_is_registered_from_its_manifest(): void {
		$this->assertSame(
			[
				'dependencies' => [ 'wp-api-fetch', 'wp-i18n' ],
				'version'      => 'abc123',
			],
			Assets::built_manifest( self::nested(), 'admin' )
		);

		$this->assertTrue( Assets::enqueue_built_script( 'lib-admin', self::nested(), 'admin', [ 'lib-config', 'wp-i18n' ] ) );

		$script = $GLOBALS['pl']['scripts']['lib-admin'];
		$this->assertSame( 'https://example.test/wp-content/plugins/host-plugin/vendor/acme/with-assets/assets/build/admin.js', $script['src'] );
		$this->assertSame( [ 'wp-api-fetch', 'wp-i18n', 'lib-config' ], $script['deps'] );
		$this->assertSame( 'abc123', $script['ver'] );
		$this->assertSame( [ 'in_footer' => true ], $script['args'] );
		$this->assertSame( [ 'script:lib-admin' ], $GLOBALS['pl']['enqueued'] );
		$this->assertSame( [ 'lib-admin' => 'sugarcommerce' ], $GLOBALS['pl']['i18n'] );
	}

	public function test_a_built_entry_without_i18n_is_registered_only(): void {
		$this->assertTrue( Assets::register_built_script( 'lib-plain', self::nested(), 'plain', [], [ 'strategy' => 'defer' ] ) );

		$this->assertSame( [], $GLOBALS['pl']['scripts']['lib-plain']['deps'] );
		$this->assertSame( 'def456', $GLOBALS['pl']['scripts']['lib-plain']['ver'] );
		$this->assertSame( [ 'in_footer' => true, 'strategy' => 'defer' ], $GLOBALS['pl']['scripts']['lib-plain']['args'] );
		$this->assertSame( [], $GLOBALS['pl']['enqueued'] );
		$this->assertSame( [], $GLOBALS['pl']['i18n'] );
	}

	/**
	 * No manifest is no build: nothing is registered, rather than a script
	 * with no dependencies that runs before what it imports.
	 */
	public function test_an_entry_never_built_is_not_registered(): void {
		$this->assertNull( Assets::built_manifest( self::nested(), 'orphan' ) );
		$this->assertNull( Assets::built_manifest( self::nested(), 'missing' ) );
		$this->assertFalse( Assets::enqueue_built_script( 'lib-orphan', self::nested(), 'orphan' ) );
		$this->assertFalse( Assets::enqueue_built_script( 'lib-missing', self::nested(), '../../../composer' ) );
		$this->assertFalse( Assets::enqueue_built_script( 'lib-gone', self::nested(), 'gone' ), 'A manifest whose script is missing was registered.' );
		$this->assertSame( [], $GLOBALS['pl']['i18n'] );
		$this->assertSame( [], $GLOBALS['pl']['scripts'] );
		$this->assertSame( [], $GLOBALS['pl']['enqueued'] );
	}
}
