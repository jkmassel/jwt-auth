<?php
/**
 * REST API: Tests for the WP_REST_Token class.
 *
 * @package JWTAuth
 * @subpackage REST_API
 * @since 0.1
 */

/**
 * Class Test_WP_REST_Token
 *
 * @since 0.1
 * @coversDefaultClass WP_REST_Token
 */
class Test_WP_REST_Token extends WP_UnitTestCase {

	/**
	 * REST Server.
	 *
	 * @var WP_REST_Server
	 */
	public $server;

	/**
	 * REST Token.
	 *
	 * @var WP_REST_Token
	 */
	public $token;

	/**
	 * Setup.
	 *
	 * @inheritdoc
	 */
	public function setUp() {
		parent::setUp();

		// @codingStandardsIgnoreStart
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		// @codingStandardsIgnoreEnd

		$this->server = $GLOBALS['wp_rest_server'];
		do_action( 'rest_api_init' );

		$this->token = new WP_REST_Token();
		$this->token->init();
	}

	/**
	 * Teardown.
	 *
	 * @inheritdoc
	 */
	public function tearDown() {
		$this->server = null;
		$this->token  = null;
		unset( $GLOBALS['wp_rest_server'] );
		parent::tearDown();
	}

	/**
	 * Test init().
	 *
	 * @covers ::init()
	 */
	public function test_init() {
		$this->assertEquals( 99, has_action( 'rest_api_init', array( $this->token, 'register_routes' ) ) );
		$this->assertEquals( 10, has_action( 'rest_authentication_errors', array( $this->token, 'authenticate' ) ) );
	}

	/**
	 * Test get_rest_uri().
	 *
	 * @covers ::get_rest_uri()
	 */
	public function test_get_rest_uri() {
		$this->assertEquals( '/wp-json/wp/v2/token', WP_REST_Token::get_rest_uri() );
	}

	/**
	 * Test register_routes().
	 *
	 * @covers ::register_routes()
	 * @since 0.1
	 */
	public function test_register_routes() {
		$this->assertArrayHasKey( '/wp/v2/token', $this->server->get_routes() );
	}

	/**
	 * Test get_item_schema().
	 *
	 * @covers ::get_item_schema()
	 * @since 0.1
	 */
	public function test_get_item_schema() {
		$schema = $this->token->get_item_schema();
		$this->assertArrayHasKey( '$schema', $schema );
		$this->assertArrayHasKey( 'title', $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	/**
	 * Test authenticate().
	 *
	 * @covers ::authenticate()
	 * @since 0.1
	 */
	public function test_authenticate() {
		$user_data = array(
			'role'       => 'administrator',
			'user_login' => 'testuser',
			'user_pass'  => 'testpassword',
			'user_email' => 'testuser@sample.org',
		);

		$user_id = $this->factory->user->create( $user_data );

		$jwt = json_decode(
			wp_json_encode(
				array(
					'data' => array(
						'user' => array(
							'id'         => $user_id,
							'type'       => 'wp_user',
							'user_login' => 'testuser',
							'user_email' => 'testuser@sample.org',
						),
					),
				)
			)
		);

		// Another authentication method was used.
		$this->assertEquals( 'alt_auth', $this->token->authenticate( 'alt_auth' ) );

		// Not is REST request.
		$this->assertNull( $this->token->authenticate( null ) );

		// Fake the request.
		add_filter( 'rest_authentication_is_rest_request', '__return_true' );

		// Authentication is not required.
		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'require_token',
				)
			)
			->getMock();
		$mock->method( 'require_token' )->willReturn( false );
		$this->assertNull( $mock->authenticate( null ) );

		// Invalid bearer token.
		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'require_token',
					'validate_token',
				)
			)
			->getMock();
		$mock->method( 'require_token' )->willReturn( true );
		$mock->method( 'validate_token' )->willReturn(
			new WP_Error(
				'rest_authentication_token_error',
				__( 'Invalid bearer token.', 'jwt-auth' ),
				array(
					'status' => 403,
				)
			)
		);

		$authenticate = $mock->authenticate( null );
		$this->assertTrue( is_wp_error( $authenticate ) );
		$this->assertEquals( $authenticate->get_error_code(), 'rest_authentication_token_error' );

		wp_set_current_user( 0 );

		// Set the current user.
		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'require_token',
					'validate_token',
				)
			)
			->getMock();
		$mock->method( 'require_token' )->willReturn( true );
		$mock->method( 'validate_token' )->willReturn( $jwt );

		$authenticate = $mock->authenticate( null );
		$this->assertTrue( $authenticate );
		$this->assertEquals( $user_id, get_current_user_id() );
		remove_filter( 'rest_authentication_is_rest_request', '__return_true' );
	}

	/**
	 * Test require_token().
	 *
	 * @covers ::require_token()
	 * @since 0.1
	 */
	public function test_require_token() {
		$prefix    = rest_get_url_prefix();
		$token_uri = sprintf( '/%s/wp/v2/token', $prefix );
		$posts_uri = sprintf( '/%s/wp/v2/posts', $prefix );
		$user_data = array(
			'role'       => 'administrator',
			'user_login' => 'testuser',
			'user_pass'  => 'testpassword',
			'user_email' => 'testuser@sample.org',
		);

		// @codingStandardsIgnoreStart
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/bad/path';

		// User is already authenticated.
		wp_set_current_user( $this->factory->user->create( $user_data ) );
		$this->assertFalse( $this->token->require_token() );
		wp_set_current_user( 0 );

		// Only check REST API requests.
		$this->assertFalse( $this->token->require_token() );

		// GET requests do not need to be authenticated.
		$_SERVER['REQUEST_URI'] = $token_uri;
		$this->assertFalse( $this->token->require_token() );

		// Don't require authentication to generate a token.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertFalse( $this->token->require_token() );

		// Must authenticate POST requests for the posts endpoint.
		$_SERVER['REQUEST_URI'] = $posts_uri;
		$this->assertTrue( $this->token->require_token() );

		// Filter to force GET requests to validate.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		add_filter( 'rest_authentication_require_token', '__return_true' );
		$this->assertTrue( $this->token->require_token() );
		add_filter( 'rest_authentication_require_token', '__return_true' );

		unset( $_SERVER['REQUEST_METHOD'] );
		unset( $_SERVER['REQUEST_URI'] );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Test generate_token() `rest_authentication_user` filter.
	 *
	 * @covers ::generate_token()
	 * @since 0.1
	 */
	public function test_generate_token_rest_authentication_user() {
		$request  = new WP_REST_Request( 'POST', 'wp/v2/token' );
		$function = function() {
			return new WP_Error(
				'rest_authentication_missing_user_id',
				__( 'The user ID is missing from the user object.', 'jwt-auth' ),
				array(
					'status' => 403,
				)
			);
		};
		add_filter( 'rest_authentication_user', $function );
		$generate_token = $this->token->generate_token( $request );
		$this->assertTrue( is_wp_error( $generate_token ) );
		$this->assertEquals( $generate_token->get_error_code(), 'rest_authentication_missing_user_id' );
		remove_filter( 'rest_authentication_user', $function );

		$function = function() {
			return json_decode( wp_json_encode( array() ) );
		};
		add_filter( 'rest_authentication_user', $function );
		$generate_token = $this->token->generate_token( $request );
		$this->assertTrue( is_wp_error( $generate_token ) );
		$this->assertEquals( $generate_token->get_error_code(), 'rest_authentication_missing_user_id' );
		remove_filter( 'rest_authentication_user', $function );

		$function = function() {
			return json_decode(
				wp_json_encode(
					array(
						'ID' => 10,
					)
				)
			);
		};
		add_filter( 'rest_authentication_user', $function );
		$generate_token = $this->token->generate_token( $request );
		$this->assertTrue( is_wp_error( $generate_token ) );
		$this->assertEquals( $generate_token->get_error_code(), 'rest_authentication_missing_user_login' );
		remove_filter( 'rest_authentication_user', $function );

		$function = function() {
			return json_decode(
				wp_json_encode(
					array(
						'ID'   => 10,
						'data' => array(
							'user_login' => 'testuser',
						),
					)
				)
			);
		};
		add_filter( 'rest_authentication_user', $function );
		$generate_token = $this->token->generate_token( $request );
		$this->assertTrue( is_wp_error( $generate_token ) );
		$this->assertEquals( $generate_token->get_error_code(), 'rest_authentication_missing_user_email' );
		remove_filter( 'rest_authentication_user', $function );
	}

	/**
	 * Test generate_token().
	 *
	 * @covers ::generate_token()
	 * @since 0.1
	 */
	public function test_generate_token() {
		$user_data = array(
			'role'       => 'administrator',
			'user_login' => 'testuser',
			'user_pass'  => 'testpassword',
			'user_email' => 'testuser@sample.org',
		);

		$request = new WP_REST_Request( 'POST', 'wp/v2/token' );

		// Set incorrect credentials.
		$request->set_param( 'username', $user_data['user_login'] );
		$request->set_param( 'password', 'incorrect-password' );
		$token = $this->token->generate_token( $request );

		// Test with invalid user.
		$this->assertTrue( is_wp_error( $token ) );
		$this->assertEquals( 'rest_authentication_invalid_username', $token->get_error_code() );

		$user_id = $this->factory->user->create( $user_data );
		$token   = $this->token->generate_token( $request );

		// Test with incorrect credentials.
		$this->assertTrue( is_wp_error( $token ) );
		$this->assertEquals( 'rest_authentication_incorrect_password', $token->get_error_code() );

		$private_claims = function( $payload ) {
			$payload['data']['user']['api_key'] = 12345;
			return $payload;
		};
		add_filter( 'rest_authentication_token_private_claims', $private_claims );

		$token_response = function( $response ) {
			$response['refresh_token'] = 54321;
			return $response;
		};
		add_filter( 'rest_authentication_token_response', $token_response );

		// Test with correct credentials.
		$request->set_param( 'password', $user_data['user_pass'] );
		$token = $this->token->generate_token( $request );

		// Test if access_token was generated.
		$this->assertArrayHasKey( 'access_token', $token );
		$this->assertTrue( ! empty( $token['access_token'] ) );
		$this->assertArrayHasKey( 'data', $token );
		$this->assertArrayHasKey( 'refresh_token', $token );
		$this->assertEquals( $user_id, $token['data']['user']['id'] );
		$this->assertEquals( $user_data['user_login'], $token['data']['user']['user_login'] );
		$this->assertEquals( $user_data['user_email'], $token['data']['user']['user_email'] );
		$this->assertEquals( 12345, $token['data']['user']['api_key'] );
		$this->assertEquals( 54321, $token['refresh_token'] );

		remove_filter( 'rest_authentication_token_private_claims', $private_claims );
		remove_filter( 'rest_authentication_token_response', $token_response );
	}

	/**
	 * Test validate_token().
	 *
	 * @covers ::validate_token()
	 * @since 0.1
	 */
	public function test_validate_token() {
		$user_data = array(
			'role'       => 'administrator',
			'user_login' => 'testuser',
			'user_pass'  => 'testpassword',
			'user_email' => 'testuser@sample.org',
		);

		$user_id = $this->factory->user->create( $user_data );

		$jwt = json_decode(
			wp_json_encode(
				array(
					'iss'  => 'http://nope.com',
					'exp'  => time() - 1,
					'data' => array(
						'user' => array(
							'id'         => 10,
							'type'       => 'wp_user',
							'user_login' => 'testuser',
							'user_email' => 'testuser@sample.org',
						),
					),
				)
			)
		);

		// Invalid HTTP Authorization Header.
		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'get_auth_header',
				)
			)
			->getMock();
		$mock->method( 'get_auth_header' )->willReturn(
			new WP_Error(
				'rest_authentication_no_header',
				__( 'Authorization header was not found.', 'jwt-auth' ),
				array(
					'status' => 403,
				)
			)
		);

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_no_header' );

		// Missing Bearer token from the header.
		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'get_auth_header',
					'get_token',
				)
			)
			->getMock();
		$mock->method( 'get_auth_header' )->willReturn( true );
		$mock->method( 'get_token' )->willReturn(
			new WP_Error(
				'rest_authentication_no_token',
				__( 'Authentication token is missing.', 'jwt-auth' ),
				array(
					'status' => 403,
				)
			)
		);

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_no_token' );

		// @codingStandardsIgnoreStart
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 12345';
		// @codingStandardsIgnoreEnd

		// Invalid token issuer.
		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'jwt',
				)
			)
			->getMock();
		$mock->method( 'jwt' )->willReturn( $jwt );

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_invalid_token_issuer' );

		// Invalid token user.
		$jwt->iss = get_bloginfo( 'url' );
		$mock     = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'jwt',
				)
			)
			->getMock();
		$mock->method( 'jwt' )->willReturn( $jwt );

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_invalid_token_wp_user' );

		// Expired token.
		$jwt->data->user->id = $user_id;

		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'jwt',
				)
			)
			->getMock();
		$mock->method( 'jwt' )->willReturn( $jwt );

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_token_expired' );

		// Valid token.
		$jwt->exp = time() + 100;

		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'jwt',
				)
			)
			->getMock();
		$mock->method( 'jwt' )->willReturn( $jwt );

		$this->assertEquals( $jwt, $mock->validate_token() );

		// Unknown JWT Exception.
		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'jwt',
				)
			)
			->getMock();
		$mock->method( 'jwt' )->willReturn( new Exception() );

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_token_error' );

		// Invalid token, user email has changed.
		wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => 'testuser1@sample.org',
			)
		);

		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'jwt',
				)
			)
			->getMock();
		$mock->method( 'jwt' )->willReturn( $jwt );

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_invalid_token_user_email' );

		// Invalid token, user login has changed. You cannot change your login, but better safe than sorry.
		$jwt->data->user->user_login = 'testuser1';

		$mock = $this->getMockBuilder( get_class( $this->token ) )
			->setMethods(
				array(
					'jwt',
				)
			)
			->getMock();
		$mock->method( 'jwt' )->willReturn( $jwt );

		$validate_token = $mock->validate_token();
		$this->assertTrue( is_wp_error( $validate_token ) );
		$this->assertEquals( $validate_token->get_error_code(), 'rest_authentication_invalid_token_user_login' );

		// @codingStandardsIgnoreStart
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Test get_auth_header().
	 *
	 * @covers ::get_auth_header()
	 * @since 0.1
	 */
	public function test_get_auth_header() {
		$http_authorization = 'test_http_authorization';

		// @codingStandardsIgnoreStart
		$_SERVER['HTTP_AUTHORIZATION']          = $http_authorization;
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = $http_authorization;

		$this->assertEquals( $http_authorization, $this->token->get_auth_header() );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertEquals( $http_authorization, $this->token->get_auth_header() );
		unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$auth_header = $this->token->get_auth_header();
		$this->assertTrue( is_wp_error( $auth_header ) );
		$this->assertEquals( $auth_header->get_error_code(), 'rest_authentication_no_header' );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Test get_token().
	 *
	 * @covers ::get_token()
	 * @since 0.1
	 */
	public function test_get_token() {
		$token_valid = $this->token->get_token( 'Bearer ' );
		$this->assertTrue( is_wp_error( $token_valid ) );
		$this->assertEquals( $token_valid->get_error_code(), 'rest_authentication_no_token' );

		$this->assertEquals( 12345, $this->token->get_token( 'Bearer 12345' ) );
	}

	/**
	 * Test validate_issuer().
	 *
	 * @covers ::validate_issuer()
	 * @since 0.1
	 */
	public function test_validate_issuer() {
		$issuer_valid = $this->token->validate_issuer( 'http://nope.com' );
		$this->assertTrue( is_wp_error( $issuer_valid ) );
		$this->assertEquals( $issuer_valid->get_error_code(), 'rest_authentication_invalid_token_issuer' );

		$this->assertTrue( $this->token->validate_issuer( get_bloginfo( 'url' ) ) );
	}

	/**
	 * Test validate_user().
	 *
	 * @covers ::validate_user()
	 * @since 0.1
	 */
	public function test_validate_user() {
		$user_data = array(
			'role'       => 'administrator',
			'user_login' => 'testuser',
			'user_pass'  => 'testpassword',
			'user_email' => 'testuser@sample.org',
		);

		$jwt = json_decode(
			wp_json_encode(
				array(
					'data' => array(
						'user' => array(
							'id'         => 10,
							'type'       => 'wp_user',
							'user_login' => 'testuser',
							'user_email' => 'testuser@sample.org',
						),
					),
				)
			)
		);

		$user_valid = $this->token->validate_user( false );
		$this->assertTrue( is_wp_error( $user_valid ) );
		$this->assertEquals( $user_valid->get_error_code(), 'rest_authentication_missing_token_user_id' );

		$user_valid = $this->token->validate_user( $jwt );
		$this->assertTrue( is_wp_error( $user_valid ) );
		$this->assertEquals( $user_valid->get_error_code(), 'rest_authentication_invalid_token_wp_user' );

		// Create the user.
		$jwt->data->user->id         = $this->factory->user->create( $user_data );
		$jwt->data->user->user_login = 'testuser1';

		$user_valid = $this->token->validate_user( $jwt );
		$this->assertTrue( is_wp_error( $user_valid ) );
		$this->assertEquals( $user_valid->get_error_code(), 'rest_authentication_invalid_token_user_login' );

		// Change user values.
		$jwt->data->user->user_login = 'testuser';
		$jwt->data->user->user_email = 'testuser1@sample.org';

		$user_valid = $this->token->validate_user( $jwt );
		$this->assertTrue( is_wp_error( $user_valid ) );
		$this->assertEquals( $user_valid->get_error_code(), 'rest_authentication_invalid_token_user_email' );

		// Reset user email.
		$jwt->data->user->user_email = 'testuser@sample.org';

		$user_valid = $this->token->validate_user( $jwt );
		$this->assertTrue( $user_valid );
	}

	/**
	 * Test validate_expiration().
	 *
	 * @covers ::validate_expiration()
	 * @since 0.1
	 */
	public function test_validate_expiration() {
		$jwt = json_decode(
			wp_json_encode(
				array(
					'exp' => time() - 1,
				)
			)
		);

		$expiration_valid = $this->token->validate_expiration( false );
		$this->assertTrue( is_wp_error( $expiration_valid ) );
		$this->assertEquals( $expiration_valid->get_error_code(), 'rest_authentication_missing_token_expiration' );

		$expiration_valid = $this->token->validate_expiration( $jwt );
		$this->assertTrue( is_wp_error( $expiration_valid ) );
		$this->assertEquals( $expiration_valid->get_error_code(), 'rest_authentication_token_expired' );

		$jwt->exp         = time() + 10;
		$expiration_valid = $this->token->validate_expiration( $jwt );
		$this->assertTrue( $expiration_valid );
	}
}
