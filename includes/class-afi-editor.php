<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-post "Use auto featured image" toggle in the post editor.
 * Off by default; stored in post meta as 'on' or 'off' (empty = never set = off).
 */
class AFI_Editor {
	const META_MODE = '_afi_mode';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_assets' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_meta_box' ) );
	}

	public static function register_meta() {
		register_post_meta(
			'post',
			self::META_MODE,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ),
				'auth_callback'     => function ( $allowed, $key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	public static function sanitize_mode( $v ) {
		return in_array( $v, array( 'on', 'off' ), true ) ? $v : '';
	}

	/** Whether the post should get a generated featured image on save. */
	public static function enabled( $post_id ) {
		return 'on' === get_post_meta( $post_id, self::META_MODE, true );
	}

	public static function block_editor_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script( 'afi-editor', AFI_URL . 'assets/editor.js', array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-data', 'wp-element' ), '0.3.0', true );
	}

	/** Classic editor only; the block editor uses the sidebar panel from editor.js. */
	public static function add_meta_box() {
		add_meta_box( 'afi-toggle', 'Featured Image by Tag', array( __CLASS__, 'render_meta_box' ), 'post', 'side', 'default', array( '__back_compat_meta_box' => true ) );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'afi_toggle', 'afi_toggle_nonce' );
		?>
		<input type="hidden" name="afi_mode_present" value="1">
		<label><input type="checkbox" name="afi_mode" value="on" <?php checked( self::enabled( $post->ID ) ); ?>> Use auto featured image</label>
		<p class="description">Generated each time the post is saved (drafts too), replacing the current featured image. Unticking removes the generated image.</p>
		<?php
	}

	public static function save_meta_box( $post_id ) {
		if ( empty( $_POST['afi_mode_present'] ) || ! isset( $_POST['afi_toggle_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['afi_toggle_nonce'] ), 'afi_toggle' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_MODE, empty( $_POST['afi_mode'] ) ? 'off' : 'on' );
	}
}
