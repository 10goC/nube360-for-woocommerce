<?php
/**
 * Base class of the unit tests: sets Brain Monkey and Mockery up, and stubs
 * the WordPress helpers that just pass their argument through.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nube360\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionProperty;
use WP_Error;

abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'__'                  => null,
				'esc_html__'          => null,
				'esc_attr__'          => null,
				'sanitize_text_field' => null,
				'wp_kses_post'        => null,
				'esc_url_raw'         => null,
				'sanitize_title'      => null,
			)
		);
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);

		$this->reset_webhooks_guard();
	}

	protected function tearDown(): void {
		$this->reset_webhooks_guard();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The webhook guard is static state: leave it at zero between tests.
	 */
	private function reset_webhooks_guard() {
		$property = new ReflectionProperty( Webhooks::class, 'suppressed' );
		$property->setAccessible( true );
		$property->setValue( null, 0 );
	}

	/**
	 * Reads a private property.
	 */
	protected function get_private( $object, $name ) {
		$property = new ReflectionProperty( $object, $name );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	/**
	 * Replaces a private property.
	 */
	protected function set_private( $object, $name, $value ) {
		$property = new ReflectionProperty( $object, $name );
		$property->setAccessible( true );
		$property->setValue( $object, $value );
	}

	/**
	 * Calls a private/protected method.
	 */
	protected function call_private( $object, $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $object, $args );
	}
}
