<?php
/**
 * Runtime name tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use ArrayPress\EDD\FraudFilter\Lib\Widgets\Deep\Utils\Runtime as LibRuntime;
use PHPUnit\Framework\TestCase;
use SugarCart\Vendor\SugarCommerce\Widgets\Utils\Runtime as SugarCartRuntime;
use SugarCommerce\Widgets\Utils\Runtime;

require_once __DIR__ . '/fixtures/runtimes.php';

/**
 * A package's hooks, keys and handles come from the build its Runtime was
 * compiled into, read from the subclass's own namespace.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Unprefixed, the names are the package's own.
	 */
	public function test_unprefixed_names_are_the_packages_own(): void {
		$this->assertSame( '', Runtime::build() );
		$this->assertSame( 'sugarcommerce_widgets', Runtime::prefix() );
		$this->assertSame( 'sugarcommerce_widgets_saved', Runtime::hook( 'saved' ) );
		$this->assertSame( 'sugarcommerce_widgets', Runtime::key() );
		$this->assertSame( 'sugarcommerce_widgets_cache', Runtime::key( 'cache' ) );
		$this->assertSame( 'sugarcommerce-widgets', Runtime::handle() );
		$this->assertSame( 'sugarcommerce-widgets-admin', Runtime::handle( 'admin' ) );
	}

	/**
	 * A prefixed copy names everything for its build, from its own namespace.
	 */
	public function test_a_prefixed_copy_is_named_for_its_build(): void {
		$this->assertSame( 'sugarcart', SugarCartRuntime::build() );
		$this->assertSame( 'sugarcart_sugarcommerce_widgets', SugarCartRuntime::prefix() );
		$this->assertSame( 'sugarcart_sugarcommerce_widgets_saved', SugarCartRuntime::hook( 'saved' ) );
		$this->assertSame( 'sugarcart_sugarcommerce_widgets_cache', SugarCartRuntime::key( 'cache' ) );
		$this->assertSame( 'sugarcart-sugarcommerce-widgets-admin', SugarCartRuntime::handle( 'admin' ) );
	}

	/**
	 * A subclass deeper in its package counts its own segments.
	 */
	public function test_the_depth_is_the_subclasss_own(): void {
		$this->assertSame( 'arraypress-edd-fraudfilter', LibRuntime::build() );
		$this->assertSame( 'arraypress_edd_fraudfilter_sugarcommerce_widgets', LibRuntime::prefix() );
	}

	/**
	 * The rule can be asked for any namespace, as the build's tests do.
	 */
	public function test_the_rule_for_any_namespace(): void {
		$this->assertSame( 'eddr2', Runtime::build_for( 'EDDR2\\SugarCommerce\\Widgets\\Utils' ) );
		$this->assertSame( 'eddr2_sugarcommerce_widgets', Runtime::prefix_for( 'EDDR2\\SugarCommerce\\Widgets\\Utils' ) );
		$this->assertSame( 'sugarcommerce_widgets', Runtime::prefix_for( 'SugarCommerce\\Widgets\\Utils' ) );
	}
}
