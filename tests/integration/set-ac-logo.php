<?php
/**
 * SET-AC-16 (owner decision S6): logo and footer on credit notes, correction credit notes and
 * cancellations, logo in HTML mails. The PDF embeds the local file of the media library image
 * (JPEG, PNG; transparency as soft mask), logo and footer are frozen with every document, documents
 * issued before stay byte for byte the same, and nothing changes while no logo or footer is set.
 * Included from run.php right before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\DocumentBrand;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\Pdf;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "SET-AC-16 logo and footer on credit notes, logo in HTML mails\n";
global $wpdb;

// Attachments of the media library (test stubs: $GLOBALS['test_posts'] + a local file).
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) { return $GLOBALS['logo_files'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $id ) { $p = get_post( $id ); return $p && isset( $p->post_mime_type ) ? $p->post_mime_type : false; }
}
if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) { return isset( $GLOBALS['logo_files'][ (int) $id ] ) ? array( 'https://shop.example/wp-content/uploads/logo-' . (int) $id . '.png', 400, 100, false ) : false; }
}
$logo_dir = sys_get_temp_dir() . '/wpac-logo-' . getmypid();
@mkdir( $logo_dir );
$logo_attach = static function ( $id, $bytes, $mime = 'image/png' ) use ( $logo_dir ) {
	$file = $logo_dir . '/logo-' . $id . ( 'image/jpeg' === $mime ? '.jpg' : '.png' );
	file_put_contents( $file, $bytes );
	$GLOBALS['logo_files'][ $id ] = $file;
	$GLOBALS['test_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_mime_type' => $mime, 'post_title' => 'Logo', 'post_status' => 'inherit', 'post_content' => '', 'post_password' => '', 'post_author' => 1 );
	return $file;
};
// PNG writer for the tests (no image library needed): rows with the given filter types.
$logo_png = static function ( $w, $h, $color, array $pixels, array $filters = array(), $palette = '', $interlace = 0 ) {
	$chunk = static function ( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	};
	$bpp = array( 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4 )[ $color ];
	$raw = '';
	$prior = array_fill( 0, $w * $bpp, 0 );
	for ( $y = 0; $y < $h; $y++ ) {
		$row = array_slice( $pixels, $y * $w * $bpp, $w * $bpp );
		$f   = $filters[ $y % max( 1, count( $filters ) ) ] ?? 0;
		$enc = array();
		foreach ( $row as $i => $v ) {
			$a = $i >= $bpp ? $row[ $i - $bpp ] : 0;
			$b = $prior[ $i ];
			$c = $i >= $bpp ? $prior[ $i - $bpp ] : 0;
			if ( 1 === $f ) {
				$v -= $a;
			} elseif ( 2 === $f ) {
				$v -= $b;
			} elseif ( 3 === $f ) {
				$v -= ( $a + $b ) >> 1;
			} elseif ( 4 === $f ) {
				$p  = $a + $b - $c;
				$pa = abs( $p - $a ); $pb = abs( $p - $b ); $pc = abs( $p - $c );
				$v -= ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c );
			}
			$enc[] = $v & 0xFF;
		}
		$raw  .= chr( $f ) . pack( 'C*', ...$enc );
		$prior = $row;
	}
	return "\x89PNG\r\n\x1A\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, $color, 0, 0, $interlace ) ) . ( '' !== $palette ? $chunk( 'PLTE', $palette ) : '' ) . $chunk( 'IDAT', gzcompress( $raw ) ) . $chunk( 'IEND', '' );
};
// Every object of a PDF at the offset the cross-reference table names (the file is readable).
$logo_xref_ok = static function ( $pdf ) {
	if ( ! preg_match( '/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m ) || 0 !== strpos( substr( $pdf, (int) $m[1] ), 'xref' ) ) {
		return false;
	}
	preg_match_all( '/^(\d{10}) 00000 n $/m', substr( $pdf, (int) $m[1] ), $rows );
	foreach ( $rows[1] as $i => $offset ) {
		if ( 0 !== strpos( substr( $pdf, (int) $offset ), ( $i + 1 ) . ' 0 obj' ) ) {
			return false;
		}
	}
	return count( $rows[1] ) > 0;
};
$logo_jpeg = base64_decode( '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAgAEAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4Ciiivnj9hP/2Q==' );

/* 1. Image reader and PDF writer (no WordPress needed). ---------------------------------------- */
$logo_ji = Pdf::image_info( $logo_jpeg );
check( 'SET-AC-16 JPEG: size, colour space and the data embedded as is (DCTDecode)', array( 4, 2, 'DeviceRGB', 'DCTDecode', true ), array( $logo_ji['w'] ?? 0, $logo_ji['h'] ?? 0, $logo_ji['cs'] ?? '', $logo_ji['filter'] ?? '', ( $logo_ji['data'] ?? '' ) === $logo_jpeg ) );
$logo_rgb = $logo_png( 3, 2, 2, array( 255, 0, 0, 0, 255, 0, 0, 0, 255, 10, 20, 30, 40, 50, 60, 70, 80, 90 ) );
$logo_ri  = Pdf::image_info( $logo_rgb );
check( 'SET-AC-16 PNG RGB: compressed data with the PNG predictors, no image library', array( 3, 2, 'DeviceRGB', 'FlateDecode', '<< /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns 3 >>', '' ), array( $logo_ri['w'] ?? 0, $logo_ri['h'] ?? 0, $logo_ri['cs'] ?? '', $logo_ri['filter'] ?? '', $logo_ri['parms'] ?? '', $logo_ri['smask'] ?? 'x' ) );
$logo_pi = Pdf::image_info( $logo_png( 2, 1, 3, array( 0, 1 ), array(), "\xFF\x00\x00\x00\x00\xFF" ) );
check( 'SET-AC-16 PNG palette: indexed colour space with the palette', array( 'Indexed', "\xFF\x00\x00\x00\x00\xFF" ), array( $logo_pi['cs'] ?? '', $logo_pi['palette'] ?? '' ) );
// Palette with transparency table (PNG optimisers), 2 bits per pixel: expanded to RGB + soft mask.
$logo_chunk = static function ( $type, $data ) {
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
};
$logo_ti = Pdf::image_info( "\x89PNG\r\n\x1A\n" . $logo_chunk( 'IHDR', pack( 'NNCCCCC', 3, 1, 2, 3, 0, 0, 0 ) ) . $logo_chunk( 'PLTE', "\xFF\x00\xFF\x00\x5A\xC8\x10\x20\x30" ) . $logo_chunk( 'tRNS', "\x00\x80" ) . $logo_chunk( 'IDAT', gzcompress( "\x00\x18" ) ) . $logo_chunk( 'IEND', '' ) );
check( 'SET-AC-16 PNG palette with transparency (2 bit): RGB pixels and alpha per palette entry', array( 'DeviceRGB', 8, "\xFF\x00\xFF\x00\x5A\xC8\x10\x20\x30", "\x00\x80\xFF" ), array( $logo_ti['cs'] ?? '', $logo_ti['bpc'] ?? 0, gzuncompress( $logo_ti['data'] ?? '' ), gzuncompress( $logo_ti['smask'] ?? '' ) ) );
// RGBA with every PNG filter (None, Sub, Up, Average, Paeth): colour and alpha separated exactly.
$logo_px = array();
for ( $i = 0; $i < 5 * 4; $i++ ) {
	$logo_px = array_merge( $logo_px, array( ( $i * 37 ) & 0xFF, ( $i * 91 + 7 ) & 0xFF, ( 255 - $i * 13 ) & 0xFF, ( $i * 53 + 11 ) & 0xFF ) );
}
$logo_ai = Pdf::image_info( $logo_png( 4, 5, 6, $logo_px, array( 0, 1, 2, 3, 4 ) ) );
$logo_rgb_exp = '';
$logo_a_exp   = '';
foreach ( array_chunk( $logo_px, 4 ) as $px ) {
	$logo_rgb_exp .= pack( 'C*', $px[0], $px[1], $px[2] );
	$logo_a_exp   .= chr( $px[3] );
}
check( 'SET-AC-16 PNG with transparency: all five row filters undone, colour and soft mask separated', array( 'DeviceRGB', '', $logo_rgb_exp, $logo_a_exp ), array( $logo_ai['cs'] ?? '', $logo_ai['parms'] ?? 'x', gzuncompress( $logo_ai['data'] ?? '' ), gzuncompress( $logo_ai['smask'] ?? '' ) ) );
Pdf::image_info( $logo_png( 2, 2, 2, array_fill( 0, 12, 9 ), array(), '', 1 ), $logo_r1 );
Pdf::image_info( 'GIF89a....', $logo_r2 );
Pdf::image_info( "\x89PNG\r\n\x1A\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NNCCCCC', 5000, 5000, 8, 2, 0, 0, 0 ) . 'crc!' . pack( 'N', 1 ) . 'IDATx' . 'crc!', $logo_r3 );
check( 'SET-AC-16 refused: interlaced PNG, other formats, too many pixels', array( 'interlaced', 'format', 'pixels' ), array( $logo_r1, $logo_r2, $logo_r3 ) );

$logo_doc = new Pdf( array( 'title' => 'T', 'created' => 0 ) );
$logo_plain_limit = $logo_doc->limit( 800 );
$logo_doc->image( $logo_ai, 50, 30, 80, 100 );
$logo_doc->set_footer( "Bank: Förde Sparkasse, IBAN DE89 3704 0044 0532 0130 00\nAmtsgericht Kiel HRB 1234 · Geschäftsführung: Łukasz Nowak" );
$logo_doc->text( 50, 120, 'Seite 1' );
$logo_doc->add_page();
$logo_doc->text( 50, 60, 'Seite 2' );
$logo_out = $logo_doc->output();
check(
	'SET-AC-16 PDF: image XObject with soft mask on page 1 only, footer on every page, valid cross references',
	array( 800.0, true, 1, 1, 1, 2, 2, true, true ),
	array( (float) $logo_plain_limit, $logo_doc->limit( 800 ) < 800, substr_count( $logo_out, '/Subtype /Image /Width 4 /Height 5 /ColorSpace /DeviceRGB' ), substr_count( $logo_out, '/SMask ' ), substr_count( $logo_out, '/XObject << /Im1 ' ), substr_count( $logo_out, "(Amtsgericht Kiel HRB 1234 \xB7 Gesch" . "\xE4" . 'ftsf' . "\xFC" . 'hrung: Lukasz Nowak)' ), substr_count( $logo_out, "(Bank: F\xF6rde Sparkasse" ), $logo_xref_ok( $logo_out ), in_array( 'Amtsgericht Kiel HRB 1234 · Geschäftsführung: Łukasz Nowak', $logo_doc->transliterated(), true ) )
);
$logo_none = new Pdf( array( 'title' => 'T', 'created' => 0 ) );
$logo_none->text( 50, 60, 'X' );
check( 'SET-AC-16 PDF without image and footer: no XObject resources, same page dictionary as before', array( false, true ), array( false !== strpos( $logo_none->output(), '/XObject' ), false !== strpos( $logo_none->output(), '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents 7 0 R' ) ) );

/* 2. Settings: register, checks, export. -------------------------------------------------------- */
$logo_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $logo_keep, array( 'min_payout_minor' => 1000, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Logo Shop GmbH', 'terminated_open' => 'pay', 'vies_check' => 0, 'maintenance_mode' => 0, 'document_logo_id' => 0, 'document_footer' => '', 'mail_logo_id' => 0, 'document_logo_max_kb' => 500, 'document_logo_height' => 15 ) ) );
Settings::flush_cache();
SettingsSchema::flush();
unset( $GLOBALS['test_caps'] );
check(
	'SET-AC-16 register: logo (media ID, not exported), height 5–40 mm, file size, footer (private), mail logo',
	array( 'image', 'payouts', true, 15, 5, 40, 500, 'textarea', true, 'image', 'emails', true ),
	array( SettingsSchema::field( 'document_logo_id' )['type'], SettingsSchema::field( 'document_logo_id' )['tab'], ! empty( SettingsSchema::field( 'document_logo_id' )['local'] ), SettingsSchema::defaults()['document_logo_height'], SettingsSchema::int_min()['document_logo_height'], SettingsSchema::int_max()['document_logo_height'], SettingsSchema::defaults()['document_logo_max_kb'], SettingsSchema::field( 'document_footer' )['type'], ! empty( SettingsSchema::field( 'document_footer' )['private'] ), SettingsSchema::field( 'mail_logo_id' )['type'], SettingsSchema::field( 'mail_logo_id' )['tab'], ! empty( SettingsSchema::field( 'mail_logo_id' )['local'] ) )
);
$logo_attach( 7001, $logo_png( 4, 5, 6, $logo_px, array( 0, 1, 2, 3, 4 ) ) );
$logo_attach( 7002, $logo_jpeg, 'image/jpeg' );
$logo_attach( 7003, 'GIF89a', 'image/gif' );
mt_srand( 16 );
$logo_noise = array();
for ( $i = 0; $i < 120 * 120 * 3; $i++ ) {
	$logo_noise[] = mt_rand( 0, 255 );
}
$logo_attach( 7004, $logo_png( 120, 120, 2, $logo_noise ) ); // About 43 KB.
$logo_attach( 7005, $logo_png( 2, 2, 2, array_fill( 0, 12, 9 ), array(), '', 1 ) ); // Interlaced.
$logo_c = SettingsTransfer::check( array( 'document_logo_id' => 7001, 'mail_logo_id' => 7002 ), Settings::all() );
check( 'SET-AC-16 a PNG and a JPEG of the media library are accepted', array( array(), array( 'document_logo_id' => 7001, 'mail_logo_id' => 7002 ) ), array( $logo_c['errors'], $logo_c['values'] ) );
$logo_bad = array();
foreach ( array( 'missing' => 9999, 'gif' => 7003, 'interlaced' => 7005, 'text' => 'abc' ) as $why => $id ) {
	$c                = SettingsTransfer::check( array( 'document_logo_id' => $id ), Settings::all() );
	$logo_bad[ $why ] = array( isset( $c['errors']['document_logo_id'] ), $c['values'] );
}
$logo_big  = SettingsTransfer::check( array( 'document_logo_id' => 7004, 'document_logo_max_kb' => 10 ), Settings::all() );
$logo_big2 = SettingsTransfer::check( array( 'document_logo_id' => 7004 ), Settings::all() );
check(
	'SET-AC-16 refused (value kept): unknown ID, GIF, interlaced PNG, not a number, file above the size limit',
	array( 'missing' => array( true, array() ), 'gif' => array( true, array() ), 'interlaced' => array( true, array() ), 'text' => array( true, array() ), 'big' => array( true, array( 'document_logo_max_kb' => 10 ) ), 'big_ok' => array( array(), array( 'document_logo_id' => 7004 ) ) ),
	$logo_bad + array( 'big' => array( isset( $logo_big['errors']['document_logo_id'] ) && false !== strpos( $logo_big['errors']['document_logo_id'], 'at most 10 KB' ), $logo_big['values'] ), 'big_ok' => array( $logo_big2['errors'], $logo_big2['values'] ) )
);
$logo_f1 = SettingsTransfer::check( array( 'document_footer' => "Förde Sparkasse · IBAN DE89 3704 0044 0532 0130 00\r\n\r\n<b>Amtsgericht</b> Kiel HRB 1234\nGF: Anna Muster" ), Settings::all() );
$logo_f2 = SettingsTransfer::check( array( 'document_footer' => "1\n2\n3\n4\n5" ), Settings::all() );
$logo_f3 = SettingsTransfer::check( array( 'document_footer' => str_repeat( 'x', 201 ) ), Settings::all() );
check(
	'SET-AC-16 footer: plain text (tags removed, empty lines dropped); more than 4 lines or 200 characters per line refused',
	array( "Förde Sparkasse · IBAN DE89 3704 0044 0532 0130 00\nAmtsgericht Kiel HRB 1234\nGF: Anna Muster", true, true ),
	array( $logo_f1['values']['document_footer'] ?? null, isset( $logo_f2['errors']['document_footer'] ), isset( $logo_f3['errors']['document_footer'] ) )
);
update_option( 'wpac_settings', array_merge( Settings::all(), array( 'document_logo_id' => 7001, 'mail_logo_id' => 7002, 'document_footer' => 'Bank X' ) ) ); Settings::flush_cache();
$logo_exp  = SettingsTransfer::export( false );
$logo_expp = SettingsTransfer::export( true );
$logo_prev = SettingsTransfer::preview( array( 'format' => 1, 'private' => true, 'settings' => array( 'document_logo_id' => 7002, 'mail_logo_id' => 7001 ) ) );
check(
	'SET-AC-16 export: media IDs never (they differ between sites, the import ignores them), footer only with private values',
	array( false, false, false, true, array( 'document_logo_id', 'mail_logo_id' ) ),
	array( array_key_exists( 'document_logo_id', $logo_expp['settings'] ), array_key_exists( 'mail_logo_id', $logo_expp['settings'] ), array_key_exists( 'document_footer', $logo_exp['settings'] ), array_key_exists( 'document_footer', $logo_expp['settings'] ), $logo_prev['ignored'] )
);
// Settings page field: labelled number input (works without JavaScript) and the media button.
$logo_rm = new ReflectionMethod( WPAffiliateCaptain\Admin\Pages\SettingsPage::class, 'image_field' );
$logo_rm->setAccessible( true );
ob_start();
$logo_rm->invoke( null, 'document_logo_id', Settings::all() );
$logo_field = (string) ob_get_clean();
check( 'SET-AC-16 settings page: labelled ID field with the stored value, media library and remove buttons', array( true, true, true, true ), array( false !== strpos( $logo_field, '<label for="wpac-document_logo_id">Logo on credit notes</label>' ), false !== strpos( $logo_field, 'name="wpac[document_logo_id]" value="7001"' ), false !== strpos( $logo_field, 'data-wpac-media="wpac-document_logo_id"' ), false !== strpos( $logo_field, 'data-wpac-media-clear="wpac-document_logo_id"' ) ) );

/* 3. Documents: issued without logo stay unchanged; new ones freeze logo and footer. ----------- */
update_option( 'wpac_settings', array_merge( Settings::all(), array( 'document_logo_id' => 0, 'mail_logo_id' => 0, 'document_footer' => '' ) ) ); Settings::flush_cache();
$logo_partner = static function ( $slug ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'Logo ' . $slug, 'email' => 'logo-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, array( 'tax_status' => 'business', 'vat_id' => 'DE123456789', 'legal_name' => 'Logo ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'Logo ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$logo_earn = static function ( $aff, $order, $net ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
};
$logo_a = $logo_partner( 'old' );
$logo_earn( $logo_a, 'logo-1', 100000 );
$logo_p1  = PayoutService::find( PayoutService::create_for_affiliate( $logo_a, gmdate( 'Y-m-d' ), 'EUR' ) );
$logo_s1  = json_decode( (string) $logo_p1->operator_snapshot, true );
$logo_f1b = Statement::stored_bytes( $logo_p1 );
check(
	'SET-AC-16 no logo and no footer set: document data and PDF as before (title at the old position, no image, no footer)',
	array( false, false, true, false, false ),
	array( array_key_exists( 'logo', $logo_s1 ), array_key_exists( 'footer', $logo_s1 ), false !== strpos( (string) $logo_f1b, sprintf( '50.00 %.2F Td', Pdf::HEIGHT - 60 ) ), false !== strpos( (string) $logo_f1b, '/XObject' ), false !== strpos( (string) $logo_f1b, 'Bank X' ) )
);

update_option( 'wpac_settings', array_merge( Settings::all(), array( 'document_logo_id' => 7001, 'document_logo_height' => 20, 'document_footer' => "Förde Sparkasse · IBAN DE89 3704 0044 0532 0130 00\nAmtsgericht Kiel HRB 1234" ) ) ); Settings::flush_cache();
check( 'SET-AC-16 a credit note issued before is built again byte for byte (no logo, no footer)', true, is_string( $logo_f1b ) && $logo_f1b === Statement::build( PayoutService::find( (int) $logo_p1->id ) ) );

$logo_b = $logo_partner( 'new' );
$logo_earn( $logo_b, 'logo-2', 100000 );
$logo_p2  = PayoutService::find( PayoutService::create_for_affiliate( $logo_b, gmdate( 'Y-m-d' ), 'EUR' ) );
$logo_s2  = json_decode( (string) $logo_p2->operator_snapshot, true );
$logo_f2b = (string) Statement::stored_bytes( $logo_p2 );
check(
	'SET-AC-16 new credit note: logo (ID, hash of the file, height) and footer frozen with the document',
	array( array( 'id' => 7001, 'sha256' => hash_file( 'sha256', $GLOBALS['logo_files'][7001] ), 'height_mm' => 20 ), "Förde Sparkasse · IBAN DE89 3704 0044 0532 0130 00\nAmtsgericht Kiel HRB 1234" ),
	array( $logo_s2['logo'] ?? null, $logo_s2['footer'] ?? null )
);
check(
	'SET-AC-16 the stored PDF contains the image (with soft mask), the footer and is readable',
	array( 1, 1, true, true, true ),
	array( substr_count( $logo_f2b, '/Subtype /Image /Width 4 /Height 5 /ColorSpace /DeviceRGB' ), substr_count( $logo_f2b, '/SMask ' ), false !== strpos( $logo_f2b, '(Amtsgericht Kiel HRB 1234)' ), false !== strpos( $logo_f2b, "(F\xF6rde Sparkasse \xB7 IBAN DE89 3704 0044 0532 0130 00)" ), $logo_xref_ok( $logo_f2b ) )
);
// Settings change later: the issued document is built again exactly as issued.
update_option( 'wpac_settings', array_merge( Settings::all(), array( 'document_logo_id' => 7002, 'document_logo_height' => 30, 'document_footer' => 'Neue Bank' ) ) ); Settings::flush_cache();
check( 'SET-AC-16 changed logo, height and footer do not change a document already issued', true, $logo_f2b === Statement::build( PayoutService::find( (int) $logo_p2->id ) ) );
$logo_storno = Statement::build( PayoutService::find( (int) $logo_p2->id ), true );
check( 'SET-AC-16 the cancellation of that credit note shows the logo and footer frozen with it', array( 1, true, false ), array( substr_count( $logo_storno, '/Subtype /Image /Width 4 /Height 5 /ColorSpace /DeviceRGB' ), false !== strpos( $logo_storno, '(Amtsgericht Kiel HRB 1234)' ), false !== strpos( $logo_storno, 'Neue Bank' ) ) );
// The image file was replaced in the media library: never another picture than the frozen one.
file_put_contents( $GLOBALS['logo_files'][7001], $logo_rgb );
$logo_dup = Statement::build( PayoutService::find( (int) $logo_p2->id ), false, true );
check( 'SET-AC-16 file of the frozen logo changed: a duplicate prints no logo (but the frozen footer)', array( false, true ), array( false !== strpos( $logo_dup, '/Subtype /Image' ), false !== strpos( $logo_dup, '(Amtsgericht Kiel HRB 1234)' ) ) );

// Correction credit note: frozen with its own logo and footer (current settings: JPEG, "Neue Bank").
PayoutService::mark_paid( (int) $logo_p2->id );
$logo_k  = Corrections::create( (int) $logo_p2->id, 12000, 'Satz 12 %', 'logo-k1', (int) $logo_p2->net_minor );
$logo_kg = is_wp_error( $logo_k ) ? null : Corrections::find( (int) $logo_k );
$logo_kb = $logo_kg ? (string) Corrections::stored_bytes( $logo_kg ) : '';
$logo_ks = $logo_kg ? json_decode( (string) $logo_kg->operator_snapshot, true ) : array();
check(
	'SET-AC-16 correction credit note: JPEG logo (DCTDecode) and footer frozen and printed, rebuilt identically',
	array( 7002, 'Neue Bank', 1, true, true ),
	array( $logo_ks['logo']['id'] ?? null, $logo_ks['footer'] ?? null, substr_count( $logo_kb, '/Filter /DCTDecode' ), false !== strpos( $logo_kb, '(Neue Bank)' ), $logo_kg && $logo_kb === Corrections::build( $logo_kg ) )
);

/* 4. HTML mails and the sample. ------------------------------------------------------------------ */
update_option( 'wpac_settings', array_merge( Settings::all(), array( 'mail_logo_id' => 0 ) ) ); Settings::flush_cache();
$logo_m0 = Notifications::html_message( "Hallo\n\nText", 'Betreff' );
update_option( 'wpac_settings', array_merge( Settings::all(), array( 'mail_logo_id' => 7001 ) ) ); Settings::flush_cache();
$logo_m1 = Notifications::html_message( "Hallo\n\nText", 'Betreff' );
check(
	'SET-AC-16 HTML mail: logo at the top (scaled to at most 200 × 60), unchanged without a mail logo',
	array( false, true, true, $logo_m0 ),
	array( false !== strpos( $logo_m0, '<img' ), false !== strpos( $logo_m1, '<img src="https://shop.example/wp-content/uploads/logo-7001.png"' ), false !== strpos( $logo_m1, 'width="200" height="50"' ), str_replace( DocumentBrand::mail_logo_html(), '', $logo_m1 ) )
);
$logo_sample = DocumentBrand::sample_pdf();
check( 'SET-AC-16 sample credit note: current logo and footer, marked as sample, readable', array( true, true, true, true ), array( false !== strpos( $logo_sample, '/Filter /DCTDecode' ), false !== strpos( $logo_sample, '(Neue Bank)' ), false !== strpos( $logo_sample, '(SAMPLE)' ), $logo_xref_ok( $logo_sample ) ) );

update_option( 'wpac_settings', $logo_keep ); Settings::flush_cache();
SettingsSchema::flush();
foreach ( (array) glob( $logo_dir . '/*' ) as $logo_file ) {
	@unlink( $logo_file );
}
@rmdir( $logo_dir );
unset( $GLOBALS['logo_files'][7001], $GLOBALS['logo_files'][7002], $GLOBALS['logo_files'][7003], $GLOBALS['logo_files'][7004], $GLOBALS['logo_files'][7005] );
foreach ( array( 7001, 7002, 7003, 7004, 7005 ) as $logo_id ) {
	unset( $GLOBALS['test_posts'][ $logo_id ] );
}
