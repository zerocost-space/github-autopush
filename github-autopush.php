<?php
/**
 * Plugin Name: GitHub Autopush
 * Plugin URI: 
 * Description: Automatically uploads the contents of a selected folder to a GitHub repository when triggered by a specified WordPress action.
 * Version: 1.0.0
 * Author: david@zerocost.space
 * Author URI: https://zerocost.space
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: github-autopush
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GITHUB_AUTOPUSH_VERSION', '1.0.0');
define('GITHUB_AUTOPUSH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GITHUB_AUTOPUSH_PLUGIN_URL', plugins_url('', __FILE__));
define('GITHUB_AUTOPUSH_CAPABILITY', 'manage_options');
define('GITHUB_AUTOPUSH_LOG_LIMIT', 10);
define('GITHUB_AUTOPUSH_RATE_LIMIT_WAIT', 60);

// Include required classes
require_once plugin_dir_path(__FILE__) . 'includes/class-github-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';

class GitHub_Autopush {
    private static $instance = null;
    private $options;
    private $logger;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger = new GitHub_Autopush_Logger();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'show_error_messages'));
        add_action('wp_ajax_clear_github_autopush_logs', array($this, 'clear_logs_ajax'));
        add_action('wp_ajax_get_github_autopush_logs', array($this, 'get_logs_ajax'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        
        // Load plugin settings
        $this->options = get_option('github_autopush_settings');
        
        // Initialize GitHub integration if settings are configured
        if ($this->are_settings_configured()) {
            $this->init_github_integration();
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('GitHub Autopush Settings', 'github-autopush'),
            __('GitHub Autopush', 'github-autopush'),
            'manage_options',
            'github-autopush',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings() {
        register_setting('github_autopush_settings', 'github_autopush_settings', array(
            'sanitize_callback' => array($this, 'validate_settings')
        ));

        add_settings_section(
            'github_autopush_main_section',
            __('GitHub Settings', 'github-autopush'),
            null,
            'github-autopush'
        );

        add_settings_field(
            'github_token',
            __('GitHub Personal Access Token', 'github-autopush'),
            array($this, 'render_token_field'),
            'github-autopush',
            'github_autopush_main_section'
        );

        add_settings_field(
            'github_repository',
            __('GitHub Repository (format: username/repository)', 'github-autopush'),
            array($this, 'render_repository_field'),
            'github-autopush',
            'github_autopush_main_section'
        );

        add_settings_field(
            'source_folder',
            __('Source Folder', 'github-autopush'),
            array($this, 'render_folder_field'),
            'github-autopush',
            'github_autopush_main_section'
        );

        add_settings_field(
            'trigger_action',
            __('Trigger Action Hook', 'github-autopush'),
            array($this, 'render_action_field'),
            'github-autopush',
            'github_autopush_main_section'
        );

        add_settings_field(
            'trigger_param',
            __('Trigger Action Parameter', 'github-autopush'),
            array($this, 'render_param_field'),
            'github-autopush',
            'github_autopush_main_section'
        );


    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Add error/update messages
        settings_errors('github_autopush_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
            <?php
                settings_fields('github_autopush_settings');
                do_settings_sections('github-autopush');
                submit_button(__('Save', 'github-autopush'));
            ?>
            </form>

            <?php
            // Log bejegyzések megjelenítése
            $logs = $this->logger->get_last_logs(10);
            if (!empty($logs)) :
            ?>
            <div class="github-autopush-logs">
                <h2><?php _e('Recent Log Entries', 'github-autopush'); ?></h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Time', 'github-autopush'); ?></th>
                            <th><?php _e('Level', 'github-autopush'); ?></th>
                            <th><?php _e('Message', 'github-autopush'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td><?php echo esc_html($log['level']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($logs)) : ?>
            <div class="clear-logs-button-wrapper" style="margin-top: 15px;">
                <button type="button" id="clear-logs-button" class="button button-secondary">
                    <?php _e('Clear Logs', 'github-autopush'); ?>
                </button>
            </div>
            <?php endif; ?>
            
            <?php
    }

    public function enqueue_admin_scripts($hook) {
        if ('settings_page_github-autopush' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'github-autopush-admin',
            plugins_url('js/admin.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('github-autopush-admin', 'githubAutopush', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('github_autopush_logs'),
            'clearLogsConfirm' => __('Are you sure you want to clear all logs?', 'github-autopush'),
            'clearLogsSuccess' => __('Logs cleared successfully.', 'github-autopush'),
            'clearLogsError' => __('Error clearing logs.', 'github-autopush')
        ));
    }

    /**
     * Clear logs via AJAX request
     *
     * @return void
     */
    public function clear_logs_ajax() {
        // Verify nonce and capabilities
        check_ajax_referer('github_autopush_logs', 'nonce');
        
        if (!current_user_can(GITHUB_AUTOPUSH_CAPABILITY)) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'github-autopush'),
                'code' => 'insufficient_permissions'
            ));            
        }

        $result = $this->logger->clear_logs();
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error(__('Failed to clear logs.', 'github-autopush'));
        }
    }

    /**
     * Get logs via AJAX request
     *
     * @return void
     */
    public function get_logs_ajax() {
        // Verify nonce and capabilities
        check_ajax_referer('github_autopush_logs', 'nonce');

        if (!current_user_can(GITHUB_AUTOPUSH_CAPABILITY)) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'github-autopush'),
                'code' => 'insufficient_permissions'
            ));
        }

        $logs = $this->logger->get_last_logs(10);
        $html = '';
        
        foreach ($logs as $log) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html($log['timestamp']) . '</td>';
            $html .= '<td>' . esc_html($log['level']) . '</td>';
            $html .= '<td>' . esc_html($log['message']) . '</td>';
            $html .= '</tr>';
        }

        wp_send_json_success($html);
    }

    public function render_token_field() {
        $value = isset($this->options['github_token']) ? $this->options['github_token'] : '';
        echo '<input type="password" id="github_token" name="github_autopush_settings[github_token]" value="' . esc_attr($value) . '" class="regular-text" required>';
        echo '<p class="description">' . __('Enter your GitHub Personal Access Token for repository access', 'github-autopush') . '</p>';
    }

    public function render_repository_field() {
        $value = isset($this->options['github_repository']) ? $this->options['github_repository'] : '';
        echo '<input type="text" id="github_repository" name="github_autopush_settings[github_repository]" value="' . esc_attr($value) . '" class="regular-text" required>';
        echo '<p class="description">' . __('Example: username/repository-name', 'github-autopush') . '</p>';
    }

    public function render_folder_field() {
        $value = isset($this->options['source_folder']) ? $this->options['source_folder'] : '';
        echo '<input type="text" id="source_folder" name="github_autopush_settings[source_folder]" value="' . esc_attr($value) . '" class="regular-text" required>';
        echo '<p class="description">' . __('The absolute path of the folder to upload (e.g., /www/html/public_static)', 'github-autopush') . '</p>';
    }

    public function render_action_field() {
        $value = isset($this->options['trigger_action']) ? $this->options['trigger_action'] : '';
        echo '<input type="text" id="trigger_action" name="github_autopush_settings[trigger_action]" value="' . esc_attr($value) . '" class="regular-text" required>';
        echo '<p class="description">' . __('The name of the WordPress action hook that triggers the upload', 'github-autopush') . '</p>';
    }

    public function render_param_field() {
        $value = isset($this->options['trigger_param']) ? $this->options['trigger_param'] : '';
        echo '<input type="text" id="trigger_param" name="github_autopush_settings[trigger_param]" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">' . __('The parameter value that must be present in the action to trigger the upload (optional)', 'github-autopush') . '</p>';
    }



    /**
     * Validate and sanitize settings
     *
     * @param array $input The input array to validate
     * @return array The sanitized input array
     */
    public function validate_settings($input) {
        $sanitized_input = array();
        $required_fields = array(
            'github_token' => __('GitHub Personal Access Token', 'github-autopush'),
            'github_repository' => __('GitHub Repository', 'github-autopush'),
            'source_folder' => __('Source Folder', 'github-autopush'),
            'trigger_action' => __('Trigger Action Hook', 'github-autopush')
        );

        foreach ($required_fields as $field => $label) {
            if (empty($input[$field])) {
                add_settings_error(
                    'github_autopush_messages',
                    'required_field_' . $field,
                    sprintf(__('%s is required.', 'github-autopush'), $label),
                    'error'
                );
                $sanitized_input[$field] = isset($this->options[$field]) ? $this->options[$field] : '';
                continue;
            }

            // Sanitize each field based on its type
            switch ($field) {
                case 'github_token':
                    $sanitized_input[$field] = sanitize_text_field($input[$field]);
                    break;
                case 'github_repository':
                    $repo = sanitize_text_field($input[$field]);
                    if (!preg_match('/^[\w-]+\/[\w-]+$/', $repo)) {
                        add_settings_error(
                            'github_autopush_messages',
                            'invalid_repository',
                            __('Invalid repository format. Please use format: username/repository', 'github-autopush'),
                            'error'
                        );
                        $sanitized_input[$field] = isset($this->options[$field]) ? $this->options[$field] : '';
                    } else {
                        $sanitized_input[$field] = $repo;
                    }
                    break;
                case 'source_folder':
                    $folder = untrailingslashit(sanitize_text_field($input[$field]));
                    if (!is_dir($folder)) { // Remove ABSPATH concatenation
                        add_settings_error(
                            'github_autopush_messages',
                            'invalid_folder',
                            __('Source folder does not exist.', 'github-autopush'),
                            'error'
                        );
                        $sanitized_input[$field] = isset($this->options[$field]) ? $this->options[$field] : '';
                    } else {
                        $sanitized_input[$field] = $folder;
                    }
                    break;
                case 'trigger_action':
                    $sanitized_input[$field] = sanitize_key($input[$field]);
                    break;
                default:
                    $sanitized_input[$field] = sanitize_text_field($input[$field]);
            }
        }

        // Sanitize optional trigger parameter
        if (isset($input['trigger_param'])) {
            $sanitized_input['trigger_param'] = sanitize_text_field($input['trigger_param']);
        }



        return $input;
    }

    public function show_error_messages() {
        settings_errors('github_autopush_messages');
    }

    private function are_settings_configured() {
        return !empty($this->options['github_token']) &&
               !empty($this->options['github_repository']) &&
               !empty($this->options['source_folder']) &&
               !empty($this->options['trigger_action']);
    }

    private function init_github_integration() {
        // Add the action hook for GitHub push with parameter check
        add_action($this->options['trigger_action'], array($this, 'handle_action'), 10, 10);
    }

    public function handle_action() {
        // Get all arguments passed to the action
        $args = func_get_args();
        
        // Check if a trigger parameter is set and if it matches any of the arguments
        if (!empty($this->options['trigger_param'])) {
            $param_found = false;
            foreach ($args as $arg) {
                if (is_scalar($arg) && $arg == $this->options['trigger_param']) {
                    $param_found = true;
                    break;
                }
            }
            
            if (!$param_found) {
                $this->logger->log('Action triggered but parameter did not match', 'info');
                return false;
            }
        }
        
        return $this->push_to_github();
    }

    /**
     * Push files to GitHub repository
     *
     * @return bool True on success, false on failure
     */
    private function push_to_github() {
        if (!$this->are_settings_configured()) {
            $this->logger->log('Settings are not properly configured', 'error');
            return false;
        }

        $this->logger->log('Starting GitHub push operation');

        try {
            $github_api = new GitHub_API(
                $this->options['github_token'],
                $this->options['github_repository']
            );

            // Check for rate limit before making API calls
            if ($github_api->is_rate_limited()) {
                $wait_time = $github_api->get_rate_limit_reset_time();
                if ($wait_time > GITHUB_AUTOPUSH_RATE_LIMIT_WAIT) {
                    $this->logger->log(
                        sprintf(
                            __('Rate limit exceeded. Please wait %d minutes before trying again.', 'github-autopush'),
                            ceil($wait_time / 60)
                        ),
                        'error'
                    );
                    return false;
                }
                // Wait for rate limit to reset if it's within acceptable time
                sleep($wait_time);
            }

            // Validate source folder exists and is readable
            $source_path = $this->options['source_folder']; // Remove ABSPATH concatenation
            if (!is_readable($source_path)) {
                throw new Exception(__('Source folder is not readable', 'github-autopush'));
            }

            $result = $github_api->push_files($this->options['source_folder']);

            if ($result) {
                $this->logger->log('GitHub push operation completed successfully');
                do_action('github_autopush_success');
                return true;
            } else {
                $error_message = $github_api->get_last_error();
                $this->logger->log('GitHub push operation failed. Error: ' . $error_message, 'error');
                do_action('github_autopush_failed', $error_message);
                return false;
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->logger->log('GitHub push error: ' . $error_message, 'error');
            do_action('github_autopush_failed', $error_message);
            return false;
        }
    }
}

// Initialize the plugin
GitHub_Autopush::get_instance();