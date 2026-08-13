<?php
/**
 * Catalog Health Scanner for WooCommerce - Minimal PDF Writer Class
 *
 * A small, dependency-free PDF 1.4 generator: Helvetica text, filled
 * rectangles, and JPEG images. Enough for the audit report, with no
 * external library shipped or loaded.
 *
 * Coordinates are given from the TOP-left in points; conversion to PDF's
 * bottom-left origin happens internally. Page size is A4.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_PDF' ) ) :

class WPFCHS_PDF {

	const PAGE_W = 595.28;
	const PAGE_H = 841.89;

	/**
	 * Per-page content streams.
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $pages = array();

	/**
	 * Images used per page: page index => name => image data.
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $images = array();

	/**
	 * Registered image blobs.
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $image_blobs = array();

	/**
	 * Current page index.
	 *
	 * @var     int
	 * @since   1.0.0
	 */
	protected $current = -1;

	/**
	 * add_page.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function add_page() {
		$this->pages[] = '';
		$this->current = count( $this->pages ) - 1;
	}

	/**
	 * Appends raw operators to the current page stream.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $ops
	 */
	protected function raw( $ops ) {
		if ( $this->current < 0 ) {
			$this->add_page();
		}
		$this->pages[ $this->current ] .= $ops . "\n";
	}

	/**
	 * Hex color to "r g b" PDF triple.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $hex
	 * @return  string
	 */
	protected function color( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			$hex = '1d2327';
		}
		return sprintf(
			'%.3F %.3F %.3F',
			hexdec( substr( $hex, 0, 2 ) ) / 255,
			hexdec( substr( $hex, 2, 2 ) ) / 255,
			hexdec( substr( $hex, 4, 2 ) ) / 255
		);
	}

	/**
	 * Codepoints living in WinAnsi's 0x80-0x9F block — the range where
	 * WinAnsi (CP1252) differs from Latin-1, and where nearly all of this
	 * report's punctuation lives: en dash, em dash, curly quotes, bullet,
	 * ellipsis, and the angle quotes used as breadcrumb separators.
	 *
	 * Converting to Latin-1 instead turns every one of them into "?", which
	 * is why the fonts declare WinAnsiEncoding and this table exists.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array Unicode codepoint => WinAnsi byte.
	 */
	protected static function winansi_high() {
		return array(
			0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84,
			0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88,
			0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
			0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93,
			0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
			0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
			0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
		);
	}

	/**
	 * ASCII stand-ins for characters WinAnsi has no glyph for at all.
	 *
	 * Emitting "?" for these is what makes a report look broken, so anything
	 * unmappable gets a deliberate ASCII equivalent — or is dropped — but is
	 * never turned into a question mark.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array Unicode codepoint => replacement string.
	 */
	protected static function transliterations() {
		return array(
			0x2192 => '->', 0x2190 => '<-', 0x2194 => '<->',
			0x2191 => '^',  0x2193 => 'v',
			0x2011 => '-',  0x2012 => '-',  0x2015 => '-', 0x2212 => '-',
			0x2264 => '<=', 0x2265 => '>=', 0x2260 => '!=',
			0x2713 => 'v',  0x2714 => 'v',  0x2717 => 'x', 0x2718 => 'x',
			0x00A0 => ' ',  0x202F => ' ',  0x2007 => ' ', 0x2009 => ' ',
			0x00AD => '',   0x200B => '',   0xFEFF => '',
		);
	}

	/**
	 * Converts UTF-8 to the WinAnsi (CP1252) bytes the report fonts declare.
	 *
	 * Two rules govern this, and they pull in opposite directions:
	 *
	 * - Template text must render correctly. Anything WinAnsi cannot carry is
	 *   transliterated to ASCII, never emitted as "?".
	 * - Product data must render literally. Genuinely mojibake titles are a
	 *   finding this plugin reports, so they are reproduced byte-faithfully
	 *   rather than repaired on the way into the PDF.
	 *
	 * HTML entities are decoded first: WordPress stores titles encoded, and
	 * "&#8211;" printed literally is a defect, not faithful reproduction.
	 *
	 * Decoding is done by hand rather than with mb_convert_encoding() because
	 * that function substitutes "?" for anything unmappable, which is exactly
	 * the outcome being prevented.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $text
	 * @return  string
	 */
	protected function to_winansi( $text ) {

		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$high  = self::winansi_high();
		$trans = self::transliterations();
		$out   = '';
		$len   = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {

			$byte = ord( $text[ $i ] );

			if ( $byte < 0x80 ) {
				$out .= $text[ $i ];
				continue;
			}

			// Decode one UTF-8 sequence. A stray or malformed byte is passed
			// through as-is: it is already CP1252-ish, and mangling it would
			// destroy exactly the corruption the report means to show.
			if ( 0xC0 === ( $byte & 0xE0 ) ) {
				$extra = 1;
				$cp    = $byte & 0x1F;
			} elseif ( 0xE0 === ( $byte & 0xF0 ) ) {
				$extra = 2;
				$cp    = $byte & 0x0F;
			} elseif ( 0xF0 === ( $byte & 0xF8 ) ) {
				$extra = 3;
				$cp    = $byte & 0x07;
			} else {
				$out .= chr( $byte );
				continue;
			}

			$valid = true;
			for ( $k = 1; $k <= $extra; $k++ ) {
				if ( $i + $k >= $len || 0x80 !== ( ord( $text[ $i + $k ] ) & 0xC0 ) ) {
					$valid = false;
					break;
				}
				$cp = ( $cp << 6 ) | ( ord( $text[ $i + $k ] ) & 0x3F );
			}
			if ( ! $valid ) {
				$out .= chr( $byte );
				continue;
			}
			$i += $extra;

			if ( isset( $high[ $cp ] ) ) {
				$out .= chr( $high[ $cp ] );
			} elseif ( isset( $trans[ $cp ] ) ) {
				$out .= $trans[ $cp ];
			} elseif ( $cp <= 0xFF ) {
				$out .= chr( $cp );
			} else {
				// Last resort: strip the accent to ASCII if that works, and
				// drop the character if it does not. Anything but "?".
				$ascii = remove_accents( substr( $text, $i - $extra, $extra + 1 ) );
				$out  .= ( '' !== $ascii && ! preg_match( '/[\x80-\xFF]/', $ascii ) ? $ascii : '' );
			}
		}

		return $out;

	}

	/**
	 * Escapes text for a PDF string literal, in the fonts' WinAnsi encoding.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $text
	 * @return  string
	 */
	protected function escape( $text ) {
		return str_replace(
			array( '\\', '(', ')', "\r", "\n" ),
			array( '\\\\', '\\(', '\\)', ' ', ' ' ),
			$this->to_winansi( $text )
		);
	}

	/**
	 * Rough Helvetica string width in points (for alignment).
	 *
	 * Measured on the encoded bytes: a multi-byte character occupies one
	 * glyph, so measuring the raw UTF-8 string would over-count it and push
	 * right-aligned text off its anchor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $text
	 * @param   float  $size
	 * @param   bool   $bold
	 * @return  float
	 */
	function width( $text, $size, $bold = false ) {
		return strlen( $this->to_winansi( $text ) ) * $size * ( $bold ? 0.53 : 0.50 );
	}

	/**
	 * Draws text. $x/$y are the top-left baseline anchor from page top.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   float  $x
	 * @param   float  $y      Distance from the TOP of the page to the baseline.
	 * @param   string $text
	 * @param   float  $size
	 * @param   bool   $bold
	 * @param   string $hex
	 * @param   string $align  'left', 'center', or 'right' relative to $x.
	 */
	function text( $x, $y, $text, $size = 10, $bold = false, $hex = '#1d2327', $align = 'left' ) {
		if ( 'right' === $align ) {
			$x -= $this->width( $text, $size, $bold );
		} elseif ( 'center' === $align ) {
			$x -= $this->width( $text, $size, $bold ) / 2;
		}
		$this->raw(
			sprintf(
				"BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET",
				$this->color( $hex ),
				( $bold ? 'F2' : 'F1' ),
				$size,
				$x,
				self::PAGE_H - $y,
				$this->escape( $text )
			)
		);
	}

	/**
	 * Filled rectangle. $x/$y from page top-left.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   float  $x
	 * @param   float  $y
	 * @param   float  $w
	 * @param   float  $h
	 * @param   string $hex
	 */
	function rect( $x, $y, $w, $h, $hex ) {
		$this->raw(
			sprintf(
				'%s rg %.2F %.2F %.2F %.2F re f',
				$this->color( $hex ),
				$x,
				self::PAGE_H - $y - $h,
				$w,
				$h
			)
		);
	}

	/**
	 * Places an image of any GD-readable format (JPEG, PNG, WebP, GIF).
	 *
	 * PDF can only carry JPEG data through the DCTDecode filter this writer
	 * implements, so anything else is transcoded with GD first. Transparency
	 * is flattened onto white, which is what a logo on a light report page
	 * needs anyway.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $data Raw image bytes.
	 * @param   float  $x
	 * @param   float  $y    From page top.
	 * @param   float  $w    Display width; height keeps aspect ratio.
	 * @return  bool
	 */
	function image( $data, $x, $y, $w ) {

		if ( $this->image_jpeg( $data, $x, $y, $w ) ) {
			return true;
		}

		$jpeg = $this->to_jpeg( $data );

		return ( '' !== $jpeg && $this->image_jpeg( $jpeg, $x, $y, $w ) );

	}

	/**
	 * Transcodes a non-JPEG image to JPEG bytes, flattening any alpha
	 * channel onto white. Returns an empty string when GD is unavailable or
	 * the data is not a readable image.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $data
	 * @return  string
	 */
	protected function to_jpeg( $data ) {

		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
			return '';
		}

		$source = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing possibly-unsupported image data; failure is handled.
		if ( ! $source ) {
			return '';
		}

		$width  = imagesx( $source );
		$height = imagesy( $source );

		$canvas = imagecreatetruecolor( $width, $height );
		if ( ! $canvas ) {
			imagedestroy( $source );
			return '';
		}

		// Flatten onto white so transparent PNG/WebP logos do not come out
		// on a black background.
		$white = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $white );
		imagecopy( $canvas, $source, 0, 0, 0, 0, $width, $height );

		ob_start();
		imagejpeg( $canvas, null, 92 );
		$jpeg = (string) ob_get_clean();

		imagedestroy( $source );
		imagedestroy( $canvas );

		return $jpeg;

	}

	/**
	 * Places a JPEG image. Returns false when the blob is not a usable JPEG.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $jpeg_data Raw JPEG bytes.
	 * @param   float  $x
	 * @param   float  $y         From page top.
	 * @param   float  $w         Display width; height keeps aspect ratio.
	 * @return  bool
	 */
	function image_jpeg( $jpeg_data, $x, $y, $w ) {

		if ( ! function_exists( 'getimagesizefromstring' ) ) {
			return false;
		}
		$info = @getimagesizefromstring( $jpeg_data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing possibly-invalid image data; failure is handled.
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] || $info[0] < 1 ) {
			return false;
		}

		$channels    = ( $info['channels'] ?? 3 );
		$colorspace  = ( 1 === $channels ? '/DeviceGray' : ( 4 === $channels ? '/DeviceCMYK' : '/DeviceRGB' ) );
		$name        = 'Im' . ( count( $this->image_blobs ) + 1 );
		$this->image_blobs[ $name ] = array(
			'data'       => $jpeg_data,
			'width'      => $info[0],
			'height'     => $info[1],
			'colorspace' => $colorspace,
		);

		$h = $w * ( $info[1] / $info[0] );

		if ( $this->current < 0 ) {
			$this->add_page();
		}
		$this->images[ $this->current ][ $name ] = true;

		$this->raw(
			sprintf(
				'q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q',
				$w,
				$h,
				$x,
				self::PAGE_H - $y - $h,
				$name
			)
		);

		return true;

	}

	/**
	 * Assembles the document.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string PDF bytes.
	 */
	function output() {

		if ( empty( $this->pages ) ) {
			$this->add_page();
		}

		$objects = array();

		// 1: Catalog, 2: Pages, 3: F1, 4: F2.
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$next = 5;

		// Image XObjects.
		$image_ids = array();
		foreach ( $this->image_blobs as $name => $image ) {
			$image_ids[ $name ] = $next;
			$objects[ $next ]   = sprintf(
				"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
				$image['width'],
				$image['height'],
				$image['colorspace'],
				strlen( $image['data'] ),
				$image['data']
			);
			$next++;
		}

		// Pages + content streams.
		$page_ids = array();
		foreach ( $this->pages as $index => $stream ) {

			$content_id             = $next;
			$objects[ $content_id ] = sprintf(
				"<< /Length %d >>\nstream\n%s\nendstream",
				strlen( $stream ),
				$stream
			);
			$next++;

			$xobjects = '';
			if ( ! empty( $this->images[ $index ] ) ) {
				$refs = array();
				foreach ( array_keys( $this->images[ $index ] ) as $name ) {
					$refs[] = '/' . $name . ' ' . $image_ids[ $name ] . ' 0 R';
				}
				$xobjects = ' /XObject << ' . implode( ' ', $refs ) . ' >>';
			}

			$page_ids[]       = $next;
			$objects[ $next ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>',
				self::PAGE_W,
				self::PAGE_H,
				$xobjects,
				$content_id
			);
			$next++;

		}

		$kids       = implode(
			' ',
			array_map(
				function ( $id ) {
					return $id . ' 0 R';
				},
				$page_ids
			)
		);
		$objects[2] = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', $kids, count( $page_ids ) );

		ksort( $objects );

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_pos = strlen( $pdf );
		$max_id   = max( array_keys( $objects ) );
		$pdf     .= "xref\n0 " . ( $max_id + 1 ) . "\n";
		$pdf     .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= $max_id; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", ( $offsets[ $i ] ?? 0 ) );
		}
		$pdf .= "trailer\n<< /Size " . ( $max_id + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref_pos . "\n%%EOF";

		return $pdf;

	}

}

endif;
