<?php
/**
 * Plugin Name: MenuMaster
 * Plugin URI: https://github.com/marvinfpham/menumaster
 * Description: Adds the ability to hide menu items in WordPress menus. Hidden menu items will not be displayed on the frontend but remain visible in the backend.
 * Version: 1.0.1
 * Author: CorePress
 * Author URI: https://corepress.com/
 * License: GPL2
 * Text Domain: menumaster
 * GitHub Plugin URI: marvinfpham/menumaster
 * Primary Branch: main
 * Requires at least: 5.2
 * Requires PHP: 7.2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class MenuMaster {
    
    private $plugin_slug = 'menumaster';
    private $github_username = 'marvinfpham';
    private $github_repo = 'menumaster';
    
    /**
     * Constructor - set up main plugin actions and filters
     */
    public function __construct() {
        // Add custom field to menu items
        add_filter('wp_nav_menu_item_custom_fields', array($this, 'add_custom_fields'), 10, 4);
        
        // Save custom field value
        add_action('wp_update_nav_menu_item', array($this, 'save_custom_fields'), 10, 3);
        
        // Filter menu items
        add_filter('wp_get_nav_menu_items', array($this, 'filter_hidden_nav_menu_items'), 10, 3);
        
        // Add admin styling
        add_action('admin_head', array($this, 'add_admin_styles'));
        
        // Add visual indicator for hidden menu items
        add_filter('wp_nav_menu_item_title', array($this, 'add_hidden_indicator'), 10, 4);
        
        // Set up the GitHub updater
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'after_update'), 10, 2);
    }
    
    /**
     * Add custom field to menu items in the admin menu editor
     */
    public function add_custom_fields($item_id, $item, $depth, $args) {
        $hide_item = get_post_meta($item_id, '_menu_item_hide', true);
        ?>
        <p class="field-hide-menu-item description description-wide">
            <label for="edit-menu-item-hide-<?php echo esc_attr($item_id); ?>">
                <input type="checkbox" id="edit-menu-item-hide-<?php echo esc_attr($item_id); ?>" 
                       name="menu-item-hide[<?php echo esc_attr($item_id); ?>]" 
                       value="1" <?php checked($hide_item, 1); ?> />
                <?php _e('Hide menu item', 'menumaster'); ?>
            </label>
        </p>
        <?php
    }
    
    /**
     * Save custom field value
     */
    public function save_custom_fields($menu_id, $menu_item_db_id, $args) {
        if (isset($_POST['menu-item-hide'][$menu_item_db_id])) {
            update_post_meta($menu_item_db_id, '_menu_item_hide', 1);
        } else {
            delete_post_meta($menu_item_db_id, '_menu_item_hide');
        }
    }
    
    /**
     * Filter out hidden menu items on the frontend
     */
    public function filter_hidden_nav_menu_items($items, $menu, $args) {
        if (is_admin()) {
            // Add visual indicator for hidden items in admin
            foreach ($items as $item) {
                $hide_item = get_post_meta($item->ID, '_menu_item_hide', true);
                if ($hide_item) {
                    $item->hidden_indicator = true;
                }
            }
        } else {
            // Remove hidden items from frontend
            foreach ($items as $key => $item) {
                $hide_item = get_post_meta($item->ID, '_menu_item_hide', true);
                if ($hide_item) {
                    unset($items[$key]);
                }
            }
            // Re-index the array
            $items = array_values($items);
        }
        return $items;
    }
    
    /**
     * Add CSS to visually indicate hidden items in admin
     */
    public function add_admin_styles() {
        ?>
        <style>
            .menu-item-bar .menu-item-handle .item-title .hidden-menu-indicator {
                color: red;
                font-weight: bold;
                margin-left: 5px;
            }
        </style>
        <?php
    }
    
    /**
     * Add [Hidden] indicator to menu items in admin
     */
    public function add_hidden_indicator($title, $item, $args, $depth) {
        if (is_admin() && isset($item->ID)) {
            $hide_item = get_post_meta($item->ID, '_menu_item_hide', true);
            if ($hide_item) {
                $title .= ' <span class="hidden-menu-indicator">[Hidden]</span>';
            }
        }
        return $title;
    }
    
    /**
     * Check for plugin updates from GitHub
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get current plugin data
        $plugin_file = plugin_basename(__FILE__);
        if (!isset($transient->checked[$plugin_file])) {
            return $transient;
        }
        
        $current_version = $transient->checked[$plugin_file];
        
        // Get data from GitHub
        $github_data = $this->get_github_data();
        if (!$github_data) {
            return $transient;
        }
        
        // Check if a new version is available
        if (isset($github_data->tag_name) && version_compare($this->get_clean_version($github_data->tag_name), $current_version, '>')) {
            $transient->response[$plugin_file] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $plugin_file,
                'new_version' => $this->get_clean_version($github_data->tag_name),
                'url'         => $github_data->html_url,
                'package'     => $github_data->zipball_url,
            );
        }
        
        return $transient;
    }
    
    /**
     * Provide plugin information for the update details screen
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        
        $github_data = $this->get_github_data();
        if (!$github_data) {
            return $result;
        }
        
        $plugin_data = get_plugin_data(__FILE__);
        
        $result = (object) array(
            'name'              => $plugin_data['Name'],
            'slug'              => $this->plugin_slug,
            'version'           => $this->get_clean_version($github_data->tag_name),
            'author'            => $plugin_data['Author'],
            'author_profile'    => $plugin_data['AuthorURI'],
            'requires'          => $plugin_data['RequiresWP'] ?? '5.2',
            'tested'            => get_bloginfo('version'),
            'requires_php'      => $plugin_data['RequiresPHP'] ?? '7.2',
            'last_updated'      => isset($github_data->published_at) ? date('Y-m-d', strtotime($github_data->published_at)) : '',
            'homepage'          => $plugin_data['PluginURI'],
            'short_description' => $plugin_data['Description'],
            'sections'          => array(
                'description'   => $plugin_data['Description'],
                'changelog'     => isset($github_data->body) ? $this->format_github_markdown($github_data->body) : '',
            ),
            'download_link'     => $github_data->zipball_url,
        );
        
        return $result;
    }
    
    /**
     * Fetch data from GitHub API
     */
    private function get_github_data() {
        // Check the cache first
        $cache_key = 'menumaster_github_data';
        $github_data = get_transient($cache_key);
        
        if ($github_data === false) {
            // Fetch from GitHub API
            $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";
            
            $args = array(
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                )
            );
            
            // Add authentication if GitHub token is defined
            if (defined('GITHUB_ACCESS_TOKEN')) {
                $args['headers']['Authorization'] = 'token ' . GITHUB_ACCESS_TOKEN;
            }
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return false;
            }
            
            $github_data = json_decode(wp_remote_retrieve_body($response));
            
            // Cache the response for 6 hours
            set_transient($cache_key, $github_data, 6 * HOUR_IN_SECONDS);
        }
        
        return $github_data;
    }
    
    /**
     * Clean version number from GitHub tag
     */
    private function get_clean_version($version) {
        return ltrim($version, 'v');
    }
    
    /**
     * Format GitHub markdown to HTML
     */
    private function format_github_markdown($markdown) {
        // Simple formatting - this could be enhanced with a Markdown parser
        $formatted = nl2br(esc_html($markdown));
        return $formatted;
    }
    
    /**
     * Clear the cache after update
     */
    public function after_update($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient('menumaster_github_data');
        }
    }
}

// Initialize the plugin
new MenuMaster();
