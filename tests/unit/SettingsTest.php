<?php
// phpcs:ignoreFile

use Brain\Monkey\Functions;

class SettingsTest extends CDNHub_Test_Case {

	public function test_token_round_trips_through_encryption() {
		$s   = $this->settings();
		$enc = $s->encrypt( 'github_pat_abc123' );

		$this->assertStringStartsWith( 'enc1:', $enc );
		$this->assertStringNotContainsString( 'github_pat_abc123', $enc );
		$this->assertSame( 'github_pat_abc123', $s->decrypt( $enc ) );
	}

	public function test_plain_tokens_from_old_versions_are_read_as_is() {
		$this->assertSame( 'ghp_plain', $this->settings()->decrypt( 'ghp_plain' ) );
	}

	public function test_changed_salts_make_the_token_unreadable() {
		$enc = $this->settings()->encrypt( 'github_pat_abc123' );

		Functions\when( 'wp_salt' )->justReturn( 'new-salt' );
		$s = $this->settings( array( 'token' => $enc ) );

		$this->assertSame( '', $s->decrypt( $enc ) );
		$this->assertTrue( $s->token_unreadable() );
		$this->assertFalse( $s->is_ready() );
	}

	public function test_readable_or_plain_token_is_not_reported() {
		$s = $this->settings();
		$this->options = array( 'token' => $s->encrypt( 'github_pat_abc123' ) );
		$this->assertFalse( $s->token_unreadable() );

		$this->options = array( 'token' => 'ghp_plain' );
		$this->assertFalse( $s->token_unreadable() );

		$this->options = array();
		$this->assertFalse( $s->token_unreadable() );
	}

	/** @dataProvider paths */
	public function test_clean_path_blocks_traversal( $in, $out ) {
		$this->assertSame( $out, $this->settings()->clean_path( $in ) );
	}

	public function paths() {
		return array(
			array( '../a//b/./c\\d', 'a/b/c/d' ),
			array( '/media/', 'media' ),
			array( 'my photos/2026', 'my-photos/2026' ),
			array( '...', '' ),
		);
	}

	public function test_sanitize_clamps_numbers_and_rejects_unknown_choices() {
		$out = $this->settings()->sanitize(
			array(
				'max_size_mb'  => 500,
				'max_attempts' => 0,
				'url_mode'     => 'ftp',
				'types'        => array( 'jpg', 'exe' ),
				'owner'        => 'me/../you',
				'custom_template' => 'javascript:alert(1)',
			)
		);

		$this->assertEquals( 90, $out['max_size_mb'] );
		$this->assertSame( 1, $out['max_attempts'] );
		$this->assertSame( 'jsdelivr', $out['url_mode'] );
		$this->assertSame( array( 'jpg' ), $out['types'] );
		$this->assertSame( 'me..you', $out['owner'] );
		$this->assertSame( '', $out['custom_template'] );
	}
}
