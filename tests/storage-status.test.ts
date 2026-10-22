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
			page: 'dashboard',
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

	it( 'shows errors beyond settings and restricts diagnostics to administrators', () => {
		expect( run( { phase: 'error', page: 'dashboard' } ).html ).toContain(
			'Safe storage error.'
		);
		expect( run( { phase: 'error', admin: false } ).html ).toBe( '' );
		expect( run( { phase: 'ready', page: 'dashboard' } ).html ).toBe( '' );
	} );
} );
