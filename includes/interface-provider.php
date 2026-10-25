<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A storage/CDN backend. GitHub is the first implementation; others (S3, Bunny, GitLab...)
 * can implement this interface and be returned via the `cdnhub_provider` filter.
 */
interface CDNHub_Provider {

	/** Short machine id, e.g. "github". */
	public function id();

	/** Human readable name. */
	public function label();

	/** Whether all credentials/settings needed are present. */
	public function is_configured();

	/**
	 * Store a local file at $remote_path.
	 *
	 * @param string $abs_path   Absolute path of local file.
	 * @param string $remote_path Path inside the storage (already sanitized).
	 * @param string $local_sha  Git-style blob SHA-1 of the local file (for change detection).
	 * @param bool   $overwrite  Replace an existing, different file.
	 * @return array|WP_Error    array( 'status' => 'created'|'updated'|'unchanged' ) or an error.
	 *                           Error code "cdnhub_conflict" = a different file exists and $overwrite is false.
	 *                           Error code "cdnhub_rate_limited" carries data array( 'retry_after' => seconds ).
	 */
	public function put( $abs_path, $remote_path, $local_sha, $overwrite = false );

	/** Delete a stored file. True (also when it doesn't exist) or WP_Error. */
	public function delete( $remote_path );

	/**
	 * Full listing of the remote storage, for reconciling pre-uploaded files.
	 *
	 * @return array|WP_Error array( 'paths' => array( path => blob SHA ), 'truncated' => bool ) or an error.
	 */
	public function tree();

	/** Check the connection. array( 'message' => string, 'private' => bool ) or WP_Error. */
	public function test();
}
