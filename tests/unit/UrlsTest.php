<?php
// phpcs:ignoreFile

class UrlsTest extends CDNHub_Test_Case {

	private function urls( array $options = array() ) {
		return new CDNHub_URLs(
			$this->settings(
				$options + array(
					'token'  => 'x',
					'owner'  => 'me',
					'repo'   => 'media-cdn',
					'branch' => 'main',
					'folder' => 'media',
				)
			)
		);
	}

	public function test_jsdelivr_url_encodes_each_segment() {
		$this->assertSame(
			'https://cdn.jsdelivr.net/gh/me/media-cdn@main/media/2026/10/my%20photo.jpg',
			$this->urls()->for_file( '2026/10', 'my photo.jpg' )
		);
	}

	public function test_other_url_modes() {
		$this->assertSame( 'https://raw.githubusercontent.com/me/media-cdn/main/media/a.png', $this->urls( array( 'url_mode' => 'raw' ) )->for_file( '', 'a.png' ) );
		$this->assertSame( 'https://me.github.io/media-cdn/media/a.png', $this->urls( array( 'url_mode' => 'pages' ) )->for_file( '', 'a.png' ) );
		$this->assertSame(
			'https://cdn.example.com/media/a.png',
			$this->urls(
				array(
					'url_mode'        => 'custom',
					'custom_template' => 'https://cdn.example.com',
				)
			)->for_file( '', 'a.png' )
		);
	}

	public function test_purge_urls_for_jsdelivr() {
		$this->assertSame(
			array( 'https://purge.jsdelivr.net/gh/me/media-cdn@main/media/2026/a.jpg' ),
			$this->urls()->purge_urls( array( 'media/2026/a.jpg', 'media/2026/a.jpg' ) )
		);
	}

	public function test_purge_urls_for_custom_template_on_jsdelivr() {
		$urls = $this->urls(
			array(
				'url_mode'        => 'custom',
				'custom_template' => 'https://cdn.jsdelivr.net/gh/{owner}/{repo}@{branch}/{path}',
			)
		);
		$this->assertSame( array( 'https://purge.jsdelivr.net/gh/me/media-cdn@main/media/a.jpg' ), $urls->purge_urls( array( 'media/a.jpg' ) ) );
	}

	public function test_no_purge_for_other_cdns() {
		$this->assertSame( array(), $this->urls( array( 'url_mode' => 'raw' ) )->purge_urls( array( 'media/a.jpg' ) ) );
	}
}
