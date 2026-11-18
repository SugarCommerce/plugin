<?php
/**
 * Plugin tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use SugarCommerce\Plugin\Plugin;

/**
 * When a plugin loads, what loading does, and what it does when it cannot.
 */
final class PluginTest extends PluginTestCase {

	// -- When --------------------------------------------------------------

	/**
	 * The check waits for plugins_loaded, at the priority asked, and the
	 * bootstrap for init.
	 */
	public function test_it_waits_for_plugins_loaded_and_boots_on_init(): void {
		$booted = 0;
		$plugin = Plugin::register( $this->plugin_file( 'waits' ), static function () use ( &$booted ) {
			++$booted;
		}, [ 'priority' => 42 ] );

		$this->assertFalse( $plugin->loaded() );
		$this->assertArrayHasKey( 42, $GLOBALS['pl']['hooks']['plugins_loaded'] );

		pl_fire( 'plugins_loaded' );

		$this->assertTrue( $plugin->loaded() );
		$this->assertSame( 0, $booted, 'It booted before init.' );
		$this->assertArrayHasKey( 0, $GLOBALS['pl']['hooks']['init'], 'The bootstrap is not at init 0.' );

		pl_fire( 'init' );

		$this->assertSame( 1, $booted );
	}

	/**
	 * Registered late -- during activation, from WP-CLI -- it runs now.
	 */
	public function test_registered_after_init_it_boots_at_once(): void {
		$GLOBALS['pl']['did'] = [
			'plugins_loaded' => 1,
			'init'           => 1,
		];

		$booted = false;

		Plugin::register( $this->plugin_file( 'late' ), static function () use ( &$booted ) {
			$booted = true;
		} );

		$this->assertTrue( $booted );
	}

	/**
	 * However often the check is asked for, it runs once.
	 */
	public function test_the_check_runs_once(): void {
		$booted = 0;
		$plugin = Plugin::register( $this->plugin_file( 'once' ), static function () use ( &$booted ) {
			++$booted;
		} );

		$plugin->run();
		$plugin->run();
		pl_fire( 'init' );

		$this->assertSame( 1, $booted );
	}

	/**
	 * Registering the same plugin twice sets it up once.
	 *
	 * The old constructor returned early on a duplicate, but returning from
	 * a constructor does not stop the object being made, and setup() ran
	 * anyway: everything was hooked twice.
	 */
	public function test_a_plugin_registered_twice_is_set_up_once(): void {
		$file  = $this->plugin_file( 'twice' );
		$first = Plugin::register( $file, static function () {} );

		$this->assertSame( $first, Plugin::register( $file, static function () {} ) );
		$this->assertSame( 2, $this->callbacks( 'plugins_loaded' ), 'Expected the text domain and the check, once each.' );
		$this->assertSame( $first, Plugin::get( $file ) );
	}

	public function test_a_plugin_without_a_file_is_refused(): void {
		$this->assertNull( Plugin::register( '', static function () {} ) );
		$this->assertSame( 0, $this->callbacks( 'plugins_loaded' ) );
	}

	// -- Unmet -------------------------------------------------------------

	/**
	 * Nothing of a plugin whose requirements fail is loaded.
	 */
	public function test_an_unmet_plugin_loads_nothing(): void {
		$booted = false;
		$file   = $this->plugin_file( 'unmet', [ 'inc/loaded.php' => '<?php $GLOBALS["pl"]["unmet_included"] = true;' ] );
		$plugin = Plugin::register( $file, static function () use ( &$booted ) {
			$booted = true;
		}, [
			'requirements' => [ 'php' => '99.0' ],
			'includes'     => [ 'inc/loaded.php' ],
			'activation'   => static function () {},
			'plugin_links' => [ 'settings' => 'https://example.test/settings' ],
			'notices'      => [ 'welcome' => [ 'message' => 'Hi' ] ],
		] );

		pl_fire( 'plugins_loaded' );
		pl_fire( 'init' );

		$this->assertFalse( $plugin->loaded() );
		$this->assertFalse( $booted );
		$this->assertArrayNotHasKey( 'unmet_included', $GLOBALS['pl'] );
		$this->assertSame( [], $GLOBALS['pl']['activation'] );
		$this->assertSame( 0, $this->callbacks( 'admin_notices' ) );
		$this->assertSame( 1, $this->callbacks( 'after_plugin_row_unmet/unmet.php' ), 'The plugins screen was not told.' );
		$this->assertSame( 1, $this->callbacks( 'plugin_action_links_unmet/unmet.php' ) );
	}

	// -- Loading -----------------------------------------------------------

	/**
	 * Files from the plugin's folder, and absolute ones before them.
	 */
	public function test_includes_are_loaded_in_order(): void {
		$GLOBALS['pl']['order'] = [];

		$file = $this->plugin_file(
			'includes',
			[
				'early.php'   => '<?php $GLOBALS["pl"]["order"][] = "early";',
				'inc/one.php' => '<?php $GLOBALS["pl"]["order"][] = "one";',
			]
		);

		Plugin::register( $file, static function () {}, [
			'early_includes' => [ dirname( $file ) . '/early.php', '/no/such/file.php', 42 ],
			'includes'       => [ '/inc/one.php', 'inc/missing.php' ],
		] );

		pl_fire( 'plugins_loaded' );

		$this->assertSame( [ 'early', 'one' ], $GLOBALS['pl']['order'] );
	}

	/**
	 * A folder entry loads its own files in name order, not its subfolders.
	 */
	public function test_an_includes_folder_is_loaded_file_by_file(): void {
		$GLOBALS['pl']['order'] = [];

		$file = $this->plugin_file(
			'includes-folder',
			[
				'early/b.php'         => '<?php $GLOBALS["pl"]["order"][] = "early-b";',
				'early/a.php'         => '<?php $GLOBALS["pl"]["order"][] = "early-a";',
				'inc/utils/zz.php'    => '<?php $GLOBALS["pl"]["order"][] = "zz";',
				'inc/utils/aa.php'    => '<?php $GLOBALS["pl"]["order"][] = "aa";',
				'inc/utils/index.php' => '<?php $GLOBALS["pl"]["order"][] = "index";',
				'inc/utils/sub/x.php' => '<?php $GLOBALS["pl"]["order"][] = "sub";',
				'inc/last.php'        => '<?php $GLOBALS["pl"]["order"][] = "last";',
			]
		);

		Plugin::register( $file, static function () {}, [
			'early_includes' => [ dirname( $file ) . '/early/' ],
			'includes'       => [ 'inc/utils/', 'inc/last.php', 'inc/no-such-folder/' ],
		] );

		pl_fire( 'plugins_loaded' );

		$this->assertSame( [ 'early-a', 'early-b', 'aa', 'zz', 'last' ], $GLOBALS['pl']['order'] );
	}

	public function test_constants_are_defined_from_a_prefix(): void {
		$file = $this->plugin_file( 'constants' );

		Plugin::register( $file, static function () {}, [ 'constants' => 'PL_ONE' ] );
		pl_fire( 'plugins_loaded' );

		$this->assertSame( '2.4.0', constant( 'PL_ONE_PLUGIN_VERSION' ), 'The version is the header\'s.' );
		$this->assertSame( $file, constant( 'PL_ONE_PLUGIN_FILE' ) );
		$this->assertSame( 'constants/constants.php', constant( 'PL_ONE_PLUGIN_BASE' ) );
		$this->assertSame( dirname( $file ) . '/', constant( 'PL_ONE_PLUGIN_DIR' ) );
		$this->assertSame( 'https://example.test/wp-content/plugins/constants/', constant( 'PL_ONE_PLUGIN_URL' ) );
		$this->assertSame( 9999999, constant( 'PL_ONE_PLUGIN_QUERY_LIMIT' ) );
	}

	public function test_constants_can_be_configured(): void {
		define( 'PL_TWO_PLUGIN_FILE', 'already' );

		Plugin::register( $this->plugin_file( 'constants-two' ), static function () {}, [
			'constants' => [
				'prefix'      => 'PL_TWO',
				'version'     => '9.9.9',
				'skip'        => [ 'QUERY_LIMIT', 'URL' ],
				'definitions' => [ 'PL_TWO_EXTRA' => 'extra' ],
			],
		] );
		pl_fire( 'plugins_loaded' );

		$this->assertSame( '9.9.9', constant( 'PL_TWO_PLUGIN_VERSION' ) );
		$this->assertSame( 'already', constant( 'PL_TWO_PLUGIN_FILE' ), 'A defined constant was redefined.' );
		$this->assertFalse( defined( 'PL_TWO_PLUGIN_QUERY_LIMIT' ) );
		$this->assertFalse( defined( 'PL_TWO_PLUGIN_URL' ) );
		$this->assertSame( 'extra', constant( 'PL_TWO_EXTRA' ) );
	}

	public function test_activation_deactivation_and_success(): void {
		$file    = $this->plugin_file( 'lifecycle' );
		$success = false;

		Plugin::register( $file, static function () {}, [
			'activation'   => [ self::class, 'noop' ],
			'deactivation' => [ self::class, 'noop' ],
			'success'      => static function () use ( &$success ) {
				$success = true;
			},
		] );
		pl_fire( 'plugins_loaded' );

		$this->assertTrue( $success );
		$this->assertSame( [ self::class, 'noop' ], $GLOBALS['pl']['activation']['activate_lifecycle/lifecycle.php'] );
		$this->assertSame( [ self::class, 'noop' ], $GLOBALS['pl']['activation']['deactivate_lifecycle/lifecycle.php'] );
	}

	/**
	 * Does nothing; a callable to register.
	 *
	 * @return void
	 */
	public static function noop(): void {}

	/**
	 * A setup hook that takes an argument is given the plugin file -- and
	 * that works for a method as well as a closure.
	 *
	 * ReflectionFunction, which the old class used to count the parameters,
	 * throws on [ $object, 'method' ].
	 */
	public function test_setup_hooks_are_given_the_file_when_they_ask(): void {
		$file     = $this->plugin_file( 'setup-hooks' );
		$receiver = new class() {
			/**
			 * What it was given.
			 *
			 * @var array<int, mixed>
			 */
			public array $calls = [];

			/**
			 * @param string $file The plugin file.
			 */
			public function with_file( string $file ): void {
				$this->calls[] = $file;
			}

			/**
			 * Takes nothing.
			 */
			public function bare(): void {
				$this->calls[] = 'bare';
			}
		};

		Plugin::register( $file, static function () {}, [
			'setup_hooks' => [
				'pl_one' => [ $receiver, 'with_file' ],
				'pl_two' => [ $receiver, 'bare' ],
				'pl_bad' => 'no_such_function',
			],
		] );
		pl_fire( 'plugins_loaded' );
		pl_fire( 'pl_one' );
		pl_fire( 'pl_two' );

		$this->assertSame( [ $file, 'bare' ], $receiver->calls );
		$this->assertSame( 0, $this->callbacks( 'pl_bad' ) );
	}

	// -- Text domains ------------------------------------------------------

	public function test_a_text_domain_is_loaded_from_the_languages_folder(): void {
		Plugin::register( $this->plugin_file( 'domain' ), static function () {}, [ 'textdomain' => 'my-domain' ] );
		pl_fire( 'plugins_loaded' );

		$this->assertContains( [ 'my-domain', 'domain/languages' ], $GLOBALS['pl']['textdomains'] );
		$this->assertContains( [ 'sugarcommerce', false ], $GLOBALS['pl']['textdomains'] );
	}

	public function test_a_text_domain_can_name_its_path_or_load_itself(): void {
		Plugin::register( $this->plugin_file( 'domain-path' ), static function () {}, [
			'textdomain' => [
				'domain' => 'mine',
				'path'   => '/i18n',
			],
		] );

		$given = null;
		$file  = $this->plugin_file( 'domain-callback' );

		Plugin::register( $file, static function () {}, [
			'textdomain' => [
				'domain'   => 'theirs',
				'callback' => static function ( string $dir ) use ( &$given ) {
					$given = $dir;
				},
			],
		] );
		pl_fire( 'plugins_loaded' );

		$this->assertContains( [ 'mine', 'domain-path/i18n' ], $GLOBALS['pl']['textdomains'] );
		$this->assertSame( dirname( $file ) . '/', $given );
	}

	// -- Ids ---------------------------------------------------------------

	public function test_the_id_is_the_folder_unless_named(): void {
		$this->assertSame( 'edd_sale_prices', Plugin::register( $this->plugin_file( 'edd-sale-prices' ), static function () {} )->id() );
		$this->assertSame( 'shop', Plugin::register( $this->plugin_file( 'named' ), static function () {}, [ 'id' => 'Shop' ] )->id() );
	}

	// -- Presets -----------------------------------------------------------

	public function test_the_edd_preset_takes_wordpress_from_the_header(): void {
		$GLOBALS['pl']['header'] = [
			'Version'    => '1.0.0',
			'RequiresWP' => '7.0',
		];

		$requirements = Plugin::edd( $this->plugin_file( 'edd-header' ), static function () {} )->config()['requirements'];

		$this->assertSame( '7.0', $requirements['wp'], 'The plugin header is the one source of the WordPress minimum.' );
		$this->assertSame( '8.3', $requirements['php'] );
	}

	public function test_the_edd_preset_falls_back_without_a_header_minimum(): void {
		$requirements = Plugin::edd( $this->plugin_file( 'edd-no-header' ), static function () {} )->config()['requirements'];

		$this->assertSame( '7.1', $requirements['wp'] );
	}

	public function test_the_edd_preset(): void {
		$plugin = Plugin::edd( $this->plugin_file( 'edd-thing' ), static function () {}, [ 'requirements' => [ 'wp' => '6.7.1' ] ] );
		$config = $plugin->config();

		$this->assertSame( 99, $config['priority'], 'It must check after EDD\'s extension loader at 98.' );
		$this->assertSame(
			[
				'php'                    => '8.3',
				'wp'                     => '6.7.1',
				'easy-digital-downloads' => '3.6.1',
			],
			$config['requirements']
		);
		$this->assertArrayHasKey( 99, $GLOBALS['pl']['hooks']['plugins_loaded'] );
	}

	public function test_the_woocommerce_preset_declares_compatibility_before_woocommerce_init(): void {
		$plugin = Plugin::woocommerce( $this->plugin_file( 'wc-thing' ), static function () {}, [ 'requirements' => [ 'woocommerce' => '' ] ] );

		$this->assertSame( 20, $plugin->config()['priority'] );
		$this->assertSame( [ 'custom_order_tables' ], $plugin->config()['setup_hooks']['woocommerce_compatibility']['features'] );
	}

	/**
	 * Declared inside before_woocommerce_init, hooked before it, and not at
	 * all after it: WooCommerce refuses anything but inside, and its refusal
	 * prints into the activation request and fails the activation.
	 */
	public function test_compatibility_is_declared_only_inside_the_action(): void {
		$declared = [];
		$config   = [
			'setup_hooks' => [
				'woocommerce_compatibility' => [
					'callback' => static function ( string $file ) use ( &$declared ) {
						$declared[] = basename( $file );
					},
				],
			],
		];

		// Before: hooked, and declared when the action runs.
		Plugin::register( $this->plugin_file( 'wc-before' ), static function () {}, $config );
		pl_fire( 'plugins_loaded' );

		$this->assertSame( [], $declared );

		pl_fire( 'before_woocommerce_init' );

		$this->assertSame( [ 'wc-before.php' ], $declared );

		// After: nothing to do in this request.
		Plugin::register( $this->plugin_file( 'wc-after' ), static function () {}, $config );

		$this->assertSame( [ 'wc-before.php' ], $declared );
		$this->assertSame( 1, $this->callbacks( 'before_woocommerce_init' ) );

		// Inside: at once.
		$GLOBALS['pl']['doing'][] = 'before_woocommerce_init';
		Plugin::register( $this->plugin_file( 'wc-inside' ), static function () {}, $config );

		$this->assertSame( [ 'wc-before.php', 'wc-inside.php' ], $declared );
	}

	public function test_the_network_preset(): void {
		$plugin = Plugin::network( $this->plugin_file( 'net' ), static function () {}, [
			'plugin_links' => [ 'settings' => 'https://example.test/s' ],
			'notices'      => [ 'hi' => [ 'message' => 'Hi' ] ],
		] );
		pl_fire( 'plugins_loaded' );

		$this->assertTrue( $plugin->config()['network'] );
		$this->assertSame( 1, $this->callbacks( 'network_admin_plugin_action_links_net/net.php' ) );
		$this->assertSame( 1, $this->callbacks( 'network_admin_notices' ) );
	}

	// -- merge_config ------------------------------------------------------

	public function test_a_caller_requirement_replaces_the_default_and_stays_a_string(): void {
		$merged = Plugin::merge_config( [ 'requirements' => [ 'php' => '7.4' ] ], [ 'requirements' => [ 'php' => '8.3' ] ] );

		$this->assertSame( '8.3', $merged['requirements']['php'] );
	}

	public function test_requirements_the_caller_did_not_mention_are_kept(): void {
		$merged = Plugin::merge_config(
			[ 'requirements' => [ 'php' => '7.4', 'wp' => '6.8.4' ] ],
			[ 'requirements' => [ 'php' => '8.3', 'edd-recurring' => '2.0' ] ]
		);

		$this->assertSame(
			[
				'php'           => '8.3',
				'wp'            => '6.8.4',
				'edd-recurring' => '2.0',
			],
			$merged['requirements']
		);
	}

	public function test_scalars_are_overridden_and_omissions_survive(): void {
		$merged = Plugin::merge_config(
			[ 'priority' => 99, 'textdomain' => 'x' ],
			[ 'priority' => 5, 'constants' => 'EDD_THING' ]
		);

		$this->assertSame( 5, $merged['priority'] );
		$this->assertSame( 'x', $merged['textdomain'] );
		$this->assertSame( 'EDD_THING', $merged['constants'] );
	}

	/**
	 * The floor a plugin declares over a preset's is the floor enforced.
	 */
	public function test_an_overridden_floor_is_enforced(): void {
		$plugin = Plugin::edd( $this->plugin_file( 'floor' ), static function () {}, [ 'requirements' => [ 'php' => '99.0', 'easy-digital-downloads' => '' ] ] );

		pl_fire( 'plugins_loaded' );

		$this->assertFalse( $plugin->loaded() );
		$this->assertContains( 'requirement_php', $plugin->requirements()->errors()->get_error_codes() );
	}
}
