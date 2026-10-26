<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether an SVG is safe to publish. Unsafe files are rejected (kept on this
 * server), never modified. The whole file is parsed as XML, so tricks that hide from a
 * text search (entity-encoded "javascript:", payloads after the first megabytes,
 * external entities) are caught too.
 */
final class CDNHub_SVG {

	const MAX_BYTES = 5242880; // 5 MB. Larger SVGs are not checked and stay on the server.

	/** Elements that can run code, load documents or embed HTML. Compared in lower case. */
	const BLOCKED_ELEMENTS = array(
		'script',
		'foreignobject',
		'iframe',
		'frame',
		'object',
		'embed',
		'applet',
		'audio',
		'video',
		'link',
		'meta',
		'base',
		'form',
		'input',
		'button',
		'textarea',
		'handler',
		'listener',
	);

	/** Raster images that may be inlined as data: URLs. */
	const SAFE_DATA_IMAGES = '~^data:image/(png|jpe?g|gif|webp|avif|bmp);~';

	/** Elements that animate another attribute; their target/value pairs need extra checks. */
	const ANIMATION_ELEMENTS = array( 'animate', 'animatecolor', 'animatemotion', 'animatetransform', 'set' );

	public static function is_safe_file( $abs ) {
		$size = is_readable( $abs ) ? filesize( $abs ) : false;
		if ( false === $size || $size > self::MAX_BYTES ) {
			return false;
		}
		$xml = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== $xml && self::is_safe( $xml );
	}

	public static function is_safe( $xml ) {
		if ( ! is_string( $xml ) || '' === trim( $xml ) || strlen( $xml ) > self::MAX_BYTES || ! class_exists( 'DOMDocument' ) ) {
			return false;
		}
		// A DTD is never needed for an image and is how entity attacks start; style sheets via processing instructions too.
		if ( preg_match( '~<!DOCTYPE|<!ENTITY|<\?xml-stylesheet~i', $xml ) ) {
			return false;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok || ! $doc->documentElement || 'svg' !== strtolower( $doc->documentElement->localName ) ) {
			return false;
		}

		foreach ( $doc->getElementsByTagName( '*' ) as $el ) {
			$tag = strtolower( $el->localName );
			if ( in_array( $tag, self::BLOCKED_ELEMENTS, true ) ) {
				return false;
			}
			if ( 'style' === $tag && ! self::is_safe_css( $el->textContent ) ) {
				return false;
			}
			foreach ( $el->attributes as $attr ) {
				if ( ! self::is_safe_attribute( strtolower( $attr->localName ), $attr->value, (string) $attr->namespaceURI ) ) {
					return false;
				}
			}
			if ( in_array( $tag, self::ANIMATION_ELEMENTS, true ) && ! self::is_safe_animation( $el ) ) {
				return false;
			}
		}
		return true;
	}

	/** Lower case, without whitespace or control characters ("java\tscript:" → "javascript:"). */
	private static function squash( $value ) {
		return strtolower( (string) preg_replace( '~[\s\x00-\x1f]+~', '', (string) $value ) );
	}

	private static function has_script_url( $squashed ) {
		return (bool) preg_match( '~(javascript|vbscript|livescript):|data:(text/html|application/|image/svg)~', $squashed );
	}

	/**
	 * Decode CSS escape sequences, so "\6a avascript:" is seen as "javascript:".
	 * Both the hex form (\6a, optionally followed by whitespace) and the
	 * one-character form (\j) are valid CSS escapes.
	 */
	private static function decode_css( $css ) {
		$css = preg_replace_callback(
			'~\\\\([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?~',
			function ( $m ) {
				$cp = hexdec( $m[1] );
				if ( $cp <= 0 || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
					return "\xEF\xBF\xBD"; // Replacement character.
				}
				if ( function_exists( 'mb_chr' ) ) {
					return mb_chr( $cp, 'UTF-8' );
				}
				return html_entity_decode( '&#' . $cp . ';', ENT_NOQUOTES, 'UTF-8' );
			},
			(string) $css
		);
		// A backslash escapes the next character literally (e.g. "\j" => "j").
		return preg_replace( '~\\\\(.)~s', '$1', (string) $css );
	}

	private static function is_safe_css( $css ) {
		$css = self::decode_css( (string) $css );
		// CSS comments can split a keyword ("java/**/script:") in the browser's parser.
		$css = preg_replace( '~/\*.*?\*/~s', '', $css );
		$css = self::squash( $css );
		return ! self::has_script_url( $css )
			&& false === strpos( $css, '@import' )
			&& false === strpos( $css, 'expression(' )
			&& false === strpos( $css, '-moz-binding' )
			&& false === strpos( $css, 'behavior:' );
	}

	private static function is_safe_attribute( $name, $value, $ns = '' ) {
		if ( 0 === strpos( $name, 'on' ) ) {
			return false; // Event handlers: onload, onclick, onbegin...
		}
		// xml:base turns relative links into arbitrary absolute ones.
		if ( 'base' === $name && 'http://www.w3.org/XML/1998/namespace' === $ns ) {
			return false;
		}
		// Decode CSS escapes too: presentation attributes such as fill="url(\6a avascript:…)"
		// accept CSS values, so a raw text search would miss the payload.
		$v = self::squash( self::decode_css( $value ) ); // Entities are already decoded by the parser.
		if ( self::has_script_url( $v ) ) {
			return false;
		}
		if ( 'style' === $name && ! self::is_safe_css( $value ) ) {
			return false;
		}
		if ( 'href' === $name || 'src' === $name ) {
			// Fragment links (<use href="#icon">), web images, and inlined raster images only.
			return '' === $v || '#' === $v[0] || (bool) preg_match( '~^https?://~', $v ) || (bool) preg_match( self::SAFE_DATA_IMAGES, $v );
		}
		return true;
	}

	/**
	 * <animate> and <set> can write any attribute over time, including event handlers
	 * and link targets. Check both the target name and every value they can apply.
	 */
	private static function is_safe_animation( $el ) {
		$target = self::squash( $el->getAttribute( 'attributeName' ) );
		if ( '' !== $target ) {
			if ( 0 === strpos( $target, 'on' ) ) {
				return false;
			}
			$target = str_replace( 'xlink:', '', $target );
			if ( in_array( $target, array( 'href', 'src', 'style' ), true ) ) {
				foreach ( array( 'from', 'to', 'by', 'values' ) as $a ) {
					if ( ! $el->hasAttribute( $a ) ) {
						continue;
					}
					foreach ( explode( ';', $el->getAttribute( $a ) ) as $piece ) {
						if ( 'style' === $target ) {
							if ( ! self::is_safe_css( $piece ) ) {
								return false;
							}
						} elseif ( ! self::is_safe_attribute( $target, $piece ) ) {
							return false;
						}
					}
				}
			}
		}
		// Whatever the target, an animated value must not smuggle in a script URL.
		foreach ( array( 'from', 'to', 'by', 'values' ) as $a ) {
			if ( $el->hasAttribute( $a ) && self::has_script_url( self::squash( self::decode_css( $el->getAttribute( $a ) ) ) ) ) {
				return false;
			}
		}
		return true;
	}
}
