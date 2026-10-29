<?php
/**
 * @group axiompress
 */
class Tests_AxiomPress_ContentAdapter extends WP_UnitTestCase {
	public function test_real_policy_and_broker_reads() {
		require_once dirname( ABSPATH ) . '/tools/axiompress/broker/bootstrap.php';
		$adapter    = new \AxiomPress\Broker\WordPressContentAdapter();
		$site       = 'site:' . get_current_blog_id();
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$author     = self::factory()->user->create( array( 'role' => 'author' ) );
		$public     = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Allowed &amp; plain</p>',
			)
		);
		$private    = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $author,
			)
		);
		$draft      = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $author,
			)
		);
		$password   = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$trash      = self::factory()->post->create( array( 'post_status' => 'trash' ) );
		$revision   = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $public,
			)
		);

		$this->assertTrue( $adapter->canRead( 'anonymous', $site, 'post:' . $public ) );
		$this->assertFalse( $adapter->canRead( 'anonymous', $site, 'post:' . $private ) );
		$this->assertFalse( $adapter->canRead( 'user:' . $subscriber, $site, 'post:' . $private ) );
		$this->assertTrue( $adapter->canRead( 'user:' . $editor, $site, 'post:' . $private ) );
		$this->assertTrue( $adapter->canRead( 'user:' . $author, $site, 'post:' . $draft ) );
		$this->assertFalse( $adapter->canRead( 'user:' . $subscriber, $site, 'post:' . $draft ) );
		$this->assertFalse( $adapter->canRead( 'anonymous', $site, 'post:' . $password ) );
		$this->assertTrue( $adapter->canRead( 'user:' . $editor, $site, 'post:' . $password ) );
		$this->assertFalse( $adapter->canRead( 'user:' . $editor, $site, 'post:' . $trash ) );
		$this->assertFalse( $adapter->canRead( 'user:' . $editor, $site, 'post:' . $revision ) );
		$this->assertFalse( $adapter->canRead( 'anonymous', 'site:999999', 'post:' . $public ) );
		$this->assertFalse( $adapter->canRead( 'user:999999', $site, 'post:' . $public ) );
		$this->assertFalse( $adapter->canRead( 'anonymous', $site, 'post:01' ) );
		$this->assertFalse( $adapter->canRead( 'anonymous', $site, 'post:9999999999999999999999999' ) );
		$this->assertNull( $adapter->read( 'site:999999', 'post:' . $public ) );
		wp_update_post(
			array(
				'ID'            => $trash,
				'post_password' => 'protected-trash',
			)
		);
		$this->assertFalse( $adapter->canRead( 'user:' . $editor, $site, 'post:' . $trash ) );
		$this->assertFalse( $adapter->canRead( 'anonymous', $site, 'post:999999999' ) );
		$this->assertNull( $adapter->read( $site, 'post:' . $revision ) );
		$current_actor = get_current_user_id();
		$adapter->canRead( 'user:' . $editor, $site, 'post:' . $private );
		$this->assertSame( $current_actor, get_current_user_id() );

		$registry = new \AxiomPress\Broker\InvocationRegistry( static fn() => 1 );
		$handle   = $registry->issue( 'test', 'test-plugin', $site, 'anonymous', array( 'post:' . $public ) );
		$broker   = new \AxiomPress\Broker\ContentBroker( $registry, $adapter->canRead( ... ), $adapter->read( ... ) );
		$request  = (object) array(
			'api'        => \AxiomPress\Protocol\Protocol::API,
			'kind'       => 'request',
			'id'         => 'r1',
			'operation'  => 'content.read',
			'invocation' => $handle,
			'params'     => (object) array( 'content_id' => 'post:' . $public ),
		);
		$result   = $broker->handle( 'test', $request )->result;
		$this->assertSame( 'Allowed & plain', $result->text );
		$this->assertFalse( property_exists( $result, 'post_password' ) );
		$first_revision = $result->revision;
		wp_update_post(
			array(
				'ID'           => $public,
				'post_content' => 'Changed',
			)
		);
		$request->id = 'r2';
		$this->assertNotSame( $first_revision, $broker->handle( 'test', $request )->result->revision );
		wp_update_post(
			array(
				'ID'          => $public,
				'post_status' => 'private',
				'post_author' => $author,
			)
		);
		$request->id = 'r3';
		try {
			$broker->handle( 'test', $request );
			$this->fail( 'Visibility revocation must deny subsequent reads.' );
		} catch ( \AxiomPress\Broker\BrokerError $error ) {
			$this->assertSame( 'not_found', $error->reason );
		} finally {
			$registry->closeConnection( 'test' );
		}
	}

	public function test_content_is_not_executed_and_limits_are_enforced() {
		require_once dirname( ABSPATH ) . '/tools/axiompress/broker/bootstrap.php';
		$adapter  = new \AxiomPress\Broker\WordPressContentAdapter();
		$site     = 'site:' . get_current_blog_id();
		$calls    = 0;
		$callback = static function () use ( &$calls ) {
			++$calls;
			return 'executed';
		};
		add_shortcode( 'axiompress_probe', $callback );
		add_filter( 'the_content', $callback );
		try {
			$post = self::factory()->post->create( array( 'post_content' => '[axiompress_probe]<p>plain</p>' ) );
			$data = $adapter->read( $site, 'post:' . $post );
			$this->assertSame( '[axiompress_probe]plain', $data->text );
			$this->assertSame( 0, $calls );
			wp_update_post(
				array(
					'ID'           => $post,
					'post_content' => str_repeat( 'x', 131073 ),
				)
			);
			try {
				$adapter->read( $site, 'post:' . $post );
				$this->fail( 'Oversized content must be rejected.' );
			} catch ( \AxiomPress\Broker\BrokerError $error ) {
				$this->assertSame( 'quota_exceeded', $error->reason );
			}
		} finally {
			remove_shortcode( 'axiompress_probe' );
			remove_filter( 'the_content', $callback );
		}
	}
}
