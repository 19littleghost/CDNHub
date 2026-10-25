<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub storage via the REST "contents" API.
 * The token is only ever sent to api.github.com in an Authorization header.
 */
final class CDNHub_GitHub implements CDNHub_Provider {

	private $token;
	private $owner;
	private $repo;
	private $branch;

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

	/** @return array|WP_Error array( 'code' => int, 'body' => mixed ) */
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
			$args['body']                    = wp_json_encode( $body );
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

	/** Blob SHA of the file currently stored at $path, or null. */
	private function remote_sha( $path ) {
		$res = $this->request( 'GET', $this->contents_endpoint( $path ) . '?ref=' . rawurlencode( $this->branch ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] || ! is_array( $res['body'] ) || empty( $res['body']['sha'] ) || ! is_string( $res['body']['sha'] ) ) {
			return null;
		}
		return $res['body']['sha'];
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
		$bin = file_get_contents( $abs_path );
		if ( false === $bin ) {
			return new WP_Error( 'cdnhub_read', 'Could not read ' . wp_basename( $abs_path ) . '.' );
		}
		$body = array(
			'message' => 'CDNHub: add ' . $remote_path,
			'content' => base64_encode( $bin ),
			'branch'  => $this->branch,
		);
		unset( $bin );

		$endpoint = $this->contents_endpoint( $remote_path );

		for ( $i = 0; $i < 3; $i++ ) {
			$res = $this->request( 'PUT', $endpoint, $body );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( 201 === $res['code'] || 200 === $res['code'] ) {
				return array( 'status' => 201 === $res['code'] ? 'created' : 'updated' );
			}
			if ( 409 === $res['code'] || 422 === $res['code'] ) {
				// 422 = file already exists (a sha is needed); 409 = branch moved by a parallel commit.
				$remote = $this->remote_sha( $remote_path );
				if ( null !== $remote ) {
					if ( $remote === $local_sha ) {
						return array( 'status' => 'unchanged' );
					}
					if ( ! $overwrite ) {
						return new WP_Error( 'cdnhub_conflict', 'A different file already exists at ' . $remote_path . '.' );
					}
					$body['sha']     = $remote;
					$body['message'] = 'CDNHub: update ' . $remote_path;
				} elseif ( 422 === $res['code'] ) {
					return new WP_Error( 'cdnhub_github', $this->error_message( $res ) );
				}
				usleep( 500000 * ( $i + 1 ) );
				continue;
			}
			return new WP_Error( 'cdnhub_github', $this->error_message( $res ) );
		}
		return new WP_Error( 'cdnhub_github', 'GitHub was busy and the upload of ' . wp_basename( $remote_path ) . ' failed after several attempts.' );
	}

	public function delete( $remote_path ) {
		$sha = $this->remote_sha( $remote_path );
		if ( null === $sha ) {
			return true;
		}
		$res = $this->request(
			'DELETE',
			$this->contents_endpoint( $remote_path ),
			array(
				'message' => 'CDNHub: delete ' . $remote_path,
				'sha'     => $sha,
				'branch'  => $this->branch,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return ( 200 === $res['code'] || 204 === $res['code'] ) ? true : new WP_Error( 'cdnhub_github', $this->error_message( $res ) );
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
