<?php

/**
 * Enhanced Meta Handler Class
 * Handles injection of OpenGraph meta tags and media library integration
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

if (!class_exists('OG_SVG_Meta_Handler')) {

  class OG_SVG_Meta_Handler
  {
    /** @var array<string, mixed> */
    private readonly array $settings;

    private readonly OG_SVG_Generator $generator;

    public function __construct()
    {
      $this->settings = get_option('og_svg_settings', []);
      $this->generator = new OG_SVG_Generator();

      // Hook into WordPress
      add_action('wp_head', [$this, 'addOpenGraphTags'], 5);
      add_filter('language_attributes', [$this, 'addOpenGraphNamespace']);

      // Media library integration
      add_filter('attachment_fields_to_edit', [$this, 'addCustomFieldsToAttachment'], 10, 2);
      add_action('admin_init', [$this, 'addMediaLibraryColumns']);
      add_action('pre_get_posts', [$this, 'filterMediaLibraryQuery']);

      // Add media library tab for OG images
      add_filter('media_upload_tabs', [$this, 'addMediaTab']);
      add_action('media_upload_og_svg', [$this, 'mediaTabContent']);

      // Add regeneration capability
      add_action('wp_ajax_regenerate_og_svg', [$this, 'ajaxRegenerateOGSVG']);

      // Clean up on post deletion
      add_action('before_delete_post', [$this, 'cleanupPostSVG']);
    }

    public function addOpenGraphNamespace(string $output): string
    {
      return $output . ' prefix="og: http://ogp.me/ns# article: http://ogp.me/ns/article#"';
    }

    public function addOpenGraphTags(): void
    {
      if (!$this->shouldAddOpenGraphTags()) {
        return;
      }

      $post = get_post();

      // Get page information
      $title = $this->getPageTitle();
      $description = $this->getPageDescription();
      $url = $this->getCurrentUrl();
      $site_name = get_bloginfo('name');
      $image_url = $this->getOpenGraphImageUrl();
      $type = $this->getOpenGraphType();

      echo "\n<!-- OpenGraph SVG Generator Meta Tags by https:openwpclub.com/ -->\n";

      // Basic OpenGraph tags
      $this->outputMetaTag('og:title', $title);
      $this->outputMetaTag('og:description', $description);
      $this->outputMetaTag('og:url', $url);
      $this->outputMetaTag('og:site_name', $site_name);
      $this->outputMetaTag('og:type', $type);
      $this->outputMetaTag('og:locale', get_locale());

      // Image meta tags
      if ($image_url) {
        $this->outputMetaTag('og:image', $image_url);
        $this->outputMetaTag('og:image:width', '1200');
        $this->outputMetaTag('og:image:height', '630');
        $image_type = str_ends_with($image_url, '.png') ? 'image/png' : 'image/svg+xml';
        $this->outputMetaTag('og:image:type', $image_type);
        $this->outputMetaTag('og:image:alt', $title . ' - ' . $site_name);

        // Add secure URL if using HTTPS
        if (is_ssl()) {
          $this->outputMetaTag('og:image:secure_url', $image_url);
        }
      }

      // Article-specific tags
      if ($type === 'article' && $post instanceof WP_Post) {
        $this->outputMetaTag('article:published_time', get_the_date('c', $post));
        $this->outputMetaTag('article:modified_time', get_the_modified_date('c', $post));

        $author = get_the_author_meta('display_name', $post->post_author);
        if ($author) {
          $this->outputMetaTag('article:author', $author);
        }

        // Categories and tags
        $categories = get_the_category($post->ID);
        if (!empty($categories)) {
          $this->outputMetaTag('article:section', $categories[0]->name);
        }

        $tags = get_the_tags($post->ID);
        if (!empty($tags) && is_array($tags)) {
          foreach (array_slice($tags, 0, 5) as $tag) {
            $this->outputMetaTag('article:tag', $tag->name);
          }
        }
      }

      // Twitter Card tags
      $this->outputMetaTag('twitter:card', 'summary_large_image', 'name');
      $this->outputMetaTag('twitter:title', $title, 'name');
      $this->outputMetaTag('twitter:description', $description, 'name');

      if ($image_url) {
        $this->outputMetaTag('twitter:image', $image_url, 'name');
      }

      // Twitter site handle if configured
      $twitter_handle = get_option('og_svg_twitter_handle', '');
      if ($twitter_handle) {
        $this->outputMetaTag('twitter:site', $twitter_handle, 'name');
        $this->outputMetaTag('twitter:creator', $twitter_handle, 'name');
      }

      // Additional SEO meta tags
      if (!has_action('wp_head', 'rel_canonical')) {
        echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
      }

      // Schema.org structured data
      $this->addStructuredData();

      echo "<!-- End OpenGraph SVG Generator Meta Tags -->\n\n";
    }

    private function outputMetaTag(string $property, string $content, string $attribute = 'property'): void
    {
      if (!empty($content)) {
        echo '<meta ' . $attribute . '="' . esc_attr($property) . '" content="' . esc_attr($content) . '" />' . "\n";
      }
    }

    private function shouldAddOpenGraphTags(): bool
    {
      // Skip if another SEO plugin is handling OG tags
      if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || class_exists('All_in_One_SEO_Pack')) {
        $override_seo = get_option('og_svg_override_seo', false);
        if (!$override_seo) {
          return false;
        }
      }

      $enabled_post_types = $this->settings['enabled_post_types'] ?? ['post', 'page'];

      if (is_home() || is_front_page()) {
        return in_array('page', $enabled_post_types, true);
      }

      if (is_singular()) {
        $post_type = get_post_type();
        return $post_type !== false && in_array($post_type, $enabled_post_types, true);
      }

      if (is_archive() || is_category() || is_tag() || is_author()) {
        return in_array('post', $enabled_post_types, true);
      }

      return false;
    }

    private function getPageTitle(): string
    {
      if (is_home() || is_front_page()) {
        $title = get_bloginfo('name');
        $tagline = get_bloginfo('description');
        return $title . ($tagline ? ' - ' . $tagline : '');
      }

      if (is_singular()) {
        return get_the_title() ?: ($this->settings['fallback_title'] ?? 'Untitled');
      }

      if (is_category()) {
        return 'Category: ' . single_cat_title('', false);
      }

      if (is_tag()) {
        return 'Tag: ' . single_tag_title('', false);
      }

      if (is_author()) {
        return 'Author: ' . get_the_author();
      }

      if (is_search()) {
        return 'Search Results for: ' . get_search_query();
      }

      if (is_404()) {
        return 'Page Not Found';
      }

      // Use modern alternative to deprecated wp_title()
      return wp_get_document_title() ?: get_bloginfo('name');
    }

    private function getPageDescription(): string
    {
      if (is_singular()) {
        $post = get_post();

        if (!$post instanceof WP_Post) {
          return get_bloginfo('description') ?: 'Welcome to our website';
        }

        // Try excerpt first
        if (has_excerpt($post)) {
          return wp_strip_all_tags(get_the_excerpt());
        }

        // Generate from content
        $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
        $content = (string) preg_replace('/\s+/', ' ', $content);

        if (strlen($content) > 160) {
          $content = substr($content, 0, 157) . '...';
        }

        return $content ?: get_bloginfo('description');
      }

      if (is_category()) {
        $description = category_description();
        return $description ? wp_strip_all_tags($description) : 'Browse posts in ' . single_cat_title('', false);
      }

      if (is_tag()) {
        $description = tag_description();
        return $description ? wp_strip_all_tags($description) : 'Posts tagged with ' . single_tag_title('', false);
      }

      if (is_author()) {
        $description = get_the_author_meta('description');
        return $description ?: 'Posts by ' . get_the_author();
      }

      if (is_search()) {
        return 'Search results for "' . get_search_query() . '"';
      }

      return get_bloginfo('description') ?: 'Welcome to our website';
    }

    private function getCurrentUrl(): string
    {
      if (is_home() || is_front_page()) {
        return get_home_url();
      }

      if (is_singular()) {
        $permalink = get_permalink();
        return $permalink !== false ? $permalink : get_home_url();
      }

      // Build URL for archives, etc.
      global $wp;
      return home_url(add_query_arg([], $wp->request ?? ''));
    }

    private function getOpenGraphImageUrl(): string
    {
      $post = get_post();
      $post_id = (is_singular() && $post instanceof WP_Post) ? $post->ID : null;

      // Prefer PNG if it exists (social platforms don't render SVG)
      $png_path = $this->generator->getPNGFilePath($post_id);
      if (file_exists($png_path)) {
        return $this->generator->getPNGFileUrl($post_id);
      }

      // Fall back to SVG URL endpoint
      return $this->generator->getSVGUrl($post_id);
    }

    private function getOpenGraphType(): string
    {
      return match (true) {
        is_home(), is_front_page() => 'website',
        is_singular('post') => 'article',
        is_singular() => 'website',
        is_author() => 'profile',
        default => 'website',
      };
    }

    public function addStructuredData(): void
    {
      $title = $this->getPageTitle();
      $description = $this->getPageDescription();
      $url = $this->getCurrentUrl();
      $image_url = $this->getOpenGraphImageUrl();

      $schema = [
        '@context' => 'https://schema.org',
        '@type' => is_singular('post') ? 'Article' : 'WebPage',
        'name' => $title,
        'headline' => $title,
        'description' => $description,
        'url' => $url,
        'publisher' => [
          '@type' => 'Organization',
          'name' => get_bloginfo('name'),
          'url' => get_home_url()
        ]
      ];

      if ($image_url) {
        $schema['image'] = [
          '@type' => 'ImageObject',
          'url' => $image_url,
          'width' => 1200,
          'height' => 630
        ];
      }

      if (is_singular('post')) {
        $post = get_post();
        if ($post instanceof WP_Post) {
          $schema['datePublished'] = get_the_date('c', $post);
          $schema['dateModified'] = get_the_modified_date('c', $post);
          $schema['author'] = [
            '@type' => 'Person',
            'name' => get_the_author_meta('display_name', $post->post_author),
            'url' => get_author_posts_url($post->post_author)
          ];

          // Add article body
          $schema['articleBody'] = wp_strip_all_tags(get_the_content());
        }
      }

      echo "\n" . '<script type="application/ld+json">' . "\n";
      echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
      echo "\n" . '</script>' . "\n";
    }

    /**
     * Media Library Integration
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function addCustomFieldsToAttachment(array $fields, WP_Post $post): array
    {
      if (get_post_meta($post->ID, '_og_svg_generated', true)) {
        $og_post_id = get_post_meta($post->ID, '_og_svg_post_id', true);

        $fields['og_svg_info'] = [
          'label' => 'OpenGraph SVG Info',
          'input' => 'html',
          'html' => $this->getOGSVGInfoHTML($post->ID, (string) $og_post_id),
          'show_in_edit' => true,
        ];
      }

      return $fields;
    }

    private function getOGSVGInfoHTML(int $attachment_id, string $og_post_id): string
    {
      $html = '<div class="og-svg-attachment-info">';
      $html .= '<p><strong>This is an auto-generated OpenGraph image.</strong></p>';

      if ($og_post_id !== '' && $og_post_id !== 'home') {
        $related_post = get_post((int) $og_post_id);
        if ($related_post instanceof WP_Post) {
          $edit_link = get_edit_post_link((int) $og_post_id);
          $html .= '<p>Generated for: <a href="' . esc_url((string) $edit_link) . '">' . esc_html(get_the_title((int) $og_post_id)) . '</a></p>';
        }
      } else {
        $html .= '<p>Generated for: Homepage</p>';
      }

      $html .= '<p>Generated: ' . get_the_date('Y-m-d H:i:s', $attachment_id) . '</p>';

      // Add regeneration button
      $html .= '<p>';
      $html .= '<button type="button" class="button button-small og-svg-regenerate" data-attachment-id="' . $attachment_id . '" data-post-id="' . $og_post_id . '">';
      $html .= 'Regenerate Image';
      $html .= '</button>';
      $html .= '</p>';

      $html .= '</div>';

      // Add inline script for regeneration
      $html .= '<script>
        jQuery(document).ready(function($) {
          $(".og-svg-regenerate").on("click", function() {
            var button = $(this);
            var originalText = button.text();
            var attachmentId = button.data("attachment-id");
            var postId = button.data("post-id");
            
            button.text("Regenerating...").prop("disabled", true);
            
            $.ajax({
              url: ajaxurl,
              type: "POST", 
              data: {
                action: "regenerate_og_svg",
                attachment_id: attachmentId,
                post_id: postId,
                nonce: "' . wp_create_nonce('regenerate_og_svg') . '"
              },
              success: function(response) {
                if (response.success) {
                  alert("Image regenerated successfully!");
                  location.reload();
                } else {
                  alert("Error: " + response.data.message);
                }
              },
              error: function() {
                alert("Failed to regenerate image. Please try again.");
              },
              complete: function() {
                button.text(originalText).prop("disabled", false);
              }
            });
          });
        });
      </script>';

      return $html;
    }

    public function addMediaLibraryColumns(): void
    {
      add_filter('manage_media_columns', [$this, 'addOGSVGColumn']);
      add_action('manage_media_custom_column', [$this, 'displayOGSVGColumn'], 10, 2);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function addOGSVGColumn(array $columns): array
    {
      $columns['og_svg'] = 'OpenGraph';
      return $columns;
    }

    public function displayOGSVGColumn(string $column_name, int $attachment_id): void
    {
      if ($column_name === 'og_svg') {
        if (get_post_meta($attachment_id, '_og_svg_generated', true)) {
          echo '<span class="og-svg-badge">OG Image</span>';

          $og_post_id = get_post_meta($attachment_id, '_og_svg_post_id', true);
          if ($og_post_id && $og_post_id !== 'home') {
            echo '<br><small>Post ID: ' . $og_post_id . '</small>';
          } elseif ($og_post_id === 'home') {
            echo '<br><small>Homepage</small>';
          }
        }
      }
    }

    public function filterMediaLibraryQuery(WP_Query $query): void
    {
      if (is_admin() && $query->is_main_query()) {
        $og_svg_filter = isset($_GET['og_svg_filter']) ? sanitize_text_field($_GET['og_svg_filter']) : '';
        if ($og_svg_filter === 'og_images') {
          $query->set('meta_query', [
            [
              'key' => '_og_svg_generated',
              'value' => '1',
              'compare' => '='
            ]
          ]);
        }
      }
    }

    /**
     * @param array<string, string> $tabs
     * @return array<string, string>
     */
    public function addMediaTab(array $tabs): array
    {
      $tabs['og_svg'] = 'OpenGraph Images';
      return $tabs;
    }

    public function mediaTabContent(): void
    {
      wp_iframe([$this, 'mediaTabIframe']);
    }

    public function mediaTabIframe(): void
    {
      // Get all OG SVG attachments
      $attachments = get_posts([
        'post_type' => 'attachment',
        'meta_query' => [
          [
            'key' => '_og_svg_generated',
            'value' => '1',
            'compare' => '='
          ]
        ],
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC'
      ]);

      echo '<div class="og-svg-media-tab">';
      echo '<h2>OpenGraph SVG Images</h2>';

      if (empty($attachments)) {
        echo '<p>No OpenGraph images have been generated yet.</p>';
        echo '<p><a href="' . admin_url('options-general.php?page=og-svg-settings') . '" class="button">Generate Your First Image</a></p>';
      } else {
        echo '<div class="og-svg-images-grid">';

        foreach ($attachments as $attachment) {
          $og_post_id = get_post_meta($attachment->ID, '_og_svg_post_id', true);
          $image_url = wp_get_attachment_url($attachment->ID);

          echo '<div class="og-svg-image-item">';
          echo '<div class="og-svg-image-preview">';
          echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($attachment->post_title) . '" />';
          echo '</div>';
          echo '<div class="og-svg-image-info">';
          echo '<h4>' . esc_html($attachment->post_title) . '</h4>';

          if ($og_post_id && $og_post_id !== 'home') {
            $post = get_post($og_post_id);
            if ($post) {
              echo '<p>For: <a href="' . get_edit_post_link($og_post_id) . '">' . get_the_title($og_post_id) . '</a></p>';
            }
          } else {
            echo '<p>For: Homepage</p>';
          }

          echo '<p>Generated: ' . get_the_date('Y-m-d H:i', $attachment->ID) . '</p>';
          echo '<div class="og-svg-image-actions">';
          echo '<a href="' . esc_url($image_url) . '" target="_blank" class="button button-small">View</a> ';
          echo '<a href="' . get_edit_post_link($attachment->ID) . '" class="button button-small">Edit</a>';
          echo '</div>';
          echo '</div>';
          echo '</div>';
        }

        echo '</div>';
      }

      echo '</div>';

      // Add CSS for the media tab
      echo '<style>
        .og-svg-media-tab {
          padding: 20px;
        }
        
        .og-svg-images-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
          gap: 20px;
          margin-top: 20px;
        }
        
        .og-svg-image-item {
          border: 1px solid #ddd;
          border-radius: 8px;
          overflow: hidden;
          background: #fff;
        }
        
        .og-svg-image-preview {
          height: 150px;
          overflow: hidden;
          background: #f5f5f5;
          display: flex;
          align-items: center;
          justify-content: center;
        }
        
        .og-svg-image-preview img {
          max-width: 100%;
          max-height: 100%;
          object-fit: contain;
        }
        
        .og-svg-image-info {
          padding: 15px;
        }
        
        .og-svg-image-info h4 {
          margin: 0 0 10px 0;
          font-size: 14px;
        }
        
        .og-svg-image-info p {
          margin: 5px 0;
          font-size: 12px;
          color: #666;
        }
        
        .og-svg-image-actions {
          margin-top: 10px;
        }
        
        .og-svg-badge {
          background: #0073aa;
          color: white;
          padding: 2px 6px;
          border-radius: 3px;
          font-size: 11px;
          font-weight: bold;
        }
      </style>';
    }

    public function ajaxRegenerateOGSVG(): void
    {
      // Verify nonce - validate before accessing
      $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
      if (empty($nonce) || !wp_verify_nonce($nonce, 'regenerate_og_svg')) {
        wp_die('Security check failed');
      }

      $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
      $post_id_raw = isset($_POST['post_id']) ? sanitize_text_field($_POST['post_id']) : '';
      $post_id = $post_id_raw === 'home' ? null : (int) $post_id_raw;

      try {
        // Generate new SVG
        $svg_content = $this->generator->generateSVG($post_id);

        // Get file path
        $file_path = $this->generator->getSVGFilePath($post_id);

        // Save new content
        $bytes_written = file_put_contents($file_path, $svg_content);
        if ($bytes_written === false) {
          throw new Exception('Failed to write SVG file');
        }

        // Update attachment metadata
        $attachment_data = [
          'ID' => $attachment_id,
          'post_modified' => current_time('mysql'),
          'post_modified_gmt' => current_time('mysql', true)
        ];
        wp_update_post($attachment_data);

        wp_send_json_success([
          'message' => 'OpenGraph image regenerated successfully!'
        ]);
      } catch (Exception $e) {
        wp_send_json_error([
          'message' => 'Failed to regenerate image: ' . $e->getMessage()
        ]);
      }
    }

    public function cleanupPostSVG(int $post_id): void
    {
      // Find and delete associated OG SVG files when a post is deleted
      $attachments = get_posts([
        'post_type' => 'attachment',
        'meta_query' => [
          [
            'key' => '_og_svg_post_id',
            'value' => (string) $post_id,
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

      // Also delete the files from filesystem
      $file_path = $this->generator->getSVGFilePath($post_id);
      if (file_exists($file_path)) {
        unlink($file_path);
      }

      $png_path = $this->generator->getPNGFilePath($post_id);
      if (file_exists($png_path)) {
        unlink($png_path);
      }
    }

    /**
     * Utility Methods
     *
     * @return array{total_images: int, by_post_type: array<string, int>}
     */
    public function getOGImageStats(): array
    {
      $stats = [
        'total_images' => 0,
        'by_post_type' => []
      ];

      // Count total OG images
      $total_images = get_posts([
        'post_type' => 'attachment',
        'meta_query' => [
          [
            'key' => '_og_svg_generated',
            'value' => '1',
            'compare' => '='
          ]
        ],
        'posts_per_page' => -1,
        'fields' => 'ids'
      ]);

      $stats['total_images'] = count($total_images);

      // Count by post type
      foreach ($total_images as $attachment_id) {
        $og_post_id = get_post_meta($attachment_id, '_og_svg_post_id', true);
        if ($og_post_id === 'home') {
          $stats['by_post_type']['home'] = ($stats['by_post_type']['home'] ?? 0) + 1;
        } else {
          $post_type = get_post_type((int) $og_post_id);
          if ($post_type !== false) {
            $stats['by_post_type'][$post_type] = ($stats['by_post_type'][$post_type] ?? 0) + 1;
          }
        }
      }

      return $stats;
    }

    /**
     * @return array<string>
     */
    public function validateSettings(): array
    {
      $errors = [];

      // Check if upload directory is writable
      $upload_dir = wp_upload_dir();

      if (!is_writable($upload_dir['basedir'])) {
        $errors[] = 'Upload directory is not writable';
      }

      // Check if rewrite rules are working
      $test_url = get_site_url() . '/og-svg/home/';
      $response = wp_safe_remote_head($test_url);

      if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $errors[] = 'URL rewriting may not be working properly. Try flushing permalinks.';
      }

      return $errors;
    }
  }
} // End class_exists check