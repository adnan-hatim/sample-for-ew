<?php

if (!defined('ABSPATH')) exit;

class Guesty_Property_Sync {

    const CPT_SLUG = 'property';
    const CRON_HOOK = 'guesty_sync_properties';
    const GUESTY_API = 'https://api.guesty.com/api/v2/';

    private $client_id;
    private $client_secret;

    public function __construct() {
        $this->client_id = defined('GUESTY_CLIENT_ID') ? GUESTY_CLIENT_ID : '';
        $this->client_secret = defined('GUESTY_CLIENT_SECRET') ? GUESTY_CLIENT_SECRET : '';

        // Init hooks
        add_action('init', [$this, 'register_property_cpt']);
        add_action('admin_init', [$this, 'setup_schedule']);
        add_action(self::CRON_HOOK, [$this, 'sync_properties']);
        add_shortcode('property_listings', [$this, 'render_listings_shortcode']);

        // Admin columns
        add_filter('manage_' . self::CPT_SLUG . '_posts_columns', [$this, 'add_custom_columns']);
        add_action('manage_' . self::CPT_SLUG . '_posts_custom_column', [$this, 'render_custom_columns'], 10, 2);
    }

    // Register Custom Post Type
    public function register_property_cpt() {
        $args = [
            'label' => 'Properties',
            'public' => true,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'capability_type' => 'post',
            'has_archive' => true,
            'rewrite' => ['slug' => 'properties'],
            'menu_icon' => 'dashicons-building'
        ];
        register_post_type(self::CPT_SLUG, $args);
    }

    // Schedule to sync properties twice a day 
    public function setup_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
    }

    // Main sync function
    public function sync_properties() {
        $token = $this->get_access_token();
        $properties = $this->fetch_properties($token);
        
        foreach ($properties as $property) {
            $this->create_update_property($property);
        }

        $this->cleanup_old_properties($properties);
    }

    private function fetch_properties($token) {
        $response = wp_remote_get(self::GUESTY_API . 'listings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (!is_wp_error($response) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return $this->sanitize_property_data($body['items'] ?? []);
        }

        return [];
    }

    // Create/update CPT entries
    private function create_update_property($property) {
        $existing = get_posts([
            'post_type' => self::CPT_SLUG,
            'meta_key' => 'guesty_id',
            'meta_value' => $property['id'],
            'posts_per_page' => 1
        ]);

        $post_data = [
            'post_title' => $property['title'],
            'post_content' => $property['description'],
            'post_type' => self::CPT_SLUG,
            'post_status' => 'publish',
            'meta_input' => [
                'guesty_id' => $property['id'],
                'external_url' => $property['external_url'],
                'bedrooms' => $property['bedrooms'],
                'bathrooms' => $property['bathrooms']
            ]
        ];

        if (!empty($existing)) {
            $post_data['ID'] = $existing[0]->ID;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
            $this->set_featured_image($post_id, $property['photos']);
        }

        return isset($post_id) ? $post_id : $existing[0]->ID;
    }

    // Handle featured images
    private function set_featured_image($post_id, $photos) {
        if (empty($photos)) return;

        $image_url = $photos[0]['xlarge'] ?? $photos[0]['large'] ?? '';
        if (!empty($image_url)) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $attachment_id = media_sideload_image($image_url, $post_id, 'Property image', 'id');
            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
    }

    // Cleanup deleted properties
    private function cleanup_old_properties($current_properties) {
        $all_local = get_posts([
            'post_type' => self::CPT_SLUG,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        $current_ids = array_column($current_properties, 'id');
        foreach ($all_local as $post_id) {
            $guesty_id = get_post_meta($post_id, 'guesty_id', true);
            if (!in_array($guesty_id, $current_ids)) {
                wp_trash_post($post_id);
            }
        }
    }

    // Shortcode to display properties
    public function render_listings_shortcode() {
        $properties = get_posts([
            'post_type' => self::CPT_SLUG,
            'posts_per_page' => -1
        ]);

        ob_start();
        ?>
        <div class="property-grid">
            <?php foreach ($properties as $property) : 
                $external_url = get_post_meta($property->ID, 'external_url', true);
                $thumbnail = get_the_post_thumbnail_url($property->ID, 'large');
            ?>
                <div class="property-card">
                    <img src="<?= esc_url($thumbnail) ?>" alt="<?= esc_attr($property->post_title) ?>">
                    <h3><?= esc_html($property->post_title) ?></h3>
                    <a href="<?= esc_url($external_url) ?>" target="_blank" rel="noopener" class="book-now">
                        Book Now
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // Admin interface customization
    public function add_custom_columns($columns) {
        return array_merge($columns, [
            'guesty_id' => 'Guesty ID',
            'external_url' => 'Booking URL'
        ]);
    }

    public function render_custom_columns($column, $post_id) {
        switch ($column) {
            case 'guesty_id':
                echo get_post_meta($post_id, 'guesty_id', true);
                break;
            case 'external_url':
                $url = get_post_meta($post_id, 'external_url', true);
                echo $url ? '<a href="' . esc_url($url) . '" target="_blank">View</a>' : '';
                break;
        }
    }

    private function get_access_token() {
        $token = get_transient('guesty_access_token');
        
        if (!$token) {
            $response = wp_remote_post(self::GUESTY_API . 'oauth2/token', [
                'body' => [
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'grant_type' => 'client_credentials'
                ]
            ]);

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $token = $body['access_token'];
                set_transient('guesty_access_token', $token, 82800);
            }
        }

        return $token;
    }

    private function sanitize_property_data($properties) {
        return array_map(function($prop) {
            return [
                'id' => sanitize_text_field($prop['id']),
                'title' => sanitize_text_field($prop['title']),
                'description' => wp_kses_post($prop['description']),
                'external_url' => esc_url_raw($prop['externalListingUrl']),
                'bedrooms' => absint($prop['bedrooms']),
                'bathrooms' => absint($prop['bathrooms']),
                'photos' => array_slice(array_map('esc_url_raw', $prop['photos']), 0, 5)
            ];
        }, $properties);
    }
}

new Guesty_Property_Sync();
