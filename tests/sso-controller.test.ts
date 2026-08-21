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
};

type SsoResult = {
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
};

const getSsoResult = (): SsoResult => {
	const controllerPath = resolve(
		process.cwd(),
		'includes/SsoController.php'
	);
	const php = `
		namespace {
			class WP_REST_Request {
				private $parameters;
				public function __construct( array $parameters ) { $this->parameters = $parameters; }
				public function get_param( $key ) { return $this->parameters[ $key ] ?? null; }
			}
			class WP_REST_Response {
				public $data;
				public $status;
				public function __construct( $data, $status ) {
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
				public static function get(): array {
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
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['ajax_success'] = null;
			$GLOBALS['ajax_error'] = null;
			$GLOBALS['redirects'] = [];
			$GLOBALS['dies'] = [];

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
			function wp_send_json_success( $data ) { $GLOBALS['ajax_success'] = $data; }
			function wp_send_json_error( $data ) { $GLOBALS['ajax_error'] = $data; }
			function wp_safe_redirect( $url ) { $GLOBALS['redirects'][] = $url; }
			function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
			function wp_die( $message, $title = '', $arguments = [] ) {
				$GLOBALS['dies'][] = compact( 'message', 'arguments' );
			}

			require ${ JSON.stringify( controllerPath ) };

			$controller = new \\RRZE\\Appointment\\SsoController();
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
				\\RRZE\\Appointment\\SsoController::class,
				'startAuthentication'
			);
			if ( PHP_VERSION_ID < 80100 ) { $startAuthentication->setAccessible( true ); }
			$permissions = new \\RRZE\\Appointment\\BooleanPermissions();
			$started = $startAuthentication->invoke(
				$controller,
				$permissions,
				'https://example.test/appointments'
			);

			echo json_encode( [
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

	it( 'rejects nested login parameters and supports boolean AccessControl APIs', () => {
		const result = getSsoResult();

		expect( result.invalidLoginIgnored ).toBe( true );
		expect( result.authenticationCompatibility ).toEqual( {
			started: true,
			checkCalls: 2,
			simpleSamlCalls: 1,
		} );
	} );
} );
