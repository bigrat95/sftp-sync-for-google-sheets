<?php
if (!defined('ABSPATH')) {
    exit;
}

class SFTP_Sync_GS_Admin_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Enqueue admin scripts properly using WordPress functions.
     */
    private function enqueue_admin_scripts() {
        wp_register_script(
            'sftp-sync-gs-admin',
            false,
            [],
            SFTP_SYNC_GS_VERSION,
            ['in_footer' => true]
        );
        
        $inline_script = "
            document.addEventListener('DOMContentLoaded', function() {
                var scheduleSelect = document.getElementById('gsheet_sftp_schedule');
                var dailyHourRow = document.getElementById('daily-hour-row');
                
                if (scheduleSelect && dailyHourRow) {
                    scheduleSelect.addEventListener('change', function() {
                        dailyHourRow.style.display = this.value === 'daily' ? '' : 'none';
                    });
                    
                    if (scheduleSelect.value !== 'daily') {
                        dailyHourRow.style.display = 'none';
                    }
                }
            });
        ";
        
        wp_add_inline_script('sftp-sync-gs-admin', $inline_script);
        wp_enqueue_script('sftp-sync-gs-admin');
    }
    
    public function register_settings() {
        // API Settings
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_api_key', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        // SFTP Settings
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_host', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_port', [
            'sanitize_callback' => 'absint',
            'default' => 22
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_username', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_password', [
            'sanitize_callback' => [$this, 'encrypt_password']
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_remote_path', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '/'
        ]);
        
        // Export Settings
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_schedule', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'daily'
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_daily_hour', [
            'sanitize_callback' => 'absint',
            'default' => 2
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_filename_mode', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'dated'
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_base_filename', [
            'sanitize_callback' => 'sanitize_file_name',
            'default' => 'sheet_export'
        ]);
        register_setting('gsheet_sftp_settings', 'gsheet_sftp_export_format', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'csv'
        ]);
    }
    
    public function encrypt_password($password) {
        if (empty($password)) {
            return get_option('gsheet_sftp_password', '');
        }
        return self::encrypt($password);
    }
    
    public static function get_password() {
        $encrypted = get_option('gsheet_sftp_password', '');
        if (empty($encrypted)) {
            return '';
        }
        return self::decrypt($encrypted);
    }
    
    private static function get_encryption_key() {
        return hash('sha256', SECURE_AUTH_KEY . SECURE_AUTH_SALT, true);
    }
    
    private static function encrypt($data) {
        $key = self::get_encryption_key();
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    private static function decrypt($data) {
        $key = self::get_encryption_key();
        $data = base64_decode($data);
        if ($data === false || strlen($data) < 17) {
            // Fallback for legacy base64-only encoded passwords
            $legacy = base64_decode(get_option('gsheet_sftp_password', ''));
            if ($legacy !== false) {
                return $legacy;
            }
            return '';
        }
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Enqueue admin script for schedule toggle
        $this->enqueue_admin_scripts();
        
        // Handle test connection
        if (isset($_POST['test_sftp_connection']) && check_admin_referer('gsheet_sftp_test_connection')) {
            $this->test_connection();
        }
        
        // Handle regenerate API key
        if (isset($_POST['regenerate_api_key']) && check_admin_referer('gsheet_sftp_regenerate_key')) {
            update_option('gsheet_sftp_api_key', wp_generate_password(32, false));
            echo '<div class="notice notice-success"><p>' . esc_html__('API key regenerated successfully!', 'sftp-sync-for-google-sheets') . '</p></div>';
        }
        
        // Handle clear logs
        if (isset($_POST['clear_logs']) && check_admin_referer('gsheet_sftp_clear_logs')) {
            $log_file = SFTP_Sync_GS::get_log_file();
            if (file_exists($log_file)) {
                wp_delete_file($log_file);
            }
            echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared!', 'sftp-sync-for-google-sheets') . '</p></div>';
        }
        
        $api_key = get_option('gsheet_sftp_api_key', '');
        $endpoint_url = rest_url('gsheet-sftp/v1/upload');
        ?>
        <div class="wrap gsheet-sftp-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="gsheet-sftp-section">
                <h2><?php esc_html_e('API Endpoint Information', 'sftp-sync-for-google-sheets'); ?></h2>
                <p><?php esc_html_e('Use these details in your Google Apps Script:', 'sftp-sync-for-google-sheets'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Endpoint URL', 'sftp-sync-for-google-sheets'); ?></th>
                        <td>
                            <code class="gsheet-sftp-endpoint"><?php echo esc_url($endpoint_url); ?></code>
                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($endpoint_url); ?>')">
                                <?php esc_html_e('Copy', 'sftp-sync-for-google-sheets'); ?>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('API Key', 'sftp-sync-for-google-sheets'); ?></th>
                        <td>
                            <code class="gsheet-sftp-api-key"><?php echo esc_html($api_key); ?></code>
                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($api_key); ?>')">
                                <?php esc_html_e('Copy', 'sftp-sync-for-google-sheets'); ?>
                            </button>
                            <form method="post" style="display: inline; margin-left: 10px;">
                                <?php wp_nonce_field('gsheet_sftp_regenerate_key'); ?>
                                <button type="submit" name="regenerate_api_key" class="button button-small" onclick="return confirm('<?php echo esc_js(__('Are you sure? You will need to update your Google Apps Script.', 'sftp-sync-for-google-sheets')); ?>')">
                                    <?php esc_html_e('Regenerate', 'sftp-sync-for-google-sheets'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                </table>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('gsheet_sftp_settings'); ?>
                
                <div class="gsheet-sftp-section">
                    <h2><?php esc_html_e('SFTP Server Settings', 'sftp-sync-for-google-sheets'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="gsheet_sftp_host"><?php esc_html_e('SFTP Host', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <input type="text" id="gsheet_sftp_host" name="gsheet_sftp_host" 
                                       value="<?php echo esc_attr(get_option('gsheet_sftp_host', '')); ?>" 
                                       class="regular-text" placeholder="sftp.example.com or IP address">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gsheet_sftp_port"><?php esc_html_e('SFTP Port', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <input type="number" id="gsheet_sftp_port" name="gsheet_sftp_port" 
                                       value="<?php echo esc_attr(get_option('gsheet_sftp_port', 22)); ?>" 
                                       class="small-text" min="1" max="65535">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gsheet_sftp_username"><?php esc_html_e('Username', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <input type="text" id="gsheet_sftp_username" name="gsheet_sftp_username" 
                                       value="<?php echo esc_attr(get_option('gsheet_sftp_username', '')); ?>" 
                                       class="regular-text" autocomplete="off">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gsheet_sftp_password"><?php esc_html_e('Password', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <input type="password" id="gsheet_sftp_password" name="gsheet_sftp_password" 
                                       value="" class="regular-text" autocomplete="new-password"
                                       placeholder="<?php echo get_option('gsheet_sftp_password') ? '••••••••' : ''; ?>">
                                <p class="description"><?php esc_html_e('Leave blank to keep existing password.', 'sftp-sync-for-google-sheets'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gsheet_sftp_remote_path"><?php esc_html_e('Remote Path', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <input type="text" id="gsheet_sftp_remote_path" name="gsheet_sftp_remote_path" 
                                       value="<?php echo esc_attr(get_option('gsheet_sftp_remote_path', '/')); ?>" 
                                       class="regular-text" placeholder="/path/to/uploads/">
                                <p class="description"><?php esc_html_e('Directory where files will be uploaded. Must end with /', 'sftp-sync-for-google-sheets'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="gsheet-sftp-section">
                    <h2><?php esc_html_e('Export Settings', 'sftp-sync-for-google-sheets'); ?></h2>
                    <p><?php esc_html_e('Configure how the Google Sheet export works. These settings are embedded in the generated Apps Script.', 'sftp-sync-for-google-sheets'); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="gsheet_sftp_schedule"><?php esc_html_e('Schedule', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <select id="gsheet_sftp_schedule" name="gsheet_sftp_schedule">
                                    <option value="daily" <?php selected(get_option('gsheet_sftp_schedule', 'daily'), 'daily'); ?>><?php esc_html_e('Daily', 'sftp-sync-for-google-sheets'); ?></option>
                                    <option value="hourly" <?php selected(get_option('gsheet_sftp_schedule', 'daily'), 'hourly'); ?>><?php esc_html_e('Hourly', 'sftp-sync-for-google-sheets'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr id="daily-hour-row">
                            <th><label for="gsheet_sftp_daily_hour"><?php esc_html_e('Daily Export Hour', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <select id="gsheet_sftp_daily_hour" name="gsheet_sftp_daily_hour">
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?php echo $h; ?>" <?php selected(get_option('gsheet_sftp_daily_hour', 2), $h); ?>>
                                        <?php echo sprintf('%02d:00', $h); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Hour of day to run export (in your timezone).', 'sftp-sync-for-google-sheets'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gsheet_sftp_filename_mode"><?php esc_html_e('Filename Mode', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <select id="gsheet_sftp_filename_mode" name="gsheet_sftp_filename_mode">
                                    <option value="dated" <?php selected(get_option('gsheet_sftp_filename_mode', 'dated'), 'dated'); ?>><?php esc_html_e('Dated (new file each export)', 'sftp-sync-for-google-sheets'); ?></option>
                                    <option value="overwrite" <?php selected(get_option('gsheet_sftp_filename_mode', 'dated'), 'overwrite'); ?>><?php esc_html_e('Overwrite (same filename)', 'sftp-sync-for-google-sheets'); ?></option>
                                </select>
                                <p class="description">
                                    <strong><?php esc_html_e('Dated:', 'sftp-sync-for-google-sheets'); ?></strong> <?php esc_html_e('Creates files like sheet_export_2026-01-07_120000.csv', 'sftp-sync-for-google-sheets'); ?><br>
                                    <strong><?php esc_html_e('Overwrite:', 'sftp-sync-for-google-sheets'); ?></strong> <?php esc_html_e('Always uses the same filename, replacing the previous file', 'sftp-sync-for-google-sheets'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gsheet_sftp_base_filename"><?php esc_html_e('Base Filename', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <input type="text" id="gsheet_sftp_base_filename" name="gsheet_sftp_base_filename" 
                                       value="<?php echo esc_attr(get_option('gsheet_sftp_base_filename', 'sheet_export')); ?>" 
                                       class="regular-text" placeholder="sheet_export">
                                <p class="description"><?php esc_html_e('Base name for exported files (without extension).', 'sftp-sync-for-google-sheets'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gsheet_sftp_export_format"><?php esc_html_e('Export Format', 'sftp-sync-for-google-sheets'); ?></label></th>
                            <td>
                                <select id="gsheet_sftp_export_format" name="gsheet_sftp_export_format">
                                    <option value="csv" <?php selected(get_option('gsheet_sftp_export_format', 'csv'), 'csv'); ?>>CSV</option>
                                    <option value="xlsx" <?php selected(get_option('gsheet_sftp_export_format', 'csv'), 'xlsx'); ?>>XLSX (Excel)</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(esc_html__('Save Settings', 'sftp-sync-for-google-sheets')); ?>
            </form>
            
            
            <div class="gsheet-sftp-section">
                <h2><?php esc_html_e('Test Connection', 'sftp-sync-for-google-sheets'); ?></h2>
                <p><?php esc_html_e('Test your SFTP connection with the current settings.', 'sftp-sync-for-google-sheets'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('gsheet_sftp_test_connection'); ?>
                    <button type="submit" name="test_sftp_connection" class="button button-secondary">
                        <?php esc_html_e('Test SFTP Connection', 'sftp-sync-for-google-sheets'); ?>
                    </button>
                </form>
            </div>
            
            <div class="gsheet-sftp-section">
                <h2><?php esc_html_e('Recent Logs', 'sftp-sync-for-google-sheets'); ?></h2>
                <form method="post" style="margin-bottom: 10px;">
                    <?php wp_nonce_field('gsheet_sftp_clear_logs'); ?>
                    <button type="submit" name="clear_logs" class="button button-small">
                        <?php esc_html_e('Clear Logs', 'sftp-sync-for-google-sheets'); ?>
                    </button>
                </form>
                <div class="gsheet-sftp-logs">
                    <?php
                    $logs = SFTP_Sync_GS::get_logs(50);
                    if (empty($logs)) {
                        echo '<span class="log-info">' . esc_html__('No logs yet.', 'sftp-sync-for-google-sheets') . '</span>';
                    } else {
                        foreach ($logs as $log) {
                            $class = 'log-info';
                            if (strpos($log, '[error]') !== false) {
                                $class = 'log-error';
                            } elseif (strpos($log, '[success]') !== false) {
                                $class = 'log-success';
                            }
                            echo '<div class="' . $class . '">' . esc_html($log) . '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
            
            <div class="gsheet-sftp-section">
                <h2><?php esc_html_e('Google Apps Script', 'sftp-sync-for-google-sheets'); ?></h2>
                <p><?php esc_html_e('Copy the code below into your Google Sheet\'s Apps Script editor (Extensions → Apps Script):', 'sftp-sync-for-google-sheets'); ?></p>
                <p><a href="#" onclick="document.getElementById('apps-script-code').style.display='block'; this.style.display='none'; return false;" class="button">
                    <?php esc_html_e('Show Google Apps Script Code', 'sftp-sync-for-google-sheets'); ?>
                </a></p>
                <textarea id="apps-script-code" style="display:none; width:100%; height:400px; font-family:monospace; font-size:12px;" readonly><?php echo esc_textarea($this->get_apps_script_template()); ?></textarea>
            </div>
        </div>
        <?php
    }
    
    private function test_connection() {
        $handler = SFTP_Sync_GS_Handler::get_instance();
        $result = $handler->test_connection();
        
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
            SFTP_Sync_GS::log('SFTP connection test successful', 'success');
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
            SFTP_Sync_GS::log('SFTP connection test failed: ' . $result['message'], 'error');
        }
    }
    
    private function get_apps_script_template() {
        $endpoint_url = rest_url('gsheet-sftp/v1/upload');
        $api_key = get_option('gsheet_sftp_api_key', '');
        $schedule = get_option('gsheet_sftp_schedule', 'daily');
        $daily_hour = get_option('gsheet_sftp_daily_hour', 2);
        $filename_mode = get_option('gsheet_sftp_filename_mode', 'dated');
        $base_filename = get_option('gsheet_sftp_base_filename', 'sheet_export');
        $export_format = get_option('gsheet_sftp_export_format', 'csv');
        $include_date = $filename_mode === 'dated' ? 'true' : 'false';
        
        $script = "/**\n";
        $script .= " * Google Apps Script - Export Sheet to WordPress SFTP Plugin\n";
        $script .= " * Generated by SFTP Sync for Google Sheets Plugin\n";
        $script .= " * \n";
        $script .= " * After pasting this code:\n";
        $script .= " * 1. Save the project (Ctrl+S)\n";
        $script .= " * 2. Run setupTrigger() once to enable automatic exports\n";
        $script .= " * 3. Or use the SFTP Export menu after reloading the sheet\n";
        $script .= " */\n\n";
        $script .= "const CONFIG = {\n";
        $script .= "  ENDPOINT_URL: '" . esc_js($endpoint_url) . "',\n";
        $script .= "  API_KEY: '" . esc_js($api_key) . "',\n";
        $script .= "  EXPORT_FORMAT: '" . esc_js($export_format) . "',\n";
        $script .= "  INCLUDE_DATE: " . $include_date . ",  // false = overwrite same file each time\n";
        $script .= "  BASE_FILENAME: '" . esc_js($base_filename) . "',\n";
        $script .= "  SHEET_NAME: '',  // Leave empty for active sheet, or specify sheet name\n";
        $script .= "  SCHEDULE: '" . esc_js($schedule) . "',\n";
        $script .= "  DAILY_HOUR: " . intval($daily_hour) . ",\n";
        $script .= "};\n\n";
        $script .= "function exportAndUpload() {\n";
        $script .= "  try {\n";
        $script .= "    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();\n";
        $script .= "    let sheet = CONFIG.SHEET_NAME ? \n";
        $script .= "      spreadsheet.getSheetByName(CONFIG.SHEET_NAME) : \n";
        $script .= "      spreadsheet.getActiveSheet();\n";
        $script .= "    \n";
        $script .= "    if (!sheet) throw new Error('Sheet not found');\n";
        $script .= "    \n";
        $script .= "    const filename = generateFilename();\n";
        $script .= "    let fileContent;\n";
        $script .= "    \n";
        $script .= "    if (CONFIG.EXPORT_FORMAT === 'xlsx') {\n";
        $script .= "      fileContent = exportAsXlsx(spreadsheet);\n";
        $script .= "    } else {\n";
        $script .= "      fileContent = exportAsCsv(sheet);\n";
        $script .= "    }\n";
        $script .= "    \n";
        $script .= "    const base64Content = Utilities.base64Encode(fileContent);\n";
        $script .= "    const result = sendToEndpoint(base64Content, filename);\n";
        $script .= "    \n";
        $script .= "    Logger.log('Export completed: ' + JSON.stringify(result));\n";
        $script .= "    return result;\n";
        $script .= "    \n";
        $script .= "  } catch (error) {\n";
        $script .= "    Logger.log('Export failed: ' + error.message);\n";
        $script .= "    throw error;\n";
        $script .= "  }\n";
        $script .= "}\n\n";
        $script .= "function exportAsCsv(sheet) {\n";
        $script .= "  // Use Google's native CSV export (identical to File > Download > CSV)\n";
        $script .= "  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();\n";
        $script .= "  const sheetId = sheet.getSheetId();\n";
        $script .= "  const url = 'https://docs.google.com/spreadsheets/d/' + spreadsheet.getId()\n";
        $script .= "    + '/export?format=csv&gid=' + sheetId;\n";
        $script .= "  const response = UrlFetchApp.fetch(url, {\n";
        $script .= "    headers: { 'Authorization': 'Bearer ' + ScriptApp.getOAuthToken() }\n";
        $script .= "  });\n";
        $script .= "  return response.getContent();\n";
        $script .= "}\n\n";
        $script .= "function exportAsXlsx(spreadsheet) {\n";
        $script .= "  const url = 'https://docs.google.com/spreadsheets/d/' + spreadsheet.getId() + '/export?format=xlsx';\n";
        $script .= "  const response = UrlFetchApp.fetch(url, {\n";
        $script .= "    headers: { 'Authorization': 'Bearer ' + ScriptApp.getOAuthToken() }\n";
        $script .= "  });\n";
        $script .= "  return response.getContent();\n";
        $script .= "}\n\n";
        $script .= "function sendToEndpoint(base64Content, filename) {\n";
        $script .= "  const payload = {\n";
        $script .= "    file_content: base64Content,\n";
        $script .= "    filename: filename,\n";
        $script .= "    timestamp: new Date().toISOString()\n";
        $script .= "  };\n";
        $script .= "  \n";
        $script .= "  const response = UrlFetchApp.fetch(CONFIG.ENDPOINT_URL, {\n";
        $script .= "    method: 'post',\n";
        $script .= "    contentType: 'application/json',\n";
        $script .= "    headers: { 'X-API-Key': CONFIG.API_KEY },\n";
        $script .= "    payload: JSON.stringify(payload),\n";
        $script .= "    muteHttpExceptions: true\n";
        $script .= "  });\n";
        $script .= "  \n";
        $script .= "  if (response.getResponseCode() !== 200) {\n";
        $script .= "    throw new Error('Upload failed: ' + response.getContentText());\n";
        $script .= "  }\n";
        $script .= "  \n";
        $script .= "  return JSON.parse(response.getContentText());\n";
        $script .= "}\n\n";
        $script .= "function generateFilename() {\n";
        $script .= "  let filename = CONFIG.BASE_FILENAME;\n";
        $script .= "  if (CONFIG.INCLUDE_DATE) {\n";
        $script .= "    filename += '_' + Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd_HHmmss');\n";
        $script .= "  }\n";
        $script .= "  return filename + '.' + CONFIG.EXPORT_FORMAT;\n";
        $script .= "}\n\n";
        $script .= "function setupTrigger() {\n";
        $script .= "  removeTriggers();\n";
        $script .= "  if (CONFIG.SCHEDULE === 'hourly') {\n";
        $script .= "    ScriptApp.newTrigger('exportAndUpload').timeBased().everyHours(1).create();\n";
        $script .= "  } else {\n";
        $script .= "    ScriptApp.newTrigger('exportAndUpload').timeBased().everyDays(1).atHour(CONFIG.DAILY_HOUR).create();\n";
        $script .= "  }\n";
        $script .= "  Logger.log('Trigger created');\n";
        $script .= "}\n\n";
        $script .= "function removeTriggers() {\n";
        $script .= "  ScriptApp.getProjectTriggers().forEach(trigger => {\n";
        $script .= "    if (trigger.getHandlerFunction() === 'exportAndUpload') {\n";
        $script .= "      ScriptApp.deleteTrigger(trigger);\n";
        $script .= "    }\n";
        $script .= "  });\n";
        $script .= "}\n\n";
        $script .= "function onOpen() {\n";
        $script .= "  SpreadsheetApp.getUi().createMenu('SFTP Export')\n";
        $script .= "    .addItem('Export Now', 'exportAndUpload')\n";
        $script .= "    .addItem('Setup Daily Trigger', 'setupTrigger')\n";
        $script .= "    .addItem('Remove Triggers', 'removeTriggers')\n";
        $script .= "    .addToUi();\n";
        $script .= "}\n";
        
        return $script;
    }
}
