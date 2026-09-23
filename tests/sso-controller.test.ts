import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type RestResponse = {
	data: {
		needsLogin: boolean;
		loginUrl: string;
		data: null | { bookerEmail: string; bookerName: string };
		error?: string;
	};
	status: number;
	headers: Record< string, string >;
};

type SsoResult = {
	security: Record<
		string,
		{
			rest: RestResponse;
			ajaxStatus: number;
			ajaxCache: string;
			reads: number;
		}
	>;
	authenticated: RestResponse;
	anonymous: RestResponse;
	malformedReturnUrl: RestResponse;
	integrationFailure: RestResponse;
	ajaxSuccess: { bookerEmail: string; bookerName: string };
	ajaxError: {
		needsLogin: boolean;
		data: { bookerEmail: string; bookerName: string };
	};
	invalidLoginIgnored: boolean;
	authenticationCompatibility: {
		started: boolean;
		checkCalls: number;
		simpleSamlCalls: number;
	};
	authenticationScenarios: Record<
		string,
		{ started: boolean; requireAuthCalls: unknown[] }
	>;
};

const getSsoResult = (): SsoResult => {
	const controllerPath = resolve(
		process.cwd(),
		'includes/Controller/SsoController.php'
	);
	const php = `
		namespace {
			class WP_REST_Request {
				private $parameters;
				public $headers = [ 'origin' => 'https://example.test' ];
				public $method = 'POST';
				public function get_header( $name ) { return $this->headers[$name] ?? ''; }
				public function get_method() { return $this->method; }
				public function __construct( array $parameters ) { $this->parameters = $parameters; }
				public function get_param( $key ) { return $this->parameters[ $key ] ?? null; }
			}
			class WP_REST_Response {
				public $data;
				public $status;
				public $headers;
				public function __construct( $data, $status, $headers = [] ) {
					$this->headers = $headers;
					$this->data = $data;
					$this->status = $status;
				}
			}
		}
		namespace RRZE\\AccessControl {
			class Permissions {}
		}
		namespace RRZE\\Appointment {
			class Rights {
				public static $identity = [];
				public static $shouldThrow = false;
				public static $reads = 0;
				public static function get(): array {
					self::$reads++;
					if ( self::$shouldThrow ) { throw new \\RuntimeException( 'integration failed' ); }
					return self::$identity;
				}
			}
			class BooleanPermissions {
				public $checkCalls = 0;
				public $simpleSamlCalls = 0;
				public function checkSSOLoggedIn() {
					$this->checkCalls++;
					return false;
				}
				public function simplesamlAuth() {
					$this->simpleSamlCalls++;
					return true;
				}
			}
			class AuthClient {
				public $requireAuthCalls = [];
				public $authenticated = false;
				public function isAuthenticated() { return $this->authenticated; }
				public function requireAuth( $options ) { $this->requireAuthCalls[] = $options; }
			}
			class CurrentPermissions {
				public $simplesamlAuth;
				public function __construct( $authenticated = false ) {
					$this->simplesamlAuth = new AuthClient();
					$this->simplesamlAuth->authenticated = $authenticated;
				}
				public function checkSSOLoggedIn( $startAutomaticAuthentication = false ) {
					return $this->simplesamlAuth->isAuthenticated();
				}
				public function simplesamlAuth() { return true; }
			}
			class ObjectPermissions extends CurrentPermissions {
				public function simplesamlAuth() { return $this->simplesamlAuth; }
			}
		}
		namespace RRZE\\Appointment\\Controller {
			function header( $value ) { $GLOBALS['identity_cache'] = $value; }
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['ajax_success'] = null;
			$GLOBALS['ajax_error'] = null;
			$GLOBALS['redirects'] = [];
			$GLOBALS['dies'] = [];
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['HTTP_ORIGIN'] = 'https://example.test';
			function wp_parse_url( $value ) { return parse_url( $value ); }
			function nocache_headers() {}

			function __( $value, $domain ) { return $value; }
			function home_url( $path = '' ) { return 'https://example.test' . $path; }
			function wp_get_referer() { return 'https://example.test/referrer'; }
			function wp_validate_redirect( $url, $fallback = '' ) {
				return is_string( $url ) && strpos( $url, 'https://example.test/' ) === 0
					? $url
					: $fallback;
			}
			function add_query_arg( $arguments, $url ) {
				return $url . '?' . http_build_query( $arguments );
			}
			function remove_query_arg( $keys ) { return 'https://example.test/current'; }
			function wp_unslash( $value ) {
				if ( is_array( $value ) ) { return array_map( 'wp_unslash', $value ); }
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
			function sanitize_email( $value ) {
				return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
			}
			function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
			function wp_send_json_success( $data, $status = 200 ) { $GLOBALS['ajax_success'] = $data; $GLOBALS['ajax_status'] = $status; }
			function wp_send_json_error( $data, $status = 200 ) { $GLOBALS['ajax_error'] = $data; $GLOBALS['ajax_status'] = $status; }
			function wp_safe_redirect( $url ) { $GLOBALS['redirects'][] = $url; }
			function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
			function wp_die( $message, $title = '', $arguments = [] ) {
				$GLOBALS['dies'][] = compact( 'message', 'arguments' );
			}

			require ${ JSON.stringify( controllerPath ) };

			$controller = new \\RRZE\\Appointment\\Controller\\SsoController();
			\\RRZE\\Appointment\\Rights::$identity = [
				'authenticated' => true,
				'bookerEmail' => 'ada@example.test',
				'bookerName' => ' <b>Ada Lovelace</b> ',
			];
			$authenticated = $controller->handleBookerRequest(
				new WP_REST_Request( [ 'returnTo' => 'https://example.test/appointments' ] )
			);

			\\RRZE\\Appointment\\Rights::$identity = [ 'authenticated' => false ];
			$anonymous = $controller->handleBookerRequest(
				new WP_REST_Request( [ 'returnTo' => 'https://evil.test/steal' ] )
			);
			$malformedReturnUrl = $controller->handleBookerRequest(
				new WP_REST_Request( [ 'returnTo' => [ 'https://example.test/appointments' ] ] )
			);

			\\RRZE\\Appointment\\Rights::$shouldThrow = true;
			$integrationFailure = $controller->handleBookerRequest(
				new WP_REST_Request( [ 'returnTo' => 'https://example.test/appointments' ] )
			);
			\\RRZE\\Appointment\\Rights::$shouldThrow = false;

			$GLOBALS['ajax_success'] = null;
			$GLOBALS['ajax_error'] = null;
			$_POST = [ 'returnTo' => 'https://example.test/appointments' ];
			\\RRZE\\Appointment\\Rights::$identity = [
				'authenticated' => true,
				'bookerEmail' => 'grace@example.test',
				'bookerName' => 'Grace Hopper',
			];
			$controller->handleBookerRequest();
			$ajaxSuccess = $GLOBALS['ajax_success'];

			$GLOBALS['ajax_success'] = null;
			$GLOBALS['ajax_error'] = null;
			\\RRZE\\Appointment\\Rights::$identity = [ 'authenticated' => false ];
			$controller->handleBookerRequest();
			$ajaxError = $GLOBALS['ajax_error'];

			$_GET = [ 'rrze_appt_sso' => [ '1' ], 'rrze_appt_return' => [ '/unsafe' ] ];
			$controller->handleLogin();
			$invalidLoginIgnored = $GLOBALS['redirects'] === [] && $GLOBALS['dies'] === [];

			$startAuthentication = new ReflectionMethod(
				\\RRZE\\Appointment\\Controller\\SsoController::class,
				'startAuthentication'
			);
			if ( PHP_VERSION_ID < 80100 ) { $startAuthentication->setAccessible( true ); }
			$permissions = new \\RRZE\\Appointment\\BooleanPermissions();
			$started = $startAuthentication->invoke(
				$controller,
				$permissions,
				'https://example.test/appointments'
			);
			$authenticationScenarios = [];
			foreach ( [
				'currentAnonymous' => new \\RRZE\\Appointment\\CurrentPermissions(),
				'currentAuthenticated' => new \\RRZE\\Appointment\\CurrentPermissions( true ),
				'objectAnonymous' => new \\RRZE\\Appointment\\ObjectPermissions(),
			] as $scenario => $scenarioPermissions ) {
				$authenticationScenarios[$scenario] = [
					'started' => $startAuthentication->invoke(
						$controller, $scenarioPermissions, 'https://example.test/appointments'
					),
					'requireAuthCalls' => $scenarioPermissions->simplesamlAuth->requireAuthCalls,
				];
			}


			$security = [];
			\\RRZE\\Appointment\\Rights::$identity = [
				'authenticated' => true, 'bookerEmail' => 'private@example.test', 'bookerName' => 'Private Name',
			];
			foreach ( [
				'allowedOrigin' => [ 'origin' => 'https://example.test' ],
				'allowedDefaultPort' => [ 'origin' => 'https://EXAMPLE.test:443' ],
				'allowedMetadata' => [ 'sec-fetch-site' => 'same-origin' ],
				'allowedReferer' => [ 'referer' => 'https://example.test/appointments?x=1' ],
				'deniedForeignOrigin' => [ 'origin' => 'https://evil.test' ],
				'deniedSibling' => [ 'origin' => 'https://other.example.test', 'sec-fetch-site' => 'same-site' ],
				'deniedPort' => [ 'origin' => 'https://example.test:8890' ],
				'deniedScheme' => [ 'origin' => 'http://example.test' ],
				'deniedNullOrigin' => [ 'origin' => 'null', 'referer' => 'https://example.test/' ],
				'deniedMissing' => [],
				'deniedMetadata' => [ 'origin' => 'https://example.test', 'sec-fetch-site' => 'cross-site' ],
				'deniedForeignReferer' => [ 'referer' => 'https://evil.test/' ],
				'deniedOriginList' => [ 'origin' => 'https://example.test, https://evil.test' ],
				'deniedCredentials' => [ 'origin' => 'https://evil.test@example.test' ],
				'deniedPath' => [ 'origin' => 'https://example.test/path' ],
				'deniedQuery' => [ 'origin' => 'https://example.test?evil' ],
				'deniedGet' => [ 'origin' => 'https://example.test' ],
			] as $scenario => $headers ) {
				$request = new WP_REST_Request( [] );
				$request->headers = $headers;
				$request->method = $scenario === 'deniedGet' ? 'GET' : 'POST';
				\\RRZE\\Appointment\\Rights::$reads = 0;
				$rest = $controller->handleBookerRequest( $request );
				$_SERVER = [ 'REQUEST_METHOD' => $request->method ];
				foreach ( $headers as $name => $value ) {
					$_SERVER['HTTP_' . strtoupper( str_replace( '-', '_', $name ) )] = $value;
				}
				$controller->handleBookerRequest();
				$security[$scenario] = [
					'rest' => $rest,
					'ajaxStatus' => $GLOBALS['ajax_status'],
					'ajaxCache' => $GLOBALS['identity_cache'],
					'reads' => \\RRZE\\Appointment\\Rights::$reads,
				];
			}

			echo json_encode( [
				'security' => $security,
				'authenticationScenarios' => $authenticationScenarios,
				'authenticated' => $authenticated,
				'anonymous' => $anonymous,
				'malformedReturnUrl' => $malformedReturnUrl,
				'integrationFailure' => $integrationFailure,
				'ajaxSuccess' => $ajaxSuccess,
				'ajaxError' => $ajaxError,
				'invalidLoginIgnored' => $invalidLoginIgnored,
				'authenticationCompatibility' => [
					'started' => $started,
					'checkCalls' => $permissions->checkCalls,
					'simpleSamlCalls' => $permissions->simpleSamlCalls,
				],
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as SsoResult;
};

describe( 'SSO controller', () => {
	it( 'returns a normalized authenticated identity over REST', () => {
		const result = getSsoResult().authenticated;

		expect( result.status ).toBe( 200 );
		expect( result.data ).toEqual( {
			needsLogin: false,
			loginUrl: '',
			data: {
				bookerEmail: 'ada@example.test',
				bookerName: 'Ada Lovelace',
			},
		} );
	} );

	it( 'uses only validated local return URLs for anonymous visitors', () => {
		const result = getSsoResult();
		const expectedLoginUrl =
			'https://example.test/?rrze_appt_sso=1&rrze_appt_return=' +
			'https%3A%2F%2Fexample.test%2Freferrer';

		expect( result.anonymous.data.loginUrl ).toBe( expectedLoginUrl );
		expect( result.malformedReturnUrl.data.loginUrl ).toBe(
			expectedLoginUrl
		);
		expect( result.anonymous.data ).toMatchObject( {
			needsLogin: true,
			data: { bookerEmail: '', bookerName: '' },
		} );
	} );

	it( 'returns generic integration failures without a redirect URL', () => {
		expect( getSsoResult().integrationFailure.data ).toEqual( {
			needsLogin: true,
			loginUrl: '',
			error: 'SSO login failed.',
			data: null,
		} );
	} );

	it( 'preserves the legacy AJAX response contract', () => {
		const result = getSsoResult();

		expect( result.ajaxSuccess ).toEqual( {
			bookerEmail: 'grace@example.test',
			bookerName: 'Grace Hopper',
		} );
		expect( result.ajaxError ).toMatchObject( {
			needsLogin: true,
			data: { bookerEmail: '', bookerName: '' },
		} );
	} );

	it( 'rejects nested login parameters and fails when authentication cannot start', () => {
		const result = getSsoResult();

		expect( result.invalidLoginIgnored ).toBe( true );
		expect( result.authenticationCompatibility ).toEqual( {
			started: false,
			checkCalls: 2,
			simpleSamlCalls: 1,
		} );
	} );

	it( 'explicitly starts SSO with the boolean initializer and public auth property', () => {
		expect(
			getSsoResult().authenticationScenarios.currentAnonymous
		).toEqual( {
			started: true,
			requireAuthCalls: [
				{
					ReturnTo: 'https://example.test/appointments',
					KeepPost: false,
				},
			],
		} );
	} );

	it( 'reuses an existing SSO session without starting another login', () => {
		expect(
			getSsoResult().authenticationScenarios.currentAuthenticated
		).toEqual( { started: true, requireAuthCalls: [] } );
	} );

	it( 'also supports an initializer that returns the auth client directly', () => {
		expect(
			getSsoResult().authenticationScenarios.objectAnonymous
		).toEqual( {
			started: true,
			requireAuthCalls: [
				{
					ReturnTo: 'https://example.test/appointments',
					KeepPost: false,
				},
			],
		} );
	} );
} );

describe( 'booking identity privacy', () => {
	const scenarios = getSsoResult().security;
	it.each( Object.keys( scenarios ) )(
		'%s protects REST and AJAX identity access',
		( name ) => {
			const result = scenarios[ name ];
			const allowed = name.startsWith( 'allowed' );
			expect( result.rest.status ).toBe( allowed ? 200 : 403 );
			expect( result.ajaxStatus ).toBe( allowed ? 200 : 403 );
			expect( result.reads ).toBe( allowed ? 2 : 0 );
			expect( result.rest.headers[ 'Cache-Control' ] ).toContain(
				'private, no-store'
			);
			expect( result.ajaxCache ).toContain( 'private, no-store' );
			if ( ! allowed ) {
				expect( result.rest.data.data ).toBeNull();
				expect( JSON.stringify( result.rest ) ).not.toContain(
					'private@example.test'
				);
			}
		}
	);
} );
