<?php
/**
 * Plugin Name: Featured Image by Tag
 * Description: Generates a post's featured image by overlaying its title, category, tags, date and author on a tag-specific background image (or a solid color).
 * Version: 0.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: featured-image-by-tag
 * Update URI: false
 */

defined( 'ABSPATH' ) || exit;

define( 'AFI_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFI_URL', plugin_dir_url( __FILE__ ) );

require_once AFI_DIR . 'includes/class-afi-settings.php';
require_once AFI_DIR . 'includes/class-afi-generator.php';
require_once AFI_DIR . 'includes/class-afi-editor.php';

AFI_Settings::init();
AFI_Generator::init();
AFI_Editor::init();
