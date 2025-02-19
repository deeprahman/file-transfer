<?php

/**
 * 
 * 1. Initialization
   - file_path = "path/to/file"
   - chunk_size = n
   - max_retries = 3
   - offset = 0
   - https_endpoint = "https://example.com/upload"
   - manifest_path = "path/to/manifest.json"

2. Manifest Initialization
   - if manifest file does not exist:
     - create new manifest:
       - file_path: file_path
       - chunk_size: chunk_size
       - last_transmitted_chunk: 0
       - last_offset: 0
     - save manifest to manifest_path

3. Reading and Sending Chunks
   - open file in binary read mode
   - while offset < total_size:
     - read chunk of size chunk_size from file starting from offset
     - store chunk in memory
     - retry_count = 0
     - while retry_count < max_retries:
       - if send_chunk_over_https(chunk) is successful:
         - increment offset by chunk_size
         - update manifest with last_transmitted_chunk and offset
         - break
       - else:
         - increment retry_count
     - if retry_count == max_retries:
       - log failure
       - exit loop

4. Closing
   - close file
   - close cURL handle
 */
class ChunkUploader {
    private $file_path;
    private $chunk_size;
    private $https_endpoint;
    private $max_retries;
    private $manifest_path;

    public function __construct($file_path, $chunk_size, $https_endpoint, $manifest_path, $max_retries = 3) {
        $this->file_path = $file_path;
        $this->chunk_size = $chunk_size;
        $this->https_endpoint = $https_endpoint;
        $this->max_retries = $max_retries;
        $this->manifest_path = $manifest_path;
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

    public function upload_file_in_chunks() {
        $manifest = json_decode(file_get_contents($this->manifest_path), true);
        $offset = $manifest['last_offset'];
        $total_size = $this->get_file_size($this->file_path);
        $chunk_index = $manifest['last_transmitted_chunk'];

        $file = fopen($this->file_path, 'rb');
        if (!$file) {
            $this->log_failure("Failed to open file: " . $this->file_path);
            return;
        }

        $ch = $this->init_curl();
        while ($offset < $total_size) {
            fseek($file, $offset);
            $chunk = fread($file, $this->chunk_size);
            $retry_count = 0;

            while ($retry_count < $this->max_retries) {
                if ($this->send_chunk_over_https($ch, $chunk)) {
                    $offset += $this->chunk_size;
                    $chunk_index++;
                    $this->update_manifest($chunk_index, $offset);
                    break;
                } else {
                    $retry_count++;
                }
            }

            if ($retry_count == $this->max_retries) {
                $this->log_failure("Failed to send chunk after 3 attempts");
                break;
            }
        }

        fclose($file);
        curl_close($ch);
    }

    private function get_file_size($file_path) {
        return filesize($file_path);
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
}

// Example usage
$file_path = 'path/to/large/file';
$chunk_size = 1024 * 1024 * 5;  // 5MB chunks
$https_endpoint = 'https://example.com/upload';
$manifest_path = 'path/to/manifest.json';

$uploader = new ChunkUploader($file_path, $chunk_size, $https_endpoint, $manifest_path);
$uploader->upload_file_in_chunks();