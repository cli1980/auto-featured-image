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

## Features

- Ordered tag-to-background mappings; the first matching mapping wins.
- Overlay and stacked layouts with a live settings preview.
- Configurable fonts, colors, alignment, positioning, and text wrapping.
- Bundled DejaVu and Liberation fonts. Additional `.ttf` and `.otf` files can
  be placed in `wp-content/uploads/afi-fonts/`.
- Individual and bulk generation actions in the Posts list.
- Optional WP-CLI generation by post ID:

  ```sh
  wp afi generate 123 456
  ```

## Development checks

The imported project has no package manager dependencies or automated test suite.
With PHP and Node.js installed, run these syntax checks from the repository root:

```sh
php -l featured-image-by-tag.php
php -l includes/class-afi-editor.php
php -l includes/class-afi-generator.php
php -l includes/class-afi-settings.php
node --check assets/admin.js
node --check assets/editor.js
```

Functional verification requires a WordPress installation with GD and FreeType.
Check the settings preview, generation on post save, tag background selection,
and removal of a generated image when the per-post option is disabled.

## License

Copyright (c) 2026 Crossborder Art & Media LLC.

Licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE), matching
the Manuscript Studio application. Any commercial use requires a separate
written license negotiated with Crossborder Art & Media LLC.

Bundled fonts retain their respective licenses.
