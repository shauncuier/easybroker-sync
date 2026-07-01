<?php
/**
 * Tests for EBS_Field_Map: payload building, validation, and ingest sanitization.
 *
 * @package EasyBroker_Sync
 */

/**
 * @covers EBS_Field_Map
 */
class Test_EBS_Field_Map extends WP_UnitTestCase {

	/**
	 * Create a fully-populated property post.
	 *
	 * @param array $meta Overrides.
	 * @return int
	 */
	private function make_property( $meta = array() ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'eb_property',
				'post_status'  => 'publish',
				'post_title'   => 'Casa en venta',
				'post_content' => 'Una hermosa casa.',
			)
		);
		$defaults = array(
			'location_name'  => 'Chablekal, Mérida, Yucatán',
			'property_type'  => 'House',
			'operation_type' => 'sale',
			'price'          => '4500000',
			'currency'       => 'MXN',
			'status'         => 'published',
		);
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) {
			update_post_meta( $post_id, '_ebs_' . $k, $v );
		}
		return $post_id;
	}

	public function test_payload_contains_required_fields_and_active_operation() {
		$post_id = $this->make_property();
		$payload = EBS_Field_Map::to_easybroker( $post_id, array(), array(), true );

		foreach ( array( 'title', 'description', 'property_type', 'status', 'operations', 'location' ) as $key ) {
			$this->assertArrayHasKey( $key, $payload, "missing required key: $key" );
		}
		$this->assertTrue( $payload['operations'][0]['active'] );
		$this->assertSame( 'sale', $payload['operations'][0]['type'] );
		$this->assertSame( 'Chablekal, Mérida, Yucatán', $payload['location']['name'] );
	}

	public function test_images_sent_on_create_but_not_on_update() {
		$post_id = $this->make_property();
		$urls    = array( 'https://assets.easybroker.com/a.jpg', 'https://assets.easybroker.com/b.jpg' );

		$create = EBS_Field_Map::to_easybroker( $post_id, $urls, array(), true );
		$this->assertArrayHasKey( 'property_images', $create );
		$this->assertCount( 2, $create['property_images'] );

		$update = EBS_Field_Map::to_easybroker( $post_id, $urls, array(), false );
		$this->assertArrayNotHasKey( 'property_images', $update, 'images must not be resent on update' );
	}

	public function test_agent_uses_valid_email_only() {
		$post_id = $this->make_property( array( 'agent_email' => 'agent@domeluxmerida.com' ) );
		$payload = EBS_Field_Map::to_easybroker( $post_id );
		$this->assertSame( 'agent@domeluxmerida.com', $payload['agent'] );

		$post_id2 = $this->make_property( array( 'agent_email' => 'not-an-email' ) );
		$payload2 = EBS_Field_Map::to_easybroker( $post_id2 );
		$this->assertArrayNotHasKey( 'agent', $payload2 );
	}

	public function test_validate_flags_missing_required_fields() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'eb_property', 'post_title' => '' ) );
		$errors  = EBS_Field_Map::validate( $post_id );
		$this->assertNotEmpty( $errors );

		$ok = EBS_Field_Map::validate( $this->make_property() );
		$this->assertSame( array(), $ok );
	}

	public function test_from_easybroker_sanitizes_incoming_values() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'eb_property' ) );
		EBS_Field_Map::from_easybroker(
			$post_id,
			array(
				'public_id' => 'EB-TEST1',
				'location'  => array( 'name' => "Mérida<script>alert(1)</script>" ),
				'bedrooms'  => '3abc',
			)
		);
		$name = get_post_meta( $post_id, '_ebs_location_name', true );
		$this->assertStringNotContainsString( '<script>', $name );
		$this->assertSame( 3, (int) get_post_meta( $post_id, '_ebs_bedrooms', true ) );
	}
}
