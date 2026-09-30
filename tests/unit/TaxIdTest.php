<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Nube360\WooCommerce\TaxId;

/**
 * @covers \Nube360\WooCommerce\TaxId
 */
class TaxIdTest extends TestCase {

	public function test_it_keeps_only_the_digits() {
		$this->assertSame( '20123456783', TaxId::normalize( '20-12345678-3' ) );
		$this->assertSame( '12345678', TaxId::normalize( '12.345.678' ) );
	}

	/**
	 * @dataProvider valid_values
	 */
	public function test_valid_values( $value ) {
		$this->assertTrue( TaxId::is_valid( $value ) );
	}

	public function valid_values() {
		return array(
			'national ID'          => array( '12345678' ),
			'national ID with dots'=> array( '12.345.678' ),
			'7-digit national ID' => array( '1234567' ),
			'tax number'           => array( '20-12345678-6' ),
			'tax number, no dashes'=> array( '30500010912' ),
		);
	}

	/**
	 * @dataProvider invalid_values
	 */
	public function test_invalid_values( $value ) {
		$this->assertFalse( TaxId::is_valid( $value ) );
	}

	public function invalid_values() {
		return array(
			'empty'                => array( '' ),
			'letters'              => array( 'abc' ),
			'too short'            => array( '123456' ),
			'9 digits'             => array( '123456789' ),
			'bad check digit'      => array( '20-12345678-1' ),
		);
	}
}
