<?php
/**
 * Build
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

/**
 * The name of the build a bundled copy of a package belongs to.
 *
 * Strauss rewrites namespaces and leaves strings alone, so a package that
 * registers REST routes, script handles, hooks or transients names them from
 * the namespace it was compiled under: two plugins bundling it then register
 * two sets, not one shared set. Every package that does so asks this class,
 * so they all name a build the same way.
 */
final class Build {

	/**
	 * The name of the build a namespace belongs to, as a hyphenated slug.
	 *
	 * Empty when the namespace is the package's own, unprefixed. Otherwise
	 * what the build put around it, in one of two shapes:
	 *
	 * - A prefix in front of the whole namespace,
	 *   "MyShop\SugarCommerce\…\Utils": the prefix's first segment, as it
	 *   always was ("SugarCart\Vendor" is "sugarcart"), or all of it when it
	 *   starts "SugarCommerce" too -- a family plugin's
	 *   "SugarCommerce\Playground\Vendor" would otherwise be taken for the
	 *   package itself.
	 * - A plugin's own namespace with the vendor segment dropped,
	 *   "ArrayPress\EDD\FraudFilter\Lib\…\Utils": every segment before "Lib".
	 *   The first alone is the vendor every such plugin shares, and two of
	 *   them would answer each other's routes and load each other's scripts.
	 *
	 * @param string $under The namespace the caller is compiled under, prefixed or not.
	 * @param int    $depth How many segments the caller's own namespace has
	 *                      unprefixed: 3 for SugarCommerce\Columns\Utils.
	 *                      Counted rather than written out, because Strauss
	 *                      rewrites a namespace it finds in a string.
	 *
	 * @return string
	 */
	public static function name( string $under, int $depth ): string {
		$under = array_values( array_filter( explode( '\\', $under ), 'strlen' ) );

		// Everything in front of the caller's own segments after the vendor
		// one (the package segment and whatever follows it).
		$segments = array_slice( $under, 0, max( 0, count( $under ) - $depth + 1 ) );
		$last     = end( $segments );

		if ( 'SugarCommerce' === $last ) {
			array_pop( $segments );

			if ( [] !== $segments && 'SugarCommerce' !== $segments[0] ) {
				$segments = [ $segments[0] ];
			}
		} elseif ( 'Lib' === $last ) {
			array_pop( $segments );
		}

		return strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', implode( '-', $segments ) ), '-' ) );
	}
}
