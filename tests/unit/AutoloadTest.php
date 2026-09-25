<?php
namespace Nube360\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The PSR-4 autoloader in the main plugin file finds a class by its file name,
 * so file and class names have to match exactly. macOS ignores case; Linux, where
 * the plugin will run, does not.
 */
class AutoloadTest extends PHPUnitTestCase {

	public function test_every_file_in_includes_declares_the_class_it_is_named_after() {
		$files = glob( dirname( __DIR__, 2 ) . '/includes/*.php' );
		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$this->assertSame( 1, preg_match( '/^namespace Nube360\\\\WooCommerce;$/m', file_get_contents( $file ) ), basename( $file ) . ' is in the plugin namespace' );
			$this->assertSame( 1, preg_match( '/^(?:final |abstract )?class (\w+)/m', file_get_contents( $file ), $match ), basename( $file ) . ' declares a class' );
			$this->assertSame( basename( $file, '.php' ), $match[1], 'File name and class name must match exactly.' );
		}
	}

	public function test_no_file_uses_the_old_wordpress_style_names() {
		$this->assertSame( array(), glob( dirname( __DIR__, 2 ) . '/includes/class-*.php' ) );
	}

	public function test_the_main_file_no_longer_requires_classes_by_hand() {
		$this->assertStringNotContainsString( "includes/class-", file_get_contents( dirname( __DIR__, 2 ) . '/nube360-for-woocommerce.php' ) );
		$this->assertDoesNotMatchRegularExpression( '/require(_once)?\s+DIR\s*\./', file_get_contents( dirname( __DIR__, 2 ) . '/nube360-for-woocommerce.php' ) );
	}
}
