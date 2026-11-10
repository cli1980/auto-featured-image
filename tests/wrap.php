<?php
/**
 * Standalone wrapping regression checks. Requires PHP GD with FreeType.
 * Usage: php tests/wrap.php [path/to/a/Chinese-font.ttf]
 */
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-afi-generator.php';

$font = $argv[1] ?? dirname( __DIR__ ) . '/fonts/DejaVuSans.ttf';
if ( ! is_readable( $font ) || ! function_exists( 'imagettfbbox' ) ) {
	fwrite( STDERR, "A readable font and PHP GD with FreeType are required.\n" );
	exit( 1 );
}

$wrap = new ReflectionMethod( 'AFI_Generator', 'wrap' );
if ( PHP_VERSION_ID < 80100 ) {
	$wrap->setAccessible( true );
}
$size = 24;
$width = function ( $text ) use ( $font, $size ) {
	$box = imagettfbbox( $size, 0, $font, $text );
	return abs( $box[2] - $box[0] );
};

$cases = array(
	array( 'Simplified Chinese', '中文标题自动换行测试', $width( '中文标题' ) + 1, array( '中文标题', '自动换行', '测试' ) ),
	array( 'Traditional Chinese', '繁體中文標題自動換行測試', $width( '繁體中文' ) + 1, array( '繁體中文', '標題自動', '換行測試' ) ),
	array( 'Mixed without spaces', '中文WordPress测试中文', $width( 'WordPress' ) + 1, array( '中文', 'WordPress', '测试中文' ) ),
	array( 'Mixed with spaces', '中文 WordPress 测试', $width( 'WordPress' ) + 1, array( '中文', 'WordPress', '测试' ) ),
	array( 'English words', 'Hello world Hello world', $width( 'Hello world' ) + 1, array( 'Hello world', 'Hello world' ) ),
	array( 'English punctuation', 'don’t example.com don’t example.com', $width( 'example.com' ) + 1, array( 'don’t', 'example.com', 'don’t', 'example.com' ) ),
	array( 'Closing punctuation', '甲乙丙，丁戊己。', $width( '甲乙丙' ) + 1, array( '甲乙', '丙，丁', '戊己。' ) ),
	array( 'Opening punctuation', '甲乙（丙丁）戊', $width( '甲乙' ) + 1, array( '甲乙', '（丙', '丁）', '戊' ) ),
	array( 'Chinese quotes', '甲乙「丙丁」戊', $width( '甲乙' ) + 1, array( '甲乙', '「丙', '丁」', '戊' ) ),
	array( 'Whitespace normalization', " \t中文\n\n测试　换行 ", $width( '中文 测试' ) + 1, array( '中文 测试', '换行' ) ),
	array( 'Combining accent', "中文Cafe\u{0301}测试", $width( "Cafe\u{0301}" ) + 1, array( '中文', "Cafe\u{0301}", '测试' ) ),
	array( 'Han variation selector', "中文\u{20000}\u{E0100}测试", $width( "中文\u{20000}\u{E0100}" ) + 1, array( "中文\u{20000}\u{E0100}", '测试' ) ),
	array( 'Empty input', '', 100, array() ),
	array( 'Whitespace only', " \t\n　", 100, array() ),
);

$failed = 0;
foreach ( $cases as list( $name, $text, $max_width, $expected ) ) {
	$lines  = $wrap->invoke( null, $text, $font, $size, $max_width );
	$errors = array();
	if ( $lines !== $expected ) {
		$errors[] = 'unexpected lines: ' . json_encode( $lines, JSON_UNESCAPED_UNICODE );
	}
	if ( preg_replace( '/\s+/u', '', implode( '', $lines ) ) !== preg_replace( '/\s+/u', '', $text ) ) {
		$errors[] = 'text lost or changed';
	}
	foreach ( $lines as $line ) {
		if ( $width( $line ) > $max_width ) {
			$errors[] = 'line exceeds available width';
		}
		if ( preg_match( '/^[\p{Pe}\p{Pf}、。，！？：；]/u', $line )
			|| preg_match( '/[\p{Ps}\p{Pi}]$/u', $line ) ) {
			$errors[] = 'punctuation stranded at a line boundary';
		}
	}
	if ( $errors ) {
		++$failed;
		fwrite( STDERR, 'FAIL ' . $name . ': ' . implode( '; ', $errors ) . "\n" );
	} else {
		echo 'PASS ' . $name . "\n";
	}
}

exit( $failed ? 1 : 0 );
