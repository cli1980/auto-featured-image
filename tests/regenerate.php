<?php
/**
 * Integration checks against a disposable WordPress installation.
 * Set AFI_TEST_WP_ROOT to its root and run: php tests/regenerate.php
 * Requires PHP GD with FreeType and a configured WordPress database.
 */
$root = getenv( 'AFI_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set AFI_TEST_WP_ROOT to a disposable WordPress installation.\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/wp-load.php';
require_once dirname( __DIR__ ) . '/featured-image-by-tag.php';

$debug_file = WP_DEBUG && WP_DEBUG_LOG ? ini_get( 'error_log' ) : '';
$debug_offset = $debug_file && is_file( $debug_file ) ? filesize( $debug_file ) : 0;

function check_regeneration( $name, $condition ) {
	if ( ! $condition ) {
		throw new RuntimeException( $name );
	}
	echo "PASS $name\n";
}
function generated_count( $post_id ) {
	return count( get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_parent'    => $post_id,
		'meta_key'       => AFI_Generator::META_GENERATED,
		'meta_value'     => 1,
		'numberposts'    => -1,
		'fields'        => 'ids',
	) ) );
}
function regenerate( $post_id, $force = true ) {
	$result = AFI_Generator::generate( $post_id, $force );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	return $result;
}

$original_settings = get_option( AFI_Settings::OPTION );
$post_ids          = array();
$attachments       = array();
$tag_id            = 0;
$blocked_dir       = '';
$failed            = false;
try {
	$options = AFI_Settings::defaults();
	$options['width']    = 600;
	$options['height']   = 400;
	$options['bg_color'] = '#cc2233';
	foreach ( $options['fields'] as &$field ) {
		$field['enabled'] = 0;
	}
	unset( $field );
	update_option( AFI_Settings::OPTION, $options );
	$post_id = wp_insert_post( array( 'post_title' => 'AFI regeneration check', 'post_status' => 'publish' ) );
	$post_ids[] = $post_id;
	update_post_meta( $post_id, AFI_Editor::META_MODE, 'on' );

	$id = regenerate( $post_id );
	$attachments[] = $id;
	$file = get_attached_file( $id );
	$url  = wp_get_attachment_url( $id );
	$meta = wp_get_attachment_metadata( $id );
	$hash = hash_file( 'sha256', $file );
	$thumbnail = dirname( $file ) . '/' . $meta['sizes']['thumbnail']['file'];
	$thumb_hash = hash_file( 'sha256', $thumbnail );
	check_regeneration( 'Initial generation creates one media attachment', 1 === generated_count( $post_id ) && $id === get_post_thumbnail_id( $post_id ) );

	$options['bg_color'] = '#2277cc';
	update_option( AFI_Settings::OPTION, $options );
	check_regeneration( 'Regeneration preserves attachment ID', $id === regenerate( $post_id ) );
	check_regeneration( 'Regeneration preserves file path and URL', $file === get_attached_file( $id ) && $url === wp_get_attachment_url( $id ) );
	check_regeneration( 'Image and thumbnails are overwritten', $hash !== hash_file( 'sha256', $file ) && $thumb_hash !== hash_file( 'sha256', $thumbnail ) );
	check_regeneration( 'Repeated regeneration creates no duplicate media items', $id === regenerate( $post_id ) && 1 === generated_count( $post_id ) );

	$obsolete = dirname( $file ) . '/' . $meta['sizes']['medium']['file'];
	$options['width'] = 800;
	update_option( AFI_Settings::OPTION, $options );
	regenerate( $post_id );
	$meta = wp_get_attachment_metadata( $id );
	check_regeneration( 'Changed dimensions refresh metadata', 800 === $meta['width'] && 400 === $meta['height'] );
	check_regeneration( 'Obsolete thumbnail sizes are removed', ! file_exists( $obsolete ) && file_exists( $thumbnail ) );

	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Updated AFI title' ) );
	check_regeneration( 'Save-triggered regeneration also reuses the media item', $id === get_post_thumbnail_id( $post_id ) && 1 === generated_count( $post_id ) );
	check_regeneration( 'Media title and alt text are updated', 'Updated AFI title' === get_the_title( $id ) && 'Updated AFI title' === get_post_meta( $id, '_wp_attachment_image_alt', true ) );

	$scale = function () { return 100; };
	$convert = function () { return array( 'image/jpeg' => 'image/webp' ); };
	add_filter( 'big_image_size_threshold', $scale );
	add_filter( 'image_editor_output_format', $convert );
	try {
		regenerate( $post_id );
		check_regeneration( 'Automatic scaling and conversion do not change the generated URL', $file === get_attached_file( $id ) && $url === wp_get_attachment_url( $id ) );
		check_regeneration( 'Temporary metadata filters are removed', 100 === apply_filters( 'big_image_size_threshold', 2560, array(), $file, $id ) && array( 'image/jpeg' => 'image/webp' ) === apply_filters( 'image_editor_output_format', array(), $file, 'image/jpeg' ) );
	} finally {
		remove_filter( 'big_image_size_threshold', $scale );
		remove_filter( 'image_editor_output_format', $convert );
	}

	// Older plugin versions allowed WordPress to create a separate scaled image.
	$legacy_original = $file;
	add_filter( 'big_image_size_threshold', $scale );
	try {
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	} finally {
		remove_filter( 'big_image_size_threshold', $scale );
	}
	$file = get_attached_file( $id );
	$url  = wp_get_attachment_url( $id );
	check_regeneration( 'Legacy scaled image fixture is valid', $file !== $legacy_original && file_exists( $legacy_original ) );
	regenerate( $post_id );
	check_regeneration( 'Legacy scaled images keep their current URL and remove the obsolete original', $file === get_attached_file( $id ) && $url === wp_get_attachment_url( $id ) && ! file_exists( $legacy_original ) );

	unlink( $file );
	check_regeneration( 'Missing image file is recreated without a duplicate attachment', $id === regenerate( $post_id, false ) && is_file( $file ) && 1 === generated_count( $post_id ) );

	$blocked_dir = dirname( $file ) . '/afi-blocked-' . $post_id;
	mkdir( $blocked_dir );
	file_put_contents( $blocked_dir . '/sentinel', 'keep' );
	$hash = hash_file( 'sha256', $file );
	$post_hash = get_post_meta( $post_id, AFI_Generator::META_HASH, true );
	update_attached_file( $id, $blocked_dir );
	try {
		$error = AFI_Generator::generate( $post_id, true );
		check_regeneration( 'Replacement failure is reported without changing the featured attachment', is_wp_error( $error ) && 'afi_replace' === $error->get_error_code() && $id === get_post_thumbnail_id( $post_id ) );
		check_regeneration( 'Failed replacement preserves existing data', $hash === hash_file( 'sha256', $file ) && $post_hash === get_post_meta( $post_id, AFI_Generator::META_HASH, true ) && 'keep' === file_get_contents( $blocked_dir . '/sentinel' ) );
	} finally {
		update_attached_file( $id, $file );
	}

	$upload = wp_upload_bits( 'afi-manual.jpg', null, file_get_contents( $file ) );
	$manual = wp_insert_attachment( array( 'post_title' => 'Manual image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $upload['file'], $post_id );
	$attachments[] = $manual;
	wp_update_attachment_metadata( $manual, wp_generate_attachment_metadata( $manual, $upload['file'] ) );
	$manual_file = get_attached_file( $manual );
	$manual_hash = hash_file( 'sha256', $manual_file );
	set_post_thumbnail( $post_id, $manual );
	$replacement = regenerate( $post_id );
	$attachments[] = $replacement;
	check_regeneration( 'Manually uploaded media is preserved', $manual !== $replacement && get_post( $manual ) && $manual_hash === hash_file( 'sha256', $manual_file ) );

	$tag = wp_insert_term( 'AFI test ' . $post_id, 'post_tag' );
	$tag_id = $tag['term_id'];
	wp_set_post_tags( $post_id, array( $tag_id ) );
	$options['tag_backgrounds'] = array( $tag_id => $replacement );
	update_option( AFI_Settings::OPTION, $options );
	$background_file = get_attached_file( $replacement );
	$background_hash = hash_file( 'sha256', $background_file );
	$next = regenerate( $post_id );
	$attachments[] = $next;
	check_regeneration( 'A generated image used as a tag background is preserved', $next !== $replacement && get_post( $replacement ) && $background_hash === hash_file( 'sha256', $background_file ) );
	check_regeneration( 'The generated attachment records which background was used', $replacement === (int) get_post_meta( $next, AFI_Generator::META_BACKGROUND, true ) );

	$rewrite_file = function ( $path, $attachment_id ) use ( $replacement ) {
		return $replacement === $attachment_id ? '/missing/offloaded/background.jpg' : $path;
	};
	add_filter( 'get_attached_file', $rewrite_file, 10, 2 );
	try {
		check_regeneration( 'Rewritten media paths fall back to the existing local file', $next === regenerate( $post_id ) );
	} finally {
		remove_filter( 'get_attached_file', $rewrite_file, 10 );
	}

	// Simulate an offloaded attachment through WordPress's HTTP API without
	// making a real external request.
	$background_data = file_get_contents( $background_file );
	$backup_file = $background_file . '.afi-test-backup';
	$media_url = 'https://example.com/afi-offloaded-background.jpg?signature=afi-test-secret';
	$response = array( 'response' => array( 'code' => 200 ), 'body' => $background_data, 'headers' => array() );
	$requests = 0;
	$rewrite_url = function ( $url, $attachment_id ) use ( $replacement, $media_url ) {
		return $replacement === $attachment_id ? $media_url : $url;
	};
	$http = function ( $preempt, $args, $url ) use ( &$response, &$requests, $media_url ) {
		if ( $url !== $media_url ) {
			return $preempt;
		}
		++$requests;
		if ( empty( $args['reject_unsafe_urls'] ) || 20 * MB_IN_BYTES !== $args['limit_response_size'] ) {
			throw new RuntimeException( 'Remote background requests must be safe and size-limited' );
		}
		return $response;
	};
	rename( $background_file, $backup_file );
	add_filter( 'wp_get_attachment_url', $rewrite_url, 10, 2 );
	add_filter( 'pre_http_request', $http, 10, 3 );
	try {
		$options['overlay_opacity'] = 0;
		update_option( AFI_Settings::OPTION, $options );
		check_regeneration( 'Offloaded tag background loads through its Media Library URL', $next === regenerate( $post_id ) && 1 === $requests );
		$source = imagecreatefromstring( $background_data );
		$rendered = imagecreatefromjpeg( get_attached_file( $next ) );
		$expected_color = imagecolorsforindex( $source, imagecolorat( $source, 0, 0 ) );
		$actual_color = imagecolorsforindex( $rendered, imagecolorat( $rendered, 0, 0 ) );
		check_regeneration(
			'Offloaded background pixels reach the generated JPEG',
			abs( $expected_color['red'] - $actual_color['red'] ) <= 3
				&& abs( $expected_color['green'] - $actual_color['green'] ) <= 3
				&& abs( $expected_color['blue'] - $actual_color['blue'] ) <= 3
		);
		imagedestroy( $source );
		imagedestroy( $rendered );

		$dark_options = $options;
		$dark_options['overlay_opacity'] = 100;
		$dark = AFI_Generator::render( $dark_options, array(), $replacement );
		check_regeneration( 'A fully opaque overlay is distinguishable from an image-loading failure', ! is_wp_error( $dark ) && 0 === imagecolorat( $dark, 0, 0 ) );
		imagedestroy( $dark );

		$output_file = get_attached_file( $next );
		$output_hash = hash_file( 'sha256', $output_file );
		$input_hash = get_post_meta( $post_id, AFI_Generator::META_HASH, true );
		$response['response']['code'] = 404;
		$error = AFI_Generator::generate( $post_id, true );
		check_regeneration( 'Missing background produces an actionable error instead of a blank image', is_wp_error( $error ) && 'afi_background' === $error->get_error_code() && false !== strpos( $error->get_error_message(), 'HTTP 404' ) );
		check_regeneration( 'Background failure preserves the featured image and its input hash', $next === get_post_thumbnail_id( $post_id ) && $output_hash === hash_file( 'sha256', $output_file ) && $input_hash === get_post_meta( $post_id, AFI_Generator::META_HASH, true ) );

		$response['response']['code'] = 200;
		$response['body'] = '<html>Not an image</html>';
		$error = AFI_Generator::generate( $post_id, true );
		check_regeneration( 'Invalid or unsupported background data does not overwrite the image', is_wp_error( $error ) && 'afi_background' === $error->get_error_code() && $output_hash === hash_file( 'sha256', $output_file ) );
		check_regeneration( 'Preview rendering also returns background errors', is_wp_error( AFI_Generator::render( $options, array(), $replacement ) ) );

		$response['body'] = str_pad( $background_data, 20 * MB_IN_BYTES, "\0" );
		$error = AFI_Generator::generate( $post_id, true );
		check_regeneration( 'Downloads at the truncation limit are rejected', is_wp_error( $error ) && 'afi_background' === $error->get_error_code() && $output_hash === hash_file( 'sha256', $output_file ) );

		$response = new WP_Error( 'http_request_failed', 'Download timed out' );
		$error = AFI_Generator::generate( $post_id, true );
		check_regeneration( 'Remote download failure is reported', is_wp_error( $error ) && false !== strpos( $error->get_error_message(), 'Download timed out' ) );
	} finally {
		remove_filter( 'wp_get_attachment_url', $rewrite_url, 10 );
		remove_filter( 'pre_http_request', $http, 10 );
		rename( $backup_file, $background_file );
	}

	$other_post = wp_insert_post( array( 'post_title' => 'Another AFI post', 'post_status' => 'publish' ) );
	$post_ids[] = $other_post;
	set_post_thumbnail( $other_post, $next );
	$shared_file = get_attached_file( $next );
	$shared_hash = hash_file( 'sha256', $shared_file );
	$other_image = regenerate( $other_post );
	$attachments[] = $other_image;
	check_regeneration( 'Another post gets its own image without overwriting the original post image', $other_image !== $next && get_post( $next ) && $shared_hash === hash_file( 'sha256', $shared_file ) );

	if ( $debug_file ) {
		$log = '';
		if ( is_file( $debug_file ) ) {
			$stream = fopen( $debug_file, 'r' );
			fseek( $stream, $debug_offset );
			$log = stream_get_contents( $stream );
			fclose( $stream );
		}
		check_regeneration( 'No verbose plugin logs are emitted with WordPress debugging enabled', false === strpos( $log, '[Featured Image by Tag]' ) );
	}
} catch ( Throwable $error ) {
	$failed = true;
	fwrite( STDERR, 'FAIL ' . $error->getMessage() . "\n" );
} finally {
	foreach ( array_unique( $attachments ) as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $tag_id ) {
		wp_delete_term( $tag_id, 'post_tag' );
	}
	if ( $blocked_dir && is_dir( $blocked_dir ) ) {
		unlink( $blocked_dir . '/sentinel' );
		rmdir( $blocked_dir );
	}
	if ( false === $original_settings ) {
		delete_option( AFI_Settings::OPTION );
	} else {
		update_option( AFI_Settings::OPTION, $original_settings );
	}
}
exit( $failed ? 1 : 0 );
