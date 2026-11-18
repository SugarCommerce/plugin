<?php
/**
 * Plugin
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;

/**
 * Boot a plugin only when what it needs is there, and say so when it is not.
 *
 * Every plugin has requirements -- a PHP version, a WordPress version, the
 * plugin it extends -- and the usual handling is a version_compare() at the
 * top of the main file, or nothing. Without it, a plugin installed on an old
 * PHP fatals on activation and takes the site with it.
 *
 * This wraps the bootstrap. Requirements are checked on `plugins_loaded`,
 * once every plugin's constants exist; if they hold, the plugin's files,
 * constants, links and notices are set up and its bootstrap runs on
 * `init`. If not, nothing of the plugin loads, its row on the plugins screen
 * turns red, and the row says which requirement failed.
 *
 * Like Requirements, this file parses on PHP 7.4, because it runs before
 * the PHP version is known to be enough. What only runs once it is
 * (Links, Notices, Assets) is ordinary PHP 8.3.
 */
final class Plugin {

	/**
	 * Registrations, by plugin basename.
	 *
	 * @var array<string, Plugin>
	 */
	private static array $registered = [];

	/**
	 * The plugin's main file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * `folder/file.php`.
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * The plugin's folder, with a trailing slash.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * The id everything stored or hooked is named from.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * What runs once the requirements are met.
	 *
	 * @var callable
	 */
	private $bootstrap;

	/**
	 * The configuration, with defaults filled in.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * The requirements, once checked.
	 *
	 * @var Requirements|null
	 */
	private ?Requirements $requirements = null;

	/**
	 * Whether the check has run.
	 *
	 * @var bool
	 */
	private bool $ran = false;

	/**
	 * Whether the requirements held and the plugin loaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Register a plugin.
	 *
	 *     Plugin::register( __FILE__, static function () {
	 *         MyPlugin::init();
	 *     }, [
	 *         'requirements' => [ 'php' => '8.3', 'wp' => '7.1' ],
	 *     ] );
	 *
	 * Registering the same file twice hands back the first registration and
	 * sets nothing up again.
	 *
	 * @param string               $file      The plugin's main file (__FILE__).
	 * @param callable             $bootstrap What starts the plugin.
	 * @param array<string, mixed> $config    See the README.
	 *
	 * @return Plugin|null Null when the file is empty.
	 */
	public static function register( string $file, callable $bootstrap, array $config = [] ): ?self {
		$config['file']      = $file;
		$config['bootstrap'] = $bootstrap;

		try {
			$plugin = new self( $config );
		} catch ( InvalidArgumentException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a developer's mistake, reported where developers look.
				error_log( 'SugarCommerce\Plugin: ' . $e->getMessage() );
			}

			return null;
		}

		if ( isset( self::$registered[ $plugin->basename ] ) ) {
			return self::$registered[ $plugin->basename ];
		}

		self::$registered[ $plugin->basename ] = $plugin;

		$plugin->setup();

		return $plugin;
	}

	/**
	 * Register an Easy Digital Downloads extension.
	 *
	 * Requires EDD 3.6.1, and checks at `plugins_loaded` 99, after EDD's own
	 * extension loader (98).
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
						'wp'                     => '7.1',
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
	 * The registration for a plugin file, if there is one.
	 *
	 * @param string $file The plugin's main file.
	 *
	 * @return Plugin|null
	 */
	public static function get( string $file ): ?self {
		return self::$registered[ plugin_basename( $file ) ] ?? null;
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

	/**
	 * Build a registration. register() is the usual way in.
	 *
	 * @param array<string, mixed> $config Needs `file` and `bootstrap`.
	 *
	 * @throws InvalidArgumentException Without a file or a callable bootstrap.
	 */
	public function __construct( array $config ) {
		if ( empty( $config['file'] ) || ! isset( $config['bootstrap'] ) || ! is_callable( $config['bootstrap'] ) ) {
			throw new InvalidArgumentException( 'Invalid configuration: file and bootstrap are required.' );
		}

		$this->file      = (string) $config['file'];
		$this->basename  = plugin_basename( $this->file );
		$this->dir       = plugin_dir_path( $this->file );
		$this->bootstrap = $config['bootstrap'];

		$this->config = array_merge(
			[
				'id'               => '',
				'priority'         => 10,
				'requirements'     => [],
				'conflicts'        => [],
				'early_includes'   => [],
				'includes'         => [],
				'constants'        => null,
				'textdomain'       => null,
				'setup_hooks'      => [],
				'activation'       => null,
				'deactivation'     => null,
				'success'          => null,
				'requirements_url' => '',
				'support_url'      => '',
				'error_message'    => null,
				'css_class_prefix' => 'sugarcommerce',
				'plugin_links'     => [],
				'plugin_meta'      => [],
				'notices'          => [],
				'network'          => false,
			],
			$config
		);

		$this->id = '' !== (string) $this->config['id']
			? sanitize_key( (string) $this->config['id'] )
			: self::id_from( $this->basename );
	}

	/**
	 * The id a plugin gets when it does not name one.
	 *
	 * Its folder, with underscores: `edd-sale-prices/edd-sale-prices.php` is
	 * `edd_sale_prices`. A plugin that is a single file in the plugins folder
	 * is named from the file.
	 *
	 * @param string $basename `folder/file.php`.
	 *
	 * @return string
	 */
	private static function id_from( string $basename ): string {
		$folder = dirname( $basename );
		$name   = '.' === $folder || '' === $folder ? basename( $basename, '.php' ) : $folder;

		return sanitize_key( str_replace( '-', '_', $name ) );
	}

	/**
	 * Hook the check onto `plugins_loaded`, or run it now if that has passed.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ], 1 );

		// Every plugin's constants exist by plugins_loaded, which is what
		// most requirement checks read. A plugin registered after it -- during
		// activation, from WP-CLI -- is checked straight away.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->run();
		} else {
			add_action( 'plugins_loaded', [ $this, 'run' ], (int) $this->config['priority'] );
		}
	}

	/**
	 * Check the requirements, then load the plugin or explain why not.
	 *
	 * Runs once, however often it is called.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( $this->ran ) {
			return;
		}

		$this->ran = true;

		if ( $this->requirements()->met() ) {
			$this->load();
		} else {
			( new Unmet( $this ) )->hook();
		}
	}

	/**
	 * The requirements and conflicts.
	 *
	 * @return Requirements
	 */
	public function requirements(): Requirements {
		if ( null === $this->requirements ) {
			$this->requirements = new Requirements( (array) $this->config['requirements'], (array) $this->config['conflicts'] );
		}

		return $this->requirements;
	}

	/**
	 * Whether the requirements held and the plugin loaded.
	 *
	 * @return bool
	 */
	public function loaded(): bool {
		return $this->loaded;
	}

	/**
	 * The plugin's main file.
	 *
	 * @return string
	 */
	public function file(): string {
		return $this->file;
	}

	/**
	 * `folder/file.php`.
	 *
	 * @return string
	 */
	public function basename(): string {
		return $this->basename;
	}

	/**
	 * The id everything stored or hooked is named from.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The configuration, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return $this->config;
	}

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

	/**
	 * Hook each `setup_hooks` callback onto its action.
	 *
	 * A callback that takes an argument is given the plugin's main file.
	 * `woocommerce_compatibility` is not an action but a declaration.
	 *
	 * @return void
	 */
	private function setup_hooks(): void {
		foreach ( (array) $this->config['setup_hooks'] as $hook => $callback ) {
			if ( 'woocommerce_compatibility' === $hook && is_array( $callback ) ) {
				$this->woocommerce_compatibility( $callback );

				continue;
			}

			if ( ! is_callable( $callback ) ) {
				continue;
			}

			// Through a Closure, so the parameter count can be read from any
			// callable. ReflectionFunction alone throws on [ $object, 'method' ],
			// which took the whole plugin down with it.
			$closure = Closure::fromCallable( $callback );
			$file    = $this->file;

			if ( ( new ReflectionFunction( $closure ) )->getNumberOfParameters() > 0 ) {
				add_action(
					(string) $hook,
					static function () use ( $closure, $file ) {
						$closure( $file );
					}
				);
			} else {
				add_action( (string) $hook, $callback );
			}
		}
	}

	/**
	 * Declare the plugin's compatibility with WooCommerce features.
	 *
	 * @param array<string, mixed> $config features, compatible, and a callback given the plugin file.
	 *
	 * @return void
	 */
	private function woocommerce_compatibility( array $config ): void {
		$file    = $this->file;
		$declare = static function () use ( $config, $file ) {
			if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				return;
			}

			foreach ( (array) ( $config['features'] ?? [ 'custom_order_tables' ] ) as $feature ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( (string) $feature, $file, (bool) ( $config['compatible'] ?? true ) );
			}

			if ( isset( $config['callback'] ) && is_callable( $config['callback'] ) ) {
				call_user_func( $config['callback'], $file );
			}
		};

		// WooCommerce accepts a declaration only from inside the
		// before_woocommerce_init action: FeaturesController checks
		// doing_action(), not did_action(), and answers anything else with a
		// doing_it_wrong notice and a refusal. Calling it after the action had
		// passed -- the normal case when a plugin is registered during
		// activation, from WP-CLI or from the admin sandbox -- printed that
		// notice into the activation request, and WordPress then refused to
		// activate the plugin for having generated unexpected output.
		//
		// When the action has already run there is nothing useful to do in
		// this request. The next one loads the plugin at plugins_loaded, well
		// before the action, and declares normally.
		if ( doing_action( 'before_woocommerce_init' ) ) {
			$declare();
		} elseif ( ! did_action( 'before_woocommerce_init' ) ) {
			add_action( 'before_woocommerce_init', $declare );
		}
	}

	/**
	 * Forget every registration. For tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$registered = [];
	}
}
