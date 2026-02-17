<?php
/**
 * Uninstall GSheet SFTP Sync
 *
 * @package GSheet_SFTP_Sync
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

// Delete log files
$log_dir = plugin_dir_path(__FILE__) . 'logs';
if (is_dir($log_dir)) {
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($log_dir);
}
