<?php
/**
 * Uninstall SFTP Sync for Google Sheets
 *
 * @package SFTP_Sync_GS
 * @author Olivier Bigras
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('gsheet_sftp_api_key');
delete_option('gsheet_sftp_host');
delete_option('gsheet_sftp_port');
delete_option('gsheet_sftp_username');
delete_option('gsheet_sftp_password');
delete_option('gsheet_sftp_remote_path');
delete_option('gsheet_sftp_schedule');
delete_option('gsheet_sftp_daily_hour');
delete_option('gsheet_sftp_filename_mode');
delete_option('gsheet_sftp_base_filename');
delete_option('gsheet_sftp_export_format');

// Delete log files from uploads directory using WP_Filesystem
$upload_dir = wp_upload_dir();
$log_dir = trailingslashit($upload_dir['basedir']) . 'sftp-sync-for-google-sheets/';
if (is_dir($log_dir)) {
    // Initialize WP_Filesystem
    global $wp_filesystem;
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    
    if ( $wp_filesystem ) {
        $wp_filesystem->delete( $log_dir, true );
    }
}
