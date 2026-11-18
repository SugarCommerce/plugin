<?php
/**
 * WooCommerce's compatibility declaration, recorded.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Utilities;

/**
 * Records each declaration rather than making it.
 */
final class FeaturesUtil {

	/**
	 * Record a declaration.
	 *
	 * @param string $feature    The feature.
	 * @param string $file       The plugin file.
	 * @param bool   $compatible Whether it is compatible.
	 *
	 * @return bool
	 */
	public static function declare_compatibility( string $feature, string $file, bool $compatible = true ): bool {
		$GLOBALS['pl']['declared'][] = [ $feature, basename( $file ), $compatible ];

		return true;
	}
}
