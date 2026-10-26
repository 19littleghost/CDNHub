<?php
// phpcs:ignoreFile

use Brain\Monkey\Functions;

class GitHubProviderTest extends CDNHub_Test_Case {

	/** @var FakeGitHub */
	private $gh;
	private $tmp = array();

	protected function setUp(): void {
		parent::setUp();
		$this->gh = new FakeGitHub(
			array(
				'README.md'           => self::git_sha( 'readme' ),
				'media/2026/old.jpg' => self::git_sha( 'old' ),
			)
		);
		Functions\when( 'wp_remote_request' )->alias( array( $this->gh, 'handle' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) {
				return $r['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) {
				return $r['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $r, $name ) {
				return isset( $r['headers'][ $name ] ) ? $r['headers'][ $name ] : '';
			}
		);
		Functions\when( 'wp_raise_memory_limit' )->justReturn( false );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( -1 ); // No memory limit.
	}

	protected function tearDown(): void {
		foreach ( $this->tmp as $file ) {
			@unlink( $file );
		}
		parent::tearDown();
	}

	private function provider() {
		return new CDNHub_GitHub( 'token', 'me', 'media-cdn', 'main' );
	}

	/** A local file with $content, as a put_many() entry. */
	private function file( $content, $overwrite = false ) {
		$abs = tempnam( sys_get_temp_dir(), 'cdnhub' );
		file_put_contents( $abs, $content );
		$this->tmp[] = $abs;
		return array(
			'abs'       => $abs,
			'sha'       => self::git_sha( $content ),
			'overwrite' => $overwrite,
		);
	}

	public function test_all_sizes_of_an_image_land_in_one_commit() {
		$r = $this->provider()->put_many(
			array(
				'media/2026/10/a.jpg'         => $this->file( 'a' ),
				'media/2026/10/a-150x150.jpg' => $this->file( 'a-small' ),
				'media/2026/10/a-300x200.jpg' => $this->file( 'a-medium' ),
			),
			'CDNHub: add a.jpg (3 files)'
		);

		$this->assertSame( array_fill_keys( array( 'media/2026/10/a.jpg', 'media/2026/10/a-150x150.jpg', 'media/2026/10/a-300x200.jpg' ), 'created' ), $r );
		$this->assertSame( 1, $this->gh->commit_count() );
		$this->assertSame( self::git_sha( 'a-small' ), $this->gh->files()['media/2026/10/a-150x150.jpg'] );
		$this->assertSame( self::git_sha( 'readme' ), $this->gh->files()['README.md'], 'Other files are kept.' );
	}

	public function test_identical_files_are_not_sent_again() {
		$r = $this->provider()->put_many( array( 'media/2026/old.jpg' => $this->file( 'old' ) ) );

		$this->assertSame( array( 'media/2026/old.jpg' => 'unchanged' ), $r );
		$this->assertSame( 0, $this->gh->commit_count() );
		$this->assertSame( 0, $this->gh->count_requests( 'POST /git/blobs' ) );
	}

	public function test_a_different_file_with_the_same_name_is_a_conflict_and_nothing_is_written() {
		$r = $this->provider()->put_many(
			array(
				'media/2026/new.jpg' => $this->file( 'new' ),
				'media/2026/old.jpg' => $this->file( 'something else' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'cdnhub_conflict', $r->get_error_code() );
		$this->assertSame( 0, $this->gh->commit_count() );
		$this->assertSame( 0, $this->gh->count_requests( 'POST /git/blobs' ) );
	}

	public function test_overwrite_replaces_the_file() {
		$r = $this->provider()->put_many( array( 'media/2026/old.jpg' => $this->file( 'replacement', true ) ) );

		$this->assertSame( array( 'media/2026/old.jpg' => 'updated' ), $r );
		$this->assertSame( self::git_sha( 'replacement' ), $this->gh->files()['media/2026/old.jpg'] );
	}

	public function test_retries_on_a_new_head_when_another_upload_commits_first() {
		$this->gh->before_next_ref_update = function ( FakeGitHub $gh ) {
			$gh->push( array( 'media/2026/other.jpg' => 'other' ) );
		};

		$r = $this->provider()->put_many( array( 'media/2026/a.jpg' => $this->file( 'a' ) ) );

		$this->assertSame( array( 'media/2026/a.jpg' => 'created' ), $r );
		$files = $this->gh->files();
		$this->assertArrayHasKey( 'media/2026/a.jpg', $files );
		$this->assertArrayHasKey( 'media/2026/other.jpg', $files, 'The parallel commit is not lost.' );
		$this->assertSame( 1, $this->gh->count_requests( 'POST /git/blobs' ), 'Content is uploaded once, even when retrying.' );
	}

	public function test_single_put_uses_the_same_flow() {
		$r = $this->provider()->put( $this->file( 'x' )['abs'], 'media/x.jpg', self::git_sha( 'x' ) );

		$this->assertSame( array( 'status' => 'created' ), $r );
		$this->assertSame( 1, $this->gh->commit_count() );
	}

	public function test_delete_many_removes_files_in_one_commit_and_ignores_missing_ones() {
		$this->gh->push( array( 'media/2026/old-150x150.jpg' => 'small' ) );
		$before = $this->gh->commit_count();

		$r = $this->provider()->delete_many( array( 'media/2026/old.jpg', 'media/2026/old-150x150.jpg', 'media/nope.jpg' ) );

		$this->assertTrue( $r );
		$this->assertSame( $before + 1, $this->gh->commit_count() );
		$this->assertArrayNotHasKey( 'media/2026/old.jpg', $this->gh->files() );
		$this->assertArrayNotHasKey( 'media/2026/old-150x150.jpg', $this->gh->files() );
	}

	public function test_deleting_files_that_do_not_exist_makes_no_commit() {
		$this->assertTrue( $this->provider()->delete( 'media/nope.jpg' ) );
		$this->assertSame( 0, $this->gh->commit_count() );
	}

	public function test_refuses_uploads_that_would_exhaust_php_memory() {
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( memory_get_usage() + 1048576 );

		$r = $this->provider()->put_many( array( 'media/big.jpg' => $this->file( str_repeat( 'x', 1048576 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'cdnhub_memory', $r->get_error_code() );
		$this->assertSame( 0, $this->gh->count_requests( 'POST /git/blobs' ) );
	}

	public function test_memory_estimate() {
		$this->assertTrue( CDNHub_GitHub::fits_in_memory( 100 * 1048576, -1, 0 ), 'No limit.' );
		$this->assertTrue( CDNHub_GitHub::fits_in_memory( 10 * 1048576, 256 * 1048576, 64 * 1048576 ) );
		$this->assertFalse( CDNHub_GitHub::fits_in_memory( 50 * 1048576, 256 * 1048576, 64 * 1048576 ) );
	}

	public function test_missing_branch_is_explained() {
		$p = new CDNHub_GitHub( 'token', 'me', 'media-cdn', 'nope' );
		$r = $p->put_many( array( 'media/a.jpg' => $this->file( 'a' ) ) );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'Branch "nope" was not found', $r->get_error_message() );
	}
}
