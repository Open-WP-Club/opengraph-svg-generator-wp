<?php

/**
 * OpenGraph Image Generator Class
 *
 * Handles the generation and serving of OpenGraph SVG images.
 * Uses a modular theme system for customizable image styles.
 *
 * @package OpenGraphImageGenerator
 * @since 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

if (!class_exists('OG_SVG_Generator')) {

  class OG_SVG_Generator
  {
    /** @var array<string, mixed> */
    private array $settings;

    /** @var array<string, string> */
    private readonly array $upload_dir;

    private readonly OG_SVG_Theme_Manager $theme_manager;

    /** @var int Maximum avatar file size in bytes (500KB) */
    private const MAX_AVATAR_SIZE = 512000;

    public function __construct()
    {
      $this->settings = get_option('og_svg_settings', []);
      $this->upload_dir = wp_upload_dir();
      $this->theme_manager = new OG_SVG_Theme_Manager();
      $this->ensureUploadDirectory();
    }

    private function ensureUploadDirectory(): void
    {
      $svg_dir = $this->upload_dir['basedir'] . '/og-svg/';
      if (!file_exists($svg_dir)) {
        wp_mkdir_p($svg_dir);
      }
    }

    /**
     * @param array<string, mixed>|null $preview_settings
     */
    public function serveSVG(?int $post_id = null, ?array $preview_settings = null): void
    {
      try {
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log('OG SVG: Serving SVG for post_id: ' . ($post_id ?? 'home') . ($preview_settings ? ' (preview mode)' : ''));
        }

        // Set proper headers
        header('Content-Type: image/svg+xml');

        if ($preview_settings) {
          // Don't cache preview images
          header('Cache-Control: no-cache, no-store, must-revalidate');
          header('Pragma: no-cache');
          header('Expires: 0');
        } else {
          header('Cache-Control: public, max-age=3600');
          header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
        }

        // Generate SVG content with preview settings if provided
        if ($preview_settings) {
          $svg_content = $this->generateSVGWithSettings($post_id, $preview_settings);
        } else {
          $svg_content = $this->generateSVG($post_id);
        }

        // Only save to media for non-preview requests
        if (!$preview_settings) {
          $this->saveSVGToMedia($svg_content, $post_id);
        }

        // Output the SVG
        echo $svg_content;

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log('OG SVG: Successfully served SVG (' . strlen($svg_content) . ' bytes)');
        }
      } catch (Exception $e) {
        // Log error and serve fallback
        error_log('OG SVG Generator Error: ' . $e->getMessage());
        error_log('OG SVG Stack trace: ' . $e->getTraceAsString());
        $this->serveFallbackSVG($e->getMessage());
      }
    }

    /**
     * @param array<string, mixed> $custom_settings
     */
    public function generateSVGWithSettings(?int $post_id = null, array $custom_settings = []): string
    {
      // Temporarily override settings
      $original_settings = $this->settings;
      $this->settings = array_merge($this->settings, $custom_settings);

      try {
        $svg_content = $this->generateSVG($post_id);

        // Restore original settings
        $this->settings = $original_settings;

        return $svg_content;
      } catch (Exception $e) {
        // Restore original settings even on error
        $this->settings = $original_settings;
        throw $e;
      }
    }

    public function generateSVG(?int $post_id = null): string
    {
      // Get data for SVG
      $data = $this->getSVGData($post_id);

      // Validate required data
      if (empty($data['site_title']) && empty($data['page_title'])) {
        throw new Exception('No title data available for SVG generation');
      }

      // Check for per-post theme override, then fall back to global setting
      $theme_id = '';
      if ($post_id !== null) {
        $theme_id = get_post_meta($post_id, '_og_svg_theme', true);
      }
      if (empty($theme_id)) {
        $theme_id = $this->settings['color_scheme'] ?? 'gabriel';
      }

      try {
        $theme = $this->theme_manager->getTheme($theme_id, $this->settings, $data);
        return $theme->generateSVG();
      } catch (Exception $e) {
        error_log('Theme generation failed: ' . $e->getMessage());

        // Fallback to gabriel theme
        if ($theme_id !== 'gabriel') {
          try {
            $theme = $this->theme_manager->getTheme('gabriel', $this->settings, $data);
            return $theme->generateSVG();
          } catch (Exception $fallback_e) {
            error_log('Fallback theme also failed: ' . $fallback_e->getMessage());
            throw new Exception('All themes failed to generate SVG');
          }
        } else {
          throw $e;
        }
      }
    }

    /**
     * Get available themes for admin interface
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableThemes(): array
    {
      return $this->theme_manager->getAvailableThemes();
    }

    /**
     * Converts an image URL to a base64 data URI with size validation and caching.
     *
     * @param string $image_url The URL of the image to convert
     * @return string|false Base64 data URI or false on failure
     * @throws Exception If avatar exceeds size limit
     */
    private function getImageAsBase64(string $image_url): string|false
    {
      if (empty($image_url)) {
        return false;
      }

      // Handle local WordPress uploads
      if (str_starts_with($image_url, $this->upload_dir['baseurl'])) {
        $local_path = str_replace($this->upload_dir['baseurl'], $this->upload_dir['basedir'], $image_url);
        if (file_exists($local_path)) {
          // Validate file size
          $file_size = filesize($local_path);
          if ($file_size !== false && $file_size > self::MAX_AVATAR_SIZE) {
            error_log('OG SVG: Avatar file too large (' . $file_size . ' bytes). Max: ' . self::MAX_AVATAR_SIZE);
            return false;
          }

          $image_data = file_get_contents($local_path);
          if ($image_data === false) {
            return false;
          }
          $file_type = wp_check_filetype($local_path);
          $mime_type = $file_type['type'] ?: 'image/jpeg';
          return 'data:' . $mime_type . ';base64,' . base64_encode($image_data);
        }
      }

      // Check for cached external avatar
      $cached_avatar = $this->getCachedAvatar($image_url);
      if ($cached_avatar !== false) {
        return $cached_avatar;
      }

      // Handle external URLs
      $response = wp_safe_remote_get($image_url, [
        'timeout' => 10,
        'headers' => [
          'User-Agent' => 'WordPress OpenGraph Image Generator'
        ]
      ]);

      if (is_wp_error($response)) {
        error_log('OG SVG: Failed to fetch avatar: ' . $response->get_error_message());
        return false;
      }

      $body = wp_remote_retrieve_body($response);
      $content_type = wp_remote_retrieve_header($response, 'content-type');

      if (empty($body) || !str_starts_with((string) $content_type, 'image/')) {
        error_log('OG SVG: Invalid image response from ' . $image_url);
        return false;
      }

      // Validate response size
      $body_size = strlen($body);
      if ($body_size > self::MAX_AVATAR_SIZE) {
        error_log('OG SVG: External avatar too large (' . $body_size . ' bytes). Max: ' . self::MAX_AVATAR_SIZE);
        return false;
      }

      // Cache the avatar locally
      $this->cacheAvatar($image_url, $body, (string) $content_type);

      return 'data:' . $content_type . ';base64,' . base64_encode($body);
    }

    /**
     * Get cached avatar if exists and not expired.
     *
     * @param string $image_url Original avatar URL
     * @return string|false Base64 data URI or false if not cached
     */
    private function getCachedAvatar(string $image_url): string|false
    {
      $cache_dir = $this->upload_dir['basedir'] . '/og-svg/avatars/';
      $cache_file = $cache_dir . md5($image_url);

      if (!file_exists($cache_file)) {
        return false;
      }

      // Check if cache is expired (7 days)
      $cache_age = time() - filemtime($cache_file);
      if ($cache_age > 604800) {
        unlink($cache_file);
        return false;
      }

      $cached_data = file_get_contents($cache_file);
      if ($cached_data === false) {
        return false;
      }

      // Cache file format: mime_type|base64_data
      $parts = explode('|', $cached_data, 2);
      if (count($parts) !== 2) {
        unlink($cache_file);
        return false;
      }

      return 'data:' . $parts[0] . ';base64,' . $parts[1];
    }

    /**
     * Cache avatar locally for faster subsequent requests.
     *
     * @param string $image_url Original avatar URL
     * @param string $image_data Raw image data
     * @param string $content_type MIME type
     */
    private function cacheAvatar(string $image_url, string $image_data, string $content_type): void
    {
      $cache_dir = $this->upload_dir['basedir'] . '/og-svg/avatars/';

      // Ensure cache directory exists
      if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir);
      }

      $cache_file = $cache_dir . md5($image_url);

      // Store as mime_type|base64_data
      $cache_content = $content_type . '|' . base64_encode($image_data);
      file_put_contents($cache_file, $cache_content);
    }

    public function saveSVGToMedia(string $svg_content, ?int $post_id = null): void
    {
      try {
        // Generate filename
        $filename = $post_id !== null ? "og-svg-{$post_id}.svg" : "og-svg-home.svg";
        $file_path = $this->upload_dir['basedir'] . '/og-svg/' . $filename;

        // Save to file system
        $bytes_written = file_put_contents($file_path, $svg_content);
        if ($bytes_written === false) {
          throw new Exception('Failed to write SVG file to disk');
        }

        // Generate PNG alongside SVG
        $png_path = $this->getPNGFilePath($post_id);
        $this->convertSVGToPNG($svg_content, $png_path);

        // Add to media library if it doesn't exist
        $existing = get_posts([
          'post_type' => 'attachment',
          'meta_query' => [
            [
              'key' => '_og_svg_file',
              'value' => $filename,
              'compare' => '='
            ]
          ],
          'posts_per_page' => 1
        ]);

        if (empty($existing)) {
          $post_title = $post_id !== null
            ? get_the_title($post_id) . ' - OpenGraph Image'
            : get_bloginfo('name') . ' - OpenGraph Image';

          $attachment_data = [
            'post_title' => $post_title,
            'post_content' => 'Auto-generated OpenGraph SVG image',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/svg+xml'
          ];

          $attachment_id = wp_insert_attachment($attachment_data, $file_path);

          if (!is_wp_error($attachment_id) && is_int($attachment_id)) {
            // Add custom meta to identify OG SVG files
            update_post_meta($attachment_id, '_og_svg_generated', '1');
            update_post_meta($attachment_id, '_og_svg_file', $filename);
            update_post_meta($attachment_id, '_og_svg_post_id', $post_id ?? 'home');

            // Generate attachment metadata
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $attach_data);
          }
        }
      } catch (Exception $e) {
        error_log('Failed to save SVG to media: ' . $e->getMessage());
      }
    }

    private function serveFallbackSVG(?string $error_message = null): void
    {
      // Set headers
      header('Content-Type: image/svg+xml');
      header('Cache-Control: public, max-age=300'); // Shorter cache for fallback

      $site_name = get_bloginfo('name') ?: 'WordPress Site';
      $debug_info = '';

      if (defined('WP_DEBUG') && WP_DEBUG && $error_message) {
        $debug_info = '<!-- Error: ' . esc_html($error_message) . ' -->' . "\n";
      }

      $fallback = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
      $fallback .= $debug_info;
      $fallback .= '<svg width="1200" height="630" viewBox="0 0 1200 630" xmlns="http://www.w3.org/2000/svg">' . "\n";
      $fallback .= '<rect width="1200" height="630" fill="#1e293b"/>' . "\n";
      $fallback .= '<text x="600" y="280" font-family="system-ui, sans-serif" font-size="36" font-weight="600" fill="#f8fafc" text-anchor="middle">' . "\n";
      $fallback .= htmlspecialchars($site_name, ENT_XML1, 'UTF-8') . "\n";
      $fallback .= '</text>' . "\n";
      $fallback .= '<text x="600" y="320" font-family="system-ui, sans-serif" font-size="16" fill="#cbd5e1" text-anchor="middle">' . "\n";
      $fallback .= 'OpenGraph Image' . "\n";
      $fallback .= '</text>' . "\n";

      if (defined('WP_DEBUG') && WP_DEBUG && $error_message) {
        $fallback .= '<text x="600" y="360" font-family="monospace" font-size="12" fill="#ef4444" text-anchor="middle">' . "\n";
        $fallback .= 'Debug: ' . htmlspecialchars(substr($error_message, 0, 80), ENT_XML1, 'UTF-8') . "\n";
        $fallback .= '</text>' . "\n";
      }

      $fallback .= '</svg>';

      echo $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSVGData(?int $post_id = null): array
    {
      $data = [];

      // Site title
      $data['site_title'] = get_bloginfo('name') ?: 'WordPress Site';

      // Page title
      if ($post_id !== null) {
        $post = get_post($post_id);
        if ($post instanceof WP_Post) {
          $data['page_title'] = $post->post_title ?: ($this->settings['fallback_title'] ?? 'Welcome');
        } else {
          $data['page_title'] = $this->settings['fallback_title'] ?? 'Page Not Found';
        }
      } else {
        // Home page
        if (is_home() || is_front_page()) {
          $data['page_title'] = $this->settings['fallback_title'] ?? 'Welcome';
        } else {
          // Use modern alternative to deprecated wp_title()
          $data['page_title'] = wp_get_document_title() ?: get_the_title() ?: 'Page';
        }
      }

      // Tagline
      $data['tagline'] = get_bloginfo('description') ?: '';

      // Avatar URL
      $data['avatar_url'] = $this->settings['avatar_url'] ?? '';

      // Post ID
      $data['post_id'] = $post_id;

      // Site URL
      $parsed_url = parse_url(get_site_url(), PHP_URL_HOST);
      $data['site_url'] = is_string($parsed_url) ? $parsed_url : get_site_url();

      return $data;
    }

    public function getSVGUrl(?int $post_id = null): string
    {
      if ($post_id !== null) {
        return get_site_url() . '/og-svg/' . $post_id . '/';
      }
      return get_site_url() . '/og-svg/home/';
    }

    public function getSVGFilePath(?int $post_id = null): string
    {
      $filename = $post_id !== null ? "og-svg-{$post_id}.svg" : "og-svg-home.svg";
      return $this->upload_dir['basedir'] . '/og-svg/' . $filename;
    }

    public function getSVGFileUrl(?int $post_id = null): string
    {
      $filename = $post_id !== null ? "og-svg-{$post_id}.svg" : "og-svg-home.svg";
      return $this->upload_dir['baseurl'] . '/og-svg/' . $filename;
    }

    public function getPNGFilePath(?int $post_id = null): string
    {
      $filename = $post_id !== null ? "og-svg-{$post_id}.png" : "og-svg-home.png";
      return $this->upload_dir['basedir'] . '/og-svg/' . $filename;
    }

    public function getPNGFileUrl(?int $post_id = null): string
    {
      $filename = $post_id !== null ? "og-svg-{$post_id}.png" : "og-svg-home.png";
      return $this->upload_dir['baseurl'] . '/og-svg/' . $filename;
    }

    /**
     * Convert SVG content to PNG using Imagick.
     *
     * @param string $svg_content The SVG XML content
     * @param string $png_path Destination file path for the PNG
     * @return bool True on success, false on failure
     */
    public function convertSVGToPNG(string $svg_content, string $png_path): bool
    {
      if (!extension_loaded('imagick')) {
        return false;
      }

      try {
        $imagick = new Imagick();
        $imagick->setResolution(150, 150);
        $imagick->readImageBlob($svg_content);
        $imagick->setImageFormat('png');
        $imagick->resizeImage(1200, 630, Imagick::FILTER_LANCZOS, 1);
        $imagick->setImageCompressionQuality(90);

        $result = $imagick->writeImage($png_path);
        $imagick->clear();
        $imagick->destroy();

        return $result;
      } catch (Exception $e) {
        error_log('OG SVG: PNG conversion failed: ' . $e->getMessage());
        return false;
      }
    }

    /**
     * @return array{files_removed: int, attachments_removed: int}
     */
    public function cleanupAllSVGs(): array
    {
      $svg_dir = $this->upload_dir['basedir'] . '/og-svg/';
      $count = 0;

      if (is_dir($svg_dir)) {
        // Remove both SVG and PNG files
        $files = glob($svg_dir . '*.{svg,png}', GLOB_BRACE);
        if ($files !== false) {
          foreach ($files as $file) {
            if (unlink($file)) {
              $count++;
            }
          }
        }

        // Remove directory if empty
        $remaining = glob($svg_dir . '*');
        if ($remaining !== false && count($remaining) === 0) {
          rmdir($svg_dir);
        }
      }

      // Remove from media library
      $attachments = get_posts([
        'post_type' => 'attachment',
        'meta_query' => [
          [
            'key' => '_og_svg_generated',
            'value' => '1',
            'compare' => '='
          ]
        ],
        'posts_per_page' => -1
      ]);

      foreach ($attachments as $attachment) {
        if ($attachment instanceof WP_Post) {
          wp_delete_attachment($attachment->ID, true);
        }
      }

      return [
        'files_removed' => $count,
        'attachments_removed' => count($attachments)
      ];
    }

    /**
     * Cleanup orphaned SVG files where the original post no longer exists.
     * Optimized to use batch queries instead of per-file queries.
     *
     * @return int Number of files cleaned up
     */
    public function cleanupOrphanedFiles(): int
    {
      $upload_dir = wp_upload_dir();
      $svg_dir = $upload_dir['basedir'] . '/og-svg/';
      $cleaned = 0;

      if (!is_dir($svg_dir)) {
        return 0;
      }

      $files = glob($svg_dir . 'og-svg-*.svg');
      if ($files === false || empty($files)) {
        return 0;
      }

      // Extract all post IDs from filenames
      $post_ids_to_check = [];
      $file_map = []; // Maps post_id => file_path

      foreach ($files as $file) {
        $filename = basename($file);
        if (preg_match('/og-svg-(\d+)\.svg/', $filename, $matches)) {
          $post_id = (int) $matches[1];
          $post_ids_to_check[] = $post_id;
          $file_map[$post_id] = $file;
        }
      }

      if (empty($post_ids_to_check)) {
        return 0;
      }

      // Batch query to find which posts still exist
      $existing_posts = get_posts([
        'post_type' => 'any',
        'post_status' => 'any',
        'post__in' => $post_ids_to_check,
        'posts_per_page' => -1,
        'fields' => 'ids'
      ]);

      $existing_post_ids = array_map('intval', $existing_posts);
      $orphaned_post_ids = array_diff($post_ids_to_check, $existing_post_ids);

      if (empty($orphaned_post_ids)) {
        return 0;
      }

      // Delete orphaned files (SVG and corresponding PNG)
      foreach ($orphaned_post_ids as $post_id) {
        if (isset($file_map[$post_id]) && file_exists($file_map[$post_id])) {
          if (unlink($file_map[$post_id])) {
            $cleaned++;
          }
        }
        // Also delete corresponding PNG
        $png_path = $svg_dir . 'og-svg-' . $post_id . '.png';
        if (file_exists($png_path)) {
          unlink($png_path);
        }
      }

      // Batch cleanup from media library
      if (!empty($orphaned_post_ids)) {
        $attachments = get_posts([
          'post_type' => 'attachment',
          'meta_query' => [
            [
              'key' => '_og_svg_post_id',
              'value' => array_map('strval', $orphaned_post_ids),
              'compare' => 'IN'
            ]
          ],
          'posts_per_page' => -1,
          'fields' => 'ids'
        ]);

        foreach ($attachments as $attachment_id) {
          wp_delete_attachment($attachment_id, true);
        }
      }

      return $cleaned;
    }
  }
} // End class_exists check