<?php
// phpcs:ignoreFile

/**
 * In-memory stand-in for the parts of the GitHub Git Data API that CDNHub_GitHub uses.
 * Commits are snapshots of path => blob SHA; tree SHAs encode "commit:folder".
 */
class FakeGitHub {

	const BASE = 'https://api.github.com/repos/me/media-cdn';

	/** @var array commit SHA => array( 'files' => path => blob SHA, 'parent' => SHA|null ) */
	public $commits = array();
	public $head;
	public $blobs = array();     // blob SHA => content
	public $requests = array();  // "METHOD /path" in order
	/** @var callable|null Runs once, right before the next ref update (to simulate a parallel commit). */
	public $before_next_ref_update;

	private $staged = array();   // new tree SHA => files
	private $n      = 0;

	public function __construct( array $files = array() ) {
		$this->head = $this->add_commit( $files, null );
	}

	public function files() {
		return $this->commits[ $this->head ]['files'];
	}

	public function commit_count() {
		return count( $this->commits ) - 1; // Not counting the initial one.
	}

	public function count_requests( $prefix ) {
		return count( array_filter( $this->requests, function ( $r ) use ( $prefix ) {
			return 0 === strpos( $r, $prefix );
		} ) );
	}

	/** Commit straight to the branch, as another client would. */
	public function push( array $files ) {
		foreach ( $files as $path => $content ) {
			$sha                 = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
			$this->blobs[ $sha ] = $content;
			$files[ $path ]      = $sha;
		}
		$this->head = $this->add_commit( array_merge( $this->files(), $files ), $this->head );
	}

	private function add_commit( array $files, $parent ) {
		$sha                   = 'c' . ( ++$this->n );
		$this->commits[ $sha ] = array(
			'files'  => $files,
			'parent' => $parent,
		);
		return $sha;
	}

	public function handle( $url, $args ) {
		$method = isset( $args['method'] ) ? $args['method'] : 'GET';
		$path   = substr( $url, strlen( self::BASE ) );
		$body   = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;

		$this->requests[] = $method . ' ' . $path;

		if ( 'GET' === $method && '/git/ref/heads/main' === $path ) {
			return $this->respond( 200, array( 'object' => array( 'sha' => $this->head ) ) );
		}
		if ( 'GET' === $method && preg_match( '~^/git/commits/(\w+)$~', $path, $m ) && isset( $this->commits[ $m[1] ] ) ) {
			return $this->respond( 200, array( 'tree' => array( 'sha' => $m[1] . ':' ) ) );
		}
		if ( 'GET' === $method && preg_match( '~^/git/trees/(.+)$~', $path, $m ) ) {
			list( $commit, $dir ) = explode( ':', rawurldecode( $m[1] ), 2 );
			return $this->respond( 200, array( 'tree' => $this->list_dir( $this->commits[ $commit ]['files'], $commit, $dir ) ) );
		}
		if ( 'POST' === $method && '/git/blobs' === $path ) {
			$content             = base64_decode( $body['content'] );
			$sha                 = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
			$this->blobs[ $sha ] = $content;
			return $this->respond( 201, array( 'sha' => $sha ) );
		}
		if ( 'POST' === $method && '/git/trees' === $path ) {
			$commit = rtrim( $body['base_tree'], ':' );
			$files  = $this->commits[ $commit ]['files'];
			foreach ( $body['tree'] as $e ) {
				if ( null === $e['sha'] ) {
					unset( $files[ $e['path'] ] );
				} else {
					$files[ $e['path'] ] = $e['sha'];
				}
			}
			$sha                  = 't' . ( ++$this->n );
			$this->staged[ $sha ] = $files;
			return $this->respond( 201, array( 'sha' => $sha ) );
		}
		if ( 'POST' === $method && '/git/commits' === $path ) {
			return $this->respond( 201, array( 'sha' => $this->add_commit( $this->staged[ $body['tree'] ], $body['parents'][0] ) ) );
		}
		if ( 'PATCH' === $method && '/git/refs/heads/main' === $path ) {
			if ( $this->before_next_ref_update ) {
				$cb                           = $this->before_next_ref_update;
				$this->before_next_ref_update = null;
				$cb( $this );
			}
			if ( $this->commits[ $body['sha'] ]['parent'] !== $this->head ) {
				return $this->respond( 422, array( 'message' => 'Update is not a fast forward' ) );
			}
			$this->head = $body['sha'];
			return $this->respond( 200, array( 'object' => array( 'sha' => $this->head ) ) );
		}
		return $this->respond( 404, array( 'message' => 'Not Found' ) );
	}

	/** Entries of one folder: files as blobs, sub-folders as trees named "commit:folder". */
	private function list_dir( array $files, $commit, $dir ) {
		$prefix  = '' === $dir ? '' : $dir . '/';
		$entries = array();
		foreach ( $files as $path => $sha ) {
			if ( '' !== $prefix && 0 !== strpos( $path, $prefix ) ) {
				continue;
			}
			$rest = substr( $path, strlen( $prefix ) );
			$slash = strpos( $rest, '/' );
			if ( false === $slash ) {
				$entries[ $rest ] = array( 'path' => $rest, 'type' => 'blob', 'sha' => $sha );
			} else {
				$sub              = substr( $rest, 0, $slash );
				$entries[ $sub ] = array( 'path' => $sub, 'type' => 'tree', 'sha' => $commit . ':' . $prefix . $sub );
			}
		}
		return array_values( $entries );
	}

	private function respond( $code, $data ) {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array(),
			'body'     => json_encode( $data ),
		);
	}
}
