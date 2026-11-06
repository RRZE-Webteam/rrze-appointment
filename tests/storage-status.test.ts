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
					phase: 'ready',
					page: 'rrze-appointment-settings',
					...overrides,
				} ),
			],
			{ encoding: 'utf8' }
		)
	);

describe( 'automatic migration status', () => {
	it( 'shows startup failures even before a migration marker could be persisted', () => {
		const result = run( {
			phase: 'ready',
			page: 'rrze-appointment-bookings',
			initializationError: 'Safe startup failure.',
		} );
		expect( result.html ).toContain( 'notice-error' );
		expect( result.html ).toContain( 'Safe startup failure.' );
		expect( result.html ).not.toContain( 'Migration starts automatically' );
	} );
	it.each( [ 'legacy', 'ready', 'migrating', 'encrypted', 'error' ] )(
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

	it.each( [ 'legacy', 'ready', 'migrating' ] )(
		'explains automatic startup or recovery in %s state',
		( phase ) => {
			expect( run( { phase } ).html ).toContain( 'automatically' );
		}
	);

	it.each( [ 'legacy', 'ready', 'migrating', 'encrypted', 'error' ] )(
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
	it( 'hides startup errors on unrelated screens and from site admins', () => {
		expect(
			run( {
				page: 'dashboard',
				initializationError: 'Safe startup failure.',
			} ).html
		).toBe( '' );
		expect(
			run( {
				multisite: true,
				initializationError: 'Safe startup failure.',
			} ).html
		).toBe( '' );
	} );
} );
