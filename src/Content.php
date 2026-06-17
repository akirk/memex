<?php

namespace Memex;

class Content {
	public static function plain_text_to_blocks( string $text, string $timestamp = '' ): string {
		$text  = str_replace( "\r\n", "\n", $text );
		$paras = preg_split( '/\n\s*\n/', $text );
		$out   = array();
		$first = true;
		foreach ( $paras as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$lines = array_map( 'esc_html', explode( "\n", $p ) );
			$inner = implode( '<br>', $lines );
			if ( $first && '' !== $timestamp ) {
				$inner = '<strong>' . esc_html( $timestamp ) . '</strong> &middot; ' . $inner;
				$first = false;
			}
			$out[] = "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->";
		}
		return implode( "\n\n", $out );
	}

	public static function markdown_to_html( string $markdown ): string {
		$markdown = str_replace( "\r\n", "\n", $markdown );
		$markdown = preg_replace_callback(
			'/\\\\([\\\\`*_{}\[\]()#+\-.!|>])/',
			static function ( $m ) {
				return 'MEMEXESCAPEDCHARTOKEN' . base64_encode( $m[1] ) . 'ENDMEMEXESCAPEDCHARTOKEN';
			},
			$markdown
		);
		$markdown = str_replace( '\\', 'MEMEXBACKSLASHTOKEN', $markdown );
		$markdown = preg_replace_callback(
			'/\[\[([^\[\]]+?)\]\]/',
			static function ( $m ) {
				return 'MEMEXLINKTOKEN' . base64_encode( $m[1] ) . 'ENDMEMEXLINKTOKEN';
			},
			$markdown
		);

		$parser = new \Parsedown();
		if ( method_exists( $parser, 'setSafeMode' ) ) {
			$parser->setSafeMode( false );
		}
		$html = $parser->text( $markdown );
		$html = preg_replace_callback(
			'/MEMEXLINKTOKEN([A-Za-z0-9+\/=]+)ENDMEMEXLINKTOKEN/',
			static function ( $m ) {
				return '[[' . base64_decode( $m[1] ) . ']]';
			},
			$html
		);
		$html = str_replace( 'MEMEXBACKSLASHTOKEN', '\\', $html );
		$html = preg_replace_callback(
			'/MEMEXESCAPEDCHARTOKEN([A-Za-z0-9+\/=]+)ENDMEMEXESCAPEDCHARTOKEN/',
			static function ( $m ) {
				return '\\' . base64_decode( $m[1] );
			},
			$html
		);
		return (string) $html;
	}

	public static function editor_text_from_html( string $content ): string {
		$content = Links::internal_anchors_to_shorthand( $content );
		$markdown = self::html_to_editor_markdown( $content );
		if ( '' !== $markdown ) {
			return $markdown;
		}
		$content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $content );
		$content = preg_replace( '/<br\s*\/?>/i', "\n", $content );
		$content = preg_replace( '/<\/(p|div|li|h[1-6])\s*>/i', "\n\n", $content );
		$content = preg_replace( '/<hr\b[^>]*>/i', "\n\n---\n\n", $content );
		$text    = wp_strip_all_tags( $content, true );
		$text    = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text    = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	private static function html_to_editor_markdown( string $content ): string {
		$content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $content );
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return '';
		}

		$blocks  = array();
		$offset  = 0;
		$pattern = '/<(h[1-6]|p|ul|ol|blockquote|pre)\b([^>]*)>(.*?)<\/\1>|<hr\b[^>]*>/is';
		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $m ) {
				$raw_match = $m[0][0];
				$position  = $m[0][1];
				self::append_editor_html_fragment( $blocks, substr( $content, $offset, $position - $offset ) );
				$offset = $position + strlen( $raw_match );

				$tag = isset( $m[1][0] ) ? strtolower( $m[1][0] ) : '';
				if ( '' === $tag && 0 === stripos( $raw_match, '<hr' ) ) {
					$blocks[] = '---';
					continue;
				}
				$inner = $m[3][0] ?? '';
				if ( preg_match( '/^h([1-6])$/', $tag, $h ) ) {
					$text = self::html_inline_to_markdown( $inner );
					if ( '' !== $text ) {
						$blocks[] = str_repeat( '#', (int) $h[1] ) . ' ' . $text;
					}
					continue;
				}
				if ( 'p' === $tag ) {
					$text = self::html_inline_to_markdown( $inner );
					if ( '' !== $text ) {
						$blocks[] = $text;
					}
					continue;
				}
				if ( 'ul' === $tag || 'ol' === $tag ) {
					$items = array();
					if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $inner, $li_matches ) ) {
						foreach ( $li_matches[1] as $i => $li ) {
							$text = self::html_inline_to_markdown( $li );
							if ( '' !== $text ) {
								$items[] = ( 'ol' === $tag ? ( $i + 1 ) . '. ' : '- ' ) . $text;
							}
						}
					}
					if ( $items ) {
						$blocks[] = implode( "\n", $items );
					}
					continue;
				}
				if ( 'blockquote' === $tag ) {
					$text = self::html_inline_to_markdown( $inner );
					if ( '' !== $text ) {
						$blocks[] = '> ' . str_replace( "\n", "\n> ", $text );
					}
					continue;
				}
				if ( 'pre' === $tag ) {
					$code = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
					$blocks[] = "```\n" . trim( $code, "\n" ) . "\n```";
				}
			}
		}
		self::append_editor_html_fragment( $blocks, substr( $content, $offset ) );

		$markdown = trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n\n", $blocks ) ) );
		$markdown = preg_replace( '/^([ \t]*)[–—][ \t]+/mu', '$1- ', $markdown );
		return $markdown;
	}

	private static function append_editor_html_fragment( array &$blocks, string $html ): void {
		$html = trim( $html );
		if ( '' !== $html ) {
			$blocks[] = $html;
		}
	}

	private static function html_inline_to_markdown( string $html ): string {
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$html = preg_replace( '/<code\b[^>]*>(.*?)<\/code>/is', '`$1`', $html );
		$html = preg_replace( '/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $html );
		$html = preg_replace( '/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $html );
		$html = preg_replace_callback(
			'/<a\b([^>]*)>(.*?)<\/a>/is',
			static function ( $m ) {
				if ( 0 === strpos( wp_strip_all_tags( $m[2] ), '[[' ) ) {
					return wp_strip_all_tags( $m[2] );
				}
				if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $m[1], $href ) ) {
					return wp_strip_all_tags( $m[2] );
				}
				return '[' . wp_strip_all_tags( $m[2] ) . '](' . $href[2] . ')';
			},
			$html
		);
		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		return trim( $text );
	}
}
