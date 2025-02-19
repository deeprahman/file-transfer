<?php
/**
 * 
 * 1. Retrieve the Manifest
   - manifest = load_manifest(manifest_path)

2. Check Completion
   - if manifest['chunks'] is empty:
     - proceed with cleanup
   - else:
     - log error "Transfer not complete" and exit

3. Clean Up Temporary Files
   - temp_files = list_files_in_directory(temp_dir, pattern="chunk_*")
   - for each file in temp_files:
     - delete file

4. Update Transfer Status
   - update status in manifest to "complete"

5. Remove Manifest
   - delete manifest file

6. Send Final Response
   - send response with progress = 100, message = "Transfer completed successfully!", next_step = false
 */
class ChunkUploaderFinalize {
    private $manifest_path;
    private $tmp_dir;

    public function __construct($manifest_path, $tmp_dir) {
        $this->manifest_path = $manifest_path;
        $this->tmp_dir = $tmp_dir;
    }

    public function finalize_transfer() {
        // Retrieve the manifest
        $manifest = json_decode(file_get_contents($this->manifest_path), true);

        // Check completion
        if (!empty($manifest['chunks'])) {
            $this->log_failure("Transfer not complete");
            return [
                'progress' => 99.9,
                'message' => 'Transfer not complete. Please retry.',
                'next_step' => 'transfer_chunk'
            ];
        }

        // Clean up temporary files
        $this->cleanup_tmp();

        // Update transfer status
        $manifest['status'] = 'complete';

        // Remove manifest
        unlink($this->manifest_path);

        // Send final response
        return [
            'progress' => 100,
            'message' => 'Transfer completed successfully!',
            'next_step' => false
        ];
    }

    private function cleanup_tmp() {
        foreach (glob($this->tmp_dir . 'chunk_*') as $file) {
            unlink($file);
        }
    }

    private function log_failure($message) {
        error_log($message);
    }
}