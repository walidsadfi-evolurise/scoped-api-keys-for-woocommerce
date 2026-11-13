<?php
/**
 * Minimal runner for environments without PHPUnit (CI uses the real thing).
 *
 * Usage: php tests/run-without-phpunit.php
 *
 * @package ScopedApiKeys
 */

namespace PHPUnit\Framework {
	if ( ! class_exists( TestCase::class ) ) {
		/**
		 * Shim with the few assertions the tests use.
		 */
		abstract class TestCase {
			/**
			 * Assertions made.
			 *
			 * @var int
			 */
			public static $assertions = 0;

			/**
			 * Setup hook.
			 */
			protected function setUp(): void {}

			/**
			 * Strict equality.
			 *
			 * @param mixed  $expected Expected.
			 * @param mixed  $actual   Actual.
			 * @param string $message  Message.
			 */
			public function assertSame( $expected, $actual, $message = '' ) {
				self::$assertions++;
				if ( $expected !== $actual ) {
					throw new \RuntimeException( ( $message ? $message . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
				}
			}

			/**
			 * True.
			 *
			 * @param mixed  $v       Value.
			 * @param string $message Message.
			 */
			public function assertTrue( $v, $message = '' ) {
				$this->assertSame( true, (bool) $v, $message );
			}

			/**
			 * Null.
			 *
			 * @param mixed  $v       Value.
			 * @param string $message Message.
			 */
			public function assertNull( $v, $message = '' ) {
				$this->assertSame( null, $v, $message );
			}

			/**
			 * Not false.
			 *
			 * @param mixed  $v       Value.
			 * @param string $message Message.
			 */
			public function assertNotFalse( $v, $message = '' ) {
				self::$assertions++;
				if ( false === $v ) {
					throw new \RuntimeException( $message ? $message : 'expected not false' );
				}
			}

			/**
			 * Contains.
			 *
			 * @param mixed  $needle   Needle.
			 * @param array  $haystack Haystack.
			 * @param string $message  Message.
			 */
			public function assertContains( $needle, $haystack, $message = '' ) {
				self::$assertions++;
				if ( ! in_array( $needle, (array) $haystack, true ) ) {
					throw new \RuntimeException( $message ? $message : 'expected to contain ' . var_export( $needle, true ) );
				}
			}

			/**
			 * False.
			 *
			 * @param mixed  $v       Value.
			 * @param string $message Message.
			 */
			public function assertFalse( $v, $message = '' ) {
				$this->assertSame( false, (bool) $v, $message );
			}
		}
	}
}

namespace {
	require_once __DIR__ . '/bootstrap.php';
	$files  = glob( __DIR__ . '/*Test.php' );
	$failed = 0;
	$ran    = 0;
	foreach ( $files as $file ) {
		require_once $file;
		$class = basename( $file, '.php' );
		foreach ( get_class_methods( $class ) as $method ) {
			if ( 0 !== strpos( $method, 'test' ) ) {
				continue;
			}
			$ran++;
			$t = new $class();
			try {
				$setup = new ReflectionMethod( $t, 'setUp' );
				$setup->setAccessible( true );
				$setup->invoke( $t );
				$t->$method();
				echo "ok   {$class}::{$method}\n";
			} catch ( Throwable $e ) {
				$failed++;
				echo "FAIL {$class}::{$method} — " . $e->getMessage() . "\n";
			}
		}
	}
	printf( "\n%d tests, %d assertions, %d failures\n", $ran, PHPUnit\Framework\TestCase::$assertions, $failed );
	exit( $failed ? 1 : 0 );
}
