# Featured Image by Tag

A WordPress plugin that generates featured images from post titles, categories,
tags, dates, and authors, using tag-specific background images or a solid color.

The initial plugin source was imported from `featured-image-by-tag.zip`.
The repository root is the plugin directory; no build step is required.

## Requirements

- WordPress with the block editor or classic editor.
- PHP 7.4 or later with GD and FreeType support.
- A writable WordPress uploads directory.

## Installation

1. Copy this repository's plugin files into
   `wp-content/plugins/featured-image-by-tag/`. Include
   `featured-image-by-tag.php`, `includes/`, `assets/`, `fonts/`, and `LICENSE`.
2. Activate **Featured Image by Tag** in the WordPress Plugins screen.
3. Open **Settings > Featured Image by Tag** to configure tag backgrounds,
   the canvas, text fields, and fonts.
4. Enable **Use auto featured image** in a post's editor and save the post.

Automatic generation is off by default. When enabled, saving a post generates
its featured image when the image inputs have changed, including for drafts,
and replaces the current featured image. Turning the option off and saving
removes the plugin-generated featured image.

Regenerating replaces the post's existing plugin-generated JPEG in the Media
Library, keeping its attachment ID, filename, and URL. Thumbnails, dimensions,
title, and alt text are refreshed, and obsolete image sizes are removed.
Manually uploaded images, generated images belonging to another post, and images
used as tag backgrounds are preserved; these cases create a separate generated
attachment. If an image cache or CDN still shows the previous image, purge its
cached copy because the URL stays the same.

Tag backgrounds load from local media files, with a Media Library URL fallback
for offloaded images (up to 20 MB per download). If a mapped image cannot be
loaded or decoded, generation stops with an error and preserves the current
featured image. The settings preview displays the same error.

After generating from the Posts list, the notice identifies the background used
and links to the regenerated image with a fresh query parameter to help bypass
cached copies. If no saved mapping matches the post's tags, the notice says that
the solid background color was used. In Overlay mode, a dark tint of 100% will
hide the background image completely; reduce it to make the image visible.

Backgrounds match the exact tag IDs assigned to each post. Add an explicit
mapping for each tag, including separate tags used in different languages.
Multiple tags can use the same image. When several mappings match a post,
the first configured row wins.

## Features

- Ordered tag-to-background mappings; the first matching row wins.
- Overlay and stacked layouts with a live settings preview.
- Configurable fonts, colors, alignment, positioning, and text wrapping.
- Chinese wrapping without spaces, with common punctuation kept next to its
  text and English words kept together in mixed-language titles.
- Bundled DejaVu and Liberation fonts. Additional `.ttf` and `.otf` files can
  be placed in `wp-content/uploads/afi-fonts/`.
- Individual and bulk generation actions in the Posts list.
- Optional WP-CLI generation by post ID:

  ```sh
  wp afi generate 123 456
  ```

## Development checks

The project has no package manager dependencies. With PHP and Node.js installed,
run these syntax checks from the repository root:

```sh
php -l featured-image-by-tag.php
php -l includes/class-afi-editor.php
php -l includes/class-afi-generator.php
php -l includes/class-afi-settings.php
node --check assets/admin.js
node --check assets/editor.js
```

Run the wrapping and tag-background regression checks with PHP GD and FreeType enabled:

```sh
php tests/wrap.php
php tests/background.php
```

For regeneration integration checks, set `AFI_TEST_WP_ROOT` to a disposable,
configured WordPress installation and run `php tests/regenerate.php`. These checks
create and remove test posts and media, and temporarily change plugin settings.

To check with Chinese glyphs, supply a Chinese font file:

```sh
php tests/wrap.php /path/to/NotoSansSC-Regular.ttf
```

Chinese rendering requires a font with Chinese glyphs, such as Noto Sans CJK,
selected for each relevant text field. The bundled fonts do not provide Chinese
coverage. Upload custom fonts directly into `wp-content/uploads/afi-fonts/`.
After updating the plugin, use **Generate featured image** in the Posts list
to regenerate existing images even when their text and settings have not changed.
If an earlier version lost tag-background mappings on the first settings save,
reselect those tags and images in the settings and save before regenerating.

Functional verification requires a WordPress installation with GD and FreeType.
Check the settings preview, generation on post save, tag background selection,
and removal of a generated image when the per-post option is disabled.

## License

Copyright (c) 2026 Crossborder Art & Media LLC.

Licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE), matching
the Manuscript Studio application. Any commercial use requires a separate
written license negotiated with Crossborder Art & Media LLC.

Bundled fonts retain their respective licenses.
