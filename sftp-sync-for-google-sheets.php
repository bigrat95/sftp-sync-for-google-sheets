<?php
/**
 * Plugin Name: SFTP Sync for Google Sheets
 * Plugin URI: https://github.com/bigrat95/sftp-sync-for-google-sheets/
 * Description: Receive Google Sheets exports via API and upload to SFTP server. Automate daily syncs from Google Sheets to your server.
 * Version: 1.5.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sftp-sync-for-google-sheets
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SFTP_SYNC_GS_VERSION', '1.5.0');
define('SFTP_SYNC_GS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SFTP_SYNC_GS_PLUGIN_URL', plugin_dir_url(__FILE__));

class SFTP_Sync_GS {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function load_dependencies() {
        require_once SFTP_SYNC_GS_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once SFTP_SYNC_GS_PLUGIN_DIR . 'includes/class-sftp-handler.php';
        require_once SFTP_SYNC_GS_PLUGIN_DIR . 'includes/class-api-endpoint.php';
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_enqueue_scripts', [$this, 'admin_styles']);
    }
    
    public function activate() {
        // Generate default API key if not exists
        if (!get_option('gsheet_sftp_api_key')) {
            update_option('gsheet_sftp_api_key', wp_generate_password(32, false));
        }
        
        // Create logs directory in uploads folder (not in plugin directory)
        $log_dir = self::get_log_directory();
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Add .htaccess to protect log files
            $htaccess = $log_dir . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
            // Add index.php for extra security
            $index = $log_dir . 'index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
        
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    public function add_admin_menu() {
        add_options_page(
            esc_html__('SFTP Sync for Google Sheets', 'sftp-sync-for-google-sheets'),
            esc_html__('SFTP Sync', 'sftp-sync-for-google-sheets'),
            'manage_options',
            'sftp-sync-for-google-sheets',
            [SFTP_Sync_GS_Admin_Settings::get_instance(), 'render_settings_page']
        );
    }
    
    public function register_settings() {
        SFTP_Sync_GS_Admin_Settings::get_instance()->register_settings();
    }
    
    public function register_rest_routes() {
        SFTP_Sync_GS_API_Endpoint::get_instance()->register_routes();
    }
    
    public function admin_styles($hook) {
        if ($hook !== 'settings_page_sftp-sync-for-google-sheets') {
            return;
        }
        wp_add_inline_style('wp-admin', '
            .gsheet-sftp-wrap { max-width: 800px; }
            .gsheet-sftp-wrap .form-table th { width: 200px; }
            .gsheet-sftp-api-key { font-family: monospace; background: #f0f0f1; padding: 8px 12px; border-radius: 4px; }
            .gsheet-sftp-endpoint { font-family: monospace; background: #e7f3e7; padding: 8px 12px; border-radius: 4px; word-break: break-all; }
            .gsheet-sftp-section { background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0; }
            .gsheet-sftp-logs { max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; font-family: monospace; font-size: 12px; }
            .gsheet-sftp-logs .log-success { color: #4ec9b0; }
            .gsheet-sftp-logs .log-error { color: #f14c4c; }
            .gsheet-sftp-logs .log-info { color: #569cd6; }
        ');
    }
    
    /**
     * Get the log directory path in wp-content/uploads.
     */
    public static function get_log_directory() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . 'sftp-sync-for-google-sheets/';
    }
    
    /**
     * Get the log file path.
     */
    public static function get_log_file() {
        return self::get_log_directory() . 'sync.log';
    }
    
    public static function log($message, $type = 'info') {
        $log_dir = self::get_log_directory();
        
        // Ensure directory exists
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $log_file = self::get_log_file();
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$type}] {$message}\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Keep log file under 1MB
        if (file_exists($log_file) && filesize($log_file) > 1048576) {
            $lines = file($log_file);
            $lines = array_slice($lines, -500);
            file_put_contents($log_file, implode('', $lines));
        }
    }
    
    public static function get_logs($limit = 50) {
        $log_file = self::get_log_file();
        if (!file_exists($log_file)) {
            return [];
        }
        $lines = file($log_file, FILE_IGNORE_NEW_LINES);
        return array_slice(array_reverse($lines), 0, $limit);
    }
}

// Initialize the plugin
SFTP_Sync_GS::get_instance();
