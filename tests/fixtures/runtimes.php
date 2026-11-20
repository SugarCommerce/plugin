<?php
/**
 * One package's Runtime, as it is compiled into three builds.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Widgets\Utils {

	/**
	 * The package's own, unprefixed.
	 */
	final class Runtime extends \SugarCommerce\Plugin\Runtime {
		protected const string LIBRARY = 'sugarcommerce_widgets';
	}
}

namespace SugarCart\Vendor\SugarCommerce\Widgets\Utils {

	/**
	 * SugarCart's copy.
	 */
	final class Runtime extends \SugarCommerce\Plugin\Runtime {
		protected const string LIBRARY = 'sugarcommerce_widgets';
	}
}

namespace ArrayPress\EDD\FraudFilter\Lib\Widgets\Deep\Utils {

	/**
	 * A plugin's own namespace with the vendor dropped, one folder deeper.
	 */
	final class Runtime extends \SugarCommerce\Plugin\Runtime {
		protected const string LIBRARY      = 'sugarcommerce_widgets';
		protected const int    OWN_SEGMENTS = 4;
	}
}
