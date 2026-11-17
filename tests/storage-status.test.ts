import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/** Exercises the real status notice with isolated WordPress boundaries. */
const run = ( overrides: Record< string, unknown > = {} ) =>
	JSON.parse(
		execFileSync(
			'php',
			[
				resolve( process.cwd(), 'tests/fixtures/storage-status.php' ),
				JSON.stringify( {
					admin: true,
					phase: 'mixed',
					page: 'rrze-appointment-settings',
					...overrides,
				} ),
			],
			{ encoding: 'utf8' }
		)
	);

describe( 'site storage status', () => {
	it( 'reports the write policy for mixed data', () => {
		expect( run().html ).toContain(
			'Encryption is active for new and changed data.'
		);
		expect( run( { phase: 'legacy' } ).html ).toContain( 'legacy format' );
		expect( run( { phase: 'error' } ).html ).toContain(
			'Safe storage error.'
		);
	} );
	it.each( [ 'legacy', 'mixed', 'error' ] )(
		'displays %s without offering or invoking a migration action',
		( phase ) => {
			const result = run( { phase } );
			expect( result.html ).not.toContain( '<form' );
			expect( result.html ).not.toContain( '<button' );
			expect( result.html ).not.toBe( '' );
			expect( result.migrations ).toBe( 0 );
			expect( result.hooks ).toEqual( [ 'admin_notices' ] );
		}
	);

	it.each( [ 'legacy', 'mixed', 'error' ] )(
		'restricts %s diagnostics before reading storage',
		( phase ) => {
			for ( const overrides of [
				{ page: 'dashboard' },
				{ page: 'rrze-appointment-settings', screen: 'dashboard' },
				{ screen: '' },
				{ admin: false },
				{ multisite: true, admin: true, superAdmin: false },
			] ) {
				const result = run( { phase, ...overrides } );
				expect( result.html ).toBe( '' );
				expect( result.reads ).toBe( 0 );
			}
			for ( const page of [
				'rrze-appointment-settings',
				'rrze-appointment-bookings',
			] ) {
				expect( run( { phase, page } ).html ).not.toBe( '' );
				expect(
					run( { phase, page, multisite: true, superAdmin: true } )
						.html
				).not.toBe( '' );
			}
		}
	);
} );
