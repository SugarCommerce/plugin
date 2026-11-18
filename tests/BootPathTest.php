<?php
/**
 * Boot path tests.
 *
 * @package SugarCommerce\Plugin
 */

declare( strict_types=1 );

namespace SugarCommerce\Plugin\Tests;

use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The files that run before the PHP version is checked parse on PHP 7.4.
 *
 * A requirement of `'php' => '8.3'` is only worth anything if the code
 * checking it runs on the older PHP it is meant to turn away. One `match`
 * in Plugin.php and a host on 7.4 gets a parse error and a white screen
 * instead of the notice naming the version it needs.
 *
 * PHPCompatibility would be the tool, but the release WPCS installs (9.3)
 * predates PHP 8 and finds none of this, so the syntax that arrived in 8.0
 * and later is looked for here, in the tokens.
 */
final class BootPathTest extends TestCase {

	/**
	 * Functions PHP 8 added that the boot path must not call.
	 */
	private const PHP8_FUNCTIONS = [ 'str_starts_with', 'str_ends_with', 'str_contains', 'array_is_list', 'get_debug_type', 'fdiv', 'get_resource_id', 'preg_last_error_msg' ];

	/**
	 * Types PHP 8 added.
	 */
	private const PHP8_TYPES = [ 'mixed', 'never', 'false', 'null', 'true' ];

	/**
	 * The files on the boot path.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function files(): array {
		return [
			'Plugin'       => [ 'Plugin.php' ],
			'Requirements' => [ 'Requirements.php' ],
			'Unmet'        => [ 'Unmet.php' ],
		];
	}

	/**
	 * Nothing from PHP 8.
	 *
	 * @param string $file The file within src/.
	 */
	#[DataProvider( 'files' )]
	public function test_the_file_parses_on_php_7_4( string $file ): void {
		$this->assertSame( [], self::findings( (string) file_get_contents( dirname( __DIR__ ) . '/src/' . $file ) ) );
	}

	/**
	 * The scan finds what it is looking for.
	 */
	public function test_the_scan_finds_php_8(): void {
		$code = <<<'PHP'
<?php
class A {
	public function __construct( private int $x ) {}
	public function b( int|string $c, mixed $d, ): static {
		return match ( $c ) { default => $this?->x };
	}
	#[Attr]
	public readonly int $e;
}
enum F {}
str_contains( 'a', 'b' );
$g = strlen( ... );
$h = $obj::class;
try {} catch ( Exception ) {}
PHP;

		$this->assertSame(
			[
				'constructor promotion',
				'union type',
				'type mixed',
				'trailing comma in parameters',
				'static return type',
				'match',
				'nullsafe operator',
				'attribute',
				'readonly',
				'enum',
				'str_contains()',
				'first-class callable',
				'::class on an object',
				'catch without a variable',
			],
			self::findings( $code )
		);
	}

	/**
	 * What in some code needs PHP 8 or later.
	 *
	 * @param string $code PHP source.
	 *
	 * @return string[]
	 */
	private static function findings( string $code ): array {
		$tokens   = array_values( array_filter( PhpToken::tokenize( $code ), static fn( PhpToken $t ): bool => ! $t->isIgnorable() ) );
		$found    = [];
		$count    = count( $tokens );
		$by_token = [
			T_MATCH                    => 'match',
			T_NULLSAFE_OBJECT_OPERATOR => 'nullsafe operator',
			T_ATTRIBUTE                => 'attribute',
			T_READONLY                 => 'readonly',
			T_ENUM                     => 'enum',
		];

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			$next  = $tokens[ $i + 1 ] ?? null;

			if ( isset( $by_token[ $token->id ] ) ) {
				$found[] = $by_token[ $token->id ];

				continue;
			}

			if ( $token->is( T_STRING ) && null !== $next && $next->is( '(' ) && in_array( strtolower( $token->text ), self::PHP8_FUNCTIONS, true ) ) {
				$prev = $tokens[ $i - 1 ] ?? null;

				if ( null === $prev || ! $prev->is( [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ] ) ) {
					$found[] = $token->text . '()';
				}

				continue;
			}

			if ( $token->is( T_ELLIPSIS ) && null !== $next && $next->is( ')' ) && ( $tokens[ $i - 1 ] ?? null )?->is( '(' ) ) {
				$found[] = 'first-class callable';

				continue;
			}

			if ( $token->is( T_VARIABLE ) && null !== $next && $next->is( T_DOUBLE_COLON ) && ( $tokens[ $i + 2 ] ?? null )?->is( T_CLASS ) ) {
				$found[] = '::class on an object';

				continue;
			}

			if ( $token->is( T_CATCH ) ) {
				for ( $j = $i + 1; $j < $count && ! $tokens[ $j ]->is( ')' ); $j++ ) {
					// To the closing parenthesis.
				}

				if ( ! $tokens[ $j - 1 ]->is( T_VARIABLE ) ) {
					$found[] = 'catch without a variable';
				}

				continue;
			}

			if ( $token->is( [ T_FUNCTION, T_FN ] ) ) {
				$found = array_merge( $found, self::signature( $tokens, $i ) );
			}
		}

		return $found;
	}

	/**
	 * What in a function's signature needs PHP 8.
	 *
	 * From `function` to the body (or the arrow, or the semicolon of an
	 * abstract method).
	 *
	 * @param PhpToken[] $tokens The tokens.
	 * @param int        $start  Where `function` is.
	 *
	 * @return string[]
	 */
	private static function signature( array $tokens, int $start ): array {
		$found   = [];
		$depth   = 0;
		$name    = null;
		$closing = false;

		for ( $i = $start + 1; $i < count( $tokens ); $i++ ) {
			$token = $tokens[ $i ];

			if ( null === $name && $token->is( T_STRING ) ) {
				$name = strtolower( $token->text );
			}

			if ( $token->is( '(' ) ) {
				++$depth;
			} elseif ( $token->is( ')' ) ) {
				--$depth;

				if ( 0 === $depth ) {
					$closing = true;

					if ( $tokens[ $i - 1 ]->is( ',' ) ) {
						$found[] = 'trailing comma in parameters';
					}
				}
			}

			if ( $token->is( [ '{', ';', T_DOUBLE_ARROW ] ) && 0 === $depth ) {
				break;
			}

			if ( 1 === $depth && '__construct' === $name && $token->is( [ T_PUBLIC, T_PROTECTED, T_PRIVATE ] ) ) {
				$found[] = 'constructor promotion';
			}

			if ( $token->is( '|' ) ) {
				$found[] = 'union type';
			}

			if ( $token->is( T_STRING ) && in_array( strtolower( $token->text ), self::PHP8_TYPES, true ) && $tokens[ $i + 1 ]->is( [ T_VARIABLE, '{', ';', T_DOUBLE_ARROW, '|', T_ELLIPSIS ] ) ) {
				$found[] = 'type ' . strtolower( $token->text );
			}

			if ( $closing && $token->is( T_STATIC ) ) {
				$found[] = 'static return type';
			}
		}

		return $found;
	}
}
