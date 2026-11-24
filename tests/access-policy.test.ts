import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/**
 * Exercises production authorization, persistence, SSO and REST code with isolated users and SQL.
 *
 * @param name Name of the PHP fixture scenario.
 * @return Recorded permissions, writes and response for this scenario.
 */
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
			expect( result.cancel.data.message ).toContain(
				'Booking cancelled'
			);
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
			'https://site1.test/wp-admin/options-general.php?page=rrze-appointment-settings&tab=access'
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
	it.each( [ 'http-transfer', 'http-transfer-manager' ] )(
		'returns the former responsible administrator to the settings tab after %s',
		( name ) => {
			const result = scenario( name );
			expect( result.redirect ).toBe(
				'https://site1.test/wp-admin/options-general.php?page=rrze-appointment-settings&tab=access&rrze_appointment_access_saved=save'
			);
			expect( result.writes[ 0 ][ 2 ].owners ).toEqual( [ 9 ] );
		}
	);
	it( 'reads legacy editor owners as booking managers without changing the saved option', () => {
		const result = scenario( 'editor-owner' );
		expect( result.result.data.status ).toBe( 403 );
		expect( result.options[ 1 ][ key ].owners ).toEqual( [ 7 ] );
		expect( result.extra.policy ).toMatchObject( {
			owners: [],
			managers: [ 8, 7 ],
			access_owners: [],
		} );
		expect( result.extra.list.status ).toBe( 200 );
		expect( result.extra.cancel.status ).toBe( 200 );
		expect( result.extra.sso.data.status ).toBe( 403 );
		expect( result.writes ).toEqual( [] );
	} );
	it.each( [
		[ 'single-v1', true ],
		[ 'multi-v1', true ],
		[ 'single-v2', false ],
		[ 'multi-v2', false ],
	] )(
		'uses effective administrator capabilities and rechecks their removal for %s',
		( configuration, bookingAfterRemoval ) => {
			const result = scenario( 'capability-' + configuration );
			expect( result.extra.storedAdmin ).toBe( false );
			expect( result.extra.before ).toEqual( {
				owner: true,
				booking: true,
			} );
			expect( result.result ).toBe( true );
			expect( result.extra.after ).toEqual( {
				owner: false,
				booking: bookingAfterRemoval,
			} );
			expect( result.extra.deniedStoredAdmin ).toBe( true );
			expect( result.extra.deniedOwner ).toBe( false );
			expect( result.writes ).toEqual( [] );
		}
	);
	it( 'checks the selected super administrator’s own site capabilities', () => {
		const editor = scenario( 'target-super-editor' );
		expect( editor.extra.effectiveAdmin ).toBe( true );
		expect( editor.result.code ).toBe( 'rrze_appointment_invalid_owners' );
		expect( editor.writes ).toEqual( [] );

		const administrator = scenario( 'target-super-admin' );
		expect( administrator.result ).toBe( true );
		expect( administrator.options[ 1 ][ key ].owners ).toEqual( [ 7, 9 ] );
	} );
	it.each( [ 'save', 'network_save', 'recover' ] )(
		'preserves the original format 1 option on an unchanged %s',
		( operation ) => {
			const result = scenario( 'unchanged-' + operation );
			expect( result.result ).toBe( true );
			expect( result.writes ).toEqual( [] );
			expect( result.extra.rawAfter ).toBe( result.extra.rawBefore );
			expect( result.options[ 1 ][ key ] ).toMatchObject( {
				version: 1,
				revision: 14,
				owners: [ 10, 8, 7 ],
				managers: [ 7, 9 ],
			} );
		}
	);
	it( 'removes a deleted format 1 manager only when a visible role actually changes', () => {
		const result = scenario( 'deleted-manager-on-save' );
		expect( result.extra.first ).toBe( true );
		expect( result.extra.rawAfterFirst ).toBe( result.extra.rawBefore );
		expect( result.extra.writesAfterFirst ).toBe( 0 );
		expect( result.result ).toBe( true );
		expect( result.writes ).toHaveLength( 1 );
		expect( result.options[ 1 ][ key ] ).toEqual( {
			version: 2,
			restricted: true,
			revision: 2,
			owners: [ 7, 9 ],
			managers: [ 8 ],
			access_owners: [],
		} );
	} );
	it.each( [
		[ 'stale', 409 ],
		[ 'overlap', 400 ],
		[ 'unauthorized', 403 ],
		[ 'expired', 401 ],
	] )(
		'checks %s even when the roles would be unchanged',
		( name, status ) => {
			const result = scenario( 'unchanged-' + name );
			expect( result.result.data.status ).toBe( status );
			expect( result.writes ).toEqual( [] );
			expect( result.extra.rawAfter ).toBe( result.extra.rawBefore );
		}
	);
	it.each( [ 'off', 'sso', 'expired' ] )(
		'isolates an access-only administrator from both booking routes with SSO %s',
		( state ) => {
			const result = scenario( 'split-' + state );
			expect( result.result.data.status ).toBe( 403 );
			expect( result.cancel.data.status ).toBe( 403 );
			expect( result.reads ).toBe( 0 );
			expect( result.extra.base ).toBe( false );
			if ( state === 'expired' ) {
				expect( result.extra.grants.data.status ).toBe( 401 );
			} else {
				expect( result.extra.grants ).toBe( true );
			}
		}
	);
	it( 'allows access-only setup and delegating data access to other site administrators', () => {
		const setup = scenario( 'split-setup' );
		expect( setup.result ).toBe( true );
		expect( setup.extra.booking ).toBe( false );
		expect( setup.options[ 1 ][ key ] ).toMatchObject( {
			version: 2,
			owners: [],
			access_owners: [ 7 ],
			managers: [ 8 ],
		} );
		const delegation = scenario( 'split-delegate' );
		expect( delegation.result ).toBe( true );
		expect( delegation.options[ 1 ][ key ] ).toMatchObject( {
			owners: [ 9 ],
			access_owners: [ 7 ],
			managers: [ 8 ],
		} );
	} );
	it.each( [
		'self-owner',
		'self-manager',
		'self-network',
		'self-recover',
		'after-removal',
	] )(
		'prevents granting new booking rights to oneself via %s',
		( operation ) => {
			const result = scenario( 'split-' + operation );
			expect( result.result.code ).toBe(
				'rrze_appointment_self_grant_forbidden'
			);
			if ( operation === 'after-removal' ) {
				expect( result.extra.first ).toBe( true );
				expect( result.writes ).toHaveLength( 1 );
			} else {
				expect( result.writes ).toEqual( [] );
			}
		}
	);
	it.each( [
		[ 'bad-overlap', 503 ],
		[ 'legacy-form', 409 ],
		[ 'invalid-owner', 400 ],
		[ 'invalid-access-owner', 400 ],
		[ 'network-member-editor', 400 ],
	] )( 'rejects %s without changing the site', ( name, status ) => {
		const result = scenario( 'split-' + name );
		expect( result.result.data.status ).toBe( status );
		expect( result.writes ).toEqual( [] );
	} );
	it( 'denies grants and booking access to a demoted access-only administrator', () => {
		const result = scenario( 'split-demoted' );
		expect( result.result.data.status ).toBe( 403 );
		expect( result.extra.grants.data.status ).toBe( 403 );
	} );
	it( 'writes a real role change once in version 2 and revokes access when the owner is demoted', () => {
		const result = scenario( 'split-new-format-demotion' );
		expect( result.result ).toBe( true );
		expect( result.writes ).toHaveLength( 1 );
		expect( result.options[ 1 ][ key ] ).toMatchObject( {
			version: 2,
			revision: 2,
			owners: [ 7, 9 ],
			managers: [ 8 ],
		} );
		expect( result.extra ).toEqual( { booking: false, owner: false } );
	} );
	it( 'permits an orphaned single site to name a responsible administrator and keeps legacy editor booking rights', () => {
		const result = scenario( 'split-recover-orphan' );
		expect( result.extra.before.owners ).toEqual( [] );
		expect( result.extra.canRecover ).toBe( true );
		expect( result.result ).toBe( true );
		expect( result.options[ 1 ][ key ] ).toMatchObject( {
			version: 2,
			owners: [],
			access_owners: [ 7 ],
			managers: [ 8, 9 ],
		} );
	} );
	it.each( [
		[ 'network-only', 403 ],
		[ 'active', 403 ],
		[ 'self', 403 ],
		[ 'expired', 401 ],
	] )( 'rejects single-site recovery for %s', ( name, status ) => {
		const result = scenario( 'split-recover-' + name );
		expect( result.result.data.status ).toBe( status );
		expect( result.writes ).toEqual( [] );
	} );
	it.each( [ 'setup', 'save' ] )(
		'allows a verified network administrator outside the site to %s on behalf of named members',
		( operation ) => {
			const result = scenario( 'network-' + operation );
			expect( result.result ).toBe( true );
			expect( result.options[ 1 ][ key ] ).toMatchObject( {
				owners: [ 8 ],
				managers: [ 7 ],
				revision: operation === 'setup' ? 1 : 2,
			} );
			expect( result.options[ 2 ] ).toEqual( [] );
			expect( result.extra.access.data.status ).toBe( 403 );
			expect( result.extra.grants.data.status ).toBe( 403 );
		}
	);
	it.each( [
		[ 'site-admin', 403 ],
		[ 'no-capability', 403 ],
		[ 'expired', 401 ],
		[ 'mismatch', 403 ],
		[ 'ineligible', 400 ],
		[ 'no-owner', 400 ],
		[ 'stale-legacy', 409 ],
	] )(
		'rejects a network change for %s before saving grants',
		( name, status ) => {
			const result = scenario( 'network-' + name );
			expect( result.result.data.status ).toBe( status );
			expect( result.writes ).toEqual( [] );
		}
	);
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
	it.each( [
		[ 'double', true ],
		[ 'owner-only', false ],
		[ 'ineligible-double', true ],
		[ 'ineligible-owner-only', false ],
	] )(
		'preserves only independent booking grants on a format 1 handover (%s)',
		( previousGrant, keepsBookings ) => {
			const result = scenario( 'handover-' + previousGrant );
			expect( result.result ).toBe( true );
			expect( result.extra ).toEqual( {
				owner: false,
				booking: keepsBookings,
			} );
			const policy = result.options[ 1 ][ key ];
			expect( policy ).toMatchObject( {
				version: 2,
				revision: 2,
				owners: [ 9 ],
			} );
			expect( [ ...policy.managers ].sort() ).toEqual(
				keepsBookings ? [ 7, 8 ] : [ 8 ]
			);
			expect( result.writes ).toHaveLength( 1 );
		}
	);
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
