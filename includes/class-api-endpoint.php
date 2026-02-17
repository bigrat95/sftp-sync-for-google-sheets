<?php
if (!defined('ABSPATH')) {
    exit;
}

class GSheet_SFTP_API_Endpoint {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function register_routes() {
        register_rest_route('gsheet-sftp/v1', '/upload', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_upload'],
            'permission_callback' => [$this, 'verify_api_key'],
        ]);
        
        register_rest_route('gsheet-sftp/v1', '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_status'],
            'permission_callback' => [$this, 'verify_api_key'],
        ]);
    }
    
    public function verify_api_key($request) {
        // Rate limiting: 60 requests per minute per IP
        if (!$this->check_rate_limit()) {
            GSheet_SFTP_Sync::log('Rate limit exceeded from ' . $this->get_client_ip(), 'error');
            return new WP_Error('rate_limit_exceeded', 'Too many requests. Please try again later.', ['status' => 429]);
        }
        
        $stored_key = get_option('gsheet_sftp_api_key', '');
        
        if (empty($stored_key)) {
            GSheet_SFTP_Sync::log('API key not configured', 'error');
            return false;
        }
        
        // Check header first
        $received_key = $request->get_header('X-API-Key');
        
        // Fallback to body parameter
        if (empty($received_key)) {
            $body = $request->get_json_params();
            $received_key = $body['api_key'] ?? '';
        }
        
        // Fallback to POST parameter
        if (empty($received_key)) {
            $received_key = $request->get_param('api_key');
        }
        
        if (empty($received_key)) {
            GSheet_SFTP_Sync::log('No API key provided in request from ' . $_SERVER['REMOTE_ADDR'], 'error');
            return false;
        }
        
        if (!hash_equals($stored_key, $received_key)) {
            GSheet_SFTP_Sync::log('Invalid API key from ' . $_SERVER['REMOTE_ADDR'], 'error');
            return false;
        }
        
        return true;
    }
    
    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    private function check_rate_limit() {
        $ip = $this->get_client_ip();
        $transient_key = 'gsheet_sftp_rate_' . md5($ip);
        $requests = get_transient($transient_key);
        
        if ($requests === false) {
            set_transient($transient_key, 1, 60);
            return true;
        }
        
        if ($requests >= 60) {
            return false;
        }
        
        set_transient($transient_key, $requests + 1, 60);
        return true;
    }
    
    public function handle_upload($request) {
        $start_time = microtime(true);
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        GSheet_SFTP_Sync::log('Upload request received from ' . $client_ip, 'info');
        
        // Get file content
        $body = $request->get_json_params();
        $file_content = null;
        $filename = null;
        
        // Check for base64 encoded content in JSON body
        if (!empty($body['file_content'])) {
            $file_content = base64_decode($body['file_content']);
            $filename = $body['filename'] ?? 'export_' . date('Y-m-d_His') . '.csv';
            
            if ($file_content === false) {
                GSheet_SFTP_Sync::log('Failed to decode base64 file content', 'error');
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Invalid base64 file content'
                ], 400);
            }
        }
        
        // Check for multipart file upload
        if (empty($file_content) && !empty($_FILES['file'])) {
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                GSheet_SFTP_Sync::log('File upload error: ' . $_FILES['file']['error'], 'error');
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'File upload error: ' . $_FILES['file']['error']
                ], 400);
            }
            
            $file_content = file_get_contents($_FILES['file']['tmp_name']);
            $filename = $_FILES['file']['name'] ?? 'export_' . date('Y-m-d_His') . '.csv';
        }
        
        if (empty($file_content)) {
            GSheet_SFTP_Sync::log('No file content provided', 'error');
            return new WP_REST_Response([
                'success' => false,
                'error' => 'No file content provided'
            ], 400);
        }
        
        // Upload to SFTP
        $handler = GSheet_SFTP_Handler::get_instance();
        $result = $handler->upload_file($file_content, $filename);
        
        $duration = round((microtime(true) - $start_time) * 1000);
        
        if ($result['success']) {
            GSheet_SFTP_Sync::log(
                "File uploaded successfully: {$result['filename']} to {$result['remote_path']} ({$duration}ms, {$result['method']})",
                'success'
            );
            
            return new WP_REST_Response([
                'success' => true,
                'message' => 'File uploaded successfully',
                'filename' => $result['filename'],
                'remote_path' => $result['remote_path'],
                'timestamp' => current_time('c'),
                'duration_ms' => $duration
            ], 200);
        } else {
            GSheet_SFTP_Sync::log('Upload failed: ' . $result['error'], 'error');
            
            return new WP_REST_Response([
                'success' => false,
                'error' => $result['error']
            ], 500);
        }
    }
    
    public function handle_status($request) {
        $handler = GSheet_SFTP_Handler::get_instance();
        $config = $handler->get_config();
        
        return new WP_REST_Response([
            'status' => 'active',
            'plugin_version' => GSHEET_SFTP_SYNC_VERSION,
            'sftp_configured' => !empty($config['host']) && !empty($config['username']),
            'timestamp' => current_time('c')
        ], 200);
    }
}
