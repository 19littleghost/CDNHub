<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider that can store or delete several files as one change. For GitHub this
 * means one commit per image (all its sizes) instead of one commit per file.
 * Optional: the uploader falls back to CDNHub_Provider::put() / delete() otherwise.
 */
interface CDNHub_Batch_Provider extends CDNHub_Provider {

	/**
	 * Store several local files in one change. Nothing is written when any file conflicts.
	 *
	 * @param array  $files   remote path => array( 'abs' => absolute local path, 'sha' => Git blob SHA-1, 'overwrite' => bool ).
	 * @param string $message Change description (commit message).
	 * @return array|WP_Error remote path => 'created'|'updated'|'unchanged', or an error with the
	 *                        same codes as CDNHub_Provider::put().
	 */
	public function put_many( array $files, $message = '' );

	/**
	 * Delete several stored files in one change. Paths that do not exist are ignored.
	 *
	 * @param string[] $remote_paths Paths inside the storage.
	 * @param string   $message      Change description (commit message).
	 * @return true|WP_Error
	 */
	public function delete_many( array $remote_paths, $message = '' );
}
