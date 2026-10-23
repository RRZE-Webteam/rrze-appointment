import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/** Exercises production authorization, persistence, SSO and REST code with isolated users and SQL. */
function scenario( name: string ) {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/backend-sso.php' ), `policy-${ name }` ],
			{ encoding: 'utf8' }
		)
	);
}
const key = 'rrze_appointment_access_policy';

describe( 'explicit appointment access policy', () => {
	it.each( [ 'owner', 'manager', 'off-manager' ] )(
		'allows viewing and cancellation for %s',
		( name ) => {
			const result = scenario( name );
			expect( result.result.status ).toBe( 200 );
			expect( result.cancel.status ).toBe( 200 );
			expect( result.cancel.data.message ).toContain( 'Booking cancelled' );
			expect( result.reads ).toBeGreaterThan( 0 );
		}
	);
	it.each( [
		[ 'admin', 403 ],
		[ 'super', 403 ],
		[ 'expired', 401 ],
		[ 'mismatch', 403 ],
		[ 'outage', 503 ],
		[ 'demoted', 403 ],
		[ 'deleted', 403 ],
		[ 'removed', 403 ],
		[ 'corrupt', 503 ],
		[ 'empty', 503 ],
		[ 'off-admin', 403 ],
	] )(
		'denies %s before reading or cancelling any bookings',
		( name, status ) => {
			const result = scenario( String( name ) );
			expect( result.result.data.status ).toBe( status );
			expect( result.cancel.data.status ).toBe( status );
			expect( result.reads ).toBe( 0 );
			expect( result.authCalls ).toEqual( [] );
		}
	);
	it.each( [ 'nonce', 'get', 'site', 'array', 'operation', 'admin' ] )(
		'rejects manipulated access POST: %s',
		( name ) => {
			const result = scenario( 'http-' + name );
			expect( result.result.data.status ).toBe( 403 );
			expect( result.writes ).toEqual( [] );
		}
	);
	it( 'accepts an owner’s valid POST and redirects to the fixed management tab', () => {
		const result = scenario( 'http-valid' );
		expect( result.writes[ 0 ][ 2 ].owners ).toEqual( [ 7, 9 ] );
		expect( result.redirect ).toContain(
			'https://site1.test/wp-admin/admin.php?page=rrze-appointment-bookings&tab=access'
		);
	} );
	it( 'sets up the acting administrator as owner with explicitly confirmed grants', () => {
		const result = scenario( 'setup' );
		expect( result.result ).toBe( true );
		expect( result.options[ 1 ][ key ] ).toMatchObject( {
			owners: [ 7 ],
			managers: [ 8 ],
			restricted: true,
			revision: 1,
		} );
		expect( result.writes[ 0 ][ 3 ] ).toBe( false );
		expect( result.locked ).toBe( false );
		expect( scenario( 'setup-expired' ).result.data.status ).toBe( 401 );
		expect( scenario( 'enable-without-setup' ).result.code ).toBe(
			'rrze_appointment_setup_required'
		);
	} );
	it( 'allows responsible editors to delegate but not to disable SSO', () => {
		const result = scenario( 'editor-owner' );
		expect( result.result ).toBe( true );
		expect( result.options[ 1 ][ key ].owners ).toEqual( [ 7, 9 ] );
		expect( result.extra.sso.data.status ).toBe( 403 );
	} );
	it.each( [
		'manager-save',
		'admin-save',
		'save-expired',
		'invalid-users',
		'malformed-ids',
		'last-owner',
		'lock-failed',
	] )( 'rejects %s without changing policy', ( name ) => {
		const result = scenario( name );
		expect( result.result.data.status ).toBeGreaterThanOrEqual( 400 );
		expect( result.writes ).toEqual( [] );
		expect( result.options[ 1 ][ key ].owners ).toEqual( [ 7 ] );
		expect( result.locked ).toBe( false );
	} );
	it( 'rejects a concurrent stale form and retains the first confirmed change', () => {
		const result = scenario( 'stale' );
		expect( result.extra.first ).toBe( true );
		expect( result.result.data.status ).toBe( 409 );
		expect( result.options[ 1 ][ key ] ).toMatchObject( {
			revision: 2,
			owners: [ 7, 9 ],
			managers: [ 8 ],
		} );
	} );
	it( 'allows transfer to another owner and immediately removes the former owner’s access', () => {
		const result = scenario( 'self-remove' );
		expect( result.result ).toBe( true );
		expect( result.extra.access.data.status ).toBe( 403 );
	} );
	it( 'recovers responsibility without changing SSO, ordinary grants or support access', () => {
		const result = scenario( 'recover' );
		expect( result.result ).toBe( true );
		expect( result.options[ 1 ][ key ] ).toMatchObject( {
			owners: [ 9 ],
			managers: [ 8 ],
		} );
		expect(
			result.options[ 1 ].rrze_appointment_backend_sso_required
		).toBe( '1' );
		expect( result.extra.access.data.status ).toBe( 403 );
		expect( scenario( 'recover-site' ).result.data.status ).toBe( 403 );
		expect( scenario( 'recover-corrupt' ).result ).toBe( true );
	} );
	it( 'resets only SSO and preserves explicit grants', () => {
		const result = scenario( 'reset' );
		expect( result.result ).toBe( true );
		expect(
			result.options[ 1 ].rrze_appointment_backend_sso_required
		).toBe( '0' );
		expect( result.options[ 1 ][ key ].owners ).toEqual( [ 7 ] );
		expect( result.extra.access.data.status ).toBe( 403 );
	} );
	it( 'ignores legacy grant tampering while allowing unrelated settings changes', () => {
		expect( scenario( 'old-settings' ).result ).toEqual( {
			appointment_manager_user_ids: [ 8 ],
			retention_days: 90,
		} );
	} );
	it( 'initializes only the selected subsite and keeps legacy sites accessible until setup', () => {
		const result = scenario( 'sites' );
		expect( result.extra.legacyAccess ).toBe( true );
		expect( result.options[ 1 ][ key ].managers ).toEqual( [ 8 ] );
		expect( result.options[ 2 ][ key ].managers ).toEqual( [ 9 ] );
	} );
} );
