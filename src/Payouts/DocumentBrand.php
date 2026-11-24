<?php
/**
 * Logo and footer on credit notes, correction credit notes and cancellations, logo in HTML mails
 * (SET-AC-16, owner decision S6: logo and sender on documents and e-mails are part of the core).
 *
 * - The logo is an image of the media library (stored as attachment ID). The PDF reads the local
 *   file of the attachment – never a URL, nothing is fetched from another server.
 * - Logo (attachment ID, SHA-256 of the file, height) and footer text are frozen with the document
 *   data (operator_snapshot) when the payout or correction is created. A document built again
 *   later (duplicate) prints exactly these: the logo only while the file still has the frozen hash,
 *   otherwise none. Documents issued before this version have no such data and stay as they were.
 * - Nothing personal is stored: company data and an image of the media library.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class DocumentBrand {

	/** Image types accepted for the logo. */
	const MIMES = array( 'image/png', 'image/jpeg' );

	/** Footer: most lines entered and printed, most characters per entered line. */
	const FOOTER_LINES = 4;
	const FOOTER_PRINTED = 6;
	const FOOTER_LINE_CHARS = 200;

	/** Widest logo on a document (points); a wider picture is scaled down. */
	const MAX_WIDTH = 250;

	/** Mail logo: largest shown size in pixels. */
	const MAIL_WIDTH  = 200;
	const MAIL_HEIGHT = 60;

	/**
	 * Local file of an image attachment ('' when there is none on this server, e.g. an attachment
	 * offloaded to a remote stream).
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function file_of( $id ) {
		$id = (int) $id;
		if ( $id <= 0 || ! function_exists( 'get_attached_file' ) ) {
			return '';
		}
		$file = (string) get_attached_file( $id );
		if ( '' === $file || false !== strpos( $file, '://' ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}
		return $file;
	}

	/**
	 * Load and check a logo of the media library.
	 *
	 * @param int         $id     Attachment ID.
	 * @param int         $max_kb Largest file size in KB (0 = the setting).
	 * @param string|null $error  Out: message when the image cannot be used.
	 * @return array|null info (Pdf::image_info()), sha256, bytes.
	 */
	public static function load( $id, $max_kb = 0, &$error = null ) {
		$error  = '';
		$id     = (int) $id;
		$max_kb = $max_kb > 0 ? (int) $max_kb : (int) Settings::get( 'document_logo_max_kb' );
		$post   = $id > 0 && function_exists( 'get_post' ) ? get_post( $id ) : null;
		if ( ! $post || ( isset( $post->post_type ) && 'attachment' !== $post->post_type ) ) {
			$error = __( 'This image was not found in the media library.', 'affiliate-captain' );
			return null;
		}
		$mime = function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( $id ) : '';
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			$error = __( 'Please choose a PNG or JPEG image.', 'affiliate-captain' );
			return null;
		}
		$file = self::file_of( $id );
		if ( '' === $file ) {
			$error = __( 'The image file is not on this server (documents only use local files, nothing is downloaded).', 'affiliate-captain' );
			return null;
		}
		$size = (int) filesize( $file );
		if ( $max_kb > 0 && $size > $max_kb * 1024 ) {
			/* translators: 1: file size in KB, 2: allowed size in KB */
			$error = sprintf( __( 'The image is %1$d KB; at most %2$d KB are allowed. Please use a smaller image.', 'affiliate-captain' ), (int) ceil( $size / 1024 ), $max_kb );
			return null;
		}
		$bytes = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file of the media library.
		$info  = Pdf::image_info( $bytes, $reason );
		if ( ! $info ) {
			$error = 'interlaced' === $reason
				? __( 'Interlaced PNG images cannot be used. Please save the image without interlacing.', 'affiliate-captain' )
				: ( 'pixels' === $reason
					? __( 'The image has too many pixels. Please use a smaller image (a logo needs a few hundred pixels).', 'affiliate-captain' )
					: __( 'This image cannot be used on documents (supported: JPEG, and PNG with 8 bits per channel without interlacing).', 'affiliate-captain' ) );
			return null;
		}
		return array(
			'info'   => $info,
			'sha256' => hash( 'sha256', $bytes ),
			'bytes'  => $bytes,
		);
	}

	/**
	 * Check an image chosen as mail logo: an image attachment (PNG or JPEG); mail programs load it
	 * from its address, so it need not be on this server.
	 *
	 * @param int         $id    Attachment ID.
	 * @param string|null $error Out: message when it cannot be used.
	 * @return bool
	 */
	public static function check_mail_logo( $id, &$error = null ) {
		$error = '';
		$post  = (int) $id > 0 && function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		if ( ! $post || ( isset( $post->post_type ) && 'attachment' !== $post->post_type ) ) {
			$error = __( 'This image was not found in the media library.', 'affiliate-captain' );
			return false;
		}
		if ( ! in_array( function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( (int) $id ) : '', self::MIMES, true ) ) {
			$error = __( 'Please choose a PNG or JPEG image.', 'affiliate-captain' );
			return false;
		}
		return true;
	}

	/**
	 * Logo and footer to freeze with a new document (merged into operator_snapshot). Empty when
	 * neither is set, so the document data stay as before.
	 *
	 * @return array
	 */
	public static function snapshot() {
		$out    = array();
		$footer = self::clean_footer( (string) Settings::get( 'document_footer' ) );
		if ( '' !== $footer ) {
			$out['footer'] = $footer;
		}
		$id = (int) Settings::get( 'document_logo_id' );
		if ( $id > 0 ) {
			$logo = self::load( $id );
			if ( $logo ) {
				$out['logo'] = array(
					'id'        => $id,
					'sha256'    => $logo['sha256'],
					'height_mm' => (int) Settings::get( 'document_logo_height' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Footer text as stored: plain text, at most FOOTER_LINES lines of FOOTER_LINE_CHARS characters.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function clean_footer( $text ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $line ) : wp_strip_all_tags( $line ) );
			if ( '' !== $line ) {
				$lines[] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, self::FOOTER_LINE_CHARS ) : substr( $line, 0, self::FOOTER_LINE_CHARS );
			}
		}
		return implode( "\n", array_slice( $lines, 0, self::FOOTER_LINES ) );
	}

	/**
	 * Print the frozen logo (first page, top left) and footer (every page) of a document.
	 *
	 * @param Pdf   $pdf Document.
	 * @param array $op  Operator data of the document.
	 * @return float Position of the title (60 without a logo, as before).
	 */
	public static function apply( Pdf $pdf, array $op ) {
		if ( ! empty( $op['footer'] ) && is_string( $op['footer'] ) ) {
			$pdf->set_footer( $op['footer'], self::FOOTER_PRINTED );
		}
		$top = 60;
		if ( empty( $op['logo'] ) || ! is_array( $op['logo'] ) || empty( $op['logo']['id'] ) ) {
			return $top;
		}
		$logo = self::load( (int) $op['logo']['id'], PHP_INT_MAX >> 10 );
		if ( ! $logo || ( isset( $op['logo']['sha256'] ) && ! hash_equals( (string) $op['logo']['sha256'], $logo['sha256'] ) ) ) {
			return $top; // The file changed or is gone: never print another picture than the frozen one.
		}
		list( $w, $h ) = self::fit( $logo['info'], isset( $op['logo']['height_mm'] ) ? (int) $op['logo']['height_mm'] : 15 );
		$pdf->image( $logo['info'], 50, 30, $w, $h );
		return 30 + $h + 28;
	}

	/**
	 * Size of the logo on the document (points).
	 *
	 * @param array $info      Image.
	 * @param int   $height_mm Height in millimetres.
	 * @return float[] width, height.
	 */
	public static function fit( array $info, $height_mm ) {
		$h = max( 5, min( 40, (int) $height_mm ) ) * 72 / 25.4;
		$w = $h * $info['w'] / max( 1, $info['h'] );
		if ( $w > self::MAX_WIDTH ) {
			$h = $h * self::MAX_WIDTH / $w;
			$w = self::MAX_WIDTH;
		}
		return array( round( $w, 2 ), round( $h, 2 ) );
	}

	/**
	 * Logo row for the HTML mail ('' when no mail logo is set or it is gone).
	 *
	 * @return string
	 */
	public static function mail_logo_html() {
		$id = (int) Settings::get( 'mail_logo_id' );
		if ( $id <= 0 || ! function_exists( 'wp_get_attachment_image_src' ) ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return '';
		}
		$w = max( 1, (int) $src[1] );
		$h = max( 1, (int) $src[2] );
		if ( $src[1] && $src[2] ) {
			$scale = min( 1, self::MAIL_WIDTH / $w, self::MAIL_HEIGHT / $h );
			$w     = max( 1, (int) round( $w * $scale ) );
			$h     = max( 1, (int) round( $h * $scale ) );
		} else {
			$w = self::MAIL_WIDTH;
			$h = 0;
		}
		$alt = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return '<p style="margin:0 0 20px;"><img src="' . esc_url( (string) $src[0] ) . '" alt="' . esc_attr( $alt ) . '" width="' . $w . '"' . ( $h ? ' height="' . $h . '"' : '' ) . ' style="display:block;border:0;max-width:100%;height:auto;"></p>' . "\n";
	}

	/**
	 * Sample credit note with the current logo and footer (preview on the settings page; not a
	 * document, no number, nothing stored).
	 *
	 * @return string PDF bytes.
	 */
	public static function sample_pdf() {
		$op  = array_merge( PayoutService::operator_snapshot(), self::snapshot(), array( 'notes_v' => Statement::NOTES_V ) ); // A sample shows the current notes.
		$pdf = new Pdf(
			array(
				'title'  => __( 'Sample credit note', 'affiliate-captain' ),
				'author' => (string) $op['name'],
			)
		);
		$y = self::apply( $pdf, $op );
		$pdf->text( 50, $y, __( 'Sample credit note', 'affiliate-captain' ), 15, true );
		$pdf->text( 545, $y, __( 'SAMPLE', 'affiliate-captain' ), 11, true, 'right' );
		$y += 30;
		$pdf->text( 50, $y, __( 'Issued by', 'affiliate-captain' ), 9, true );
		$pdf->text( 310, $y, __( 'Partner', 'affiliate-captain' ), 9, true );
		$y   += 14;
		$left = array_merge( array( (string) $op['name'] ), preg_split( '/\r\n|\r|\n/', (string) $op['address'] ) );
		$left = array_values( array_filter( array_map( 'trim', $left ), 'strlen' ) );
		$right = array( __( 'Sample partner', 'affiliate-captain' ), __( 'Sample street 1', 'affiliate-captain' ), '12345 ' . __( 'Sample town', 'affiliate-captain' ) );
		for ( $i = 0, $rows = max( count( $left ), count( $right ) ); $i < $rows; $i++ ) {
			if ( isset( $left[ $i ] ) ) {
				$pdf->text( 50, $y, $left[ $i ] );
			}
			if ( isset( $right[ $i ] ) ) {
				$pdf->text( 310, $y, $right[ $i ] );
			}
			$y += 13;
		}
		$y += 20;
		$pdf->text( 50, $y, __( 'Description', 'affiliate-captain' ), 9, true );
		$pdf->text( 545, $y, __( 'Net', 'affiliate-captain' ), 9, true, 'right' );
		$y += 4;
		$pdf->line( 50, $y, 545, $y );
		$y += 13;
		$pdf->text( 50, $y, __( 'Referral of customers (affiliate commission)', 'affiliate-captain' ), 9 );
		$pdf->text( 545, $y, \WPAffiliateCaptain\Core\Money::format( 10000, (string) Settings::get( 'currency' ) ), 9, false, 'right' );
		$y += 30;
		foreach ( Pdf::wrap( __( 'This is a preview of logo and footer. It is not a document and has no number.', 'affiliate-captain' ), 495, 8 ) as $line ) {
			$pdf->text( 50, min( $y, $pdf->limit( 800 ) ), $line, 8 );
			$y += 11;
		}
		return $pdf->output();
	}
}
