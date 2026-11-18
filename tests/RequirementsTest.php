<?php
/**
 * Requirements tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Plugin\Requirements;

/**
 * Whether a plugin's requirements hold, and what is said when they do not.
 */
final class RequirementsTest extends TestCase {

	/**
	 * A clean WordPress.
	 */
	protected function setUp(): void {
		pl_reset();
	}

	// -- Versions ----------------------------------------------------------

	public function test_a_satisfied_php_version_passes(): void {
		$this->assertTrue( ( new Requirements( [ 'php' => '8.0' ] ) )->met() );
	}

	public function test_an_unsatisfied_php_version_fails(): void {
		$this->assertFalse( ( new Requirements( [ 'php' => '99.0' ] ) )->met() );
	}

	public function test_the_wordpress_version_is_read(): void {
		$GLOBALS['pl']['wp_version'] = '6.8';

		$this->assertTrue( ( new Requirements( [ 'wp' => '6.0' ] ) )->met() );
		$this->assertFalse( ( new Requirements( [ 'wp' => '7.0' ] ) )->met() );
	}

	public function test_an_equal_version_satisfies_the_minimum(): void {
		$GLOBALS['pl']['wp_version'] = '6.8';

		$this->assertTrue( ( new Requirements( [ 'wp' => '6.8' ] ) )->met() );
	}

	public function test_a_wordpress_release_candidate_meets_its_release(): void {
		$GLOBALS['pl']['wp_version'] = '7.1-RC1';

		$this->assertTrue( ( new Requirements( [ 'wp' => '7.1' ] ) )->met(), '7.1-RC1 is 7.1, as is_wp_version_compatible() reads it.' );
		$this->assertTrue( ( new Requirements( [ 'wp' => '7.0' ] ) )->met() );
		$this->assertFalse( ( new Requirements( [ 'wp' => '7.2' ] ) )->met() );

		$GLOBALS['pl']['wp_version'] = '7.1.4-src';

		$this->assertTrue( ( new Requirements( [ 'wp' => '7.1.4' ] ) )->met() );
		$this->assertFalse( ( new Requirements( [ 'wp' => '7.1.5' ] ) )->met() );
	}

	public function test_only_wordpress_drops_a_pre_release_suffix(): void {
		$requirement = [
			'current' => static fn() => '3.6.1-beta1',
			'minimum' => '3.6.1',
		];

		$this->assertFalse( ( new Requirements( [ 'dep' => $requirement ] ) )->met() );
	}

	public function test_a_constant_dependency_is_version_checked(): void {
		define( 'PL_TEST_DEP_VERSION', '3.2.1' );

		$requirement = [
			'check' => 'PL_TEST_DEP_VERSION',
			'type'  => 'constant',
		];

		$this->assertTrue( ( new Requirements( [ 'dep' => $requirement + [ 'minimum' => '3.0' ] ] ) )->met() );
		$this->assertFalse( ( new Requirements( [ 'dep' => $requirement + [ 'minimum' => '4.0' ] ] ) )->met() );
	}

	public function test_a_known_dependency_needs_only_its_minimum(): void {
		$this->assertFalse( ( new Requirements( [ 'woocommerce' => '10.0' ] ) )->met(), 'WooCommerce is not loaded.' );
		$this->assertArrayHasKey( 'sugarcart', Requirements::known() );
	}

	public function test_a_missing_constant_is_unmet(): void {
		$this->assertFalse(
			( new Requirements(
				[
					'dep' => [
						'check' => 'PL_NOT_DEFINED',
						'type'  => 'constant',
					],
				]
			) )->met()
		);
	}

	public function test_an_existing_class_satisfies_a_requirement(): void {
		$this->assertTrue(
			( new Requirements(
				[
					'dep' => [
						'check' => TestCase::class,
						'type'  => 'class',
					],
				]
			) )->met()
		);
	}

	public function test_a_missing_class_is_unmet(): void {
		$this->assertFalse(
			( new Requirements(
				[
					'dep' => [
						'check' => 'No\\Such\\Thing',
						'type'  => 'class',
					],
				]
			) )->met()
		);
	}

	public function test_an_active_plugin_satisfies_a_requirement(): void {
		$GLOBALS['pl']['active'] = [ 'dep/dep.php' ];

		$this->assertTrue(
			( new Requirements(
				[
					'dep' => [
						'check' => 'dep/dep.php',
						'type'  => 'plugin_active',
					],
				]
			) )->met()
		);
		$this->assertFalse(
			( new Requirements(
				[
					'dep' => [
						'check' => 'other/other.php',
						'type'  => 'plugin_active',
					],
				]
			) )->met()
		);
	}

	/**
	 * A version was asked for and none can be read: that is not met, since
	 * claiming it is would say a check happened that did not.
	 */
	public function test_a_version_that_cannot_be_read_is_unmet(): void {
		$this->assertFalse(
			( new Requirements(
				[
					'dep' => [
						'check'   => TestCase::class,
						'type'    => 'class',
						'minimum' => '1.0',
					],
				]
			) )->met()
		);
	}

	public function test_auto_finds_a_constant_a_function_or_a_class(): void {
		$this->assertTrue( ( new Requirements( [ 'a' => [ 'check' => 'PHP_VERSION' ] ] ) )->met() );
		$this->assertTrue( ( new Requirements( [ 'b' => [ 'check' => 'phpversion' ] ] ) )->met() );
		$this->assertTrue( ( new Requirements( [ 'c' => [ 'check' => TestCase::class ] ] ) )->met() );
		$this->assertFalse( ( new Requirements( [ 'd' => [ 'check' => 'nothing_by_this_name' ] ] ) )->met() );
	}

	// -- Callables ---------------------------------------------------------

	public function test_a_custom_callback_supplies_existence_and_version(): void {
		$check = static fn(): array => [
			'exists'  => true,
			'version' => '5.0',
		];

		$this->assertTrue( ( new Requirements( [ 'dep' => [ 'check' => $check, 'type' => 'callback', 'minimum' => '4.0' ] ] ) )->met() );
		$this->assertFalse( ( new Requirements( [ 'dep' => [ 'check' => $check, 'type' => 'callback', 'minimum' => '6.0' ] ] ) )->met() );
	}

	/**
	 * Asked twice, the same answer.
	 *
	 * The old class wrote each answer back over its configuration, so an
	 * `exists` callable became the boolean it returned and the second check
	 * had nothing to call: a plugin that passed once failed the next time.
	 */
	public function test_a_second_check_gives_the_same_answer(): void {
		$requirements = new Requirements(
			[
				'dep' => [
					'exists'  => static fn(): bool => true,
					'current' => static fn(): string => '2.0',
					'minimum' => '1.0',
				],
			]
		);

		$this->assertTrue( $requirements->met() );
		$this->assertTrue( $requirements->met(), 'The second check lost the callables.' );
	}

	/**
	 * A version from `current` is enough to say the dependency is there.
	 */
	public function test_a_version_alone_means_it_exists(): void {
		$this->assertTrue( ( new Requirements( [ 'dep' => [ 'current' => static fn(): string => '2.0', 'minimum' => '1.0' ] ] ) )->met() );
	}

	/**
	 * A name that is also a PHP function is a name, not a call.
	 *
	 * Every configured value used to go through is_callable(), so a
	 * dependency called "Date" called date() with no arguments, which is a
	 * fatal error on the plugins screen.
	 */
	public function test_a_name_that_is_a_function_is_not_called(): void {
		$requirements = new Requirements( [ 'dep' => [ 'name' => 'Date', 'check' => 'PL_NOT_DEFINED', 'type' => 'constant', 'minimum' => '1.0' ] ] );

		$this->assertStringContainsString( '<strong>Date</strong>', $requirements->errors()->get_error_message( 'requirement_dep' ) );
	}

	public function test_a_closure_name_is_called(): void {
		$requirements = new Requirements( [ 'dep' => [ 'name' => static fn(): string => 'Late Name', 'check' => 'PL_NOT_DEFINED', 'type' => 'constant' ] ] );

		$this->assertStringContainsString( 'Late Name', $requirements->errors()->get_error_message( 'requirement_dep' ) );
	}

	// -- What is said ------------------------------------------------------

	public function test_a_missing_dependency_without_a_minimum_says_only_that(): void {
		$message = ( new Requirements( [ 'dep' => [ 'name' => 'Some Plugin', 'check' => 'PL_NOT_DEFINED', 'type' => 'constant' ] ] ) )
			->errors()
			->get_error_message( 'requirement_dep' );

		$this->assertSame( 'Missing <strong>Some Plugin</strong>', $message );
	}

	public function test_a_missing_dependency_with_a_minimum_names_it(): void {
		$message = ( new Requirements( [ 'dep' => [ 'name' => 'Some Plugin', 'check' => 'PL_NOT_DEFINED', 'type' => 'constant', 'minimum' => '2.0' ] ] ) )
			->errors()
			->get_error_message( 'requirement_dep' );

		$this->assertSame( 'Missing <strong>Some Plugin</strong>: minimum required <strong>2.0</strong>', $message );
	}

	public function test_an_outdated_dependency_reports_both_versions(): void {
		$message = ( new Requirements( [ 'php' => '99.0' ] ) )->errors()->get_error_message( 'requirement_php' );

		$this->assertSame( '<strong>PHP</strong>: minimum required <strong>99.0</strong> (you have <strong>' . PHP_VERSION . '</strong>)', $message );
	}

	public function test_a_name_is_escaped(): void {
		$message = ( new Requirements( [ 'dep' => [ 'name' => '<img src=x>', 'check' => 'PL_NOT_DEFINED', 'type' => 'constant' ] ] ) )
			->errors()
			->get_error_message( 'requirement_dep' );

		$this->assertStringNotContainsString( '<img', $message );
	}

	public function test_errors_are_keyed_by_requirement(): void {
		$GLOBALS['pl']['wp_version'] = '6.8';

		$this->assertSame(
			[ 'requirement_php', 'requirement_wp' ],
			( new Requirements( [ 'php' => '99.0', 'wp' => '99.0', 'Engine' => [ 'check' => 'PHP_VERSION' ] ] ) )->errors()->get_error_codes(),
			'A requirement that holds was reported.'
		);
	}

	public function test_a_satisfied_set_reports_no_errors(): void {
		$this->assertFalse( ( new Requirements( [ 'php' => '8.0' ] ) )->errors()->has_errors() );
		$this->assertTrue( ( new Requirements() )->met() );
	}

	public function test_the_findings_are_readable(): void {
		$found = ( new Requirements( [ 'php' => '99.0' ] ) )->requirements();

		$this->assertSame(
			[
				'name'    => 'PHP',
				'minimum' => '99.0',
				'exists'  => true,
				'current' => PHP_VERSION,
				'met'     => false,
			],
			$found['php']
		);
	}

	// -- Conflicts ---------------------------------------------------------

	public function test_an_active_conflicting_plugin_blocks_loading(): void {
		$GLOBALS['pl']['active'] = [ 'other/other.php' ];

		$requirements = new Requirements( [], [ 'other' => [ 'name' => 'Other', 'plugin' => 'other/other.php', 'action' => 'notice' ] ] );

		$this->assertFalse( $requirements->met() );
		$this->assertSame( [ 'conflict_other' ], $requirements->errors()->get_error_codes() );
		$this->assertSame( 'Conflict detected: <strong>Other</strong> is active and conflicts with this plugin.', $requirements->errors()->get_error_message( 'conflict_other' ) );
		$this->assertSame( [], $GLOBALS['pl']['deactivated'], 'A notice-only conflict deactivated something.' );
	}

	public function test_an_inactive_conflicting_plugin_does_not(): void {
		$this->assertTrue( ( new Requirements( [], [ 'other' => 'other/other.php' ] ) )->met() );
	}

	/**
	 * The default for a conflicting plugin: switch it off, and carry on.
	 */
	public function test_a_conflicting_plugin_is_deactivated_by_default(): void {
		$GLOBALS['pl']['active'] = [ 'lite/lite.php' ];

		$this->assertTrue( ( new Requirements( [], [ 'lite' => 'lite/lite.php' ] ) )->met() );
		$this->assertSame( [ 'lite/lite.php' ], $GLOBALS['pl']['deactivated'] );
	}

	public function test_a_conflict_condition_can_clear_it(): void {
		$GLOBALS['pl']['active'] = [ 'other/other.php' ];

		$seen         = null;
		$requirements = new Requirements(
			[],
			[
				'other' => [
					'plugin'    => 'other/other.php',
					'action'    => 'notice',
					'condition' => static function ( array $found ) use ( &$seen ): bool {
						$seen = $found;

						return false;
					},
				],
			]
		);

		$this->assertTrue( $requirements->met() );
		$this->assertTrue( $seen['exists'] );
	}

	public function test_a_custom_conflict_message_is_used(): void {
		$GLOBALS['pl']['active'] = [ 'other/other.php' ];

		$requirements = new Requirements( [], [ 'other' => [ 'plugin' => 'other/other.php', 'action' => 'notice', 'message' => 'Turn off Other first.' ] ] );

		$this->assertSame( 'Turn off Other first.', $requirements->errors()->get_error_message( 'conflict_other' ) );
		$this->assertTrue( $requirements->conflicts()['other']['conflict'] );
	}
}
