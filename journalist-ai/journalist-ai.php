<?php
/**
 * @link              https://arvow.com/
 * @since             1.1.0
 * @package           Arvow
 *
 * @wordpress-plugin
 * Plugin Name:     Arvow AI SEO Writer
 * Description:     Arvow - AI SEO writer for WordPress.
 * Version:         1.5.2
 * Author:          Arvow
 * Author URI:      https://arvow.com/
 * License:         GPL-2.0 or later
 * License URI:     http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('WPINC')) {
    die;
}

if (!class_exists('JournalistAI')) {
    class JournalistAI
    {
        const REST_VERSION = 1;
        const SECRET_OPTION = 'journalistai_secret';

        public function __construct()
        {
            add_action('rest_api_init', [$this, 'register_rest_route']);

            if (is_admin()) {
                add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
                add_action('admin_menu', [$this, 'add_plugin_page']);
                // New explicit handlers
                add_action('admin_post_journalistai_update_secret', [$this, 'handle_update_secret']);
                add_action('admin_post_journalistai_connect', [$this, 'handle_connect']);
                register_deactivation_hook(__FILE__, [$this, 'deactivate']);
            }
        }

        public function register_rest_route(): void
        {
            register_rest_route('journalistai/v' . self::REST_VERSION, '/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_webhook'],
                'permission_callback' => '__return_true',
            ]);
        }

        public function handle_webhook($request): WP_REST_Response
        {
            $event = $request->get_param('event');
            $received_secret_header = $request->get_header('x-secret');
            $received_secret_param = $request->get_param('secret');
            $stored_secret = get_option(self::SECRET_OPTION);

            if ($received_secret_header !== $stored_secret && $received_secret_param !== $stored_secret) {
                return new WP_REST_Response('Unauthorized', 401);
            }

            switch ($event) {
                case 'integration_created':
                    return new WP_REST_Response('Integration successful', 200);

                case 'get_data':
                    return new WP_REST_Response([
                        'api_version' => self::REST_VERSION,
                        'authors' => $this->get_available_authors(),
                        'categories' => $this->get_available_categories(),
                        'tags' => $this->get_available_tags()
                    ], 200);

                case 'create_post':
                    $title = $request->get_param('payload')['title'];
                    $content = $request->get_param('payload')['content'];
                    $thumbnail = $request->get_param('payload')['thumbnail'] ?? null;
                    $thumbnail_alt = $request->get_param('payload')['thumbnail_alt'] ?? '';
                    $status = $request->get_param('payload')['status'] ?? 'publish';
                    $metadescription = $request->get_param('payload')['metadescription'] ?? null;
                    $keyword = $request->get_param('payload')['keyword'] ?? null;
                    $elementor = $request->get_param('payload')['elementor'] ?? false;
                    $elementor_json = $request->get_param('payload')['elementor_json'] ?? null;
                    $post_type = $request->get_param('payload')['post_type'] ?? 'post';
                    $use_keyword_slug = $request->get_param('payload')['use_keyword_slug'] ?? false;
                    $author = $request->get_param('payload')['author'] ?? null;
                    $categories = $request->get_param('payload')['categories'] ?? [];
                    $tags = $request->get_param('payload')['tags'] ?? [];

                    if (empty($title) || empty($content)) {
                        return new WP_REST_Response('Invalid payload: title and content are required', 400);
                    }

                    // Validate status
                    $valid_statuses = ['publish', 'draft', 'pending', 'private'];
                    if (!in_array($status, $valid_statuses)) {
                        $status = 'publish'; // Default to publish if invalid status
                    }

                    $post_data = array(
                        'post_title' => sanitize_text_field($title),
                        'post_content' => wp_kses_post($content),
                        'post_status' => $status,
                        'post_type' => $post_type
                    );

                    // Set author if provided
                    if (!empty($author)) {
                        $post_data['post_author'] = (int)$author;
                    }

                    // Set categories if provided
                    if (!empty($categories) && is_array($categories)) {
                        $post_data['post_category'] = array_map('intval', $categories);
                    }

                    // Set tags if provided
                    if (!empty($tags) && is_array($tags)) {
                        $post_data['tags_input'] = array_map('intval', $tags);
                    }

                    // Generate slug from keyword if use_keyword_slug is enabled
                    if ($use_keyword_slug && !empty($keyword)) {
                        $post_data['post_name'] = sanitize_title($keyword);
                    }

                    $post_id = wp_insert_post($post_data);

                    if (is_wp_error($post_id)) {
                        return new WP_REST_Response('Failed to create post: ' . $post_id->get_error_message(), 500);
                    }

                    // Handle thumbnail if provided
                    if (!empty($thumbnail)) {
                        require_once(ABSPATH . 'wp-admin/includes/media.php');
                        require_once(ABSPATH . 'wp-admin/includes/file.php');
                        require_once(ABSPATH . 'wp-admin/includes/image.php');

                        // Download and attach the image
                        $tmp = download_url($thumbnail);
                        if (is_wp_error($tmp)) {
                            // Use proper WordPress logging
                            if (WP_DEBUG) {
                                error_log('Arvow: Failed to download image: ' . esc_html($tmp->get_error_message()));
                            }
                        } else {
                            $file_array = array(
                                'name' => basename($thumbnail),
                                'tmp_name' => $tmp
                            );

                            $attachment_id = media_handle_sideload($file_array, $post_id);
                            if (is_wp_error($attachment_id)) {
                                if (WP_DEBUG) {
                                    error_log('Arvow: Failed to process image: ' . esc_html($attachment_id->get_error_message()));
                                }
                            } else {
                                set_post_thumbnail($post_id, $attachment_id);
                                
                                // Set alt text if provided
                                if (!empty($thumbnail_alt)) {
                                    update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($thumbnail_alt));
                                }
                            }
                            
                            // Clean up temp file using WordPress function
                            wp_delete_file($tmp);
                        }
                    }

                    // Set Elementor data if enabled and available
                    if ($elementor && !empty($elementor_json)) {
                        $this->set_elementor_data($post_id, $elementor_json);
                    }

                    // Set SEO metadata for popular SEO plugins
                    $this->set_seo_metadata($post_id, $title, $metadescription, $keyword);

                    $post_url = get_permalink($post_id);
                    return new WP_REST_Response(array(
                        'message' => 'Post created successfully',
                        'post_id' => $post_id,
                        'url' => $post_url
                    ), 200);

                default:
                    return new WP_REST_Response('Event not recognized', 400);
            }
        }

        public function deactivate(): void
        {
            delete_option(self::SECRET_OPTION);
        }

        /**
         * Updates the stored secret from the settings form
         */
        public function handle_update_secret(): void
        {
            check_admin_referer('journalistai_update_secret_action', 'journalistai_update_secret_nonce');

            $provided_secret = isset($_POST['secret']) ? sanitize_text_field(wp_unslash($_POST['secret'])) : '';
            if (empty($provided_secret) || strlen($provided_secret) < 8) {
                add_settings_error(
                    'journalistai_secret',
                    'journalistai_secret_short',
                    'Secret must be at least 8 characters long.',
                    'error'
                );
                set_transient('settings_errors', get_settings_errors(), 30);
                wp_safe_redirect(admin_url('options-general.php?page=journalist-ai-setting-admin&settings-updated=false'));
                exit;
            }

            update_option(self::SECRET_OPTION, $provided_secret);
            add_settings_error('journalistai', 'settings_updated', 'Secret updated.', 'updated');
            set_transient('settings_errors', get_settings_errors(), 30);
            wp_safe_redirect(admin_url('options-general.php?page=journalist-ai-setting-admin&settings-updated=true'));
            exit;
        }

        /**
         * Connects the site to Arvow using the current stored secret
         */
        public function handle_connect(): void
        {
            check_admin_referer('journalistai_connect_action', 'journalistai_connect_nonce');
            $existing_secret = get_option(self::SECRET_OPTION);
            $secret = $existing_secret;
            if (empty($existing_secret)) {
                // generate if none existed
                $secret = wp_generate_password(32, false);
                update_option(self::SECRET_OPTION, $secret);
            }

            $webhook_url = esc_url_raw(rest_url('journalistai/v' . self::REST_VERSION . '/webhook'));
            $redirect_url = add_query_arg(
                array(
                    'webhook_url' => urlencode($webhook_url),
                    'secret' => urlencode($secret)
                ),
                'https://api.arvow.com/wp-plugin/authorize'
            );

            wp_redirect($redirect_url);
            exit;
        }

        public function add_settings_link($links): array
        {
            $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=journalist-ai-setting-admin')) . '">Settings</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

        public function add_plugin_page(): void
        {
            add_options_page(
                'Arvow Settings',
                'Arvow',
                'manage_options',
                'journalist-ai-setting-admin',
                [$this, 'create_admin_page']
            );
        }

        public function create_admin_page(): void
        {
            // Ensure there is a secret generated to pre-fill the form
            $existing = get_option(self::SECRET_OPTION);
            if (empty($existing)) {
                $generated = wp_generate_password(32, false);
                update_option(self::SECRET_OPTION, $generated);
            }
            $template_path = plugin_dir_path(__FILE__) . 'admin-settings.php';
            if (file_exists($template_path)) {
                include $template_path;
            }
        }

        /**
         * Set SEO metadata for popular SEO plugins
         */
        private function set_seo_metadata($post_id, $title, $metadescription, $keyword): void
        {
            if (empty($metadescription) && empty($keyword)) {
                return; // Nothing to set
            }

            // Ensure plugin functions are available
            if (!function_exists('is_plugin_active')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }

            // Check which SEO plugins are active and set appropriate meta fields
            
            // Yoast SEO
            if (is_plugin_active('wordpress-seo/wp-seo.php') || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php')) {
                if (!empty($title)) {
                    update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($title));
                }
                if (!empty($metadescription)) {
                    update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($metadescription));
                }
                if (!empty($keyword)) {
                    update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($keyword));
                }
            }

            // All in One SEO Pack
            if (is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') || is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                if (!empty($title)) {
                    update_post_meta($post_id, '_aioseo_title', sanitize_text_field($title));
                    update_post_meta($post_id, '_aioseo_og_title', sanitize_text_field($title));
                }
                if (!empty($metadescription)) {
                    update_post_meta($post_id, '_aioseo_description', sanitize_text_field($metadescription));
                    update_post_meta($post_id, '_aioseo_og_description', sanitize_text_field($metadescription));
                }
                if (!empty($keyword)) {
                    update_post_meta($post_id, '_aioseo_keywords', sanitize_text_field($keyword));
                }
            }

            // Rank Math SEO
            if (is_plugin_active('seo-by-rank-math/rank-math.php')) {
                if (!empty($title)) {
                    update_post_meta($post_id, 'rank_math_title', sanitize_text_field($title));
                }
                if (!empty($metadescription)) {
                    update_post_meta($post_id, 'rank_math_description', sanitize_text_field($metadescription));
                }
                if (!empty($keyword)) {
                    update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($keyword));
                }
            }

            // SEOPress
            if (is_plugin_active('wp-seopress/seopress.php')) {
                if (!empty($title)) {
                    update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($title));
                    update_post_meta($post_id, '_seopress_social_fb_title', sanitize_text_field($title));
                    update_post_meta($post_id, '_seopress_social_twitter_title', sanitize_text_field($title));
                }
                if (!empty($metadescription)) {
                    update_post_meta($post_id, '_seopress_titles_desc', sanitize_text_field($metadescription));
                    update_post_meta($post_id, '_seopress_social_fb_desc', sanitize_text_field($metadescription));
                    update_post_meta($post_id, '_seopress_social_twitter_desc', sanitize_text_field($metadescription));
                }
                if (!empty($keyword)) {
                    update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($keyword));
                }
            }

            // The SEO Framework
            if (is_plugin_active('autodescription/autodescription.php')) {
                if (!empty($title)) {
                    update_post_meta($post_id, '_genesis_title', sanitize_text_field($title));
                    update_post_meta($post_id, '_open_graph_title', sanitize_text_field($title));
                    update_post_meta($post_id, '_twitter_title', sanitize_text_field($title));
                }
                if (!empty($metadescription)) {
                    update_post_meta($post_id, '_genesis_description', sanitize_text_field($metadescription));
                    update_post_meta($post_id, '_open_graph_description', sanitize_text_field($metadescription));
                    update_post_meta($post_id, '_twitter_description', sanitize_text_field($metadescription));
                }
            }

            // Squirrly SEO
            if (is_plugin_active('squirrly-seo/squirrly.php')) {
                if (!empty($title)) {
                    update_post_meta($post_id, '_sq_title', sanitize_text_field($title));
                }
                if (!empty($metadescription)) {
                    update_post_meta($post_id, '_sq_description', sanitize_text_field($metadescription));
                }
                if (!empty($keyword)) {
                    update_post_meta($post_id, '_sq_keywords', sanitize_text_field($keyword));
                }
            }
        }

        /**
         * Set Elementor data for posts when Elementor is enabled
         */
        private function set_elementor_data($post_id, $elementor_json): void
        {
            // Ensure plugin functions are available
            if (!function_exists('is_plugin_active')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }

            // Only proceed if Elementor plugin is active
            if (is_plugin_active('elementor/elementor.php')) {
                // Set required Elementor meta fields
                update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                update_post_meta($post_id, '_elementor_template_type', 'wp-post');
                update_post_meta($post_id, '_elementor_data', $elementor_json);
                
                // Get and set Elementor version if available
                if (function_exists('get_plugin_data')) {
                    $elementor_plugin_file = WP_PLUGIN_DIR . '/elementor/elementor.php';
                    if (file_exists($elementor_plugin_file)) {
                        $elementor_data = get_plugin_data($elementor_plugin_file);
                        if (isset($elementor_data['Version'])) {
                            update_post_meta($post_id, '_elementor_version', sanitize_text_field($elementor_data['Version']));
                        }
                    }
                }
            }
        }

        /**
         * Get available authors for the site
         */
        private function get_available_authors(): array
        {
            if (!function_exists('get_users')) {
                return [];
            }

            $users = get_users([
                'role__in' => ['administrator', 'author', 'editor'],
                'fields' => ['ID', 'display_name']
            ]);

            return array_map(function($user) {
                return [
                    'id' => (int)$user->ID,
                    'name' => $user->display_name
                ];
            }, $users);
        }

        /**
         * Get available categories for the site
         */
        private function get_available_categories(): array
        {
            $categories = get_categories([
                'hide_empty' => false
            ]);

            return array_map(function($category) {
                return [
                    'id' => (int)$category->term_id,
                    'name' => $category->name
                ];
            }, $categories);
        }

        /**
         * Get available tags for the site
         */
        private function get_available_tags(): array
        {
            $tags = get_tags([
                'hide_empty' => false
            ]);

            return array_map(function($tag) {
                return [
                    'id' => (int)$tag->term_id,
                    'name' => $tag->name
                ];
            }, $tags);
        }
    }

    new JournalistAI();
}