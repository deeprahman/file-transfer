<?php
/**
 * 1. Initialization
   - Define parameters: file_path, chunk_size, manifest_path, https_endpoint
   - Initialize or retrieve the manifest

2. Handle AJAX Request
   - Check nonce for security
   - Determine current step from AJAX request

3. Process Steps
   - if step == 'init':
     - Initialize the manifest
     - Send response with next_step = 'create_manifest'
   - if step == 'create_manifest':
     - Create manifest listing all files
     - Send response with next_step = 'create_chunk'
   - if step == 'create_chunk':
     - Read chunk of file and store temporarily
     - Update manifest with chunk information
     - Send response with next_step = 'transfer_chunk'
   - if step == 'transfer_chunk':
     - Send chunk over HTTPS
     - Update manifest with progress
     - Send response with next_step = 'create_chunk' or 'finalize' if all chunks are done
   - if step == 'finalize':
     - Complete transfer and clean up temporary files
     - Send response with progress = 100 and next_step = false

4. Update Progress
   - Update manifest with current progress
   - Send response back to client with progress and next_step
 */

class ChunkUploaderAjaxHandler {
    private $file_path;
    private $chunk_size;
    private $https_endpoint;
    private $max_retries;
    private $manifest_path;
    private $action;

    public function __construct($file_path, $chunk_size, $https_endpoint, $manifest_path, $action, $max_retries = 3) {
        $this->file_path = $file_path;
        $this->chunk_size = $chunk_size;
        $this->https_endpoint = $https_endpoint;
        $this->max_retries = $max_retries;
        $this->manifest_path = $manifest_path;
        $this->action = $action;
        $this->init_manifest();
    }

    private function init_manifest() {
        if (!file_exists($this->manifest_path)) {
            $manifest = [
                'file_path' => $this->file_path,
                'chunk_size' => $this->chunk_size,
                'last_transmitted_chunk' => 0,
                'last_offset' => 0
            ];
            file_put_contents($this->manifest_path, json_encode($manifest));
        }
    }

    private function update_manifest($chunk_index, $offset) {
        $manifest = json_decode(file_get_contents($this->manifest_path), true);
        $manifest['last_transmitted_chunk'] = $chunk_index;
        $manifest['last_offset'] = $offset;
        file_put_contents($this->manifest_path, json_encode($manifest));
    }

    public function handle_ajax_request() {
        check_ajax_referer($this->action, 'nonce');

        $step = $_POST['step'] ?? 'init';
        $result = [];

        switch ($step) {
            case 'init':
                $result = $this->init_transfer();
                break;

            case 'create_manifest':
                $result = $this->create_manifest();
                break;

            case 'create_chunk':
                $result = $this->create_chunk();
                break;

            case 'transfer_chunk':
                $result = $this->transfer_chunk();
                break;

            case 'finalize':
                $result = $this->finalize_transfer();
                break;
        }

        wp_send_json_success($result);
    }

    private function init_transfer() {
        $this->init_manifest();
        return [
            'progress' => 0,
            'message' => 'Initialization complete. Starting manifest creation...',
            'next_step' => 'create_manifest'
        ];
    }

    private function create_manifest() {
        $manifest = get_transient('wbt_manifest');
        
        if (!$manifest) {
            $manifest = [
                'files' => [],
                'chunks' => [],
                'current_file' => 0,
                'current_offset' => 0,
                'total_size' => 0
            ];
            
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(WP_CONTENT_DIR),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $manifest['files'][] = $file->getPathname();
                    $manifest['total_size'] += $file->getSize();
                }
            }
            
            set_transient('wbt_manifest', $manifest, DAY_IN_SECONDS);
        }
        
        return [
            'progress' => 0,
            'message' => 'Manifest creation complete. Starting chunk creation...',
            'next_step' => 'create_chunk'
        ];
    }

    private function create_chunk() {
        $manifest = get_transient('wbt_manifest');
        $file = $manifest['files'][$manifest['current_file']];
        $handle = fopen($file, 'rb');
        
        fseek($handle, $manifest['current_offset']);
        $data = fread($handle, $this->chunk_size);
        fclose($handle);
        
        $chunk_name = 'chunk_' . md5($file) . '_' . $manifest['current_offset'];
        file_put_contents($this->tmp_dir . $chunk_name, $data);
        
        // Update manifest
        $manifest['chunks'][] = [
            'source' => $file,
            'offset' => $manifest['current_offset'],
            'size' => strlen($data),
            'hash' => hash('sha256', $data),
            'chunk_file' => $chunk_name
        ];
        
        $manifest['current_offset'] += strlen($data);
        
        if (feof($handle)) {
            $manifest['current_file']++;
            $manifest['current_offset'] = 0;
        }
        
        $progress = ($manifest['current_file'] / count($manifest['files'])) * 100;
        set_transient('wbt_manifest', $manifest, DAY_IN_SECONDS);
        
        return [
            'progress' => min(99, round($progress, 2)),
            'message' => sprintf('Processing %s...', basename($file)),
            'next_step' => ($manifest['current_file'] < count($manifest['files'])) ? 'create_chunk' : 'transfer_chunk'
        ];
    }

    private function transfer_chunk() {
        $manifest = get_transient('wbt_manifest');
        $chunk = array_shift($manifest['chunks']);
        
        $retry_count = 0;
        $ch = $this->init_curl();

        while ($retry_count < $this->max_retries) {
            if ($this->send_chunk_over_https($ch, $chunk)) {
                $progress = 100 - (count($manifest['chunks']) / count($manifest['original_chunks']) * 100);
                set_transient('wbt_manifest', $manifest, DAY_IN_SECONDS);
                
                return [
                    'progress' => min(99.9, round($progress, 2)),
                    'message' => 'Transferring chunks...',
                    'next_step' => !empty($manifest['chunks']) ? 'transfer_chunk' : 'finalize'
                ];
            } else {
                $retry_count++;
            }
        }

        if ($retry_count == $this->max_retries) {
            $this->log_failure("Failed to send chunk after 3 attempts");
            return [
                'progress' => min(99.9, round($progress, 2)),
                'message' => 'Failed to send chunk after 3 attempts',
                'next_step' => !empty($manifest['chunks']) ? 'transfer_chunk' : 'finalize'
            ];
        }
    }

    private function finalize_transfer() {
        delete_transient('wbt_manifest');
        $this->cleanup_tmp();
        
        return [
            'progress' => 100,
            'message' => 'Transfer completed successfully!',
            'next_step' => false
        ];
    }

    private function init_curl() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->https_endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, false);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        return $ch;
    }

    private function send_chunk_over_https($ch, $chunk) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $http_code >= 200 && $http_code < 300;
    }

    private function log_failure($message) {
        error_log($message);
    }

    private function cleanup_tmp() {
        array_map('unlink', glob($this->tmp_dir . 'chunk_*'));
    }
}

// Example usage in a WordPress AJAX handler
add_action('wp_ajax_chunk_upload', 'handle_chunk_upload');
function handle_chunk_upload() {
    $file_path = 'path/to/large/file';
    $chunk_size = 1024 * 1024 * 5;  // 5MB chunks
    $https_endpoint = 'https://example.com/upload';
    $manifest_path = 'path/to/manifest.json';
    $action = 'chunk_upload';

    $uploader = new ChunkUploaderAjaxHandler($file_path, $chunk_size, $https_endpoint, $manifest_path, $action);
    $uploader->handle_ajax_request();
}