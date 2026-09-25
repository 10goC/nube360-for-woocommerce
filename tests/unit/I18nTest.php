<?php
namespace Nube360\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The translations shipped in languages/ stay in step with the source: every
 * string in the code is in the template and translated, and the compiled .mo
 * files match their .po.
 *
 * Runs without WordPress: it only reads files.
 */
class I18nTest extends PHPUnitTestCase {

	const DOMAIN = 'nube360-for-woocommerce';

	private $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 );
	}

	private function source_files() {
		return array_merge(
			array( $this->root . '/nube360-for-woocommerce.php' ),
			glob( $this->root . '/includes/*.php' )
		);
	}

	/**
	 * msgids used in the code: __( 'text', 'domain' ) and its esc_* variants.
	 *
	 * @return string[]
	 */
	private function strings_in_code() {
		$pattern = "/(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1\s*,\s*'([^']+)'/s";
		$found   = array();
		foreach ( $this->source_files() as $file ) {
			preg_match_all( $pattern, file_get_contents( $file ), $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				$this->assertSame( self::DOMAIN, $match[3], basename( $file ) . ': "' . $match[2] . '" uses another text domain' );
				$found[] = str_replace( array( "\\'", '\\"' ), array( "'", '"' ), $match[2] );
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Minimal .po/.pot reader (one string per msgid/msgstr line, as generated).
	 *
	 * @return array msgid => msgstr
	 */
	private function read_po( $file ) {
		$entries = array();
		$msgid   = null;
		foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( '/^msgid "(.*)"$/', $line, $m ) ) {
				$msgid = stripcslashes( $m[1] );
			} elseif ( preg_match( '/^msgstr "(.*)"$/', $line, $m ) && null !== $msgid ) {
				if ( '' !== $msgid ) {
					$entries[ $msgid ] = stripcslashes( $m[1] );
				}
				$msgid = null;
			}
		}
		return $entries;
	}

	/**
	 * Minimal .mo reader.
	 *
	 * @return array msgid => msgstr
	 */
	private function read_mo( $file ) {
		$data  = file_get_contents( $file );
		$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
		$this->assertSame( 0x950412de, $magic, basename( $file ) . ' is not a little-endian .mo file' );
		$header = unpack( 'Vrevision/Vcount/Voriginals/Vtranslations', substr( $data, 4, 16 ) );
		$out    = array();
		for ( $i = 0; $i < $header['count']; $i++ ) {
			$original    = unpack( 'Vlength/Voffset', substr( $data, $header['originals'] + 8 * $i, 8 ) );
			$translation = unpack( 'Vlength/Voffset', substr( $data, $header['translations'] + 8 * $i, 8 ) );
			$msgid       = substr( $data, $original['offset'], $original['length'] );
			if ( '' !== $msgid ) {
				$out[ $msgid ] = substr( $data, $translation['offset'], $translation['length'] );
			}
		}
		return $out;
	}

	private function placeholders( $string ) {
		preg_match_all( '/%(?:\d+\$)?[sdf]/', $string, $m );
		sort( $m[0] );
		return $m[0];
	}

	public function test_the_template_has_exactly_the_strings_of_the_code() {
		$template = array_keys( $this->read_po( $this->root . '/languages/' . self::DOMAIN . '.pot' ) );
		$code     = $this->strings_in_code();

		sort( $template );
		sort( $code );

		$this->assertSame( $code, $template, 'languages/' . self::DOMAIN . '.pot is out of date: regenerate it.' );
	}

	/**
	 * @dataProvider locales
	 */
	public function test_every_string_is_translated_keeping_its_placeholders( $locale ) {
		$po = $this->read_po( $this->root . "/languages/" . self::DOMAIN . "-$locale.po" );

		foreach ( $this->strings_in_code() as $msgid ) {
			$this->assertArrayHasKey( $msgid, $po, "$locale is missing: $msgid" );
			$this->assertNotSame( '', $po[ $msgid ], "$locale has an empty translation for: $msgid" );
			$this->assertSame( $this->placeholders( $msgid ), $this->placeholders( $po[ $msgid ] ), "$locale changes the placeholders of: $msgid" );
		}
		$this->assertSame( array(), array_diff( array_keys( $po ), $this->strings_in_code() ), "$locale has translations of strings that no longer exist" );
	}

	/**
	 * @dataProvider locales
	 */
	public function test_the_compiled_file_matches_the_po( $locale ) {
		$po = $this->read_po( $this->root . "/languages/" . self::DOMAIN . "-$locale.po" );
		$mo = $this->read_mo( $this->root . "/languages/" . self::DOMAIN . "-$locale.mo" );

		ksort( $po );
		ksort( $mo );
		$this->assertSame( $po, $mo, "languages/" . self::DOMAIN . "-$locale.mo is out of date: recompile it with msgfmt." );
	}

	public function locales() {
		return array(
			'es_ES' => array( 'es_ES' ),
			'es_AR' => array( 'es_AR' ),
		);
	}

	public function test_the_source_of_the_plugin_is_in_english() {
		$files = array_merge( $this->source_files(), array( $this->root . '/uninstall.php' ), glob( $this->root . '/assets/*.{js,css}', GLOB_BRACE ) );

		foreach ( $files as $file ) {
			$this->assertSame( 0, preg_match( '/[áéíóúüñÁÉÍÓÚÜÑ¿¡]/u', file_get_contents( $file ), $m ), basename( $file ) . ' contains Spanish text' . ( $m ? ': ' . $m[0] : '' ) );
		}
	}
}
