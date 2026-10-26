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
				if ( ! self::is_safe_attribute( strtolower( $attr->localName ), $attr->value ) ) {
					return false;
				}
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

	private static function is_safe_css( $css ) {
		$css = self::squash( $css );
		return ! self::has_script_url( $css ) && false === strpos( $css, '@import' ) && false === strpos( $css, 'expression(' ) && false === strpos( $css, '-moz-binding' );
	}

	private static function is_safe_attribute( $name, $value ) {
		if ( 0 === strpos( $name, 'on' ) ) {
			return false; // Event handlers: onload, onclick, onbegin...
		}
		$v = self::squash( $value ); // Entities are already decoded by the parser.
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
}
