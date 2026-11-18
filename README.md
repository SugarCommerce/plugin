# Plugin

Boot a WordPress plugin only when what it needs is there, say plainly when it
is not, and enqueue the CSS and JavaScript a Composer package ships.

## What it does

Every plugin has requirements -- a PHP version, a WordPress version, the
plugin it extends -- and the usual handling is a `version_compare()` at the
top of the main file, or nothing. Without it, a plugin installed on an old
PHP fatals on activation and takes the site with it.

This wraps the bootstrap. Requirements are checked once every plugin has
loaded; if they hold, the plugin's files, constants, links and notices are
set up and its bootstrap runs on `init`. If not, nothing of it loads and its
row on the plugins screen says which requirement failed.

It also answers the other question every bundled library asks: what is the
URL of a file inside my own package, wherever Composer or Strauss put it?

## Features

* Boot only when the PHP and WordPress versions are high enough
* Require another plugin -- EDD, WooCommerce, SugarCart -- at a version
* Turn a plugin's row red and list what failed, rather than white-screening
* Switch off, or refuse to run beside, a conflicting plugin
* Define the usual `MYPLUGIN_PLUGIN_DIR`-style constants and load the text domain
* Add Settings and Docs links, and welcome notices users can dismiss
* Load a whole folder of PHP files in name order, the same on every server
* Enqueue a library's own CSS and JavaScript from `vendor/`, prefixed or symlinked

## Installation

```bash
composer require sugarcommerce/plugin
```

## Quick start

In the plugin's main file, in place of calling your bootstrap directly:

```php
use SugarCommerce\Plugin\Plugin;

Plugin::register( __FILE__, static function () {
	MyPlugin::init();
}, [
	'requirements' => [
		'php' => '8.3',
		'wp'  => '7.1',
	],
] );
```

`MyPlugin::init()` runs on `init` only if both hold. If not, nothing loads,
and the plugins screen names the version that is missing and the one there is.

## When things run

| When | What |
|------|------|
| `plugins_loaded` 1 | the text domain |
| `plugins_loaded` at `priority` (10) | the check; then `early_includes`, `includes`, `constants`, `setup_hooks`, `success`, activation hooks, links, notices |
| `init` 0 | the bootstrap |

Registered after `plugins_loaded` (during activation, from WP-CLI) the check
runs at once; after `init`, so does the bootstrap. Registering the same file
twice returns the first registration and sets nothing up again.

`register()` returns the `Plugin`, or null for an empty file:

```php
$plugin = Plugin::register( __FILE__, $boot, $config );

$plugin->loaded();                     // the requirements held
$plugin->requirements()->errors();     // WP_Error: what failed, as shown on the plugins screen
$plugin->id();                         // 'my_plugin', from the folder
Plugin::get( __FILE__ );               // the same object, from anywhere
```

## Requirements

A known dependency needs only its minimum:

```php
'requirements' => [
	'php'                    => '8.3',
	'wp'                     => '7.1',
	'easy-digital-downloads' => '3.6.1',  // also woocommerce, elementor, sugarcart, advanced-custom-fields
],
```

WordPress is compared the way core's `is_wp_version_compatible()` compares it,
without a pre-release suffix, so 7.1-RC1 meets `'wp' => '7.1'`.

Anything else says how to find it:

```php
'requirements' => [
	'edd-recurring' => [
		'name'    => 'EDD Recurring Payments',
		'check'   => 'EDD_RECURRING_VERSION',
		'type'    => 'constant',      // constant, function, function_with_args, class, plugin_active, callback, auto
		'minimum' => '2.12',
	],
	'stripe-sdk'    => [ 'name' => 'Stripe SDK', 'check' => 'Stripe\\StripeClient', 'type' => 'class' ],
	'custom'        => [
		'name'    => 'Some Service',
		'exists'  => static fn() => function_exists( 'some_service' ),
		'current' => static fn() => some_service_version(),
		'minimum' => '1.4',
	],
],
```

Without a `minimum`, being there is enough. With one and no readable version
(a class, an active plugin), the requirement is unmet: claiming it was met
would say a check happened that did not. A `name`, `minimum` or `message`
may be a closure; a plain string is never called, even one that is also the
name of a PHP function.

`Requirements` works on its own too:

```php
use SugarCommerce\Plugin\Requirements;

$requirements = new Requirements( [ 'php' => '8.3', 'woocommerce' => '10.3' ] );

$requirements->met();            // bool, checked afresh each time
$requirements->errors();         // WP_Error, codes requirement_{id} and conflict_{id}
$requirements->requirements();   // [ id => [ name, minimum, exists, current, met ] ]
Requirements::known();           // the ids that need only a minimum
```

### On an old PHP

`Plugin`, `Requirements` and `Unmet` -- everything that runs before the PHP
version has been checked -- parse on PHP 7.4, so an old host sees the notice
rather than a parse error. A test holds that line. For it to matter, the
plugin's own main file must parse there too, and Composer's platform check
must not get there first: set `"platform-check": false` in the plugin's
`composer.json`, since every package in this family requires PHP 8.3.

## Conflicts

```php
'conflicts' => [
	'my-plugin-lite' => 'my-plugin-lite/my-plugin-lite.php',  // deactivated, in the admin
	'old-addon'      => [
		'name'      => 'Old Add-on',
		'plugin'    => 'old-addon/old-addon.php',
		'action'    => 'notice',                              // refuse to load, and say why
		'message'   => static fn() => __( 'Old Add-on is built into My Plugin now. Deactivate it to continue.', 'my-plugin' ),
		'condition' => static fn( array $found ) => version_compare( (string) $found['version'], '2.0', '<' ),
	],
],
```

A conflict naming a `plugin` is that plugin being active. Its default action
deactivates it, where WordPress can (the admin); anywhere else, and with any
other action, the plugin does not load and its row says why.

## What a plugin that did not load shows

Its row turns red and a row beneath lists each failure. The configuration
for that:

| Key | For |
|-----|-----|
| `error_message` | the line in the name column (default "This plugin is not fully active."); a closure is translated when shown |
| `requirements_url` | adds a Requirements link |
| `support_url` | adds a Support link |
| `css_class_prefix` | the rows' class is `{prefix}-{folder}-row` (default `sugarcommerce`) |

## Loading

| Key | Does |
|-----|------|
| `priority` | the `plugins_loaded` priority the check runs at (default 10) |
| `early_includes` | absolute paths, required first; a folder is loaded file by file |
| `includes` | paths within the plugin's folder, required next; a folder is loaded file by file |
| `constants` | `'MY_PLUGIN'` defines `MY_PLUGIN_PLUGIN_VERSION` (from the header), `_FILE`, `_BASE`, `_DIR`, `_URL` and `_QUERY_LIMIT`; or an array of `prefix`, `version`, `skip` (suffixes, `[ 'URL' ]`) and `definitions` (`[ 'NAME' => value ]`) |
| `textdomain` | `'my-plugin'` loads `languages/`; or an array of `domain` and `path` (`'/i18n'`), or `domain` and a `callback` given the folder |
| `setup_hooks` | `[ 'action' => callable ]`, hooked once loaded; a callable that takes an argument is given the plugin file |
| `activation`, `deactivation` | registered as the plugin's activation and deactivation hooks |
| `success` | called once everything is set up |
| `id` | what notices are stored under (default: the folder, `my-plugin` → `my_plugin`) |

A path that is not there is skipped, not fatal.

### Loading a folder

```php
Plugin::register( __FILE__, $boot, [
	'includes' => [ 'includes/functions.php', 'includes/utils/', 'includes/admin/' ],
] );
```

A folder is loaded file by file, in name order -- its own files, not its
subfolders (list those too). Dot files and the `index.php` silence stubs are
skipped. The order is the point: `DirectoryIterator` and
`glob( …, GLOB_NOSORT )` hand back whatever the filesystem holds -- creation
order on APFS, hashed elsewhere -- so a class extending another in the same
folder loads on your machine and fatals on the server. Here the order is the
names', which you control: prefix to pin it (`10-base.php`,
`20-extends-base.php`; sorting is by string, so pad the numbers).

`Slurp` does the same anywhere else:

```php
use SugarCommerce\Plugin\Slurp;

Slurp::dir( __DIR__ . '/includes/utils' );          // the paths it loaded, in order
Slurp::dir( __DIR__ . '/includes', true );           // and subfolders, each after its folder's own files
Slurp::dirs( [ $schemas, $tables, $queries ] );      // several, in the order given
Slurp::files( __DIR__ . '/includes/utils' );         // what it would load, without loading it
```

Files are `require_once`d, so anything at file scope runs the moment they
load. Declare classes and functions in them and hook from a bootstrap that
runs afterwards. `scandir()` is used, not `glob()`, so a path with a bracket
in it (`Sites [old]`) is read literally rather than as a pattern.

## Links on the plugins screen

```php
'plugin_links' => [                                      // beside Deactivate, first
	'settings' => [
		'url'        => admin_url( 'options-general.php?page=my-plugin' ),
		'text'       => static fn() => __( 'Settings', 'my-plugin' ),
		'capability' => 'manage_options',
	],
	'docs'     => [ 'url' => 'https://example.com/docs', 'target' => '_blank' ],
],
'plugin_meta'  => [                                      // under the description, new tab
	'support' => 'https://example.com/support',          // a URL alone: the key is the text
],
```

`text` as a closure keeps `__()` out of the configuration, which is read
before `init`. A link may also take `style`. Links opening a new tab get
`rel="noopener noreferrer"`.

## Notices

```php
'notices' => [
	'welcome' => [
		'type'        => 'success',                      // info, success, warning, error
		'message'     => static fn() => sprintf( __( 'Thanks! <a href="%s">Configure it</a>.', 'my-plugin' ), admin_url( '…' ) ),
		'dismissible' => true,                           // remembered per user
		'show_once'   => true,                           // one page load, ever
		'capability'  => 'manage_shop_settings',         // who sees it, and who may dismiss it
		'pages'       => [ 'plugins', 'edit-download' ], // screen ids
		'show_until'  => 'option_not_empty:my_plugin_api_key', // or a callable: shown while it is true
	],
],
```

Everything remembered is named from the plugin's id, so two plugins that both
call a notice `welcome` keep separate dismissals:

| Stored | Name |
|--------|------|
| user meta, a dismissal | `{id}_notice_dismissed_{notice}` |
| option, a show-once notice shown | `{id}_notice_shown_{notice}` |
| AJAX action and nonce | `{id}_dismiss_notice` |

## Presets

```php
Plugin::edd( __FILE__, $boot, $config );          // EDD 3.6.1; checks at plugins_loaded 99, after EDD's loader (98)
Plugin::woocommerce( __FILE__, $boot, $config );  // WooCommerce 10.3.5; plugins_loaded 20; declares HPOS compatibility
Plugin::network( __FILE__, $boot, $config );      // links and notices in the network admin too
```

Each preset asks for PHP 8.3 and WordPress 7.1, except that `edd()` takes
WordPress from the plugin header's `Requires at least` when it has one. The caller's
configuration is merged over it key by key (`Plugin::merge_config()`), so
`'requirements' => [ 'wp' => '6.7.1' ]` changes WordPress and keeps the rest.

WooCommerce compatibility is declared from inside `before_woocommerce_init`,
the only place WooCommerce accepts it. Add features with
`'setup_hooks' => [ 'woocommerce_compatibility' => [ 'features' => [ 'custom_order_tables', 'cart_checkout_blocks' ] ] ]`.

## Assets from a Composer package

`plugins_url()` works from a plugin's file, not from a library in `vendor/`,
which cannot know whether it was installed there, prefixed into
`vendor-prefixed/` by Strauss, or symlinked from a checkout. `Assets` finds the
package from `__FILE__` -- the nearest folder above holding a `composer.json`
-- and serves its `assets/` folder:

```php
use SugarCommerce\Plugin\Assets;

add_action( 'admin_enqueue_scripts', static function () {
	Assets::enqueue_style( 'my-lib', __FILE__, 'css/my-lib.css' );
	Assets::enqueue_script( 'my-lib', __FILE__, 'js/my-lib.js', [ 'wp-api-fetch' ], false, [ 'strategy' => 'defer' ] );
} );
```

| Method | Gives |
|--------|-------|
| `enqueue_script()`, `enqueue_style()` | register and enqueue; false when the file is not there |
| `register_script()`, `register_style()` | register only, to enqueue by handle later |
| `url( __FILE__, 'img/logo.svg' )` | the file's URL, or null |
| `path( __FILE__, 'img/logo.svg' )` | the file's absolute path, or null |
| `contents( __FILE__, 'icons/cart.svg' )` | the file's contents, or false |
| `resolve()`, `locate()` | the file, or the assets folder, as `path` and `url` |
| `path_to_url( $absolute_path )` | the URL of any path under WordPress, for files outside `assets/` |

A version of `false` (the default) is the file's modification time, so an
edit busts caches; a string is used as given, and null adds none. A path
that climbs out of `assets/` (`../`) is refused. A symlinked plugin is found
through the same real-path map `plugins_url()` reads.

**This is the one shared way.** Several packages in this family still work
out their own asset URLs with a copy of the same arithmetic --
`sugarcommerce/conditions` (`Admin\Assets`), `emails` (`Utils\Assets`),
`fields` (`Assets`), `jobs` (`Sync::assets_url()`), `geo` (`Flags`),
`network` (`Referrer\Icons`), `security` (`Turnstile\Widget`) and `storage`
(`Utils\Assets`). Each can require `sugarcommerce/plugin` and call
`Assets::url()` or `Assets::path_to_url()` instead.

## Moving from arraypress/wp-*

From `arraypress/wp-register-plugin` (`ArrayPress\RegisterPlugin`),
`arraypress/wp-composer-assets` (`ArrayPress\ComposerAssets`) and
`arraypress/wp-slurp` (`ArrayPress\Slurp`). The global
functions are gone -- a library bundled into several plugins must not
declare any -- and each is a static method. Every configuration key keeps its
name and meaning.

| Old | New |
|-----|-----|
| `register_plugin( __FILE__, $boot, $config )` | `Plugin::register( __FILE__, $boot, $config )` |
| `register_edd_plugin( … )` (Strauss: `eddsp_register_edd_plugin`) | `Plugin::edd( … )` |
| `register_woocommerce_plugin( … )` | `Plugin::woocommerce( … )` |
| `register_network_plugin( … )` | `Plugin::network( … )` (now also shows links and notices in the network admin) |
| `ArrayPress\RegisterPlugin\Plugin::register( [ 'file', 'bootstrap', … ] )` | `Plugin::register( $file, $bootstrap, $config )` |
| `new Plugin( $config )` then `->setup()` | the same, under `SugarCommerce\Plugin` |
| `Plugin::merge_config()` | `Plugin::merge_config()` |
| `$plugin->delayed_setup()`, `->plugins_loaded()` | `$plugin->run()` |
| `ArrayPress\RegisterPlugin\Requirements` | `SugarCommerce\Plugin\Requirements` |
| `Requirements::get_errors()` | `Requirements::errors()` |
| — | `Requirements::requirements()`, `::conflicts()`, `::known()`, `$plugin->loaded()`, `->id()`, `Plugin::get()` |
| preset floors PHP 7.4, WordPress 6.8.4 | PHP 8.3, WordPress 7.1 (a caller's own still win) |
| user meta `wp_register_plugin_dismissed_{notice}` | `{id}_notice_dismissed_{notice}` |
| option `wp_register_plugin_shown_{notice}` | `{id}_notice_shown_{notice}` |
| AJAX action `wp_register_plugin_dismiss_notice` | `{id}_dismiss_notice` |
| dismissing needed `manage_options` | needs the notice's own `capability` |
| `css_class_prefix` default `arraypress` | `sugarcommerce` |
| a `plugin_links` URL alone: text was the key as written (`settings`) | the key capitalised (`Settings`), as `plugin_meta` always did |
| text domain `arraypress` | text domain `sugarcommerce` |
| `arraypress_enqueue_composer_script()` / `wp_enqueue_composer_script()` | `Assets::enqueue_script()` |
| `arraypress_enqueue_composer_style()` / `wp_enqueue_composer_style()` | `Assets::enqueue_style()` |
| `arraypress_register_composer_script()` / `wp_register_composer_script()` | `Assets::register_script()` |
| `arraypress_register_composer_style()` / `wp_register_composer_style()` | `Assets::register_style()` |
| `arraypress_get_composer_file()` / `wp_get_composer_file()`, `AssetLoader::get_file()` | `Assets::contents()` |
| `AssetLoader::resolve_asset()` | `Assets::resolve()` |
| `AssetLoader::locate_assets()` | `Assets::locate()` |
| `AssetLoader::clear_cache()` | `Assets::clear_cache()` |
| — | `Assets::url()`, `Assets::path()`, `Assets::path_to_url()` |
| `ArrayPress\Slurp\Slurp::dir()`, `dirs()`, `files()`, `SKIP` | `SugarCommerce\Plugin\Slurp::` the same (same answers) |
| an `includes` / `early_includes` entry that was a folder: a fatal `require_once` | the folder's files, in name order |

Fixed on the way: registering a file twice set it up twice; a second
requirements check lost an `exists` callable; a requirement named like a PHP
function (`Date`) was called; a `setup_hooks` method callback (`[ $obj,
'method' ]`) fatalled; a `current` callable alone never counted as present.

## Requirements

* PHP 8.3 or later (the boot path parses on 7.4, see above)
* WordPress 7.1 or later

## License

GPL-2.0-or-later
