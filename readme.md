# OpenGraph Image Generator

A WordPress plugin that automatically generates beautiful, branded OpenGraph images for social media sharing. Uses SVG for crisp, scalable graphics with a modular theme system.

## Features

- **Automatic Social Sharing** - Works with Facebook, Twitter, LinkedIn, Discord, Slack, WhatsApp
- **Dynamic Content** - Uses your site title, page titles, and tagline
- **Avatar Integration** - Upload your profile image with automatic caching
- **Themes** -  Modern Card, Purple Guide, Split, Dark Author, Simple Featured, Minimal, and more
- **Modular Theme System** - Easy to create custom themes
- **Configurable Footer Text** - Add your own professional tagline
- **Bulk Generation** - Generate images for all posts at once with progress tracking
- **Media Library Integration** - Images appear in WordPress media library
- **Smart Caching** - Avatar caching (7 days) and preview caching (15 minutes)
- **Performance Optimized** - Batch database queries, lazy theme loading
- **WP-CLI Support** - Full command-line interface for automation

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through WordPress admin
3. Go to **Settings → OpenGraph SVG**
4. Upload your avatar image
5. Choose your theme
6. Configure footer text (optional)
7. Save settings

## Usage

The plugin works automatically once activated. Every page on your site will have a unique OpenGraph image when shared on social media.

### Generate Images for All Posts

1. Go to Settings → OpenGraph SVG
2. Click "Generate All Images"
3. Optionally check "Regenerate existing images"
4. Wait for the process to complete

### Image URLs

- Homepage: `yoursite.com/og-svg/home/`
- Specific pages: `yoursite.com/og-svg/[post-id]/`

## Settings

| Setting | Description |
|---------|-------------|
| **Avatar Image** | Your profile photo (200x200px recommended, max 500KB) |
| **Theme** | Choose from 8+ professional themes |
| **Show Tagline** | Include/exclude site tagline in images |
| **Fallback Title** | Text for pages without titles (e.g., homepage) |
| **Post Types** | Enable for posts, pages, custom post types |
| **Footer Text** | Custom text shown in footer (e.g., "Developer • Designer • Creator") |

## Available Themes

| Theme | Description |
|-------|-------------|
| **Modern Card** | Clean card-based layout |
| **Purple Guide** | Elegant purple color scheme |
| **Split** | Two-column layout design |
| **Dark Author** | Dark theme with author focus |
| **Simple Featured** | Minimal featured image layout |
| **Minimal** | Ultra-simple, clean design |

## Creating Custom Themes

Themes are located in the `/themes/` directory. Each theme extends `OG_SVG_Theme_Base`:

```php
<?php
class OG_SVG_Theme_MyTheme extends OG_SVG_Theme_Base
{
  public function getThemeInfo(): array
  {
    return [
      'name' => 'My Theme',
      'description' => 'Custom theme description',
      'author' => 'Your Name',
      'preview_colors' => ['#3b82f6', '#1e40af', '#60a5fa']
    ];
  }

  public function getColorScheme(): array
  {
    return [
      'background' => '#1e40af',
      'gradient_start' => '#3b82f6',
      'gradient_end' => '#1e40af',
      'text_primary' => '#ffffff',
      'text_secondary' => '#e5e7eb',
      'accent' => '#60a5fa'
    ];
  }

  public function generateSVG(): string
  {
    // Your SVG generation logic
  }
}
```

## Troubleshooting

### OpenGraph URLs return 404 errors

1. Go to Settings → OpenGraph SVG
2. Click "Fix URL Issues"
3. Test URLs with "Test URLs" button

### Images don't appear on social media

1. Check that your post type is enabled in settings
2. Use "Generate All Images" to create missing images
3. Verify upload directory is writable
4. Use [Facebook Debugger](https://developers.facebook.com/tools/debug/) to refresh cache

### Avatar not loading

1. Ensure image is under 500KB
2. Check that the URL is accessible
3. Try re-uploading the image
4. Check `/uploads/og-svg/avatars/` for cached files

## WP-CLI Commands

```bash
# Generate images for all posts
wp og-svg generate

# Force regenerate all images
wp og-svg generate --force

# Generate for specific post type
wp og-svg generate --post-type=post

# Show statistics
wp og-svg stats

# Test URLs
wp og-svg test

# Clean up all images
wp og-svg cleanup
```

## Requirements

- WordPress 6.0+
- PHP 8.2+
- Pretty permalinks enabled (recommended)
- Writable uploads directory

## License

GPL v2 or later
