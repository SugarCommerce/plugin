<?php
/**
 * Build name tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Plugin\Build;

/**
 * A bundled copy of a package is named for the build it is in, so two plugins
 * bundling one package never share its routes, handles, hooks or keys.
 */
final class BuildTest extends TestCase {

	/**
	 * Each namespace gives its build's name.
	 *
	 * @param string $under    The namespace the caller is compiled under.
	 * @param int    $depth    How many segments the caller's own namespace has.
	 * @param string $expected The build's name.
	 */
	#[DataProvider( 'namespaces' )]
	public function test_a_build_is_named_for_itself( string $under, int $depth, string $expected ): void {
		$this->assertSame( $expected, Build::name( $under, $depth ) );
	}

	/**
	 * Namespaces and the name each gives.
	 *
	 * @return array<string, array{string, int, string}>
	 */
	public static function namespaces(): array {
		return [
			'unprefixed'                 => [ 'SugarCommerce\\Columns\\Utils', 3, '' ],
			'unprefixed, shallower'      => [ 'SugarCommerce\\StatusBadge', 2, '' ],
			'unprefixed, one segment'    => [ 'SugarCommerce', 1, '' ],
			'nothing at all'             => [ '', 3, '' ],
			'a leading slash'            => [ '\\SugarCommerce\\Columns\\Utils', 3, '' ],
			'a prefix, leading slash'    => [ '\\SugarCart\\Vendor\\SugarCommerce\\Columns\\Utils', 3, 'sugarcart' ],
			'a plugin prefix'            => [ 'EDDFF\\SugarCommerce\\Columns\\Utils', 3, 'eddff' ],
			'a vendor prefix'            => [ 'SugarCart\\Vendor\\SugarCommerce\\Columns\\Utils', 3, 'sugarcart' ],
			'a prefix with underscores'  => [ 'EDD_Fraud_Filter\\SugarCommerce\\Jobs\\Utils', 3, 'edd-fraud-filter' ],
			'a family plugin'            => [ 'SugarCommerce\\Playground\\Vendor\\SugarCommerce\\Columns\\Utils', 3, 'sugarcommerce-playground-vendor' ],
			'a plugin namespace'         => [ 'ArrayPress\\EDD\\FraudFilter\\Lib\\Columns\\Utils', 3, 'arraypress-edd-fraudfilter' ],
			'a plugin namespace, deeper' => [ 'ArrayPress\\EDD\\FraudFilter\\Lib\\Storage\\Browser\\Rest', 4, 'arraypress-edd-fraudfilter' ],
			'a plugin namespace, at one' => [ 'ArrayPress\\EDD\\FraudFilter\\Lib', 1, 'arraypress-edd-fraudfilter' ],
			'a one-segment Lib'          => [ 'Playground\\Lib\\Columns\\Utils', 3, 'playground' ],
		];
	}

	/**
	 * Two plugins under one vendor, each with its packages in its own Lib, are
	 * two builds: the vendor segment they share does not name either.
	 */
	public function test_plugins_sharing_a_vendor_are_told_apart(): void {
		$this->assertNotSame(
			Build::name( 'ArrayPress\\EDD\\FraudFilter\\Lib\\Columns\\Utils', 3 ),
			Build::name( 'ArrayPress\\EDD\\SalePrices\\Lib\\Columns\\Utils', 3 )
		);
	}
}
