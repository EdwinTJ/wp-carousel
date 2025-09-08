<?php
/**
 * Plugin Name: Advanced Carousel Manager
 * Plugin URI: https://yoursite.com
 * Description: Professional carousel plugin with multiple styles and advanced management
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: advanced-carousel
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ADVANCED_CAROUSEL_VERSION', '1.0.0');
define('ADVANCED_CAROUSEL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ADVANCED_CAROUSEL_PLUGIN_PATH', plugin_dir_path(__FILE__));

class AdvancedCarouselPlugin {
    
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_save_carousel', array($this, 'ajax_save_carousel'));
        add_action('wp_ajax_delete_carousel', array($this, 'ajax_delete_carousel'));
        add_action('wp_ajax_duplicate_carousel', array($this, 'ajax_duplicate_carousel'));
        add_action('wp_ajax_update_carousel_status', array($this, 'ajax_update_carousel_status'));
        
        // Shortcode
        add_shortcode('advanced_carousel', array($this, 'carousel_shortcode'));
    }
    
    public function activate() {
        $this->create_tables();
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    public function init() {
        load_plugin_textdomain('advanced-carousel', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Carousels table
        $carousels_table = $wpdb->prefix . 'advanced_carousels';
        $sql_carousels = "CREATE TABLE $carousels_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL UNIQUE,
            style_theme varchar(50) DEFAULT 'modern',
            settings longtext,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_slug (slug),
            KEY idx_status (status)
        ) $charset_collate;";
        
        // Carousel images table
        $images_table = $wpdb->prefix . 'carousel_images';
        $sql_images = "CREATE TABLE $images_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            carousel_id int(11) NOT NULL,
            attachment_id int(11) NOT NULL,
            sort_order int(11) DEFAULT 0,
            alt_text varchar(255),
            caption text,
            link_url varchar(500),
            settings longtext,
            PRIMARY KEY (id),
            KEY idx_carousel_order (carousel_id, sort_order),
            KEY idx_attachment (attachment_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_carousels);
        dbDelta($sql_images);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('Carousel Manager', 'advanced-carousel'),
            __('Carousels', 'advanced-carousel'),
            'manage_options',
            'advanced-carousel',
            array($this, 'admin_page'),
            'dashicons-images-alt2',
            30
        );
        
        add_submenu_page(
            'advanced-carousel',
            __('All Carousels', 'advanced-carousel'),
            __('All Carousels', 'advanced-carousel'),
            'manage_options',
            'advanced-carousel',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'advanced-carousel',
            __('Add New Carousel', 'advanced-carousel'),
            __('Add New', 'advanced-carousel'),
            'manage_options',
            'advanced-carousel-add',
            array($this, 'add_carousel_page')
        );
        
        add_submenu_page(
            'advanced-carousel',
            __('Edit Carousel', 'advanced-carousel'),
            '',
            'manage_options',
            'advanced-carousel-edit',
            array($this, 'edit_carousel_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'advanced-carousel') === false) {
            return;
        }
        
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('advanced-carousel-admin', ADVANCED_CAROUSEL_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'jquery-ui-sortable'), ADVANCED_CAROUSEL_VERSION, true);
        wp_enqueue_style('advanced-carousel-admin', ADVANCED_CAROUSEL_PLUGIN_URL . 'assets/css/admin.css', array(), ADVANCED_CAROUSEL_VERSION);
        
        wp_localize_script('advanced-carousel-admin', 'advancedCarousel', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('advanced_carousel_nonce'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this carousel?', 'advanced-carousel'),
                'saving' => __('Saving...', 'advanced-carousel'),
                'saved' => __('Saved!', 'advanced-carousel'),
                'error' => __('Error occurred. Please try again.', 'advanced-carousel'),
            )
        ));
    }
    
    public function enqueue_frontend_scripts() {
        wp_enqueue_script('advanced-carousel-frontend', ADVANCED_CAROUSEL_PLUGIN_URL . 'assets/js/carousel.js', array('jquery'), ADVANCED_CAROUSEL_VERSION, true);
        wp_enqueue_style('advanced-carousel-frontend', ADVANCED_CAROUSEL_PLUGIN_URL . 'assets/css/carousel.css', array(), ADVANCED_CAROUSEL_VERSION);
    }
    
    public function admin_page() {
        global $wpdb;
        
        $carousels_table = $wpdb->prefix . 'advanced_carousels';
        $images_table = $wpdb->prefix . 'carousel_images';
        
        // Get all carousels with image counts
        $carousels = $wpdb->get_results("
            SELECT c.*, COUNT(i.id) as image_count 
            FROM $carousels_table c 
            LEFT JOIN $images_table i ON c.id = i.carousel_id 
            GROUP BY c.id 
            ORDER BY c.updated_at DESC
        ");
        
        ?>
        <div class="wrap advanced-carousel-admin">
            <h1 class="wp-heading-inline"><?php _e('Carousel Manager', 'advanced-carousel'); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=advanced-carousel-add'); ?>" class="page-title-action">
                <?php _e('Add New Carousel', 'advanced-carousel'); ?>
            </a>
            
            <div class="carousel-stats">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($carousels); ?></div>
                        <div class="stat-label"><?php _e('Total Carousels', 'advanced-carousel'); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count(array_filter($carousels, function($c) { return $c->status === 'active'; })); ?></div>
                        <div class="stat-label"><?php _e('Active Carousels', 'advanced-carousel'); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo array_sum(array_column($carousels, 'image_count')); ?></div>
                        <div class="stat-label"><?php _e('Total Images', 'advanced-carousel'); ?></div>
                    </div>
                </div>
            </div>
            
            <?php if (empty($carousels)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <span class="dashicons dashicons-images-alt2"></span>
                    </div>
                    <h2><?php _e('No carousels found', 'advanced-carousel'); ?></h2>
                    <p><?php _e('Create your first carousel to get started.', 'advanced-carousel'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=advanced-carousel-add'); ?>" class="button button-primary button-large">
                        <?php _e('Create Your First Carousel', 'advanced-carousel'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="carousel-table-container">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th class="column-name"><?php _e('Name', 'advanced-carousel'); ?></th>
                                <th class="column-shortcode"><?php _e('Shortcode', 'advanced-carousel'); ?></th>
                                <th class="column-style"><?php _e('Style', 'advanced-carousel'); ?></th>
                                <th class="column-images"><?php _e('Images', 'advanced-carousel'); ?></th>
                                <th class="column-status"><?php _e('Status', 'advanced-carousel'); ?></th>
                                <th class="column-date"><?php _e('Last Modified', 'advanced-carousel'); ?></th>
                                <th class="column-actions"><?php _e('Actions', 'advanced-carousel'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($carousels as $carousel): ?>
                                <tr data-carousel-id="<?php echo $carousel->id; ?>">
                                    <td class="column-name">
                                        <strong>
                                            <a href="<?php echo admin_url('admin.php?page=advanced-carousel-edit&id=' . $carousel->id); ?>">
                                                <?php echo esc_html($carousel->name); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td class="column-shortcode">
                                        <code onclick="this.select()">[advanced_carousel id="<?php echo esc_attr($carousel->slug); ?>"]</code>
                                    </td>
                                    <td class="column-style">
                                        <span class="style-badge style-<?php echo esc_attr($carousel->style_theme); ?>">
                                            <?php echo esc_html(ucfirst($carousel->style_theme)); ?>
                                        </span>
                                    </td>
                                    <td class="column-images">
                                        <span class="image-count"><?php echo $carousel->image_count; ?></span>
                                    </td>
                                    <td class="column-status">
                                        <span class="status-toggle" data-carousel-id="<?php echo $carousel->id; ?>" data-status="<?php echo $carousel->status; ?>">
                                            <span class="status-indicator status-<?php echo $carousel->status; ?>"></span>
                                            <?php echo esc_html(ucfirst($carousel->status)); ?>
                                        </span>
                                    </td>
                                    <td class="column-date">
                                        <?php echo human_time_diff(strtotime($carousel->updated_at), current_time('timestamp')); ?> <?php _e('ago', 'advanced-carousel'); ?>
                                    </td>
                                    <td class="column-actions">
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a href="<?php echo admin_url('admin.php?page=advanced-carousel-edit&id=' . $carousel->id); ?>">
                                                    <?php _e('Edit', 'advanced-carousel'); ?>
                                                </a>
                                            </span>
                                            <span class="duplicate">
                                                <a href="#" class="duplicate-carousel" data-carousel-id="<?php echo $carousel->id; ?>">
                                                    <?php _e('Duplicate', 'advanced-carousel'); ?>
                                                </a>
                                            </span>
                                            <span class="delete">
                                                <a href="#" class="delete-carousel" data-carousel-id="<?php echo $carousel->id; ?>">
                                                    <?php _e('Delete', 'advanced-carousel'); ?>
                                                </a>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function add_carousel_page() {
        $this->render_carousel_form();
    }
    
    public function edit_carousel_page() {
        $carousel_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$carousel_id) {
            wp_die(__('Invalid carousel ID', 'advanced-carousel'));
        }
        
        global $wpdb;
        $carousel = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}advanced_carousels WHERE id = %d",
            $carousel_id
        ));
        
        if (!$carousel) {
            wp_die(__('Carousel not found', 'advanced-carousel'));
        }
        
        $this->render_carousel_form($carousel);
    }
    
    private function render_carousel_form($carousel = null) {
        $is_edit = $carousel !== null;
        $carousel_id = $is_edit ? $carousel->id : 0;
        
        // Get carousel images if editing
        $images = array();
        if ($is_edit) {
            global $wpdb;
            $images = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}carousel_images WHERE carousel_id = %d ORDER BY sort_order ASC",
                $carousel_id
            ));
        }
        
        // Available styles
        $available_styles = array(
            'modern' => array(
                'name' => __('Modern', 'advanced-carousel'),
                'description' => __('Clean, minimal design with smooth transitions', 'advanced-carousel'),
                'preview' => 'modern-preview.jpg'
            ),
            'classic' => array(
                'name' => __('Classic', 'advanced-carousel'),
                'description' => __('Traditional carousel with elegant styling', 'advanced-carousel'),
                'preview' => 'classic-preview.jpg'
            ),
            'minimal' => array(
                'name' => __('Minimal', 'advanced-carousel'),
                'description' => __('Ultra-clean design with focus on content', 'advanced-carousel'),
                'preview' => 'minimal-preview.jpg'
            )
        );
        
        ?>
        <div class="wrap advanced-carousel-form">
            <h1><?php echo $is_edit ? __('Edit Carousel', 'advanced-carousel') : __('Add New Carousel', 'advanced-carousel'); ?></h1>
            
            <form id="carousel-form" class="carousel-form">
                <?php wp_nonce_field('advanced_carousel_nonce', 'carousel_nonce'); ?>
                <input type="hidden" name="carousel_id" value="<?php echo $carousel_id; ?>">
                
                <div class="form-container">
                    <div class="form-main">
                        <!-- Basic Settings -->
                        <div class="form-section">
                            <h2><?php _e('Basic Settings', 'advanced-carousel'); ?></h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="carousel-name"><?php _e('Carousel Name', 'advanced-carousel'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="carousel-name" name="carousel_name" class="regular-text" 
                                               value="<?php echo $is_edit ? esc_attr($carousel->name) : ''; ?>" required>
                                        <p class="description"><?php _e('Enter a name for this carousel', 'advanced-carousel'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="carousel-slug"><?php _e('Carousel Slug', 'advanced-carousel'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="carousel-slug" name="carousel_slug" class="regular-text" 
                                               value="<?php echo $is_edit ? esc_attr($carousel->slug) : ''; ?>" required>
                                        <p class="description">
                                            <?php _e('Unique identifier for shortcode. Only lowercase letters, numbers, and hyphens.', 'advanced-carousel'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Style Selection -->
                        <div class="form-section">
                            <h2><?php _e('Choose Style', 'advanced-carousel'); ?></h2>
                            
                            <div class="style-selector">
                                <?php foreach ($available_styles as $style_key => $style_info): ?>
                                    <div class="style-option">
                                        <input type="radio" id="style-<?php echo $style_key; ?>" name="carousel_style" 
                                               value="<?php echo $style_key; ?>" 
                                               <?php checked($is_edit ? $carousel->style_theme : 'modern', $style_key); ?>>
                                        <label for="style-<?php echo $style_key; ?>" class="style-card">
                                            <div class="style-preview">
                                                <img src="<?php echo ADVANCED_CAROUSEL_PLUGIN_URL . 'assets/images/previews/' . $style_info['preview']; ?>" 
                                                     alt="<?php echo esc_attr($style_info['name']); ?>" />
                                            </div>
                                            <div class="style-info">
                                                <h3><?php echo esc_html($style_info['name']); ?></h3>
                                                <p><?php echo esc_html($style_info['description']); ?></p>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Images Management -->
                        <div class="form-section">
                            <h2><?php _e('Manage Images', 'advanced-carousel'); ?></h2>
                            
                            <div class="images-toolbar">
                                <button type="button" id="add-images-btn" class="button button-primary">
                                    <span class="dashicons dashicons-plus-alt"></span>
                                    <?php _e('Add Images', 'advanced-carousel'); ?>
                                </button>
                                <button type="button" id="remove-all-btn" class="button button-secondary">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php _e('Remove All', 'advanced-carousel'); ?>
                                </button>
                            </div>
                            
                            <div id="carousel-images" class="carousel-images-container">
                                <?php if (!empty($images)): ?>
                                    <?php foreach ($images as $image): ?>
                                        <?php $this->render_image_item($image); ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-images-placeholder">
                                        <span class="dashicons dashicons-images-alt2"></span>
                                        <p><?php _e('No images added yet. Click "Add Images" to get started.', 'advanced-carousel'); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-sidebar">
                        <!-- Publish Box -->
                        <div class="sidebar-section publish-box">
                            <h3><?php _e('Publish', 'advanced-carousel'); ?></h3>
                            
                            <div class="misc-pub-section">
                                <label for="carousel-status"><?php _e('Status:', 'advanced-carousel'); ?></label>
                                <select id="carousel-status" name="carousel_status">
                                    <option value="active" <?php selected($is_edit ? $carousel->status : 'active', 'active'); ?>>
                                        <?php _e('Active', 'advanced-carousel'); ?>
                                    </option>
                                    <option value="inactive" <?php selected($is_edit ? $carousel->status : '', 'inactive'); ?>>
                                        <?php _e('Inactive', 'advanced-carousel'); ?>
                                    </option>
                                </select>
                            </div>
                            
                            <?php if ($is_edit): ?>
                                <div class="misc-pub-section">
                                    <strong><?php _e('Shortcode:', 'advanced-carousel'); ?></strong><br>
                                    <code onclick="this.select()">[advanced_carousel id="<?php echo esc_attr($carousel->slug); ?>"]</code>
                                </div>
                            <?php endif; ?>
                            
                            <div class="publishing-actions">
                                <input type="submit" class="button button-primary button-large" 
                                       value="<?php echo $is_edit ? __('Update Carousel', 'advanced-carousel') : __('Create Carousel', 'advanced-carousel'); ?>">
                                
                                <?php if ($is_edit): ?>
                                    <a href="<?php echo admin_url('admin.php?page=advanced-carousel'); ?>" class="button button-secondary">
                                        <?php _e('Cancel', 'advanced-carousel'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Settings Box -->
                        <div class="sidebar-section settings-box">
                            <h3><?php _e('Carousel Settings', 'advanced-carousel'); ?></h3>
                            
                            <table class="form-table">
                                <tr>
                                    <th><label for="autoplay"><?php _e('Autoplay', 'advanced-carousel'); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="autoplay" name="settings[autoplay]" value="1" checked>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="autoplay_speed"><?php _e('Speed (ms)', 'advanced-carousel'); ?></label></th>
                                    <td>
                                        <input type="number" id="autoplay_speed" name="settings[autoplay_speed]" 
                                               value="5000" min="1000" max="10000" step="500">
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="show_arrows"><?php _e('Show Arrows', 'advanced-carousel'); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="show_arrows" name="settings[show_arrows]" value="1" checked>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="show_dots"><?php _e('Show Dots', 'advanced-carousel'); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="show_dots" name="settings[show_dots]" value="1" checked>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Image Item Template -->
        <script type="text/template" id="image-item-template">
            <div class="image-item" data-attachment-id="{{attachment_id}}">
                <div class="image-preview">
                    <img src="{{thumbnail_url}}" alt="{{alt_text}}">
                    <div class="image-overlay">
                        <button type="button" class="remove-image" title="<?php _e('Remove Image', 'advanced-carousel'); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                </div>
                <div class="image-details">
                    <input type="hidden" name="images[{{index}}][attachment_id]" value="{{attachment_id}}">
                    <input type="hidden" name="images[{{index}}][sort_order]" value="{{sort_order}}">
                    
                    <div class="detail-row">
                        <label><?php _e('Alt Text:', 'advanced-carousel'); ?></label>
                        <input type="text" name="images[{{index}}][alt_text]" value="{{alt_text}}" class="widefat">
                    </div>
                    
                    <div class="detail-row">
                        <label><?php _e('Caption:', 'advanced-carousel'); ?></label>
                        <textarea name="images[{{index}}][caption]" class="widefat" rows="2">{{caption}}</textarea>
                    </div>
                    
                    <div class="detail-row">
                        <label><?php _e('Link URL:', 'advanced-carousel'); ?></label>
                        <input type="url" name="images[{{index}}][link_url]" value="{{link_url}}" class="widefat">
                    </div>
                </div>
                <div class="image-handle">
                    <span class="dashicons dashicons-menu"></span>
                </div>
            </div>
        </script>
        <?php
    }
    
    private function render_image_item($image) {
        $attachment = wp_get_attachment_image_src($image->attachment_id, 'thumbnail');
        $thumbnail_url = $attachment ? $attachment[0] : '';
        ?>
        <div class="image-item" data-attachment-id="<?php echo $image->attachment_id; ?>">
            <div class="image-preview">
                <img src="<?php echo esc_url($thumbnail_url); ?>" alt="<?php echo esc_attr($image->alt_text); ?>">
                <div class="image-overlay">
                    <button type="button" class="remove-image" title="<?php _e('Remove Image', 'advanced-carousel'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            </div>
            <div class="image-details">
                <input type="hidden" name="images[<?php echo $image->id; ?>][attachment_id]" value="<?php echo $image->attachment_id; ?>">
                <input type="hidden" name="images[<?php echo $image->id; ?>][sort_order]" value="<?php echo $image->sort_order; ?>">
                
                <div class="detail-row">
                    <label><?php _e('Alt Text:', 'advanced-carousel'); ?></label>
                    <input type="text" name="images[<?php echo $image->id; ?>][alt_text]" value="<?php echo esc_attr($image->alt_text); ?>" class="widefat">
                </div>
                
                <div class="detail-row">
                    <label><?php _e('Caption:', 'advanced-carousel'); ?></label>
                    <textarea name="images[<?php echo $image->id; ?>][caption]" class="widefat" rows="2"><?php echo esc_textarea($image->caption); ?></textarea>
                </div>
                
                <div class="detail-row">
                    <label><?php _e('Link URL:', 'advanced-carousel'); ?></label>
                    <input type="url" name="images[<?php echo $image->id; ?>][link_url]" value="<?php echo esc_url($image->link_url); ?>" class="widefat">
                </div>
            </div>
            <div class="image-handle">
                <span class="dashicons dashicons-menu"></span>
            </div>
        </div>
        <?php
    }
    
    // AJAX Handlers
    public function ajax_save_carousel() {
        check_ajax_referer('advanced_carousel_nonce', 'carousel_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        $carousel_id = intval($_POST['carousel_id']);
        $name = sanitize_text_field($_POST['carousel_name']);
        $slug = sanitize_title($_POST['carousel_slug']);
        $style = sanitize_text_field($_POST['carousel_style']);
        $status = sanitize_text_field($_POST['carousel_status']);
        $settings = isset($_POST['settings']) ? wp_json_encode($_POST['settings']) : '{}';
        
        $data = array(
            'name' => $name,
            'slug' => $slug,
            'style_theme' => $style,
            'status' => $status,
            'settings' => $settings,
            'updated_at' => current_time('mysql')
        );
        
        if ($carousel_id > 0) {
            // Update existing carousel
            $result = $wpdb->update(
                $wpdb->prefix . 'advanced_carousels',
                $data,
                array('id' => $carousel_id),
                array('%s', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            // Create new carousel
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert(
                $wpdb->prefix . 'advanced_carousels',
                $data,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            $carousel_id = $wpdb->insert_id;
        }
        
        if ($result === false) {
            wp_send_json_error(__('Failed to save carousel', 'advanced-carousel'));
        }
        
        // Handle images
        if (isset($_POST['images']) && is_array($_POST['images'])) {
            // Clear existing images for this carousel
            $wpdb->delete(
                $wpdb->prefix . 'carousel_images',
                array('carousel_id' => $carousel_id),
                array('%d')
            );
            
            // Insert new images
            foreach ($_POST['images'] as $index => $image_data) {
                $wpdb->insert(
                    $wpdb->prefix . 'carousel_images',
                    array(
                        'carousel_id' => $carousel_id,
                        'attachment_id' => intval($image_data['attachment_id']),
                        'sort_order' => intval($image_data['sort_order']),
                        'alt_text' => sanitize_text_field($image_data['alt_text']),
                        'caption' => sanitize_textarea_field($image_data['caption']),
                        'link_url' => esc_url_raw($image_data['link_url'])
                    ),
                    array('%d', '%d', '%d', '%s', '%s', '%s')
                );
            }
        }
        
        wp_send_json_success(array(
            'message' => __('Carousel saved successfully', 'advanced-carousel'),
            'carousel_id' => $carousel_id,
            'redirect_url' => admin_url('admin.php?page=advanced-carousel-edit&id=' . $carousel_id)
        ));
    }
    
    public function ajax_delete_carousel() {
        check_ajax_referer('advanced_carousel_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $carousel_id = intval($_POST['carousel_id']);
        
        global $wpdb;
        
        // Delete carousel images first
        $wpdb->delete(
            $wpdb->prefix . 'carousel_images',
            array('carousel_id' => $carousel_id),
            array('%d')
        );
        
        // Delete carousel
        $result = $wpdb->delete(
            $wpdb->prefix . 'advanced_carousels',
            array('id' => $carousel_id),
            array('%d')
        );
        
        if ($result) {
            wp_send_json_success(__('Carousel deleted successfully', 'advanced-carousel'));
        } else {
            wp_send_json_error(__('Failed to delete carousel', 'advanced-carousel'));
        }
    }
    
    public function ajax_duplicate_carousel() {
        check_ajax_referer('advanced_carousel_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $carousel_id = intval($_POST['carousel_id']);
        
        global $wpdb;
        
        // Get original carousel
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}advanced_carousels WHERE id = %d",
            $carousel_id
        ));
        
        if (!$original) {
            wp_send_json_error(__('Original carousel not found', 'advanced-carousel'));
        }
        
        // Create duplicate
        $new_name = $original->name . ' (Copy)';
        $new_slug = $original->slug . '-copy';
        
        // Ensure unique slug
        $counter = 1;
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}advanced_carousels WHERE slug = %s",
            $new_slug
        )) > 0) {
            $new_slug = $original->slug . '-copy-' . $counter;
            $counter++;
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'advanced_carousels',
            array(
                'name' => $new_name,
                'slug' => $new_slug,
                'style_theme' => $original->style_theme,
                'settings' => $original->settings,
                'status' => 'inactive',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            wp_send_json_error(__('Failed to duplicate carousel', 'advanced-carousel'));
        }
        
        $new_carousel_id = $wpdb->insert_id;
        
        // Duplicate images
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}carousel_images WHERE carousel_id = %d",
            $carousel_id
        ));
        
        foreach ($images as $image) {
            $wpdb->insert(
                $wpdb->prefix . 'carousel_images',
                array(
                    'carousel_id' => $new_carousel_id,
                    'attachment_id' => $image->attachment_id,
                    'sort_order' => $image->sort_order,
                    'alt_text' => $image->alt_text,
                    'caption' => $image->caption,
                    'link_url' => $image->link_url,
                    'settings' => $image->settings
                ),
                array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
            );
        }
        
        wp_send_json_success(array(
            'message' => __('Carousel duplicated successfully', 'advanced-carousel'),
            'new_carousel_id' => $new_carousel_id,
            'edit_url' => admin_url('admin.php?page=advanced-carousel-edit&id=' . $new_carousel_id)
        ));
    }
    
    public function ajax_update_carousel_status() {
        check_ajax_referer('advanced_carousel_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $carousel_id = intval($_POST['carousel_id']);
        $status = sanitize_text_field($_POST['status']);
        
        if (!in_array($status, array('active', 'inactive'))) {
            wp_send_json_error(__('Invalid status', 'advanced-carousel'));
        }
        
        global $wpdb;
        
        $result = $wpdb->update(
            $wpdb->prefix . 'advanced_carousels',
            array('status' => $status, 'updated_at' => current_time('mysql')),
            array('id' => $carousel_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(__('Status updated successfully', 'advanced-carousel'));
        } else {
            wp_send_json_error(__('Failed to update status', 'advanced-carousel'));
        }
    }
    
    public function carousel_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
        ), $atts, 'advanced_carousel');
        
        if (empty($atts['id'])) {
            return '<p>' . __('Please specify a carousel ID', 'advanced-carousel') . '</p>';
        }
        
        global $wpdb;
        
        // Get carousel
        $carousel = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}advanced_carousels WHERE slug = %s AND status = 'active'",
            $atts['id']
        ));
        
        if (!$carousel) {
            return '<p>' . __('Carousel not found or inactive', 'advanced-carousel') . '</p>';
        }
        
        // Get carousel images
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}carousel_images WHERE carousel_id = %d ORDER BY sort_order ASC",
            $carousel->id
        ));
        
        if (empty($images)) {
            return '<p>' . __('No images found in this carousel', 'advanced-carousel') . '</p>';
        }
        
        // Parse settings
        $settings = json_decode($carousel->settings, true);
        if (!is_array($settings)) {
            $settings = array();
        }
        
        $default_settings = array(
            'autoplay' => true,
            'autoplay_speed' => 5000,
            'show_arrows' => true,
            'show_dots' => true
        );
        
        $settings = array_merge($default_settings, $settings);
        
        // Generate carousel HTML
        ob_start();
        ?>
        <div class="advanced-carousel-container carousel-<?php echo esc_attr($carousel->style_theme); ?>" 
             data-carousel-id="<?php echo esc_attr($carousel->id); ?>"
             data-settings="<?php echo esc_attr(json_encode($settings)); ?>">
            
            <div class="carousel-wrapper">
                <div class="carousel-track">
                    <?php foreach ($images as $index => $image): ?>
                        <?php
                        $attachment_data = wp_get_attachment_image_src($image->attachment_id, 'large');
                        $image_url = $attachment_data ? $attachment_data[0] : '';
                        $image_alt = $image->alt_text ?: get_post_meta($image->attachment_id, '_wp_attachment_image_alt', true);
                        ?>
                        <div class="carousel-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-slide="<?php echo $index; ?>">
                            <?php if (!empty($image->link_url)): ?>
                                <a href="<?php echo esc_url($image->link_url); ?>" target="_blank" rel="noopener">
                            <?php endif; ?>
                            
                            <img src="<?php echo esc_url($image_url); ?>" 
                                 alt="<?php echo esc_attr($image_alt); ?>"
                                 loading="lazy">
                            
                            <?php if (!empty($image->link_url)): ?>
                                </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($image->caption)): ?>
                                <div class="carousel-caption">
                                    <p><?php echo esc_html($image->caption); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($settings['show_arrows'] && count($images) > 1): ?>
                    <button class="carousel-arrow carousel-prev" aria-label="<?php _e('Previous slide', 'advanced-carousel'); ?>">
                        <span class="arrow-icon">‹</span>
                    </button>
                    <button class="carousel-arrow carousel-next" aria-label="<?php _e('Next slide', 'advanced-carousel'); ?>">
                        <span class="arrow-icon">›</span>
                    </button>
                <?php endif; ?>
                
                <?php if ($settings['show_dots'] && count($images) > 1): ?>
                    <div class="carousel-dots">
                        <?php foreach ($images as $index => $image): ?>
                            <button class="carousel-dot <?php echo $index === 0 ? 'active' : ''; ?>" 
                                    data-slide="<?php echo $index; ?>"
                                    aria-label="<?php printf(__('Go to slide %d', 'advanced-carousel'), $index + 1); ?>">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
}

// Initialize the plugin
new AdvancedCarouselPlugin();