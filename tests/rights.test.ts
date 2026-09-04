import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type Booker = {
	authenticated: boolean;
	bookerName: string;
	bookerEmail: string;
};

type RightsResult = {
	anonymous: Booker;
	structured: Booker;
	displayName: Booker;
	failedSso: Booker;
	missingMarker: Booker;
};

const getRightsResult = (): RightsResult => {
	const rightsPath = resolve( process.cwd(), 'includes/Rights.php' );
	const php = `
		namespace RRZE\\Appointment {
			class AppointmentException extends \\Exception {}
		}
		namespace RRZE\\AccessControl {
			class AuthStub {
				private array $attributes;
				private bool $throws;
				public function __construct( array $attributes, bool $throws = false ) {
					$this->attributes = $attributes;
					$this->throws = $throws;
				}
				public function isAuthenticated(): bool {
					if ( $this->throws ) { throw new \\RuntimeException( 'SSO unavailable' ); }
					return true;
				}
				public function getAttributes(): array { return $this->attributes; }
			}
			class Permissions {
				public $simplesamlAuth;
				public function __construct() {
					$scenario = $GLOBALS['sso_scenario'];
					$this->simplesamlAuth = new AuthStub(
						$scenario['attributes'] ?? [],
						$scenario['throws'] ?? false
					);
				}
				public function simplesamlAuth(): bool {
					return $GLOBALS['sso_scenario']['loaded'] ?? true;
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
			function sanitize_email( $value ) {
				return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
			}
			require ${ JSON.stringify( rightsPath ) };

			$class = \\RRZE\\Appointment\\Rights::class;
			$GLOBALS['sso_scenario'] = [ 'loaded' => false ];
			$anonymous = $class::get();
			$GLOBALS['sso_scenario'] = [
				'attributes' => [
					'uid' => [ '', 'idm-123' ],
					'givenName' => [ '<b>Ada</b>' ],
					'sn' => [ 'Lovelace' ],
					'mail' => [ 'invalid', 'ada@example.test' ],
				],
			];
			$structured = $class::get();
			$GLOBALS['sso_scenario'] = [
				'attributes' => [
					'eduPersonPrincipalName' => 'person@example.test',
					'displayName' => 'Grace Hopper',
					'surname' => 'Hopper',
					'mailPrimaryAddress' => 'grace@example.test',
				],
			];
			$displayName = $class::get();
			$GLOBALS['sso_scenario'] = [ 'throws' => true ];
			$failedSso = $class::get();
			$GLOBALS['sso_scenario'] = [
				'attributes' => [ 'displayName' => 'No Marker', 'mail' => 'marker@example.test' ],
			];
			$missingMarker = $class::get();
			echo json_encode( compact(
				'anonymous',
				'structured',
				'displayName',
				'failedSso',
				'missingMarker'
			) );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as RightsResult;
};

describe( 'passive SSO identity', () => {
	it( 'returns an anonymous schema without triggering authentication', () => {
		expect( getRightsResult().anonymous ).toEqual( {
			authenticated: false,
			bookerName: '',
			bookerEmail: '',
		} );
	} );

	it( 'normalizes attribute lists and selects the first valid email', () => {
		expect( getRightsResult().structured ).toEqual( {
			authenticated: true,
			bookerName: 'Ada Lovelace',
			bookerEmail: 'ada@example.test',
		} );
	} );

	it( 'uses complete display names without duplicating the surname', () => {
		expect( getRightsResult().displayName ).toEqual( {
			authenticated: true,
			bookerName: 'Grace Hopper',
			bookerEmail: 'grace@example.test',
		} );
	} );

	it( 'fails closed when SSO errors or lacks an identity marker', () => {
		const result = getRightsResult();

		expect( result.failedSso.authenticated ).toBe( false );
		expect( result.missingMarker.authenticated ).toBe( false );
	} );
} );
