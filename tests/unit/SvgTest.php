<?php
// phpcs:ignoreFile

class SvgTest extends CDNHub_Test_Case {

	private static function svg( $inner, $attrs = '' ) {
		return '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10"' . $attrs . '>' . $inner . '</svg>';
	}

	/** @dataProvider safe */
	public function test_accepts_ordinary_svgs( $xml ) {
		$this->assertTrue( CDNHub_SVG::is_safe( $xml ) );
	}

	public function safe() {
		return array(
			'shapes'          => array( self::svg( '<rect width="10" height="10" fill="#f00" style="stroke:blue"/>' ) ),
			'internal use'    => array( self::svg( '<defs><circle id="c" r="2"/></defs><use href="#c"/><use xlink:href="#c"/>' ) ),
			'inlined png'     => array( self::svg( '<image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>' ) ),
			'linked image'    => array( self::svg( '<image href="https://example.com/a.png"/>' ) ),
			'editor metadata' => array( '<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" inkscape:version="1.3"><g inkscape:label="Layer 1"/></svg>' ),
			'style element'   => array( self::svg( '<style>.a{fill:red}</style><rect class="a"/>' ) ),
			'animate fill'    => array( self::svg( '<rect><animate attributeName="fill" values="red;blue" dur="1s"/></rect>' ) ),
		);
	}

	/** @dataProvider unsafe */
	public function test_rejects_unsafe_svgs( $xml ) {
		$this->assertFalse( CDNHub_SVG::is_safe( $xml ) );
	}

	public function unsafe() {
		return array(
			'script'                 => array( self::svg( '<script>alert(1)</script>' ) ),
			'upper-case script'      => array( self::svg( '<SCRIPT>alert(1)</SCRIPT>' ) ),
			'event handler'          => array( self::svg( '<rect/>', ' onload="alert(1)"' ) ),
			'javascript link'        => array( self::svg( '<a href="javascript:alert(1)"><rect/></a>' ) ),
			'entity-encoded scheme'  => array( self::svg( '<a href="&#106;avascript:alert(1)"><rect/></a>' ) ),
			'tab inside scheme'      => array( self::svg( '<a xlink:href="java&#x09;script:alert(1)"><rect/></a>' ) ),
			'animated href'          => array( self::svg( '<a><animate attributeName="href" values="javascript:alert(1)"/><rect/></a>' ) ),
			'foreignObject'          => array( self::svg( '<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>' ) ),
			'iframe'                 => array( self::svg( '<iframe src="https://example.com"/>' ) ),
			'nested svg data url'    => array( self::svg( '<image href="data:image/svg+xml;base64,PHN2Zz4="/>' ) ),
			'html data url'          => array( self::svg( '<a href="data:text/html,&lt;script&gt;"><rect/></a>' ) ),
			'other scheme in href'   => array( self::svg( '<use href="file:///etc/passwd#x"/>' ) ),
			'css import'             => array( self::svg( '<style>@import url(https://evil.example/x.css);</style>' ) ),
			'css escaped import'     => array( self::svg( '<style>@\69 mport url(https://evil.example/x.css);</style>' ) ),
			'css script url'         => array( self::svg( '<rect style="fill:url(javascript:alert(1))"/>' ) ),
			'css escaped script url' => array( self::svg( '<rect style="fill:url(\6a avascript:alert(1))"/>' ) ),
			'escaped fill url'       => array( self::svg( '<rect fill="url(\6a avascript:alert(1))"/>' ) ),
			'css comment in url'     => array( self::svg( '<rect style="fill:url(java/**/script:alert(1))"/>' ) ),
			'animated event handler' => array( self::svg( '<rect><set attributeName="onload" to="alert(1)"/></rect>' ) ),
			'xml base'               => array( self::svg( '<rect/>', ' xml:base="https://evil.example/"' ) ),
			'entity declaration'     => array( '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>' ),
			'xml stylesheet'         => array( '<?xml version="1.0"?><?xml-stylesheet href="https://evil.example/x.xsl"?><svg xmlns="http://www.w3.org/2000/svg"/>' ),
			'not an svg'             => array( '<html><body>hi</body></html>' ),
			'broken xml'             => array( '<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>' ),
			'empty'                  => array( '' ),
			'payload after padding'  => array( self::svg( '<!--' . str_repeat( 'x', 3 * 1048576 ) . '--><script>alert(1)</script>' ) ),
		);
	}

	public function test_rejects_files_over_the_size_limit() {
		$this->assertFalse( CDNHub_SVG::is_safe( self::svg( '<!--' . str_repeat( 'x', CDNHub_SVG::MAX_BYTES ) . '-->' ) ) );
	}

	public function test_checks_files_on_disk() {
		$file = tempnam( sys_get_temp_dir(), 'svg' );
		file_put_contents( $file, self::svg( '<rect/>' ) );
		$this->assertTrue( CDNHub_SVG::is_safe_file( $file ) );

		file_put_contents( $file, self::svg( '<script>alert(1)</script>' ) );
		$this->assertFalse( CDNHub_SVG::is_safe_file( $file ) );

		unlink( $file );
		$this->assertFalse( CDNHub_SVG::is_safe_file( $file ) );
	}
}
