<?php
defined( 'ABSPATH' ) || exit;

class AFI_Generator {
	const META_GENERATED  = '_afi_generated';
	const META_HASH       = '_afi_hash';
	const META_BACKGROUND = '_afi_background_id';

	public static function init() {
		// Fires after terms/meta are saved for both the classic editor and the block editor (REST).
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_post_afi_generate', array( __CLASS__, 'handle_row_action' ) );
		add_filter( 'bulk_actions-edit-post', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-post', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'afi generate', array( __CLASS__, 'cli_generate' ) );
		}
	}

	public static function on_save( $post_id, $post ) {
		$statuses = array( 'publish', 'future', 'draft', 'pending', 'private' );
		if ( 'post' !== $post->post_type || ! in_array( $post->post_status, $statuses, true ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( AFI_Editor::enabled( $post_id ) ) {
			self::generate( $post_id );
			return;
		}
		// Turned off: drop our generated image; a manually chosen featured image is left alone.
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id && get_post_meta( $thumb_id, self::META_GENERATED, true ) ) {
			delete_post_thumbnail( $post_id );
			wp_delete_attachment( $thumb_id, true );
			delete_post_meta( $post_id, self::META_HASH );
		}
	}

	public static function row_action( $actions, $post ) {
		if ( 'post' === $post->post_type && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $post->ID ) ) {
			$url                    = wp_nonce_url( admin_url( 'admin-post.php?action=afi_generate&post=' . $post->ID ), 'afi_generate_' . $post->ID );
			$actions['afi_generate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Generate featured image', 'featured-image-by-tag' ) . '</a>';
		}
		return $actions;
	}

	public static function handle_row_action() {
		$post_id = absint( $_GET['post'] ?? 0 );
		check_admin_referer( 'afi_generate_' . $post_id );
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'featured-image-by-tag' ) );
		}
		$result = self::generate( $post_id, true );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
		}
		wp_safe_redirect( add_query_arg( 'afi_generated_post', $post_id, wp_get_referer() ?: admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function bulk_action( $actions ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}
		$actions['afi_generate'] = __( 'Generate featured images', 'featured-image-by-tag' );
		return $actions;
	}

	public static function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( 'afi_generate' !== $action || ! current_user_can( 'upload_files' ) ) {
			return $redirect;
		}
		$done = 0;
		foreach ( $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) && ! is_wp_error( self::generate( $post_id, true ) ) ) {
				++$done;
			}
		}
		return add_query_arg( 'afi_generated', $done, $redirect );
	}

	public static function bulk_notice() {
		$post_id = absint( $_GET['afi_generated_post'] ?? 0 );
		if ( $post_id && 'edit-post' === get_current_screen()->id && current_user_can( 'edit_post', $post_id ) ) {
			$att_id = get_post_thumbnail_id( $post_id );
			if ( $att_id && get_post_meta( $att_id, self::META_GENERATED, true ) ) {
				$bg_id = (int) get_post_meta( $att_id, self::META_BACKGROUND, true );
				$message = $bg_id
					? sprintf( 'Featured image generated using background "%s".', get_the_title( $bg_id ) )
					: 'Featured image generated using the solid background color. No saved tag-background mapping matched this post. Check the post tags and save their background mappings in Settings > Featured Image by Tag.';
				$url = add_query_arg( 'afi_preview', time(), wp_get_attachment_url( $att_id ) );
				printf( '<div class="notice notice-success is-dismissible"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>', esc_html( $message ), esc_url( $url ), esc_html__( 'View regenerated image', 'featured-image-by-tag' ) );
			}
		}
		if ( isset( $_GET['afi_generated'] ) && 'edit-post' === get_current_screen()->id ) {
			$n = absint( $_GET['afi_generated'] );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( 'Generated %d featured image.', 'Generated %d featured images.', $n, 'featured-image-by-tag' ), $n ) ) );
		}
	}

	/**
	 * Generate the featured image for a post.
	 *
	 * ## OPTIONS
	 * <id>...
	 * : Post IDs.
	 */
	public static function cli_generate( $args ) {
		foreach ( $args as $id ) {
			$res = self::generate( absint( $id ), true );
			is_wp_error( $res ) ? WP_CLI::warning( "$id: " . $res->get_error_message() ) : WP_CLI::success( "$id -> attachment $res" );
		}
	}

	/** Text for each front-matter item. */
	public static function texts( $post ) {
		$cats = wp_list_pluck( get_the_category( $post->ID ), 'name' );
		$tags = wp_list_pluck( wp_get_post_tags( $post->ID ), 'name' );
		return array(
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'category' => strtoupper( implode( ' · ', $cats ) ),
			'tags'     => implode( '  ', array_map( function ( $t ) { return '#' . $t; }, $tags ) ),
			'date'     => get_the_date( '', $post ),
			'author'   => get_the_author_meta( 'display_name', $post->post_author ),
		);
	}

	/** First configured mapping that matches one of the post's tag IDs. */
	public static function background_id( $post_id, $o ) {
		$post_tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $post_tags ) ) {
			return $post_tags;
		}
		$post_tags = array_map( 'intval', $post_tags );
		foreach ( $o['tag_backgrounds'] as $tag_id => $img_id ) {
			if ( in_array( (int) $tag_id, $post_tags, true ) ) {
				return (int) $img_id;
			}
		}
		return 0;
	}

	public static function generate( $post_id, $force = false ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'afi_no_post', 'Post not found' );
		}
		if ( ! function_exists( 'imagettftext' ) ) {
			return new WP_Error( 'afi_no_gd', 'PHP GD with FreeType is required' );
		}
		$o     = AFI_Settings::get();
		$bg_id = self::background_id( $post_id, $o );
		if ( is_wp_error( $bg_id ) ) {
			return $bg_id;
		}
		$texts = self::texts( $post );
		$hash  = md5( wp_json_encode( array( $o, $texts, $bg_id ) ) );
		$old   = get_post_thumbnail_id( $post_id );
		// Only overwrite this post's generated JPEG, never an uploaded image or
		// an attachment being used as a tag background.
		$reuse = $old && get_post_meta( $old, self::META_GENERATED, true )
			&& (int) wp_get_post_parent_id( $old ) === (int) $post_id
			&& 'image/jpeg' === get_post_mime_type( $old )
			&& ! in_array( (int) $old, array_map( 'intval', $o['tag_backgrounds'] ), true );
		if ( ! $force && $reuse && is_file( get_attached_file( $old ) ) && get_post_meta( $post_id, self::META_HASH, true ) === $hash ) {
			return $old;
		}

		$img = self::render( $o, $texts, $bg_id );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		ob_start();
		$encoded = imagejpeg( $img, null, 90 );
		$data = ob_get_clean();
		imagedestroy( $img );
		if ( ! $encoded || '' === $data ) {
			return new WP_Error( 'afi_encode', 'Could not encode the generated image.' );
		}

		if ( $reuse ) {
			$file   = get_attached_file( $old );
			$result = self::overwrite_image_file( $file, $data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$att_id = wp_update_post( array( 'ID' => $old, 'post_title' => $texts['title'] ), true );
		} else {
			$upload = wp_upload_bits( 'featured-' . ( $post->post_name ?: $post_id ) . '.jpg', null, $data );
			if ( ! empty( $upload['error'] ) ) {
				return new WP_Error( 'afi_upload', $upload['error'] );
			}
			$file   = $upload['file'];
			$att_id = wp_insert_attachment(
				array(
					'post_title'     => $texts['title'],
					'post_mime_type' => 'image/jpeg',
					'post_status'    => 'inherit',
				),
				$file,
				$post_id,
				true
			);
		}
		if ( is_wp_error( $att_id ) ) {
			if ( ! $reuse ) {
				wp_delete_file( $file );
			}
			return $att_id;
		}
		$result = self::refresh_image_metadata( $att_id, $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $att_id, self::META_GENERATED, 1 );
		update_post_meta( $att_id, self::META_BACKGROUND, $bg_id );
		update_post_meta( $att_id, '_wp_attachment_image_alt', $texts['title'] );
		set_post_thumbnail( $post_id, $att_id );
		update_post_meta( $post_id, self::META_HASH, $hash );

		return $att_id;
	}

	/** Stage the complete JPEG before replacing the existing file. */
	private static function overwrite_image_file( $file, $data ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! $file || ! is_dir( dirname( $file ) ) ) {
			return new WP_Error( 'afi_replace', 'The generated image directory is unavailable.' );
		}
		$temp = wp_tempnam( wp_basename( $file ), dirname( $file ) );
		if ( ! $temp ) {
			return new WP_Error( 'afi_replace', 'Could not create a temporary image file.' );
		}
		if ( strlen( $data ) !== file_put_contents( $temp, $data ) ) {
			wp_delete_file( $temp );
			return new WP_Error( 'afi_replace', 'Could not write the regenerated image.' );
		}
		$mode = file_exists( $file ) ? fileperms( $file ) & 0777 : fileperms( dirname( $file ) ) & 0666;
		chmod( $temp, $mode );
		if ( ! @rename( $temp, $file ) ) {
			wp_delete_file( $temp );
			return new WP_Error( 'afi_replace', 'Could not replace the generated image. Check upload directory permissions.' );
		}
		clearstatcache( true, $file );
		return true;
	}

	/** Refresh thumbnails in place and remove sizes no longer in use. */
	private static function refresh_image_metadata( $att_id, $file ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$old_meta = wp_get_attachment_metadata( $att_id );
		// Keep the full-size JPEG URL stable, even above WordPress's scaling limit
		// or when another plugin requests automatic format conversion.
		$keep_size = function ( $threshold, $size, $path, $id ) use ( $att_id ) {
			return (int) $id === (int) $att_id ? false : $threshold;
		};
		$keep_format = function ( $formats, $path ) use ( $file ) {
			return $path === $file ? array() : $formats;
		};
		add_filter( 'big_image_size_threshold', $keep_size, PHP_INT_MAX, 4 );
		add_filter( 'image_editor_output_format', $keep_format, PHP_INT_MAX, 2 );
		try {
			$metadata = wp_generate_attachment_metadata( $att_id, $file );
		} finally {
			remove_filter( 'big_image_size_threshold', $keep_size, PHP_INT_MAX );
			remove_filter( 'image_editor_output_format', $keep_format, PHP_INT_MAX );
		}
		if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return new WP_Error( 'afi_metadata', 'Could not generate image metadata.' );
		}
		wp_update_attachment_metadata( $att_id, $metadata );
		$files = function ( $meta ) {
			$names   = wp_list_pluck( $meta['sizes'] ?? array(), 'file' );
			$names[] = wp_basename( $meta['file'] ?? '' );
			$names[] = $meta['original_image'] ?? '';
			return array_filter( $names );
		};
		foreach ( array_diff( $files( $old_meta ), $files( $metadata ) ) as $obsolete ) {
			wp_delete_file_from_directory( dirname( $file ) . '/' . wp_basename( $obsolete ), dirname( $file ) );
		}
		return true;
	}

	/** Load local media or the attachment URL when a media service offloads it. */
	private static function load_background( $bg_id ) {
		$reason = 'The image file is missing or unreadable on this server.';
		$files  = array_unique( array( get_attached_file( $bg_id ), get_attached_file( $bg_id, true ) ) );
		foreach ( $files as $file ) {
			if ( $file && is_readable( $file ) && is_file( $file ) ) {
				$data = @file_get_contents( $file );
				$src  = $data ? @imagecreatefromstring( $data ) : false;
				if ( $src ) {
					return $src;
				}
				$reason = 'The server could not decode this image. Try uploading a JPEG or PNG copy.';
			}
		}

		// Use WordPress's safe HTTP client for CDN/offloaded attachments. Do not
		// require PHP URL streams, which many hosts disable.
		$url = wp_get_attachment_url( $bg_id );
		if ( $url ) {
			$response = wp_safe_remote_get( $url, array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 20 * MB_IN_BYTES,
			) );
			if ( is_wp_error( $response ) ) {
				$reason .= ' The Media Library URL could not be fetched: ' . $response->get_error_message();
			} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$reason .= sprintf( ' The Media Library URL returned HTTP %d.', wp_remote_retrieve_response_code( $response ) );
			} else {
				$data = wp_remote_retrieve_body( $response );
				// Reject a response truncated at the limit rather than decoding a
				// partial JPEG, which GD may otherwise accept with missing pixels.
				$src = $data && strlen( $data ) < 20 * MB_IN_BYTES ? @imagecreatefromstring( $data ) : false;
				if ( $src ) {
					return $src;
				}
				$reason = 'The Media Library URL did not return an image this server can decode (20 MB download limit). Try uploading a JPEG or PNG copy.';
			}
		}
		return new WP_Error( 'afi_background', sprintf( 'Could not load mapped background "%1$s" (media ID %2$d). %3$s Generation stopped without replacing the featured image.', get_the_title( $bg_id ), $bg_id, $reason ) );
	}

	public static function render( $o, $texts, $bg_id ) {
		$stacked = 'stacked' === $o['layout'];
		$src     = $bg_id ? self::load_background( $bg_id ) : false;
		if ( is_wp_error( $src ) ) {
			return $src;
		}

		if ( $src ) {
			// A tag background keeps its own size; Stacked adds the text panel below it.
			$w     = imagesx( $src );
			$img_h = imagesy( $src );
			$h     = $stacked ? (int) round( $img_h * 100 / $o['image_height'] ) : $img_h;
		} else {
			// No tag background: use the default canvas size.
			$w     = (int) $o['width'];
			$h     = (int) $o['height'];
			$img_h = $stacked ? (int) round( $h * $o['image_height'] / 100 ) : $h;
		}

		// Font sizes and padding are set for the default canvas width; scale them to the output width.
		$k            = $w / max( 1, (int) $o['width'] );
		$o['padding'] = (int) round( $o['padding'] * $k );

		$img = imagecreatetruecolor( $w, $h );
		imagealphablending( $img, true );
		imagefilledrectangle( $img, 0, 0, $w, $h, self::color( $img, $o['bg_color'] ) );

		if ( $src ) {
			imagecopy( $img, $src, 0, 0, 0, 0, $w, $img_h );
			imagedestroy( $src );
			if ( ! $stacked && $o['overlay_opacity'] > 0 ) {
				$alpha = 127 - (int) round( 127 * $o['overlay_opacity'] / 100 );
				imagefilledrectangle( $img, 0, 0, $w, $h, imagecolorallocatealpha( $img, 0, 0, 0, $alpha ) );
			}
		}

		$pad   = (int) $o['padding'];
		$max_w = max( 50, $w - 2 * $pad );
		if ( $stacked ) {
			self::draw_stacked( $img, $o, $texts, $max_w, $img_h, $h - $img_h, $k );
		} else {
			foreach ( self::items( $o, $texts, $max_w, $k ) as $item ) {
				self::draw_item( $img, $o, $item, (int) round( $h * $item['y'] / 100 ) );
			}
		}
		return $img;
	}

	/**
	 * Stacked layout: items flow top-to-bottom in the panel below the image, ordered by
	 * their vertical position setting (items with equal values share a row), and are
	 * scaled down together until they fit the panel.
	 */
	private static function draw_stacked( $img, $o, $texts, $max_w, $top, $panel_h, $scale ) {
		$v_pad = (int) round( min( $o['padding'], $panel_h / 6 ) );
		$avail = $panel_h - 2 * $v_pad;
		$size_floor = 0.3 * $scale;
		do {
			$rows  = array();
			foreach ( self::items( $o, $texts, $max_w, $scale ) as $item ) {
				$rows[ $item['y'] ][] = $item;
			}
			ksort( $rows );
			$heights = array();
			foreach ( $rows as $y => $row ) {
				$heights[ $y ] = max( wp_list_pluck( $row, 'height' ) );
			}
			$gap   = (int) round( 14 * $scale );
			$total = array_sum( $heights ) + $gap * max( 0, count( $rows ) - 1 );
			$scale *= 0.92;
		} while ( $total > $avail && $size_floor < $scale );

		$cursor = $top + $v_pad + (int) max( 0, ( $avail - $total ) / 2 );
		foreach ( $rows as $y => $row ) {
			foreach ( $row as $item ) {
				self::draw_item( $img, $o, $item, $cursor );
			}
			$cursor += $heights[ $y ] + $gap;
		}
	}

	/** Wrapped, size-fitted text items for the enabled front-matter fields. */
	private static function items( $o, $texts, $max_w, $scale ) {
		$fonts = AFI_Settings::fonts();
		$items = array();
		foreach ( AFI_Settings::fields() as $key => $label ) {
			$f    = $o['fields'][ $key ];
			$text = trim( (string) ( $texts[ $key ] ?? '' ) );
			if ( ! $f['enabled'] || '' === $text || empty( $fonts[ $f['font'] ] ) ) {
				continue;
			}
			$font = $fonts[ $f['font'] ];
			$size = max( 8, (int) round( $f['size'] * $scale ) );
			// Shrink the font until the text fits in max_lines.
			$lines = self::wrap( $text, $font, $size, $max_w );
			while ( count( $lines ) > $f['max_lines'] && $size > 10 ) {
				$size -= 2;
				$lines = self::wrap( $text, $font, $size, $max_w );
			}
			$line_h  = (int) round( $size * 1.3 );
			$items[] = array(
				'lines'  => $lines,
				'font'   => $font,
				'size'   => $size,
				'line_h' => $line_h,
				'height' => $size + $line_h * ( count( $lines ) - 1 ) + (int) round( $size * 0.3 ),
				'color'  => $f['color'],
				'align'  => $f['align'],
				'y'      => (int) $f['y'],
			);
		}
		return $items;
	}

	private static function draw_item( $img, $o, $item, $top ) {
		$w     = imagesx( $img );
		$pad   = (int) $o['padding'];
		$color = self::color( $img, $item['color'] );
		$y     = $top + $item['size'];
		foreach ( $item['lines'] as $line ) {
			$lw = self::text_width( $line, $item['font'], $item['size'] );
			$x  = $pad;
			if ( 'center' === $item['align'] ) {
				$x = (int) ( ( $w - $lw ) / 2 );
			} elseif ( 'right' === $item['align'] ) {
				$x = $w - $pad - $lw;
			}
			imagettftext( $img, $item['size'], 0, $x, $y, $color, $item['font'], $line );
			$y += $item['line_h'];
		}
	}

	private static function color( $img, $hex ) {
		list( $r, $g, $b ) = sscanf( $hex, '#%02x%02x%02x' );
		return imagecolorallocate( $img, $r, $g, $b );
	}

	private static function text_width( $text, $font, $size ) {
		$box = imagettfbbox( $size, 0, $font, $text );
		return abs( $box[2] - $box[0] );
	}

	/**
	 * Break at spaces or Han character boundaries without splitting English words
	 * or Unicode grapheme clusters. Keep opening punctuation with the next item,
	 * and closing punctuation with the preceding item.
	 */
	private static function wrap( $text, $font, $size, $max_w ) {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		preg_match_all( '/\X/u', $text, $matches );
		$opening  = '/^[\p{Ps}\p{Pi}]/u';
		$closing  = '/^[\p{Pe}\p{Pf}、。，．！？：；％‰…,.!?:;%]/u';
		$units    = array();
		$unit     = '';
		$previous = '';
		$space    = false;
		foreach ( $matches[0] as $character ) {
			if ( ' ' === $character ) {
				$unit .= ' ';
				$space = true;
				continue;
			}
			// Include CJK punctuation and fullwidth forms at mixed-language boundaries.
			$can_break = $space || preg_match( '/[\p{Han}\x{3000}-\x{303f}\x{ff01}-\x{ff65}]/u', $previous . $character );
			if ( '' !== $unit && $can_break
				&& ! preg_match( $opening, $previous )
				&& ! preg_match( $closing, $character ) ) {
				$units[] = $unit;
				$unit    = '';
			}
			$unit    .= $character;
			$previous = $character;
			$space    = false;
		}
		if ( '' !== $unit ) {
			$units[] = $unit;
		}

		$lines = array();
		$cur   = '';
		foreach ( $units as $unit ) {
			$try = $cur . $unit;
			if ( '' !== $cur && self::text_width( rtrim( $try ), $font, $size ) > $max_w ) {
				$lines[] = rtrim( $cur );
				$cur     = $unit;
			} else {
				$cur = $try;
			}
		}
		if ( '' !== $cur ) {
			$lines[] = rtrim( $cur );
		}
		return $lines;
	}
}
