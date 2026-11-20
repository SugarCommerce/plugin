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
use SugarCommerce\Plugin\Traits\Plugin\Loading;
use SugarCommerce\Plugin\Traits\Plugin\Presets;

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
 *
 * The EDD, WooCommerce and network presets are Traits/Plugin/Presets; what
 * runs once the requirements are met is Traits/Plugin/Loading.
 */
final class Plugin {

	use Presets;
	use Loading;

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
