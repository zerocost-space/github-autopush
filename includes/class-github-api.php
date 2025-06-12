<?php

if (!defined('ABSPATH')) {
    exit;
}

class GitHub_API {
    private $token;
    private $repository;
    private $api_base = 'https://api.github.com';
    private $last_error = '';
    private $default_branch = 'master'; // Default branch

    public function __construct($token, $repository) {
        $this->token = $token;
        $this->repository = $repository;
    }

    public function push_files($source_path) {
        try {
            // Get the current commit SHA
            $master_sha = $this->get_default_branch_sha();
            $base_tree_sha = $this->get_base_tree_sha($master_sha);

            // Create blobs and get tree data
            $tree_data = $this->create_tree_data($source_path);

            // Create new tree
            $new_tree_sha = $this->create_tree($tree_data, $base_tree_sha);

            // Create new commit
            $new_commit_sha = $this->create_commit(
                $new_tree_sha,
                $master_sha,
                'Automated push from WordPress'
            );

            // Update master reference
            $this->update_master_reference($new_commit_sha);

            return true;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            error_log('GitHub Autopush Error: ' . $this->last_error);
            return false;
        }
    }

    private function get_default_branch_sha() {

        try {
            return $this->get_branch_sha('master');
        } catch (Exception $e) {
            
            try {
                $this->default_branch = 'main';
                return $this->get_branch_sha('main');
            } catch (Exception $e2) {
                throw new Exception('Failed to find default branch (tried both master and main): ' . $e2->getMessage());
            }
        }
    }

    private function get_branch_sha($branch) {
        $url = $this->api_base . '/repos/' . $this->repository . '/git/refs/heads/' . $branch;
        $response = wp_remote_get(
            $url,
            array(
                'headers' => $this->get_headers()
            )
        );

        if (is_wp_error($response)) {
            $error_msg = 'Failed to get ' . $branch . ' SHA: ' . $response->get_error_message();
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        
        if ($status !== 200) {
            $error_msg = 'Failed to get ' . $branch . ' SHA. Status: ' . $status . ' Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $data = json_decode($body);
        if (!isset($data->object->sha)) {
            $error_msg = 'Invalid response format when getting ' . $branch . ' SHA. Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg);
            throw new Exception($error_msg);
        }

        error_log('GitHub Autopush Success: Got ' . $branch . ' SHA ' . $data->object->sha);
        return $data->object->sha;
    }

    private function get_base_tree_sha($commit_sha) {
        $response = wp_remote_get(
            $this->api_base . '/repos/' . $this->repository . '/git/commits/' . $commit_sha,
            array(
                'headers' => $this->get_headers()
            )
        );

        if (is_wp_error($response)) {
            throw new Exception('Failed to get base tree SHA: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        return $body->tree->sha;
    }

    private function create_tree_data($source_path) {
        $tree_data = array();
        $base_path = $source_path;

        if (!is_dir($base_path)) {
            throw new Exception('Source directory not found: ' . $base_path);
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $relative_path = str_replace($base_path, '', $file->getPathname());
                $relative_path = str_replace('\\', '/', $relative_path);
                $relative_path = ltrim($relative_path, '/');
                
                $blob_sha = $this->create_blob(file_get_contents($file->getPathname()));
                
                $tree_data[] = array(
                    'path' => $relative_path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => $blob_sha
                );
            }
        }

        return $tree_data;
    }

    private function create_blob($content) {
        $url = $this->api_base . '/repos/' . $this->repository . '/git/blobs';
        $response = wp_remote_post(
            $url,
            array(
                'headers' => $this->get_headers(),
                'body' => json_encode(array(
                    'content' => base64_encode($content),
                    'encoding' => 'base64'
                ))
            )
        );

        if (is_wp_error($response)) {
            $error_msg = 'Failed to create blob: ' . $response->get_error_message();
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 201) {
            $error_msg = 'Failed to create blob. Status: ' . $status . ' Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $data = json_decode($body);
        if (!isset($data->sha)) {
            $error_msg = 'Invalid response format when creating blob. Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg);
            throw new Exception($error_msg);
        }

        error_log('GitHub Autopush Success: Created blob ' . $data->sha);
        return $data->sha;
    }

    private function create_tree($tree_data, $base_tree_sha) {
        $url = $this->api_base . '/repos/' . $this->repository . '/git/trees';
        $response = wp_remote_post(
            $url,
            array(
                'headers' => $this->get_headers(),
                'body' => json_encode(array(
                    'base_tree' => $base_tree_sha,
                    'tree' => $tree_data
                ))
            )
        );

        if (is_wp_error($response)) {
            $error_msg = 'Failed to create tree: ' . $response->get_error_message();
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 201) {
            $error_msg = 'Failed to create tree. Status: ' . $status . ' Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $data = json_decode($body);
        if (!isset($data->sha)) {
            $error_msg = 'Invalid response format when creating tree. Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg);
            throw new Exception($error_msg);
        }

        error_log('GitHub Autopush Success: Created tree ' . $data->sha);
        return $data->sha;
    }

    private function create_commit($tree_sha, $parent_sha, $message) {
        $url = $this->api_base . '/repos/' . $this->repository . '/git/commits';
        $response = wp_remote_post(
            $url,
            array(
                'headers' => $this->get_headers(),
                'body' => json_encode(array(
                    'message' => $message,
                    'tree' => $tree_sha,
                    'parents' => array($parent_sha)
                ))
            )
        );

        if (is_wp_error($response)) {
            $error_msg = 'Failed to create commit: ' . $response->get_error_message();
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 201) {
            $error_msg = 'Failed to create commit. Status: ' . $status . ' Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $data = json_decode($body);
        if (!isset($data->sha)) {
            $error_msg = 'Invalid response format when creating commit. Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg);
            throw new Exception($error_msg);
        }

        error_log('GitHub Autopush Success: Created commit ' . $data->sha);
        return $data->sha;
    }

    private function update_master_reference($commit_sha) {
        $url = $this->api_base . '/repos/' . $this->repository . '/git/refs/heads/' . $this->default_branch;
        $response = wp_remote_post(
            $url,
            array(
                'headers' => $this->get_headers(),
                'body' => json_encode(array(
                    'sha' => $commit_sha,
                    'force' => true
                ))
            )
        );

        if (is_wp_error($response)) {
            $error_msg = 'Failed to update master reference: ' . $response->get_error_message();
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            $error_msg = 'Failed to update master reference. Status: ' . $status . ' Response: ' . $body;
            error_log('GitHub Autopush Error: ' . $error_msg . ' URL: ' . $url);
            throw new Exception($error_msg);
        }

        error_log('GitHub Autopush Success: Updated ' . $this->default_branch . ' reference to ' . $commit_sha);
        return true;
    }

    private function get_headers() {
        return array(
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/GitHub-Autopush'
        );
    }

    public function get_last_error() {
        return $this->last_error;
    }

    public function is_rate_limited() {
        $url = $this->api_base . '/rate_limit';
        $response = wp_remote_get($url, array('headers' => $this->get_headers()));
        if (is_wp_error($response)) {
            return false; // Ha nem tudja lekérni, inkább engedje tovább
        }
        $body = json_decode(wp_remote_retrieve_body($response));
        if (!isset($body->resources->core)) {
            return false;
        }
        $remaining = $body->resources->core->remaining;
        return $remaining <= 0;
    }
}