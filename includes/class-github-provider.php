<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub storage via the Git Data API: every image (with all its sizes) becomes one commit.
 * The token is only ever sent to api.github.com in an Authorization header.
 */
final class CDNHub_GitHub implements CDNHub_Batch_Provider {

	/** Rough peak memory per uploaded byte: raw file + base64 + JSON body + HTTP transport copy. */
	const MEMORY_FACTOR = 4.5;

	private $token;
	private $owner;
	private $repo;
	private $branch;
	private $trees = array(); // Tree SHA => entries. Trees are immutable, so this never goes stale.

	public function __construct( $token, $owner, $repo, $branch ) {
		$this->token  = (string) $token;
		$this->owner  = (string) $owner;
		$this->repo   = (string) $repo;
		$this->branch = (string) $branch;
	}

	public function id() {
		return 'github';
	}

	public function label() {
		return 'GitHub';
	}

	public function is_configured() {
		return '' !== $this->token && '' !== $this->owner && '' !== $this->repo && '' !== $this->branch;
	}

	/* ------------------------------------------------------------------ http */

	private function repo_endpoint() {
		return '/repos/' . rawurlencode( $this->owner ) . '/' . rawurlencode( $this->repo );
	}

	private function encode_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	private function contents_endpoint( $path ) {
		return $this->repo_endpoint() . '/contents/' . $this->encode_path( $path );
	}

	/**
	 * @param string            $method   HTTP method.
	 * @param string            $endpoint Path below https://api.github.com.
	 * @param array|string|null $body     Data to JSON-encode, or an already encoded JSON string.
	 * @return array|WP_Error array( 'code' => int, 'body' => mixed )
	 */
	private function request( $method, $endpoint, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization'        => 'Bearer ' . $this->token,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'CDNHub-WordPress-Plugin',
			),
		);
		if ( null !== $body ) {
			$args['body']                    = is_string( $body ) ? $body : wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$res = wp_remote_request( 'https://api.github.com' . $endpoint, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'cdnhub_http', 'Could not reach GitHub: ' . $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 403 === $code || 429 === $code ) {
			$remaining = wp_remote_retrieve_header( $res, 'x-ratelimit-remaining' );
			$retry     = wp_remote_retrieve_header( $res, 'retry-after' );
			$reset     = wp_remote_retrieve_header( $res, 'x-ratelimit-reset' );
			$msg       = ( is_array( $data ) && isset( $data['message'] ) ) ? strtolower( (string) $data['message'] ) : '';
			if ( 429 === $code || '0' === (string) $remaining || '' !== (string) $retry || false !== strpos( $msg, 'rate limit' ) ) {
				$wait = 60;
				if ( '' !== (string) $retry && is_numeric( $retry ) ) {
					$wait = (int) $retry;
				} elseif ( '' !== (string) $reset && is_numeric( $reset ) ) {
					$wait = (int) $reset - time();
				}
				$wait = max( 30, min( 3600, $wait ) );
				return new WP_Error(
					'cdnhub_rate_limited',
					'GitHub rate limit reached. CDNHub will retry automatically in about ' . max( 1, (int) ceil( $wait / 60 ) ) . ' minute(s).',
					array( 'retry_after' => $wait )
				);
			}
		}

		return array(
			'code' => $code,
			'body' => $data,
		);
	}

	private function error_message( $res ) {
		$m = ( is_array( $res['body'] ) && ! empty( $res['body']['message'] ) ) ? (string) $res['body']['message'] : '';
		switch ( $res['code'] ) {
			case 401:
				return 'GitHub rejected the access token (invalid, revoked or expired). Create a new token and save it.';
			case 403:
				return 'GitHub denied access' . ( $m ? ': ' . $m : '' ) . '. Make sure the token has “Contents: Read and write” on this repository.';
			case 404:
				return 'Repository or branch not found, or the token cannot access it. Check owner, repository and branch name.';
			case 413:
				return 'GitHub says the file is too large.';
			default:
				return 'GitHub error ' . $res['code'] . ( $m ? ': ' . $m : '.' );
		}
	}

	/* ---------------------------------------------------------------- memory */

	/** Bytes of PHP memory an upload of $bytes needs (the API only accepts base64 inside JSON). */
	public static function memory_needed( $bytes ) {
		return (int) ceil( $bytes * self::MEMORY_FACTOR ) + 8 * 1024 * 1024;
	}

	/** Whether an upload of $bytes fits; $limit <= 0 means "no memory limit". */
	public static function fits_in_memory( $bytes, $limit, $used ) {
		return $limit <= 0 || self::memory_needed( $bytes ) <= $limit - $used;
	}

	/** @return true|WP_Error */
	private function check_memory( $bytes, $name ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'cdnhub' );
		}
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$used  = memory_get_usage();
		if ( self::fits_in_memory( $bytes, $limit, $used ) ) {
			return true;
		}
		return new WP_Error(
			'cdnhub_memory',
			sprintf(
				'%s needs about %d MB of PHP memory to upload, but only %d MB is free. Raise WP_MAX_MEMORY_LIMIT or lower the maximum file size.',
				$name,
				(int) ceil( self::memory_needed( $bytes ) / 1048576 ),
				max( 0, (int) floor( ( $limit - $used ) / 1048576 ) )
			)
		);
	}

	/* -------------------------------------------------------------- git data */

	private function basename( $path ) {
		$p = strrpos( $path, '/' );
		return false === $p ? $path : substr( $path, $p + 1 );
	}

	private function dirname( $path ) {
		$p = strrpos( $path, '/' );
		return false === $p ? '' : substr( $path, 0, $p );
	}

	/** @return array|WP_Error array( 'commit' => branch head SHA, 'tree' => its root tree SHA ) */
	private function head() {
		$ref = $this->request( 'GET', $this->repo_endpoint() . '/git/ref/heads/' . $this->encode_path( $this->branch ) );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		if ( 200 !== $ref['code'] || empty( $ref['body']['object']['sha'] ) ) {
			if ( 404 === $ref['code'] || 409 === $ref['code'] ) {
				return new WP_Error( 'cdnhub_github', 'Branch "' . $this->branch . '" was not found. If the repository is empty, add a README on GitHub first.' );
			}
			return new WP_Error( 'cdnhub_github', $this->error_message( $ref ) );
		}
		$commit = (string) $ref['body']['object']['sha'];

		$c = $this->request( 'GET', $this->repo_endpoint() . '/git/commits/' . rawurlencode( $commit ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( 200 !== $c['code'] || empty( $c['body']['tree']['sha'] ) ) {
			return new WP_Error( 'cdnhub_github', $this->error_message( $c ) );
		}
		return array(
			'commit' => $commit,
			'tree'   => (string) $c['body']['tree']['sha'],
		);
	}

	/** Entries of one tree (not recursive). @return array|WP_Error */
	private function tree_entries( $sha ) {
		if ( isset( $this->trees[ $sha ] ) ) {
			return $this->trees[ $sha ];
		}
		$res = $this->request( 'GET', $this->repo_endpoint() . '/git/trees/' . rawurlencode( $sha ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ! isset( $res['body']['tree'] ) || ! is_array( $res['body']['tree'] ) ) {
			return new WP_Error( 'cdnhub_github', $this->error_message( $res ) );
		}
		if ( ! empty( $res['body']['truncated'] ) ) {
			return new WP_Error( 'cdnhub_github', 'A folder in the repository holds too many files for GitHub to list. Use a folder structure with sub-folders.' );
		}
		$this->trees[ $sha ] = $res['body']['tree'];
		return $this->trees[ $sha ];
	}

	/** File name => blob SHA of the files directly in $dir. Empty when the folder does not exist. @return array|WP_Error */
	private function folder_shas( $root, $dir ) {
		$sha = $root;
		foreach ( explode( '/', $dir ) as $seg ) {
			if ( '' === $seg ) {
				continue;
			}
			$entries = $this->tree_entries( $sha );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			$sha = null;
			foreach ( $entries as $e ) {
				if ( isset( $e['type'], $e['path'], $e['sha'] ) && 'tree' === $e['type'] && $seg === $e['path'] ) {
					$sha = (string) $e['sha'];
					break;
				}
			}
			if ( null === $sha ) {
				return array();
			}
		}
		$entries = $this->tree_entries( $sha );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$out = array();
		foreach ( $entries as $e ) {
			if ( isset( $e['type'], $e['path'], $e['sha'] ) && 'blob' === $e['type'] ) {
				$out[ (string) $e['path'] ] = (string) $e['sha'];
			}
		}
		return $out;
	}

	/** Remote path => blob SHA for those of $paths that exist under the tree $root. @return array|WP_Error */
	private function remote_shas( $root, array $paths ) {
		$by_dir = array();
		foreach ( $paths as $path ) {
			$by_dir[ $this->dirname( $path ) ][] = $path;
		}
		$out = array();
		foreach ( $by_dir as $dir => $list ) {
			$names = $this->folder_shas( $root, (string) $dir );
			if ( is_wp_error( $names ) ) {
				return $names;
			}
			foreach ( $list as $path ) {
				$name = $this->basename( $path );
				if ( isset( $names[ $name ] ) ) {
					$out[ $path ] = $names[ $name ];
				}
			}
		}
		return $out;
	}

	/** Upload one file's content as a blob (no commit yet). @return string|WP_Error Blob SHA. */
	private function create_blob( $abs_path ) {
		$name = wp_basename( $abs_path );
		$size = is_readable( $abs_path ) ? filesize( $abs_path ) : false;
		if ( false === $size ) {
			return new WP_Error( 'cdnhub_read', 'Could not read ' . $name . '.' );
		}
		$mem = $this->check_memory( (int) $size, $name );
		if ( is_wp_error( $mem ) ) {
			return $mem;
		}
		$bin = file_get_contents( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bin ) {
			return new WP_Error( 'cdnhub_read', 'Could not read ' . $name . '.' );
		}
		// Built by hand: base64 needs no JSON escaping, and this avoids one more copy of the file in memory.
		$body = '{"encoding":"base64","content":"' . base64_encode( $bin ) . '"}'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		unset( $bin );
		$res = $this->request( 'POST', $this->repo_endpoint() . '/git/blobs', $body );
		unset( $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 201 !== $res['code'] || empty( $res['body']['sha'] ) ) {
			return new WP_Error( 'cdnhub_github', $this->error_message( $res ) );
		}
		return (string) $res['body']['sha'];
	}

	/**
	 * Commit tree changes on top of $head and move the branch to it.
	 *
	 * @return bool|WP_Error True when saved, false when the branch moved meanwhile (try again).
	 */
	private function commit( array $head, array $entries, $message ) {
		$tree = $this->request(
			'POST',
			$this->repo_endpoint() . '/git/trees',
			array(
				'base_tree' => $head['tree'],
				'tree'      => $entries,
			)
		);
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		if ( 201 !== $tree['code'] || empty( $tree['body']['sha'] ) ) {
			return new WP_Error( 'cdnhub_github', $this->error_message( $tree ) );
		}

		$commit = $this->request(
			'POST',
			$this->repo_endpoint() . '/git/commits',
			array(
				'message' => $message,
				'tree'    => (string) $tree['body']['sha'],
				'parents' => array( $head['commit'] ),
			)
		);
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}
		if ( 201 !== $commit['code'] || empty( $commit['body']['sha'] ) ) {
			return new WP_Error( 'cdnhub_github', $this->error_message( $commit ) );
		}

		$ref = $this->request(
			'PATCH',
			$this->repo_endpoint() . '/git/refs/heads/' . $this->encode_path( $this->branch ),
			array(
				'sha'   => (string) $commit['body']['sha'],
				'force' => false,
			)
		);
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		if ( 200 === $ref['code'] ) {
			return true;
		}
		if ( 422 === $ref['code'] || 409 === $ref['code'] ) {
			return false; // Not a fast-forward: another upload committed first.
		}
		return new WP_Error( 'cdnhub_github', $this->error_message( $ref ) );
	}

	/**
	 * Run $plan against the current branch head and commit what it returns, retrying
	 * on a fresh head when parallel uploads move the branch.
	 *
	 * @param callable $plan Receives the root tree SHA; returns array( 'entries' => tree entries, 'result' => mixed ) or WP_Error.
	 * @return mixed|WP_Error The plan's result once committed.
	 */
	private function commit_changes( callable $plan, $message ) {
		for ( $i = 0; $i < 3; $i++ ) {
			$head = $this->head();
			if ( is_wp_error( $head ) ) {
				return $head;
			}
			$p = $plan( $head['tree'] );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			if ( ! $p['entries'] ) {
				return $p['result'];
			}
			$done = $this->commit( $head, $p['entries'], $message );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			if ( $done ) {
				return $p['result'];
			}
			usleep( 500000 * ( $i + 1 ) );
		}
		return new WP_Error( 'cdnhub_github', 'GitHub was busy (the branch kept changing) and the change could not be saved after several attempts.' );
	}

	/**
	 * Download the raw bytes of a stored file (any size, via the raw media type).
	 * Not part of the CDNHub_Provider interface yet; callers check method_exists().
	 *
	 * @return string|WP_Error Binary contents.
	 */
	public function get( $remote_path ) {
		$res = wp_remote_request(
			'https://api.github.com' . $this->contents_endpoint( $remote_path ) . '?ref=' . rawurlencode( $this->branch ),
			array(
				'method'  => 'GET',
				'timeout' => 120,
				'headers' => array(
					'Authorization'        => 'Bearer ' . $this->token,
					'Accept'               => 'application/vnd.github.raw',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'CDNHub-WordPress-Plugin',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'cdnhub_http', 'Could not reach GitHub: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( 404 === $code ) {
			return new WP_Error( 'cdnhub_missing', wp_basename( $remote_path ) . ' is not in the repository anymore.' );
		}
		if ( 200 !== $code || null === $body || '' === $body ) {
			return new WP_Error( 'cdnhub_get', 'GitHub error ' . $code . ' while downloading ' . wp_basename( $remote_path ) . '.' );
		}
		return $body;
	}

	/* ------------------------------------------------------------- interface */

	public function put( $abs_path, $remote_path, $local_sha, $overwrite = false ) {
		$r = $this->put_many(
			array(
				$remote_path => array(
					'abs'       => $abs_path,
					'sha'       => $local_sha,
					'overwrite' => $overwrite,
				),
			),
			'CDNHub: add ' . $remote_path
		);
		return is_wp_error( $r ) ? $r : array( 'status' => $r[ $remote_path ] );
	}

	public function delete( $remote_path ) {
		return $this->delete_many( array( $remote_path ), 'CDNHub: delete ' . $remote_path );
	}

	public function put_many( array $files, $message = '' ) {
		if ( ! $files ) {
			return array();
		}
		$blobs = array(); // Kept across retries: uploaded content does not need to be sent again.
		return $this->commit_changes(
			function ( $root ) use ( $files, &$blobs ) {
				$remote = $this->remote_shas( $root, array_keys( $files ) );
				if ( is_wp_error( $remote ) ) {
					return $remote;
				}
				$status = array();
				foreach ( $files as $path => $f ) {
					$current = isset( $remote[ $path ] ) ? $remote[ $path ] : null;
					if ( $current === $f['sha'] ) {
						$status[ $path ] = 'unchanged';
					} elseif ( null !== $current && empty( $f['overwrite'] ) ) {
						return new WP_Error( 'cdnhub_conflict', 'A different file already exists at ' . $path . '.' );
					} else {
						$status[ $path ] = null === $current ? 'created' : 'updated';
					}
				}
				// Content is only sent once no file conflicts, so a refused batch uploads nothing.
				$entries = array();
				foreach ( $status as $path => $st ) {
					if ( 'unchanged' === $st ) {
						continue;
					}
					if ( ! isset( $blobs[ $path ] ) ) {
						$blob = $this->create_blob( $files[ $path ]['abs'] );
						if ( is_wp_error( $blob ) ) {
							return $blob;
						}
						$blobs[ $path ] = $blob;
					}
					$entries[] = array(
						'path' => $path,
						'mode' => '100644',
						'type' => 'blob',
						'sha'  => $blobs[ $path ],
					);
				}
				return array(
					'entries' => $entries,
					'result'  => $status,
				);
			},
			'' !== $message ? $message : 'CDNHub: add ' . count( $files ) . ' file(s)'
		);
	}

	public function delete_many( array $remote_paths, $message = '' ) {
		$remote_paths = array_values( array_unique( array_filter( array_map( 'strval', $remote_paths ), 'strlen' ) ) );
		if ( ! $remote_paths ) {
			return true;
		}
		return $this->commit_changes(
			function ( $root ) use ( $remote_paths ) {
				$remote = $this->remote_shas( $root, $remote_paths );
				if ( is_wp_error( $remote ) ) {
					return $remote;
				}
				$entries = array();
				foreach ( $remote_paths as $path ) {
					if ( isset( $remote[ $path ] ) ) {
						// A null SHA removes the file from the tree.
						$entries[] = array(
							'path' => $path,
							'mode' => '100644',
							'type' => 'blob',
							'sha'  => null,
						);
					}
				}
				return array(
					'entries' => $entries,
					'result'  => true,
				);
			},
			'' !== $message ? $message : 'CDNHub: delete ' . count( $remote_paths ) . ' file(s)'
		);
	}

	/**
	 * Entire branch tree as path => blob SHA (one API call), for the adopt tool.
	 *
	 * @return array|WP_Error array( 'paths' => array, 'truncated' => bool )
	 */
	public function tree() {
		$res = $this->request( 'GET', $this->repo_endpoint() . '/git/trees/' . rawurlencode( $this->branch ) . '?recursive=1' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) $res['code'];
		$body = is_array( $res['body'] ) ? $res['body'] : array();
		if ( 409 === $code ) {
			return new WP_Error( 'cdnhub_tree', 'The repository is empty — there is nothing to adopt. Use the upload tool instead.' );
		}
		if ( 200 !== $code || empty( $body['tree'] ) || ! is_array( $body['tree'] ) ) {
			$message = 404 === $code
				? 'Branch "' . $this->branch . '" was not found. Save the correct branch name and try again.'
				: $this->error_message( $res );
			return new WP_Error( 'cdnhub_tree', $message );
		}
		$paths = array();
		foreach ( $body['tree'] as $entry ) {
			$type = is_array( $entry ) && isset( $entry['type'] ) ? $entry['type'] : '';
			if ( 'blob' === $type && ! empty( $entry['sha'] ) && isset( $entry['path'] ) && is_string( $entry['path'] ) ) {
				$paths[ $entry['path'] ] = (string) $entry['sha'];
			}
		}
		return array(
			'paths'     => $paths,
			'truncated' => ! empty( $body['truncated'] ),
		);
	}

	public function test() {
		$res = $this->request( 'GET', $this->repo_endpoint() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ! is_array( $res['body'] ) ) {
			return new WP_Error( 'cdnhub_test', $this->error_message( $res ) );
		}
		$repo = $res['body'];
		if ( isset( $repo['permissions']['push'] ) && ! $repo['permissions']['push'] ) {
			return new WP_Error( 'cdnhub_test', 'Connected, but this token cannot write to the repository. Give it “Contents: Read and write”.' );
		}
		$br = $this->request( 'GET', $this->repo_endpoint() . '/branches/' . $this->encode_path( $this->branch ) );
		if ( is_wp_error( $br ) ) {
			return $br;
		}
		if ( 200 !== $br['code'] ) {
			return new WP_Error( 'cdnhub_test', 'Repository found, but branch “' . $this->branch . '” does not exist. If the repository is empty, add a README on GitHub first.' );
		}
		return array(
			'message' => 'Connected to ' . ( isset( $repo['full_name'] ) ? $repo['full_name'] : $this->owner . '/' . $this->repo ) . ' — write access confirmed.',
			'private' => ! empty( $repo['private'] ),
		);
	}
}
