<?php
/**
 * Standalone tag-background regression checks with minimal WordPress stubs.
 * Requires PHP GD with FreeType. Usage: php tests/background.php
 */
define( 'ABSPATH', __DIR__ );
define( 'AFI_DIR', dirname( __DIR__ ) . '/' );
require_once AFI_DIR . 'includes/class-afi-settings.php';
require_once AFI_DIR . 'includes/class-afi-generator.php';

function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_hex_color( $value ) {
	return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : null;
}
function wp_attachment_is_image( $id ) {
	return in_array( $id, array( 101, 202 ), true );
}
function wp_upload_dir() {
	return array( 'basedir' => sys_get_temp_dir() );
}
function trailingslashit( $path ) {
	return rtrim( $path, '/\\' ) . '/';
}
function get_option( $name, $default = false ) {
	return $GLOBALS['stored_options'] ?? $default;
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}
function wp_get_post_tags( $post_id, $args ) {
	if ( 42 !== $post_id || array( 'fields' => 'ids' ) !== $args ) {
		throw new RuntimeException( 'Unexpected tag lookup' );
	}
	return $GLOBALS['post_tags'];
}
function get_attached_file( $id ) {
	return 202 === $id ? $GLOBALS['background_file'] : '';
}
function __( $text, $domain ) {
	return $text;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
$failed = 0;
function check_background( $name, $condition ) {
	if ( $condition ) {
		echo "PASS $name\n";
	} else {
		++$GLOBALS['failed'];
		fwrite( STDERR, "FAIL $name\n" );
	}
}

$input = array( 'tag_backgrounds' => array( 'tag' => array( '22', '11' ), 'image' => array( '202', '101' ) ) );
$once  = AFI_Settings::sanitize( $input );
// On first save, update_option() sanitizes, then add_option() sanitizes again.
$stored_options = AFI_Settings::sanitize( $once );
check_background( 'Form mappings are normalized', array( 22 => 202, 11 => 101 ) === $once['tag_backgrounds'] );
check_background( 'First-save mappings survive both sanitization passes', $once === $stored_options );
check_background( 'Later sanitization remains stable', $stored_options === AFI_Settings::sanitize( $stored_options ) );

$post_tags = array( 11, 22 );
$options   = AFI_Settings::get();
$bg_id     = AFI_Generator::background_id( 42, $options );
check_background( 'Saved mapping order takes priority over post tag order', 202 === $bg_id );
$post_tags = array( '11', '22' );
check_background( 'Tag IDs returned as strings still match saved mappings', 202 === AFI_Generator::background_id( 42, $options ) );
$post_tags = array( 11 );
check_background( 'A later mapping matches when the first does not', 101 === AFI_Generator::background_id( 42, $options ) );
$post_tags = array( 33 );
check_background( 'Unmapped tags use the blank background', 0 === AFI_Generator::background_id( 42, $options ) );
$post_tags = array();
check_background( 'Untagged posts use the blank background', 0 === AFI_Generator::background_id( 42, $options ) );

$post_tags = array( 30 );
$explicit_options = array( 'tag_backgrounds' => array( 12 => 202 ) );
check_background( 'A tag requires its own mapping', 0 === AFI_Generator::background_id( 42, $explicit_options ) );
$explicit_options['tag_backgrounds'][30] = 202;
check_background( 'Two explicitly mapped tags can share one background', 202 === AFI_Generator::background_id( 42, $explicit_options ) );
$explicit_options['tag_backgrounds'][30] = 101;
check_background( 'Each tag can select a different background', 101 === AFI_Generator::background_id( 42, $explicit_options ) );
$post_tags = array( 30, 12 );
check_background( 'With both tags assigned, the first configured mapping wins', 202 === AFI_Generator::background_id( 42, $explicit_options ) );

$removed = AFI_Settings::sanitize( array( 'tag_backgrounds' => array( 'tag' => array( '' ), 'image' => array( '' ) ) ) );
check_background( 'Removing all mappings still works', array() === $removed['tag_backgrounds'] );
$invalid = AFI_Settings::sanitize( array( 'tag_backgrounds' => array( 22 => 999, 11 => 101, 0 => 202 ) ) );
check_background( 'Stored mappings still validate attachment and tag IDs', array( 11 => 101 ) === $invalid['tag_backgrounds'] );

if ( ! function_exists( 'imagettfbbox' ) ) {
	fwrite( STDERR, "PHP GD with FreeType is required for the rendering check.\n" );
	exit( 1 );
}
$background_file = tempnam( sys_get_temp_dir(), 'afi-background-' );
try {
	$source = imagecreatetruecolor( 80, 60 );
	imagefill( $source, 0, 0, imagecolorallocate( $source, 210, 40, 60 ) );
	imagepng( $source, $background_file );
	imagedestroy( $source );

	$options['overlay_opacity'] = 0;
	$image = AFI_Generator::render( $options, array(), $bg_id );
	check_background(
		'Rendering uses the saved tag image instead of the blank canvas',
		80 === imagesx( $image ) && 60 === imagesy( $image ) && 0xd2283c === imagecolorat( $image, 0, 0 )
	);
	imagedestroy( $image );
} finally {
	unlink( $background_file );
}

exit( $failed ? 1 : 0 );
