<?php
if (!defined('ABSPATH')) {
    exit;
}

class GSheet_SFTP_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get_config() {
        return [
            'host' => get_option('gsheet_sftp_host', ''),
            'port' => (int) get_option('gsheet_sftp_port', 22),
            'username' => get_option('gsheet_sftp_username', ''),
            'password' => GSheet_SFTP_Admin_Settings::get_password(),
            'remote_path' => get_option('gsheet_sftp_remote_path', '/'),
        ];
    }
    
    public function upload_file($file_content, $filename) {
        $config = $this->get_config();
        
        // Validate config
        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            return [
                'success' => false,
                'error' => 'SFTP configuration incomplete. Please check plugin settings.'
            ];
        }
        
        // Sanitize filename
        $filename = $this->sanitize_filename($filename);
        
        // Validate file type
        $allowed_extensions = ['csv', 'xlsx', 'xls'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            return [
                'success' => false,
                'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowed_extensions)
            ];
        }
        
        // Ensure remote path ends with /
        $remote_path = rtrim($config['remote_path'], '/') . '/';
        
        // Try SSH2 extension first, then phpseclib
        if (function_exists('ssh2_connect')) {
            return $this->upload_via_ssh2($file_content, $filename, $config, $remote_path);
        }
        
        return $this->upload_via_phpseclib($file_content, $filename, $config, $remote_path);
    }
    
    private function upload_via_ssh2($file_content, $filename, $config, $remote_path) {
        $connection = @ssh2_connect($config['host'], $config['port']);
        
        if (!$connection) {
            return [
                'success' => false,
                'error' => 'Could not connect to SFTP server at ' . $config['host'] . ':' . $config['port']
            ];
        }
        
        if (!@ssh2_auth_password($connection, $config['username'], $config['password'])) {
            return [
                'success' => false,
                'error' => 'SFTP authentication failed for user: ' . $config['username']
            ];
        }
        
        $sftp = @ssh2_sftp($connection);
        if (!$sftp) {
            return [
                'success' => false,
                'error' => 'Could not initialize SFTP subsystem'
            ];
        }
        
        $remote_file = "ssh2.sftp://{$sftp}" . $remote_path . $filename;
        
        if (@file_put_contents($remote_file, $file_content) === false) {
            return [
                'success' => false,
                'error' => 'Failed to write file to SFTP. Check remote path permissions.'
            ];
        }
        
        return [
            'success' => true,
            'filename' => $filename,
            'remote_path' => $remote_path . $filename,
            'method' => 'ssh2'
        ];
    }
    
    private function upload_via_phpseclib($file_content, $filename, $config, $remote_path) {
        // Try to load phpseclib
        $autoload_paths = [
            GSHEET_SFTP_SYNC_PLUGIN_DIR . 'vendor/autoload.php',
            ABSPATH . 'vendor/autoload.php',
            WP_CONTENT_DIR . '/vendor/autoload.php',
        ];
        
        foreach ($autoload_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
        
        // Try phpseclib3
        if (class_exists('phpseclib3\Net\SFTP')) {
            try {
                $sftp = new \phpseclib3\Net\SFTP($config['host'], $config['port'], 30);
                
                if (!$sftp->login($config['username'], $config['password'])) {
                    $errors = $sftp->getErrors();
                    $lastError = !empty($errors) ? end($errors) : 'Unknown error';
                    return [
                        'success' => false,
                        'error' => 'SFTP authentication failed: ' . $lastError . ' (Host: ' . $config['host'] . ':' . $config['port'] . ', User: ' . $config['username'] . ')'
                    ];
                }
                
                if (!$sftp->put($remote_path . $filename, $file_content)) {
                    return [
                        'success' => false,
                        'error' => 'Failed to write file (phpseclib3)'
                    ];
                }
                
                return [
                    'success' => true,
                    'filename' => $filename,
                    'remote_path' => $remote_path . $filename,
                    'method' => 'phpseclib3'
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => 'phpseclib3 error: ' . $e->getMessage()
                ];
            }
        }
        
        // Try phpseclib 2.x
        if (class_exists('phpseclib\Net\SFTP')) {
            try {
                $sftp = new \phpseclib\Net\SFTP($config['host'], $config['port']);
                
                if (!$sftp->login($config['username'], $config['password'])) {
                    return [
                        'success' => false,
                        'error' => 'SFTP authentication failed (phpseclib)'
                    ];
                }
                
                if (!$sftp->put($remote_path . $filename, $file_content)) {
                    return [
                        'success' => false,
                        'error' => 'Failed to write file (phpseclib)'
                    ];
                }
                
                return [
                    'success' => true,
                    'filename' => $filename,
                    'remote_path' => $remote_path . $filename,
                    'method' => 'phpseclib'
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => 'phpseclib error: ' . $e->getMessage()
                ];
            }
        }
        
        return [
            'success' => false,
            'error' => 'No SFTP library available. Please install ssh2 PHP extension or phpseclib via Composer.'
        ];
    }
    
    public function test_connection() {
        $config = $this->get_config();
        
        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            return [
                'success' => false,
                'message' => 'SFTP configuration incomplete. Please fill in all fields.'
            ];
        }
        
        // Try SSH2 extension
        if (function_exists('ssh2_connect')) {
            $connection = @ssh2_connect($config['host'], $config['port']);
            
            if (!$connection) {
                return [
                    'success' => false,
                    'message' => 'Could not connect to ' . $config['host'] . ':' . $config['port']
                ];
            }
            
            if (!@ssh2_auth_password($connection, $config['username'], $config['password'])) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed for user: ' . $config['username']
                ];
            }
            
            $sftp = @ssh2_sftp($connection);
            if (!$sftp) {
                return [
                    'success' => false,
                    'message' => 'Could not initialize SFTP subsystem'
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Connection successful! (using ssh2 extension)'
            ];
        }
        
        // Try phpseclib
        $autoload_paths = [
            GSHEET_SFTP_SYNC_PLUGIN_DIR . 'vendor/autoload.php',
            ABSPATH . 'vendor/autoload.php',
        ];
        
        foreach ($autoload_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
        
        if (class_exists('phpseclib3\Net\SFTP')) {
            try {
                $sftp = new \phpseclib3\Net\SFTP($config['host'], $config['port']);
                
                if (!$sftp->login($config['username'], $config['password'])) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed (phpseclib3)'
                    ];
                }
                
                return [
                    'success' => true,
                    'message' => 'Connection successful! (using phpseclib3)'
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Connection error: ' . $e->getMessage()
                ];
            }
        }
        
        if (class_exists('phpseclib\Net\SFTP')) {
            try {
                $sftp = new \phpseclib\Net\SFTP($config['host'], $config['port']);
                
                if (!$sftp->login($config['username'], $config['password'])) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed (phpseclib)'
                    ];
                }
                
                return [
                    'success' => true,
                    'message' => 'Connection successful! (using phpseclib)'
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Connection error: ' . $e->getMessage()
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'No SFTP library available. Install ssh2 PHP extension or run: composer require phpseclib/phpseclib:~3.0'
        ];
    }
    
    private function sanitize_filename($filename) {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $filename);
        $filename = preg_replace('/\.+/', '.', $filename);
        return $filename;
    }
}
