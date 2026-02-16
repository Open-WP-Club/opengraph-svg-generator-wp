<?php

/**
 * Base Theme Class for OpenGraph Image Generator
 *
 * Abstract class that all themes must extend. Provides common helper methods
 * for SVG generation, text handling, and image processing.
 *
 * @package OpenGraphImageGenerator
 * @since 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

abstract class OG_SVG_Theme_Base
{
  /** @var int Maximum avatar file size in bytes (500KB) */
  protected const MAX_AVATAR_SIZE = 512000;

  /**
   * Initialize theme with settings and data.
   *
   * @param array<string, mixed> $settings Plugin settings from WordPress options
   * @param array<string, mixed> $data SVG data including titles, tagline, avatar URL
   */
  public function __construct(
    protected readonly array $settings,
    protected readonly array $data,
  ) {}

  /**
   * Get theme metadata for display in admin interface.
   *
   * @return array<string, mixed> Theme info including name, description, author, preview_colors
   */
  abstract public function getThemeInfo(): array;

  /**
   * Get the color scheme for this theme.
   *
   * @return array<string, string> Colors including background, gradients, text, and accent colors
   */
  abstract public function getColorScheme(): array;

  /**
   * Generate the complete SVG content.
   *
   * @return string Complete SVG XML content
   */
  abstract public function generateSVG(): string;

  /**
   * Escape text for safe use in XML/SVG content.
   *
   * @param string $text The text to escape
   * @return string XML-safe escaped text
   */
  protected function escapeXML(string $text): string
  {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }

  /**
   * Truncate text to a maximum length, adding ellipsis if needed.
   *
   * @param string $text The text to truncate
   * @param int $max_length Maximum allowed length
   * @return string Truncated text with "..." if it was shortened
   */
  protected function truncateText(string $text, int $max_length): string
  {
    $text = trim($text);
    if (mb_strlen($text) <= $max_length) {
      return $text;
    }
    return mb_substr($text, 0, $max_length - 3) . '...';
  }

  /**
   * Convert an image URL to a base64 data URI with size validation.
   *
   * Handles both local WordPress uploads and external URLs.
   * Images larger than MAX_AVATAR_SIZE (500KB) are rejected.
   *
   * @param string $image_url The URL of the image to convert
   * @return string|false Base64 data URI or false on failure
   */
  protected function getImageAsBase64(string $image_url): string|false
  {
    if (empty($image_url)) {
      return false;
    }

    $upload_dir = wp_upload_dir();

    // Handle local WordPress uploads
    if (str_starts_with($image_url, $upload_dir['baseurl'])) {
      $local_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
      if (file_exists($local_path)) {
        // Validate file size
        $file_size = filesize($local_path);
        if ($file_size !== false && $file_size > self::MAX_AVATAR_SIZE) {
          error_log('OG Image: Avatar file too large (' . $file_size . ' bytes). Max: ' . self::MAX_AVATAR_SIZE);
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

    // Handle external URLs with caching
    $cache_dir = $upload_dir['basedir'] . '/og-svg/avatars/';
    $cache_file = $cache_dir . md5($image_url);

    // Check for cached avatar (7 days TTL)
    if (file_exists($cache_file)) {
      $cache_age = time() - filemtime($cache_file);
      if ($cache_age < 604800) {
        $cached_data = file_get_contents($cache_file);
        if ($cached_data !== false) {
          $parts = explode('|', $cached_data, 2);
          if (count($parts) === 2) {
            return 'data:' . $parts[0] . ';base64,' . $parts[1];
          }
        }
      }
      unlink($cache_file);
    }

    // Fetch external URL
    $response = wp_safe_remote_get($image_url, [
      'timeout' => 10,
      'headers' => [
        'User-Agent' => 'WordPress OpenGraph Image Generator'
      ]
    ]);

    if (is_wp_error($response)) {
      error_log('OG Image: Failed to fetch avatar: ' . $response->get_error_message());
      return false;
    }

    $body = wp_remote_retrieve_body($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');

    if (empty($body) || !str_starts_with((string) $content_type, 'image/')) {
      error_log('OG Image: Invalid image response from ' . $image_url);
      return false;
    }

    // Validate response size
    $body_size = strlen($body);
    if ($body_size > self::MAX_AVATAR_SIZE) {
      error_log('OG Image: External avatar too large (' . $body_size . ' bytes). Max: ' . self::MAX_AVATAR_SIZE);
      return false;
    }

    // Cache the avatar locally
    if (!file_exists($cache_dir)) {
      wp_mkdir_p($cache_dir);
    }
    $cache_content = $content_type . '|' . base64_encode($body);
    file_put_contents($cache_file, $cache_content);

    return 'data:' . $content_type . ';base64,' . base64_encode($body);
  }

  /**
   * Get the effective color scheme, merging any custom color overrides
   * from settings into the theme's default color scheme.
   *
   * @return array<string, string> Merged color scheme
   */
  protected function getEffectiveColorScheme(): array
  {
    $colors = $this->getColorScheme();
    $custom = $this->settings['custom_colors'] ?? [];

    if (!is_array($custom) || empty($custom)) {
      return $colors;
    }

    $overridable = ['accent', 'gradient_start', 'gradient_end', 'text_primary'];

    foreach ($overridable as $key) {
      if (!empty($custom[$key]) && isset($colors[$key])) {
        $colors[$key] = $custom[$key];
      }
    }

    return $colors;
  }

  /**
   * Get the featured image URL for the current post.
   *
   * @param string $size WordPress image size to retrieve
   * @return string|null Image URL or null if unavailable
   */
  protected function getFeaturedImageUrl(string $size = 'large'): ?string
  {
    if (!empty($this->data['post_id'])) {
      $post_id = $this->data['post_id'];
    } else {
      global $post;
      if ($post instanceof WP_Post && $post->ID) {
        $post_id = $post->ID;
      } else {
        return null;
      }
    }

    $featured_id = get_post_thumbnail_id($post_id);
    if ($featured_id) {
      $image_url = wp_get_attachment_image_url($featured_id, $size);
      if ($image_url) {
        return $image_url;
      }
    }

    return null;
  }

  /**
   * Generate a featured image background with a dark overlay for text readability.
   *
   * @param string $image_url The featured image URL
   * @param float $overlay_opacity Opacity of the dark overlay (0.0 to 1.0)
   * @return string SVG markup for the featured image background
   */
  protected function generateFeaturedImageBackground(string $image_url, float $overlay_opacity = 0.6): string
  {
    $bg = '';

    try {
      $image_data = $this->getImageAsBase64($image_url);
      if ($image_data) {
        $bg .= '<image x="0" y="0" width="1200" height="630" href="' . $image_data . '" preserveAspectRatio="xMidYMid slice" opacity="0.3"/>' . "\n";
        $bg .= '<rect width="1200" height="630" fill="rgba(0,0,0,' . $overlay_opacity . ')"/>' . "\n";
      }
    } catch (Exception $e) {
      // Silently fail - the theme will render without the featured image background
    }

    return $bg;
  }

  /**
   * Get the clean domain name from the site URL (no protocol, no www, no path).
   *
   * @return string Clean domain name
   */
  protected function getCleanDomain(): string
  {
    $url = $this->data['site_url'] ?? '';
    $domain = preg_replace('#^https?://(www\.)?#', '', $url);

    return strtok((string) $domain, '/') ?: $url;
  }

  /**
   * Generate the SVG XML header with proper namespaces.
   *
   * @return string SVG XML header
   */
  protected function generateSVGHeader(): string
  {
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
      '<svg width="1200" height="630" viewBox="0 0 1200 630" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">' . "\n";
  }

  /**
   * Generate the SVG closing tag.
   *
   * @return string SVG closing tag
   */
  protected function generateSVGFooter(): string
  {
    return '</svg>';
  }

  /**
   * Generate common SVG definitions (gradients, filters, clip paths).
   *
   * @param array<string, string> $colors Theme color scheme
   * @return string SVG defs element
   */
  protected function generateDefs(array $colors): string
  {
    $defs = '<defs>' . "\n";

    // Background gradient
    $defs .= '<linearGradient id="bgGradient" x1="0%" y1="0%" x2="100%" y2="100%">' . "\n";
    $defs .= '<stop offset="0%" style="stop-color:' . $colors['gradient_start'] . ';stop-opacity:1" />' . "\n";
    $defs .= '<stop offset="100%" style="stop-color:' . $colors['gradient_end'] . ';stop-opacity:1" />' . "\n";
    $defs .= '</linearGradient>' . "\n";

    // Text shadow filter
    $defs .= '<filter id="textShadow" x="-20%" y="-20%" width="140%" height="140%">' . "\n";
    $defs .= '<feDropShadow dx="2" dy="2" stdDeviation="3" flood-color="rgba(0,0,0,0.3)"/>' . "\n";
    $defs .= '</filter>' . "\n";

    // Avatar clip path
    $defs .= '<clipPath id="avatarClip">' . "\n";
    $defs .= '<circle cx="65" cy="65" r="65"/>' . "\n";
    $defs .= '</clipPath>' . "\n";

    $defs .= '</defs>' . "\n";

    return $defs;
  }
}
