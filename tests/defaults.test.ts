import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type DefaultsResult = {
	all: unknown[];
	missing: unknown;
	prefixedKey: string;
	prefixOnly: string;
};

const getDefaultsResult = (): DefaultsResult => {
	const defaultsPath = resolve( process.cwd(), 'includes/Defaults.php' );
	const php = `
		namespace RRZE\\Appointment {
			function plugin() {
				return new class {
					public function getSlug(): string {
						return 'RRZE-Appointment';
					}
				};
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			function sanitize_key( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $value ) );
			}
			require ${ JSON.stringify( defaultsPath ) };
			$defaults = new \\RRZE\\Appointment\\Defaults();
			echo json_encode( [
				'all' => $defaults->all(),
				'missing' => $defaults->get( 'missing' ),
				'prefixedKey' => $defaults->withPrefix( 'Booking Option!' ),
				'prefixOnly' => $defaults->withPrefix(),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as DefaultsResult;
};

describe( 'defaults', () => {
	it( 'exposes the legacy empty collection safely', () => {
		const result = getDefaultsResult();

		expect( result.all ).toEqual( [] );
		expect( result.missing ).toBeNull();
	} );

	it( 'builds a stable normalized plugin prefix', () => {
		const result = getDefaultsResult();
		const prefix = result.prefixOnly.slice( 0, -1 );

		expect( prefix ).toHaveLength( 6 );
		expect( prefix ).toMatch( /^[a-z][a-z0-9]{5}$/ );
		expect( result.prefixedKey ).toBe( `${ prefix }_bookingoption` );
	} );
} );
