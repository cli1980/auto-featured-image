<?php
defined( 'ABSPATH' ) || exit;

class AFI_Settings {
	const OPTION = 'afi_settings';

	public static function fields() {
		return array(
			'title'    => __( 'Title', 'featured-image-by-tag' ),
			'category' => __( 'Category', 'featured-image-by-tag' ),
			'tags'     => __( 'Tags', 'featured-image-by-tag' ),
			'date'     => __( 'Date', 'featured-image-by-tag' ),
			'author'   => __( 'Author', 'featured-image-by-tag' ),
		);
	}

	public static function defaults() {
		return array(
			// Default canvas: used only when none of the post's tags has a background image.
			// With a tag image, the output keeps the image's own size (see AFI_Generator::render()).
			'width'           => 1200,
			'height'          => 630,
			'layout'          => 'overlay',
			'image_height'    => 55,
			'bg_color'        => '#1e293b',
			'overlay_opacity' => 40,
			'padding'         => 60,
			'tag_backgrounds' => array(),
			'fields'          => array(
				'title'    => array( 'enabled' => 1, 'font' => 'DejaVuSans-Bold.ttf', 'size' => 56, 'color' => '#ffffff', 'y' => 40, 'align' => 'left', 'max_lines' => 3 ),
				'category' => array( 'enabled' => 1, 'font' => 'DejaVuSans-Bold.ttf', 'size' => 24, 'color' => '#76b900', 'y' => 15, 'align' => 'left', 'max_lines' => 1 ),
				'tags'     => array( 'enabled' => 1, 'font' => 'DejaVuSans.ttf', 'size' => 20, 'color' => '#cbd5e1', 'y' => 80, 'align' => 'left', 'max_lines' => 1 ),
				'date'     => array( 'enabled' => 1, 'font' => 'DejaVuSans.ttf', 'size' => 20, 'color' => '#e2e8f0', 'y' => 90, 'align' => 'left', 'max_lines' => 1 ),
				'author'   => array( 'enabled' => 0, 'font' => 'DejaVuSans.ttf', 'size' => 20, 'color' => '#e2e8f0', 'y' => 90, 'align' => 'right', 'max_lines' => 1 ),
			),
		);
	}

	public static function get() {
		$opts     = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		$opts     = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );
		foreach ( $defaults['fields'] as $key => $field_defaults ) {
			$opts['fields'][ $key ] = wp_parse_args( $opts['fields'][ $key ] ?? array(), $field_defaults );
		}
		return $opts;
	}

	/** Bundled fonts plus any .ttf/.otf uploaded to wp-content/uploads/afi-fonts/. */
	public static function fonts() {
		$fonts = array();
		$dirs  = array( AFI_DIR . 'fonts' );
		$up    = wp_upload_dir();
		$dirs[] = trailingslashit( $up['basedir'] ) . 'afi-fonts';
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				if ( $file && preg_match( '/\.(ttf|otf)$/i', $file ) ) {
					$fonts[ basename( $file ) ] = $file;
				}
			}
		}
		ksort( $fonts );
		return $fonts;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_afi_preview', array( __CLASS__, 'ajax_preview' ) );
	}

	/** Renders a preview image from the (unsaved) settings form values. */
	public static function ajax_preview() {
		check_ajax_referer( 'afi_preview', 'afi_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', 403 );
		}
		$o       = self::sanitize( wp_unslash( $_POST[ self::OPTION ] ?? array() ) );
		$tag_id = absint( $_POST['afi_preview_tag'] ?? 0 );
		$bg_id  = (int) ( $o['tag_backgrounds'][ $tag_id ] ?? 0 );
		$texts  = array(
			'title'    => 'Sample Post Title: How Your Featured Image Will Look',
			'category' => 'CATEGORY',
			'tags'     => '#tag-one  #tag-two',
			'date'     => wp_date( get_option( 'date_format' ) ),
			'author'   => wp_get_current_user()->display_name,
		);
		$img = AFI_Generator::render( $o, $texts, $bg_id );
		if ( is_wp_error( $img ) ) {
			wp_send_json_error( array( 'message' => $img->get_error_message() ), 422 );
		}
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		imagejpeg( $img, null, 85 );
		imagedestroy( $img );
		exit;
	}

	public static function menu() {
		add_options_page( 'Featured Image by Tag', 'Featured Image by Tag', 'manage_options', 'afi', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( 'afi', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( 'settings_page_afi' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'afi-admin', AFI_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), '0.2.1', true );
		wp_localize_script(
			'afi-admin',
			'afiPreview',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'afi_preview' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$d   = self::defaults();
		$out = array(
			'width'           => max( 200, min( 4000, absint( $in['width'] ?? $d['width'] ) ) ),
			'height'          => max( 200, min( 4000, absint( $in['height'] ?? $d['height'] ) ) ),
			'layout'          => in_array( $in['layout'] ?? '', array( 'overlay', 'stacked' ), true ) ? $in['layout'] : $d['layout'],
			'image_height'    => max( 10, min( 90, absint( $in['image_height'] ?? $d['image_height'] ) ) ),
			'bg_color'        => sanitize_hex_color( $in['bg_color'] ?? '' ) ?: $d['bg_color'],
			'overlay_opacity' => max( 0, min( 100, absint( $in['overlay_opacity'] ?? 0 ) ) ),
			'padding'         => max( 0, min( 500, absint( $in['padding'] ?? $d['padding'] ) ) ),
			'tag_backgrounds' => array(),
			'fields'          => array(),
		);
		// WordPress can sanitize twice on first save: accept both form rows and
		// the normalized tag ID => attachment ID mapping returned by this method.
		$backgrounds = $in['tag_backgrounds'] ?? array();
		$backgrounds = is_array( $backgrounds ) ? $backgrounds : array();
		if ( isset( $backgrounds['tag'] ) || isset( $backgrounds['image'] ) ) {
			$tags = $backgrounds['tag'] ?? array();
			$imgs = $backgrounds['image'] ?? array();
		} else {
			$tags = array_keys( $backgrounds );
			$imgs = array_values( $backgrounds );
		}
		foreach ( (array) $tags as $i => $tag_id ) {
			$tag_id = absint( $tag_id );
			$img_id = absint( $imgs[ $i ] ?? 0 );
			if ( $tag_id && $img_id && wp_attachment_is_image( $img_id ) ) {
				$out['tag_backgrounds'][ $tag_id ] = $img_id;
			}
		}
		$fonts = self::fonts();
		foreach ( $d['fields'] as $key => $fd ) {
			$f                     = $in['fields'][ $key ] ?? array();
			$out['fields'][ $key ] = array(
				'enabled' => empty( $f['enabled'] ) ? 0 : 1,
				'font'    => isset( $fonts[ $f['font'] ?? '' ] ) ? $f['font'] : $fd['font'],
				'size'    => max( 6, min( 300, absint( $f['size'] ?? $fd['size'] ) ) ),
				'color'   => sanitize_hex_color( $f['color'] ?? '' ) ?: $fd['color'],
				'y'       => max( 0, min( 100, absint( $f['y'] ?? $fd['y'] ) ) ),
				'align'   => in_array( $f['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $f['align'] : $fd['align'],
				'max_lines' => max( 1, min( 10, absint( $f['max_lines'] ?? $fd['max_lines'] ) ) ),
			);
		}
		return $out;
	}

	public static function render() {
		$o     = self::get();
		$n     = self::OPTION;
		$fonts = array_keys( self::fonts() );
		$tags  = get_tags( array( 'hide_empty' => false ) );
		?>
		<div class="wrap">
			<h1>Featured Image by Tag</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'afi' ); ?>
				<h2>Canvas</h2>
				<table class="form-table">
					<tr><th>Default size (px)</th><td>
						<input type="number" name="<?php echo esc_attr( $n ); ?>[width]" value="<?php echo esc_attr( $o['width'] ); ?>" class="small-text"> &times;
						<input type="number" name="<?php echo esc_attr( $n ); ?>[height]" value="<?php echo esc_attr( $o['height'] ); ?>" class="small-text">
						<p class="description">Used only when no tag background image is found. With a tag image, the output keeps the image's own size (Stacked adds the text panel below it); text sizes and padding scale with the output width.</p>
					</td></tr>
					<tr><th>Layout</th><td>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[layout]" value="overlay" <?php checked( $o['layout'], 'overlay' ); ?>> Overlay &mdash; text on top of the background image</label><br>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[layout]" value="stacked" <?php checked( $o['layout'], 'stacked' ); ?>> Stacked &mdash; background image on top, text in a panel below</label>
					</td></tr>
					<tr><th>Stacked: image share of total height (%)</th><td><input type="number" min="10" max="90" name="<?php echo esc_attr( $n ); ?>[image_height]" value="<?php echo esc_attr( $o['image_height'] ); ?>" class="small-text"></td></tr>
					<tr><th>Background color</th><td><input type="text" class="afi-color" name="<?php echo esc_attr( $n ); ?>[bg_color]" value="<?php echo esc_attr( $o['bg_color'] ); ?>"><p class="description">Blank background when none of the post's tags has an image; also the text panel color in the Stacked layout.</p></td></tr>
					<tr><th>Overlay: dark tint on images (%)</th><td><input type="number" min="0" max="100" name="<?php echo esc_attr( $n ); ?>[overlay_opacity]" value="<?php echo esc_attr( $o['overlay_opacity'] ); ?>" class="small-text"></td></tr>
					<tr><th>Side padding (px)</th><td><input type="number" name="<?php echo esc_attr( $n ); ?>[padding]" value="<?php echo esc_attr( $o['padding'] ); ?>" class="small-text"></td></tr>
				</table>

				<h2>Tag backgrounds</h2>
				<p class="description">If a post has several mapped tags, the first matching row wins. Add a separate mapping for each tag you want to use.</p>
				<table class="widefat striped" id="afi-tag-table" style="max-width:800px">
					<thead><tr><th>Tag</th><th>Background image</th><th></th></tr></thead>
					<tbody>
					<?php
					$rows = $o['tag_backgrounds'] ?: array( 0 => 0 );
					foreach ( $rows as $tag_id => $img_id ) :
						$thumb = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
						?>
						<tr class="afi-tag-row">
							<td><select name="<?php echo esc_attr( $n ); ?>[tag_backgrounds][tag][]">
								<option value="">— Select tag —</option>
								<?php foreach ( $tags as $t ) : ?>
									<option value="<?php echo esc_attr( $t->term_id ); ?>" <?php selected( $tag_id, $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td>
								<input type="hidden" class="afi-img-id" name="<?php echo esc_attr( $n ); ?>[tag_backgrounds][image][]" value="<?php echo esc_attr( $img_id ); ?>">
								<img class="afi-img-preview" src="<?php echo esc_url( $thumb ); ?>" style="max-height:60px;vertical-align:middle;<?php echo $thumb ? '' : 'display:none'; ?>">
								<button type="button" class="button afi-pick">Choose image</button>
							</td>
							<td><button type="button" class="button-link-delete afi-remove">Remove</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="afi-add-row">Add tag mapping</button></p>

				<h2>Text items</h2>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr><th>Item</th><th>Show</th><th>Font</th><th>Size (px)</th><th>Color</th><th>Vertical position (% from top)</th><th>Align</th><th>Max lines (auto-shrink)</th></tr></thead>
					<tbody>
					<?php
					foreach ( self::fields() as $key => $label ) :
						$f  = $o['fields'][ $key ];
						$fn = $n . '[fields][' . $key . ']';
						?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $fn ); ?>[enabled]" value="1" <?php checked( $f['enabled'] ); ?>></td>
							<td><select name="<?php echo esc_attr( $fn ); ?>[font]">
								<?php foreach ( $fonts as $font ) : ?>
									<option <?php selected( $f['font'], $font ); ?>><?php echo esc_html( $font ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><input type="number" class="small-text" name="<?php echo esc_attr( $fn ); ?>[size]" value="<?php echo esc_attr( $f['size'] ); ?>"></td>
							<td><input type="text" class="afi-color" name="<?php echo esc_attr( $fn ); ?>[color]" value="<?php echo esc_attr( $f['color'] ); ?>"></td>
							<td><input type="number" min="0" max="100" class="small-text" name="<?php echo esc_attr( $fn ); ?>[y]" value="<?php echo esc_attr( $f['y'] ); ?>"></td>
							<td><select name="<?php echo esc_attr( $fn ); ?>[align]">
								<?php foreach ( array( 'left', 'center', 'right' ) as $a ) : ?>
									<option <?php selected( $f['align'], $a ); ?>><?php echo esc_html( $a ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><input type="number" min="1" max="10" class="small-text" name="<?php echo esc_attr( $fn ); ?>[max_lines]" value="<?php echo esc_attr( $f['max_lines'] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Stacked layout: items flow top-to-bottom in the panel in order of their vertical position value (equal values share a row) and shrink together to fit.</p>
				<p class="description">Custom fonts: upload .ttf/.otf files to <code>wp-content/uploads/afi-fonts/</code>.</p>

				<h2>Preview</h2>
				<p>
					<label for="afi-preview-tag">Background:</label>
					<select id="afi-preview-tag">
						<option value="0">Background color (no image)</option>
						<?php
						foreach ( array_keys( $o['tag_backgrounds'] ) as $tag_id ) :
							$t = get_term( $tag_id, 'post_tag' );
							if ( $t && ! is_wp_error( $t ) ) :
								?>
								<option value="<?php echo esc_attr( $tag_id ); ?>" <?php selected( $tag_id, array_key_first( $o['tag_backgrounds'] ) ); ?>>Tag image: <?php echo esc_html( $t->name ); ?></option>
								<?php
							endif;
						endforeach;
						?>
					</select>
					<span class="spinner" id="afi-preview-spinner" style="float:none"></span>
				</p>
				<p class="description">Placeholder text; updates as you edit and reflects unsaved changes. Click Save Changes to keep them.</p>
				<p id="afi-preview-error" role="alert" class="notice notice-error" style="display:none;padding:10px"></p>
				<div style="max-width:800px;border:1px solid #c3c4c7;background:#f6f7f7;line-height:0">
					<img id="afi-preview" alt="Featured image preview" style="width:100%;height:auto">
				</div>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
