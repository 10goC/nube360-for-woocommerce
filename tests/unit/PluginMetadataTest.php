<?php
namespace Nube360\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Release hygiene: the metadata WordPress and WordPress.org read has to agree
 * with itself and with the code.
 */
class PluginMetadataTest extends PHPUnitTestCase {

	private $root;
	private $header;
	private $readme;

	protected function setUp(): void {
		$this->root   = dirname( __DIR__, 2 );
		$this->header = $this->read_header( $this->root . '/nube360-for-woocommerce.php' );
		$this->readme = $this->read_readme( $this->root . '/readme.txt' );
	}

	private function read_header( $file ) {
		preg_match( '#/\*\*(.*?)\*/#s', file_get_contents( $file ), $block );
		$header = array();
		foreach ( explode( "\n", $block[1] ) as $line ) {
			if ( preg_match( '/^\s*\*\s*([A-Za-z ]+):\s*(.+?)\s*$/', $line, $m ) ) {
				$header[ $m[1] ] = $m[2];
			}
		}
		return $header;
	}

	private function read_readme( $file ) {
		$readme = array();
		foreach ( explode( "\n", file_get_contents( $file ) ) as $line ) {
			if ( '' === trim( $line ) ) {
				break;
			}
			if ( preg_match( '/^([A-Za-z ]+):\s*(.+?)\s*$/', $line, $m ) ) {
				$readme[ $m[1] ] = $m[2];
			}
		}
		return $readme;
	}

	public function test_the_main_file_is_named_after_the_plugin_folder() {
		$this->assertSame( basename( $this->root ), 'nube360-for-woocommerce' );
		$this->assertFileExists( $this->root . '/nube360-for-woocommerce.php' );
	}

	public function test_the_text_domain_is_the_slug() {
		$this->assertSame( 'nube360-for-woocommerce', $this->header['Text Domain'] );
		$this->assertSame( '/languages', $this->header['Domain Path'] );
		$this->assertDirectoryExists( $this->root . '/languages' );
	}

	public function test_woocommerce_is_declared_as_a_required_plugin() {
		$this->assertSame( 'woocommerce', $this->header['Requires Plugins'] );
		$this->assertSame( 'woocommerce', $this->readme['Requires Plugins'] );
	}

	public function test_the_versions_agree() {
		$this->assertSame( $this->header['Version'], $this->readme['Stable tag'] );
		$this->assertMatchesRegularExpression( "/define\\( __NAMESPACE__ \\. '\\\\\\\\?VERSION', '" . preg_quote( $this->header['Version'], '/' ) . "' \\);/", file_get_contents( $this->root . '/nube360-for-woocommerce.php' ) );
		$this->assertStringContainsString( '= ' . $this->header['Version'] . ' =', file_get_contents( $this->root . '/readme.txt' ), 'The changelog has an entry for the current version.' );
	}

	public function test_the_minimum_requirements_agree() {
		$this->assertSame( $this->header['Requires at least'], $this->readme['Requires at least'] );
		$this->assertSame( $this->header['Requires PHP'], $this->readme['Requires PHP'] );

		$composer = json_decode( file_get_contents( $this->root . '/composer.json' ), true );
		$this->assertSame( '>=' . $this->header['Requires PHP'], $composer['require']['php'] );
	}

	public function test_the_license_is_gpl_compatible_and_consistent() {
		$this->assertStringContainsString( 'GPL', $this->header['License'] );
		$this->assertStringContainsString( 'GPLv2', $this->readme['License'] );
		$this->assertStringContainsString( 'gnu.org/licenses/gpl-2.0', $this->header['License URI'] );
	}

	public function test_the_file_starts_with_the_namespace_declaration() {
		$code = file_get_contents( $this->root . '/nube360-for-woocommerce.php' );
		$code = preg_replace( '#/\*\*.*?\*/#s', '', $code, 1 );

		$this->assertMatchesRegularExpression( '/^<\?php\s+namespace Nube360\\\\WooCommerce;/', $code );
	}

	public function test_the_local_test_config_is_ignored_and_a_dist_template_is_versioned() {
		$this->assertFileExists( $this->root . '/tests/integration/wp-tests-config.php.dist' );
		$this->assertContains( 'tests/integration/wp-tests-config.php', file( $this->root . '/.gitignore', FILE_IGNORE_NEW_LINES ) );
		$this->assertStringContainsString(
			'must contain "test"',
			str_replace( '\\"', '"', file_get_contents( $this->root . '/tests/integration/wp-tests-config.php.dist' ) ),
			'The template keeps the guard against pointing the suite at a real database.'
		);
	}

	public function test_the_ignore_file_keeps_the_development_files_out_of_the_release() {
		$this->assertFileExists( $this->root . '/.distignore' );
		$ignored = file( $this->root . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		foreach ( array( 'tests', 'vendor', 'composer.json', 'phpunit.unit.xml.dist', 'phpunit.integration.xml.dist', '.git', '.gitignore', 'AGENTS.md', 'CLAUDE.md' ) as $path ) {
			$this->assertContains( $path, $ignored, "$path must not be released" );
		}
	}
}
