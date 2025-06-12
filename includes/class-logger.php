<?php
/**
 * Logger class for GitHub Autopush plugin
 *
 * @package GitHub_Autopush
 */

if (!defined('ABSPATH')) {
    exit;
}

class GitHub_Autopush_Logger {
    private $log_dir;
    private $is_logging_enabled;
    private $log_file;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/github-autopush';
        $this->is_logging_enabled = get_option('github_autopush_logging_enabled', true);
        
        // Create logs directory if it doesn't exist
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
        
        // Add .htaccess to protect log files
        $htaccess_file = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Deny from all\n");
        }

        // Use a single log file for all sessions
        $this->log_file = $this->log_dir . '/github-autopush.log';
    }

    public function log($message, $level = 'debug') {
        if (!$this->is_logging_enabled) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            $timestamp,
            strtoupper($level),
            $message
        );

        // Append log entry to the file
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }

    public function is_logging_enabled() {
        return $this->is_logging_enabled;
    }

    public function set_logging_enabled($enabled) {
        $this->is_logging_enabled = $enabled;
        update_option('github_autopush_logging_enabled', $enabled);
    }

    public function get_last_logs($limit = 10) {
        if (!file_exists($this->log_file)) {
            return array();
        }

        $logs = array();
        $lines = array_filter(array_map('trim', file($this->log_file)));
        $lines = array_reverse($lines);

        foreach ($lines as $index => $line) {
            if ($index >= $limit) {
                break;
            }

            if (preg_match('/\[(.*?)\] \[(.*?)\] (.*)/', $line, $matches)) {
                $logs[] = array(
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => $matches[3]
                );
            }
        }

        return $logs;
    }

    public function clear_logs() {
        if (file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
            $this->log('Log file cleared', 'info');
            return true;
        }
        return false;
    }
}