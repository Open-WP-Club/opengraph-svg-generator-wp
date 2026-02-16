<?php

/**
 * Admin Settings Class
 * Handles WordPress admin interface for plugin configuration
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

if (!class_exists('OG_SVG_Admin_Settings')) {

  class OG_SVG_Admin_Settings
  {
    /** @var array<string, mixed> */
    private readonly array $settings;

    private bool $settings_updated = false;

    public function __construct()
    {
      add_action('admin_menu', [$this, 'addAdminMenu']);
      add_action('admin_init', [$this, 'initSettings']);
      add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
      add_action('wp_ajax_og_svg_generate_preview', [$this, 'ajaxGeneratePreview']);
      add_action('wp_ajax_og_svg_cleanup_images', [$this, 'ajaxCleanupImages']);
      add_action('wp_ajax_og_svg_flush_rewrite', [$this, 'ajaxFlushRewrite']);
      add_action('wp_ajax_og_svg_test_url', [$this, 'ajaxTestUrl']);
      add_action('wp_ajax_og_svg_bulk_generate', [$this, 'ajaxBulkGenerate']);

      // Suppress default WordPress settings messages
      add_action('admin_notices', [$this, 'suppressDefaultNotices'], 1);

      // Per-post theme meta box
      add_action('add_meta_boxes', [$this, 'addThemeMetaBox']);
      add_action('save_post', [$this, 'saveThemeMetaBox']);

      $this->settings = get_option('og_svg_settings', []);
    }

    /**
     * Verify AJAX request nonce and capability.
     * Terminates with wp_die() on failure.
     */
    private function verifyAjaxRequest(string $action = 'og_svg_admin_nonce'): void
    {
      $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
      if (empty($nonce) || !wp_verify_nonce($nonce, $action)) {
        wp_die('Security check failed');
      }

      if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
      }
    }

    public function suppressDefaultNotices(): void
    {
      // Remove the default "Settings saved." message on our settings page
      $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
      $settings_updated = isset($_GET['settings-updated']);

      if ($page === 'og-svg-settings' && $settings_updated) {
        // Mark that settings were updated so we can show our custom message
        $this->settings_updated = true;

        // Remove the settings-updated parameter to prevent WordPress from showing its message
        add_action('admin_head', function (): void {
          echo '<script type="text/javascript">
            if (window.history.replaceState) {
              window.history.replaceState(null, null, window.location.href.split("&settings-updated=true")[0]);
            }
          </script>';
        });
      }
    }

    public function addAdminMenu(): void
    {
      add_options_page(
        'OpenGraph SVG Settings',
        'OpenGraph SVG',
        'manage_options',
        'og-svg-settings',
        [$this, 'settingsPage']
      );
    }

    public function enqueueAdminScripts(string $hook): void
    {
      // Load minimal CSS for meta box on post editor pages
      if ($hook === 'post.php' || $hook === 'post-new.php') {
        wp_enqueue_style(
          'og-svg-admin-css',
          OG_SVG_PLUGIN_URL . 'assets/css/admin.css',
          [],
          OG_SVG_VERSION
        );
        return;
      }

      if ($hook !== 'settings_page_og-svg-settings') {
        return;
      }

      wp_enqueue_media();

      wp_enqueue_style(
        'og-svg-admin-css',
        OG_SVG_PLUGIN_URL . 'assets/css/admin.css',
        [],
        OG_SVG_VERSION
      );

      wp_enqueue_script(
        'og-svg-admin',
        OG_SVG_PLUGIN_URL . 'assets/js/admin.js',
        ['jquery'],
        OG_SVG_VERSION,
        true
      );

      wp_localize_script('og-svg-admin', 'og_svg_admin', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('og_svg_admin_nonce'),
        'settings_updated' => $this->settings_updated,
        'messages' => [
          'generating' => __('Generating preview...', 'og-svg-generator'),
          'cleaning' => __('Removing images...', 'og-svg-generator'),
          'success' => __('Operation completed successfully!', 'og-svg-generator'),
          'error' => __('An error occurred. Please try again.', 'og-svg-generator')
        ]
      ]);
    }

    public function initSettings(): void
    {
      register_setting(
        'og_svg_settings_group',
        'og_svg_settings',
        ['sanitize_callback' => [$this, 'sanitizeSettings']]
      );

      add_settings_section(
        'og_svg_general_section',
        '',
        [$this, 'generalSectionCallback'],
        'og-svg-settings'
      );

      add_settings_field(
        'avatar_url',
        'Avatar Image',
        [$this, 'avatarUrlFieldCallback'],
        'og-svg-settings',
        'og_svg_general_section'
      );

      add_settings_field(
        'color_scheme',
        'Theme',
        [$this, 'colorSchemeFieldCallback'],
        'og-svg-settings',
        'og_svg_general_section'
      );

      add_settings_field(
        'custom_colors',
        'Custom Colors',
        [$this, 'customColorsFieldCallback'],
        'og-svg-settings',
        'og_svg_general_section'
      );

      add_settings_field(
        'show_tagline',
        'Display Options',
        [$this, 'displayOptionsFieldCallback'],
        'og-svg-settings',
        'og_svg_general_section'
      );

      add_settings_field(
        'fallback_title',
        'Fallback Title',
        [$this, 'fallbackTitleFieldCallback'],
        'og-svg-settings',
        'og_svg_general_section'
      );

      add_settings_field(
        'enabled_post_types',
        'Enabled Post Types',
        [$this, 'enabledPostTypesFieldCallback'],
        'og-svg-settings',
        'og_svg_general_section'
      );

      add_settings_field(
        'footer_text',
        'Footer Text',
        [$this, 'footerTextFieldCallback'],
        'og-svg-settings',
        'og_svg_general_section'
      );
    }

    public function generalSectionCallback(): void
    {
      // Simplified - no intro text needed
    }

    public function avatarUrlFieldCallback(): void
    {
      $value = $this->settings['avatar_url'] ?? '';

      echo '<div class="og-svg-field">';
      echo '<div class="og-svg-input-group">';
      echo '<input type="url" id="avatar_url" name="og_svg_settings[avatar_url]" value="' . esc_attr($value) . '" class="og-svg-input" placeholder="https://example.com/avatar.jpg" />';
      echo '<button type="button" class="og-svg-button-secondary" id="upload_avatar_button">';
      echo '<span class="dashicons dashicons-upload"></span> Upload';
      echo '</button>';
      echo '</div>';
      echo '<p class="og-svg-description">Your profile image for OpenGraph cards (200×200px recommended)</p>';

      if ($value) {
        echo '<div class="og-svg-avatar-preview">';
        echo '<img src="' . esc_url($value) . '" alt="Avatar Preview" />';
        echo '<button type="button" class="og-svg-remove-avatar" data-target="avatar_url">×</button>';
        echo '</div>';
      }
      echo '</div>';
    }

    public function colorSchemeFieldCallback(): void
    {
      $value = $this->settings['color_scheme'] ?? 'gabriel';

      // Get available themes from generator
      $generator = new OG_SVG_Generator();
      $themes = $generator->getAvailableThemes();

      echo '<div class="og-svg-themes">';
      foreach ($themes as $theme_id => $theme_info) {
        $checked = checked($value, $theme_id, false);
        echo '<label class="og-svg-theme-option">';
        echo '<input type="radio" name="og_svg_settings[color_scheme]" value="' . esc_attr($theme_id) . '" ' . $checked . ' />';
        echo '<div class="og-svg-theme-preview">';
        if (isset($theme_info['preview_colors'])) {
          foreach ($theme_info['preview_colors'] as $color) {
            echo '<div class="og-svg-color-dot" style="background-color: ' . esc_attr($color) . '"></div>';
          }
        }
        echo '</div>';
        echo '<div class="og-svg-theme-info">';
        echo '<strong>' . esc_html($theme_info['name']) . '</strong>';
        echo '<span>' . esc_html($theme_info['description']) . '</span>';
        echo '</div>';
        echo '</label>';
      }
      echo '</div>';
    }

    public function customColorsFieldCallback(): void
    {
      $custom_colors = $this->settings['custom_colors'] ?? [];

      $fields = [
        'accent' => ['label' => 'Accent', 'description' => 'Buttons, links, highlights'],
        'gradient_start' => ['label' => 'Gradient Start', 'description' => 'Background gradient start'],
        'gradient_end' => ['label' => 'Gradient End', 'description' => 'Background gradient end'],
        'text_primary' => ['label' => 'Text', 'description' => 'Primary text color'],
      ];

      echo '<div class="og-svg-field">';
      echo '<div class="og-svg-color-pickers">';

      foreach ($fields as $key => $field) {
        $value = $custom_colors[$key] ?? '';
        $input_name = 'og_svg_settings[custom_colors][' . $key . ']';
        $input_id = 'custom_color_' . $key;

        echo '<div class="og-svg-color-picker-field">';
        echo '<label for="' . esc_attr($input_id) . '">' . esc_html($field['label']) . '</label>';
        echo '<div class="og-svg-color-picker-row">';
        echo '<input type="color" id="' . esc_attr($input_id) . '_picker" value="' . esc_attr($value ?: '#000000') . '" class="og-svg-color-input-picker" data-target="' . esc_attr($input_id) . '"' . ($value ? '' : ' disabled') . ' />';
        echo '<input type="text" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . esc_attr($value) . '" class="og-svg-color-input-text" placeholder="Theme default" pattern="^#[0-9a-fA-F]{6}$" maxlength="7" />';
        echo '<button type="button" class="og-svg-color-clear" data-target="' . esc_attr($input_id) . '" title="Reset to theme default">&times;</button>';
        echo '</div>';
        echo '<span class="og-svg-color-description">' . esc_html($field['description']) . '</span>';
        echo '</div>';
      }

      echo '</div>';
      echo '<p class="og-svg-description">Override specific theme colors. Leave empty to use theme defaults.</p>';
      echo '</div>';
    }

    public function displayOptionsFieldCallback(): void
    {
      $show_tagline = $this->settings['show_tagline'] ?? true;

      echo '<div class="og-svg-field">';
      echo '<label class="og-svg-checkbox">';
      echo '<input type="checkbox" name="og_svg_settings[show_tagline]" value="1" ' . checked(1, $show_tagline, false) . ' />';
      echo '<span class="og-svg-checkbox-mark"></span>';
      echo '<span>Show site tagline in OpenGraph images</span>';
      echo '</label>';
      echo '</div>';
    }

    public function fallbackTitleFieldCallback(): void
    {
      $value = $this->settings['fallback_title'] ?? 'Welcome';
      echo '<div class="og-svg-field">';
      echo '<input type="text" id="fallback_title" name="og_svg_settings[fallback_title]" value="' . esc_attr($value) . '" class="og-svg-input" placeholder="Welcome" />';
      echo '<p class="og-svg-description">Default title for pages without specific titles (like homepage)</p>';
      echo '</div>';
    }

    public function enabledPostTypesFieldCallback(): void
    {
      $value = $this->settings['enabled_post_types'] ?? ['post', 'page'];
      $post_types = get_post_types(['public' => true], 'objects');

      echo '<div class="og-svg-field">';
      echo '<div class="og-svg-checkbox-group">';
      foreach ($post_types as $post_type) {
        $checked = in_array($post_type->name, $value) ? 'checked="checked"' : '';
        echo '<label class="og-svg-checkbox">';
        echo '<input type="checkbox" name="og_svg_settings[enabled_post_types][]" value="' . esc_attr($post_type->name) . '" ' . $checked . ' />';
        echo '<span class="og-svg-checkbox-mark"></span>';
        echo '<span>' . esc_html($post_type->label) . '</span>';
        echo '</label>';
      }
      echo '</div>';
      echo '<p class="og-svg-description">Select post types for automatic OpenGraph image generation</p>';
      echo '</div>';
    }

    public function footerTextFieldCallback(): void
    {
      $value = $this->settings['footer_text'] ?? '';
      echo '<div class="og-svg-field">';
      echo '<input type="text" id="footer_text" name="og_svg_settings[footer_text]" value="' . esc_attr($value) . '" class="og-svg-input og-svg-input-wide" placeholder="e.g. Developer • Designer • Creator" />';
      echo '<p class="og-svg-description">Custom text shown in footer area of some themes (like Gabriel). Leave empty to hide.</p>';
      echo '</div>';
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>
     */
    public function sanitizeSettings(?array $input): array
    {
      $sanitized = [];

      if (!is_array($input)) {
        return $sanitized;
      }

      if (isset($input['avatar_url'])) {
        $sanitized['avatar_url'] = esc_url_raw($input['avatar_url']);
      }

      // Validate theme selection dynamically
      if (isset($input['color_scheme'])) {
        try {
          $generator = new OG_SVG_Generator();
          $available_themes = $generator->getAvailableThemes();

          if (array_key_exists($input['color_scheme'], $available_themes)) {
            $sanitized['color_scheme'] = sanitize_text_field($input['color_scheme']);
          } else {
            $sanitized['color_scheme'] = 'gabriel'; // fallback
            add_settings_error('og_svg_settings', 'invalid_theme', 'Selected theme is not available. Defaulted to Gabriel theme.');
          }
        } catch (Exception $e) {
          $sanitized['color_scheme'] = 'gabriel';
          add_settings_error('og_svg_settings', 'theme_error', 'Error loading themes. Defaulted to Gabriel theme.');
        }
      } else {
        $sanitized['color_scheme'] = 'gabriel';
      }

      // Custom colors
      if (isset($input['custom_colors']) && is_array($input['custom_colors'])) {
        $sanitized['custom_colors'] = [];
        $allowed_keys = ['accent', 'gradient_start', 'gradient_end', 'text_primary'];
        foreach ($allowed_keys as $key) {
          $color = $input['custom_colors'][$key] ?? '';
          if (!empty($color)) {
            $sanitized_color = sanitize_hex_color($color);
            if ($sanitized_color) {
              $sanitized['custom_colors'][$key] = $sanitized_color;
            }
          }
        }
      } else {
        $sanitized['custom_colors'] = [];
      }

      $sanitized['show_tagline'] = isset($input['show_tagline']);

      if (isset($input['fallback_title'])) {
        $sanitized['fallback_title'] = sanitize_text_field($input['fallback_title']);
      }

      if (isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
        $sanitized['enabled_post_types'] = array_map('sanitize_text_field', $input['enabled_post_types']);
      } else {
        $sanitized['enabled_post_types'] = [];
      }

      if (isset($input['footer_text'])) {
        $sanitized['footer_text'] = sanitize_text_field($input['footer_text']);
      }

      return $sanitized;
    }

    public function ajaxGeneratePreview(): void
    {
      $this->verifyAjaxRequest();

      try {
        // Parse form data to get current settings
        $preview_settings = [];

        $settings_data = isset($_POST['settings_data']) ? sanitize_text_field($_POST['settings_data']) : '';
        if (!empty($settings_data)) {
          parse_str($settings_data, $form_data);

          if (isset($form_data['og_svg_settings']) && is_array($form_data['og_svg_settings'])) {
            $form_settings = $form_data['og_svg_settings'];

            // Map form data to preview settings
            if (isset($form_settings['color_scheme'])) {
              $preview_settings['color_scheme'] = sanitize_text_field($form_settings['color_scheme']);
            }
            if (isset($form_settings['avatar_url'])) {
              $preview_settings['avatar_url'] = esc_url_raw($form_settings['avatar_url']);
            }
            $preview_settings['show_tagline'] = isset($form_settings['show_tagline']);
            if (isset($form_settings['fallback_title'])) {
              $preview_settings['fallback_title'] = sanitize_text_field($form_settings['fallback_title']);
            }
            if (isset($form_settings['footer_text'])) {
              $preview_settings['footer_text'] = sanitize_text_field($form_settings['footer_text']);
            }
            if (isset($form_settings['custom_colors']) && is_array($form_settings['custom_colors'])) {
              $preview_settings['custom_colors'] = [];
              foreach ($form_settings['custom_colors'] as $key => $color) {
                if (!empty($color)) {
                  $sanitized_color = sanitize_hex_color($color);
                  if ($sanitized_color) {
                    $preview_settings['custom_colors'][sanitize_text_field($key)] = $sanitized_color;
                  }
                }
              }
            }
          }
        }

        // Generate cache key based on settings
        $cache_key = 'og_svg_preview_' . md5(serialize($preview_settings));

        // Check for cached preview (15 minutes TTL)
        $cached_svg = get_transient($cache_key);
        if ($cached_svg !== false) {
          $data_url = 'data:image/svg+xml;base64,' . base64_encode($cached_svg);
          wp_send_json_success([
            'image_url' => $data_url,
            'message' => 'Preview loaded from cache',
            'theme' => $preview_settings['color_scheme'] ?? $this->settings['color_scheme'] ?? 'gabriel',
            'cached' => true
          ]);
          return;
        }

        // Generate preview SVG content
        $generator = new OG_SVG_Generator();
        $svg_content = $generator->generateSVGWithSettings(null, $preview_settings);

        // Cache the preview for 15 minutes
        set_transient($cache_key, $svg_content, 900);

        // Create a data URL for immediate display
        $data_url = 'data:image/svg+xml;base64,' . base64_encode($svg_content);

        wp_send_json_success([
          'image_url' => $data_url,
          'message' => 'Preview generated successfully!',
          'theme' => $preview_settings['color_scheme'] ?? $this->settings['color_scheme'] ?? 'gabriel',
          'cached' => false
        ]);
      } catch (Exception $e) {
        wp_send_json_error([
          'message' => 'Failed to generate preview: ' . $e->getMessage()
        ]);
      }
    }

    public function ajaxCleanupImages(): void
    {
      $this->verifyAjaxRequest();

      try {
        $upload_dir = wp_upload_dir();
        $svg_dir = $upload_dir['basedir'] . '/og-svg/';

        $count = 0;
        if (is_dir($svg_dir)) {
          $files = glob($svg_dir . '*.{svg,png}', GLOB_BRACE);
          if ($files !== false) {
            foreach ($files as $file) {
              if (unlink($file)) {
                $count++;
              }
            }
          }

          $remaining = glob($svg_dir . '*');
          if ($remaining !== false && count($remaining) === 0) {
            rmdir($svg_dir);
          }
        }

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

        wp_send_json_success([
          'message' => sprintf('Successfully removed %d SVG files and %d media library entries.', $count, count($attachments))
        ]);
      } catch (Exception $e) {
        wp_send_json_error([
          'message' => 'Failed to cleanup images: ' . $e->getMessage()
        ]);
      }
    }

    public function ajaxFlushRewrite(): void
    {
      $this->verifyAjaxRequest();

      try {
        flush_rewrite_rules(true);

        wp_send_json_success([
          'message' => 'Rewrite rules flushed successfully! Please test the URLs again.'
        ]);
      } catch (Exception $e) {
        wp_send_json_error([
          'message' => 'Failed to flush rewrite rules: ' . $e->getMessage()
        ]);
      }
    }

    public function ajaxTestUrl(): void
    {
      $this->verifyAjaxRequest();

      try {
        $test_url = get_site_url() . '/og-svg/home/';

        $response = wp_safe_remote_head($test_url, [
          'timeout' => 10,
          'sslverify' => false
        ]);

        if (is_wp_error($response)) {
          throw new Exception('URL test failed: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        if ($response_code === 200) {
          wp_send_json_success([
            'message' => 'URL is working correctly!',
            'details' => [
              'url' => $test_url,
              'response_code' => $response_code,
              'content_type' => $content_type
            ]
          ]);
        } else {
          wp_send_json_error([
            'message' => 'URL returned status code: ' . $response_code,
            'details' => [
              'url' => $test_url,
              'response_code' => $response_code
            ]
          ]);
        }
      } catch (Exception $e) {
        wp_send_json_error([
          'message' => 'URL test failed: ' . $e->getMessage()
        ]);
      }
    }

    public function ajaxBulkGenerate(): void
    {
      // Enable error reporting for debugging
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_reporting(E_ALL);
      }

      try {
        $this->verifyAjaxRequest();

        // Get settings
        $settings = get_option('og_svg_settings', []);
        $enabled_types = $settings['enabled_post_types'] ?? ['post', 'page'];
        $force_raw = isset($_POST['force']) ? sanitize_text_field($_POST['force']) : '0';
        $force_regenerate = $force_raw === '1';
        $batch_size = 3; // Smaller batch size to prevent timeouts
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;

        // Validate settings
        if (empty($enabled_types)) {
          wp_send_json_error(['message' => 'No post types enabled for generation']);
          return;
        }

        // Get posts to process
        $query_args = [
          'post_type' => $enabled_types,
          'post_status' => 'publish',
          'posts_per_page' => $batch_size,
          'offset' => $offset,
          'fields' => 'ids'
        ];

        $posts = get_posts($query_args);

        // If no more posts, we're done
        if (empty($posts)) {
          wp_send_json_success([
            'completed' => true,
            'message' => 'All images generated successfully!',
            'processed' => $offset
          ]);
          return;
        }

        // Initialize generator
        if (!class_exists('OG_SVG_Generator')) {
          wp_send_json_error(['message' => 'SVG Generator class not found']);
          return;
        }

        $generator = new OG_SVG_Generator();
        $generated = 0;
        $skipped = 0;
        $errors = [];

        // Process each post
        foreach ($posts as $post_id) {
          $post_id = (int) $post_id;
          try {
            // Check if file already exists
            $file_path = $generator->getSVGFilePath($post_id);

            if (!$force_regenerate && file_exists($file_path) && filesize($file_path) > 0) {
              $skipped++;
              continue;
            }

            // Generate SVG content
            $svg_content = $generator->generateSVG($post_id);

            if (empty($svg_content)) {
              $errors[] = "Post {$post_id}: Generated empty SVG";
              continue;
            }

            // Save to file system
            $bytes_written = file_put_contents($file_path, $svg_content);

            if ($bytes_written === false) {
              $errors[] = "Post {$post_id}: Failed to write file";
              continue;
            }

            // Try to save to media library (non-critical)
            try {
              $generator->saveSVGToMedia($svg_content, $post_id);
            } catch (Exception $media_error) {
              error_log('Media library save failed for post ' . $post_id . ': ' . $media_error->getMessage());
              // Continue anyway, file system save succeeded
            }

            $generated++;
          } catch (Exception $e) {
            $errors[] = "Post {$post_id}: " . $e->getMessage();
            error_log('OG SVG bulk generation error for post ' . $post_id . ': ' . $e->getMessage());
          }
        }

        // Get total count for progress calculation
        $total_query = [
          'post_type' => $enabled_types,
          'post_status' => 'publish',
          'posts_per_page' => -1,
          'fields' => 'ids'
        ];
        $all_posts = get_posts($total_query);
        $total_posts = count($all_posts);

        // Return progress update
        wp_send_json_success([
          'completed' => false,
          'processed' => $offset + count($posts),
          'total' => $total_posts,
          'generated' => $generated,
          'skipped' => $skipped,
          'errors' => $errors,
          'next_offset' => $offset + $batch_size,
          'message' => sprintf(
            'Processed %d/%d posts. Generated: %d, Skipped: %d',
            $offset + count($posts),
            $total_posts,
            $generated,
            $skipped
          )
        ]);
      } catch (Exception $e) {
        error_log('OG SVG bulk generation fatal error: ' . $e->getMessage());
        wp_send_json_error([
          'message' => 'Bulk generation failed: ' . $e->getMessage()
        ]);
      } catch (Error $e) {
        error_log('OG SVG bulk generation PHP error: ' . $e->getMessage());
        wp_send_json_error([
          'message' => 'PHP Error: ' . $e->getMessage()
        ]);
      }
    }

    public function addThemeMetaBox(): void
    {
      $enabled_types = $this->settings['enabled_post_types'] ?? ['post', 'page'];

      foreach ($enabled_types as $post_type) {
        add_meta_box(
          'og_svg_theme_override',
          'OpenGraph Theme',
          [$this, 'renderThemeMetaBox'],
          $post_type,
          'side',
          'low'
        );
      }
    }

    public function renderThemeMetaBox(WP_Post $post): void
    {
      wp_nonce_field('og_svg_theme_meta', 'og_svg_theme_nonce');

      $current_theme = get_post_meta($post->ID, '_og_svg_theme', true);
      $global_theme_id = $this->settings['color_scheme'] ?? 'gabriel';

      $generator = new OG_SVG_Generator();
      $themes = $generator->getAvailableThemes();

      $global_theme_name = $themes[$global_theme_id]['name'] ?? ucfirst($global_theme_id);

      echo '<select name="og_svg_theme" class="og-svg-metabox-select">';
      echo '<option value="">Default (' . esc_html($global_theme_name) . ')</option>';

      foreach ($themes as $theme_id => $theme_info) {
        $selected = selected($current_theme, $theme_id, false);
        echo '<option value="' . esc_attr($theme_id) . '" ' . $selected . '>' . esc_html($theme_info['name']) . '</option>';
      }

      echo '</select>';
      echo '<p class="og-svg-metabox-description">Override the global theme for this post\'s OpenGraph image.</p>';
    }

    public function saveThemeMetaBox(int $post_id): void
    {
      $nonce = isset($_POST['og_svg_theme_nonce']) ? sanitize_text_field($_POST['og_svg_theme_nonce']) : '';
      if (empty($nonce) || !wp_verify_nonce($nonce, 'og_svg_theme_meta')) {
        return;
      }

      if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
      }

      if (!current_user_can('edit_post', $post_id)) {
        return;
      }

      $theme = isset($_POST['og_svg_theme']) ? sanitize_text_field($_POST['og_svg_theme']) : '';

      if (empty($theme)) {
        delete_post_meta($post_id, '_og_svg_theme');
      } else {
        update_post_meta($post_id, '_og_svg_theme', $theme);
      }
    }

    public function settingsPage(): void
    {
      if (!current_user_can('manage_options')) {
        return;
      }

      $total_posts = wp_count_posts()->publish ?? 0;
      $enabled_types = $this->settings['enabled_post_types'] ?? array();
      $estimated_images = 0;
      foreach ($enabled_types as $type) {
        $count = wp_count_posts($type);
        $estimated_images += isset($count->publish) ? $count->publish : 0;
      }

?>
      <div class="og-svg-admin">
        <div class="og-svg-header">
          <h1><span class="dashicons dashicons-share"></span> OpenGraph Image Generator</h1>
          <p>Create beautiful, branded social media preview images automatically</p>
        </div>

        <?php
        // Show custom success message only
        if ($this->settings_updated):
        ?>
          <div class="og-svg-notice og-svg-notice-success">
            <p><strong>Settings saved successfully!</strong> Your OpenGraph images will now use the updated configuration.</p>
            <button type="button" class="og-svg-notice-dismiss">×</button>
          </div>
        <?php endif; ?>

        <?php settings_errors('og_svg_settings'); ?>

        <div class="og-svg-container">
          <div class="og-svg-main">
            <div class="og-svg-card">
              <form method="post" action="options.php" class="og-svg-form">
                <?php
                settings_fields('og_svg_settings_group');
                do_settings_sections('og-svg-settings');
                ?>

                <div class="og-svg-actions">
                  <?php submit_button('Save Settings', 'primary', 'submit', false, array('class' => 'og-svg-button og-svg-button-primary')); ?>
                  <button type="button" class="og-svg-button og-svg-button-secondary" id="generate_preview_button">
                    <span class="dashicons dashicons-visibility"></span> Preview
                  </button>
                </div>
              </form>
            </div>

            <div class="og-svg-card" id="preview_section" style="display: none;">
              <h2><span class="dashicons dashicons-format-image"></span> Preview</h2>
              <div id="preview_container"></div>
            </div>
          </div>

          <div class="og-svg-sidebar">
            <div class="og-svg-card">
              <h3><span class="dashicons dashicons-admin-tools"></span> Tools</h3>
              <div class="og-svg-tools">
                <button type="button" class="og-svg-button og-svg-button-primary og-svg-full-width" id="bulk_generate_button">
                  <span class="dashicons dashicons-images-alt2"></span> Generate All Images
                </button>

                <label class="og-svg-checkbox og-svg-bulk-option">
                  <input type="checkbox" id="force_regenerate" />
                  <span class="og-svg-checkbox-mark"></span>
                  <span>Regenerate existing images</span>
                </label>

                <div id="bulk_progress" class="og-svg-progress" style="display: none;">
                  <div class="og-svg-progress-bar">
                    <div class="og-svg-progress-fill"></div>
                  </div>
                  <div class="og-svg-progress-text">Preparing...</div>
                </div>

                <button type="button" class="og-svg-button og-svg-button-outline og-svg-full-width" id="cleanup_images_button">
                  <span class="dashicons dashicons-trash"></span> Remove All Images
                </button>

                <button type="button" class="og-svg-button og-svg-button-outline og-svg-full-width" id="flush_rewrite_button">
                  <span class="dashicons dashicons-update"></span> Fix URL Issues
                </button>

                <button type="button" class="og-svg-button og-svg-button-outline og-svg-full-width" id="test_url_button">
                  <span class="dashicons dashicons-admin-links"></span> Test URLs
                </button>
              </div>
            </div>

            <div class="og-svg-card">
              <h3><span class="dashicons dashicons-info"></span> Status</h3>
              <div class="og-svg-status">
                <div class="og-svg-stat">
                  <span class="og-svg-stat-number"><?php echo number_format($estimated_images); ?></span>
                  <span class="og-svg-stat-label">Posts</span>
                </div>
                <div class="og-svg-stat">
                  <span class="og-svg-stat-number"><?php echo count($enabled_types); ?></span>
                  <span class="og-svg-stat-label">Post Types</span>
                </div>
              </div>

              <div class="og-svg-status-checks">
                <div class="og-svg-status-item">
                  <span>Upload Directory</span>
                  <span class="og-svg-status-badge <?php echo is_writable(wp_upload_dir()['basedir']) ? 'og-svg-status-ok' : 'og-svg-status-error'; ?>">
                    <?php echo is_writable(wp_upload_dir()['basedir']) ? 'OK' : 'Error'; ?>
                  </span>
                </div>
                <div class="og-svg-status-item">
                  <span>Permalinks</span>
                  <span class="og-svg-status-badge <?php echo get_option('permalink_structure') ? 'og-svg-status-ok' : 'og-svg-status-warning'; ?>">
                    <?php echo get_option('permalink_structure') ? 'OK' : 'Plain'; ?>
                  </span>
                </div>
                <div class="og-svg-status-item">
                  <span>PNG Conversion</span>
                  <span class="og-svg-status-badge <?php echo extension_loaded('imagick') ? 'og-svg-status-ok' : 'og-svg-status-warning'; ?>">
                    <?php echo extension_loaded('imagick') ? 'Imagick' : 'Unavailable'; ?>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
<?php
    }
  }
} // End class_exists check