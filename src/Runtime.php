<?php
/**
 * Runtime names
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin;

/**
 * Every runtime string a package registers -- hooks, option and transient
 * keys, script handles -- named for the build it was compiled into.
 *
 * Strauss rewrites class namespaces and leaves string literals alone. Two
 * plugins each bundling a prefixed copy of a package would otherwise
 * register identical hooks, handles and keys, so one plugin's cached answer
 * would be read as the other's and a filter one of them added would steer
 * both. A package extends this once, in its own namespace, naming itself:
 *
 *     namespace SugarCommerce\S3\Utils;
 *
 *     final class Runtime extends \SugarCommerce\Plugin\Runtime {
 *         protected const string LIBRARY      = 'sugarcommerce_s3';
 *         protected const int    OWN_SEGMENTS = 3; // SugarCommerce\S3\Utils
 *     }
 *
 *     Runtime::hook( 'uploaded' );   // sugarcommerce_s3_uploaded
 *                                    // sugarcart_sugarcommerce_s3_uploaded in SugarCart's copy
 *
 * The build is read from the subclass's own namespace -- the one thing
 * Strauss does rewrite -- through {@see Build::name()}.
 */
abstract class Runtime {

	/**
	 * The package's own identifier, used when running unprefixed:
	 * `sugarcommerce_{package}`.
	 */
	protected const string LIBRARY = 'sugarcommerce';

	/**
	 * How many segments the subclass's own namespace has before any
	 * prefix: 3 for SugarCommerce\S3\Utils. Counted rather than written
	 * out: Strauss rewrites a namespace it finds in a string.
	 */
	protected const int OWN_SEGMENTS = 3;

	/**
	 * The per-build prefix for hooks and keys.
	 *
	 * @return string `sugarcommerce_s3` unprefixed; `sugarcart_sugarcommerce_s3` in SugarCart's copy.
	 */
	public static function prefix(): string {
		return static::prefix_for( static::under() );
	}

	/**
	 * The name of this build, as a hyphenated slug; empty unprefixed.
	 *
	 * For names that are not hooks or keys -- a REST namespace, a cache
	 * prefix -- but must still be this build's own.
	 *
	 * @return string
	 */
	public static function build(): string {
		return static::build_for( static::under() );
	}

	/**
	 * The prefix a given namespace would produce.
	 *
	 * Split out so the rule can be tested: prefix() reads the class's own
	 * namespace, which cannot change at runtime.
	 *
	 * @param string $under A namespace the subclass could be compiled under.
	 *
	 * @return string
	 */
	public static function prefix_for( string $under ): string {
		$build = static::build_for( $under );

		return '' === $build ? static::LIBRARY : str_replace( '-', '_', $build ) . '_' . static::LIBRARY;
	}

	/**
	 * The name of the build a namespace belongs to. See {@see Build::name()}.
	 *
	 * @param string $under A namespace the subclass could be compiled under.
	 *
	 * @return string
	 */
	public static function build_for( string $under ): string {
		return Build::name( $under, static::OWN_SEGMENTS );
	}

	/**
	 * A hook name for this build.
	 *
	 * @param string $name What the hook is for.
	 *
	 * @return string
	 */
	public static function hook( string $name ): string {
		return static::prefix() . '_' . $name;
	}

	/**
	 * An option or transient key for this build.
	 *
	 * @param string $suffix Optional key suffix.
	 *
	 * @return string
	 */
	public static function key( string $suffix = '' ): string {
		return '' === $suffix ? static::prefix() : static::prefix() . '_' . $suffix;
	}

	/**
	 * A script or style handle for this build, hyphenated as handles are.
	 *
	 * @param string $suffix Optional handle suffix.
	 *
	 * @return string
	 */
	public static function handle( string $suffix = '' ): string {
		$base = str_replace( '_', '-', static::prefix() );

		return '' === $suffix ? $base : $base . '-' . $suffix;
	}

	/**
	 * The namespace the subclass was compiled under.
	 *
	 * @return string
	 */
	private static function under(): string {
		$class = static::class;
		$cut   = strrpos( $class, '\\' );

		return false === $cut ? '' : substr( $class, 0, $cut );
	}
}
