<?php
/**
 * Tests for EBS_Images::is_safe_remote_url (SSRF guard).
 *
 * @package EasyBroker_Sync
 */

/**
 * @covers EBS_Images::is_safe_remote_url
 */
class Test_EBS_Images extends WP_UnitTestCase {

	public function test_rejects_non_http_schemes() {
		$this->assertFalse( EBS_Images::is_safe_remote_url( 'ftp://example.com/a.jpg' ) );
		$this->assertFalse( EBS_Images::is_safe_remote_url( 'file:///etc/passwd' ) );
		$this->assertFalse( EBS_Images::is_safe_remote_url( 'javascript:alert(1)' ) );
	}

	public function test_rejects_private_and_loopback_hosts() {
		$this->assertFalse( EBS_Images::is_safe_remote_url( 'http://127.0.0.1/a.jpg' ) );
		$this->assertFalse( EBS_Images::is_safe_remote_url( 'http://169.254.169.254/latest/meta-data/' ) );
		$this->assertFalse( EBS_Images::is_safe_remote_url( 'http://10.0.0.5/a.jpg' ) );
	}

	public function test_allows_public_https_url() {
		$this->assertTrue( EBS_Images::is_safe_remote_url( 'https://assets.easybroker.com/x/EB-VP3628.jpg' ) );
	}

	public function test_respects_host_allowlist_filter() {
		add_filter( 'ebs_allowed_image_hosts', function () {
			return array( 'assets.easybroker.com' );
		} );
		$this->assertTrue( EBS_Images::is_safe_remote_url( 'https://assets.easybroker.com/x.jpg' ) );
		$this->assertFalse( EBS_Images::is_safe_remote_url( 'https://evil.example.com/x.jpg' ) );
		remove_all_filters( 'ebs_allowed_image_hosts' );
	}
}
