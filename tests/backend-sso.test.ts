import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { isAccessError, needsSsoLogin } from '../src/admin/api';

jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );
jest.mock( '@wordpress/i18n', () => ( { __: ( value: string ) => value } ), {
	virtual: true,
} );

/** Runs real access controllers and the installed RRZE-SSO helper with synthetic dependencies. */
function scenario( name: string ) {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/backend-sso.php' ), name ],
			{
				encoding: 'utf8',
			}
		)
	);
}

describe( 'backend SSO authorization', () => {
	it.each( [ 'allowed', 'disabled', 'scoped', 'delegated', 'multisite' ] )(
		'allows %s',
		( name ) => {
			expect( scenario( name ).result.status ).toBe( 200 );
		}
	);
	it.each( [
		[ 'expired', 401 ],
		[ 'mismatch', 403 ],
		[ 'local', 403 ],
		[ 'scoped-mismatch', 403 ],
		[ 'unknown-idp', 503 ],
		[ 'missing-claims', 503 ],
		[ 'unavailable', 503 ],
		[ 'exception', 503 ],
		[ 'force-off', 503 ],
		[ 'ac-inactive', 503 ],
		[ 'sso-inactive', 503 ],
		[ 'forbidden', 403 ],
		[ 'nonce', 403 ],
		[ 'super-expired', 401 ],
	] )( 'denies %s before accessing bookings', ( name, status ) => {
		const result = scenario( String( name ) );
		expect( result.result.data.status ).toBe( status );
		expect( result.cancel.data.status ).toBe( status );
		expect( result.reads ).toBe( 0 );
		expect( result.authCalls ).toEqual( [] );
		expect( JSON.stringify( result.result ) ).not.toContain( 'secret' );
	} );
	it.each( [ 'activate-new', 'activate-network', 'activate-present' ] )(
		'prepares %s without enabling protection',
		( name ) => {
			const result = scenario( name );
			expect( result.result ).toBe( true );
			expect( result.writes ).toEqual( [] );
			expect( result.activations ).toEqual(
				name === 'activate-new'
					? [ [ 1, 'rrze-ac/rrze-ac.php', false, false ] ]
					: []
			);
			expect( result.options[ '2' ] ).toEqual( [] );
		}
	);
	it.each( [
		'missing',
		'hidden',
		'policy',
		'forbidden',
		'no-manage',
		'network-only',
		'failed',
	] )( 'rejects activation when %s', ( name ) => {
		const result = scenario( 'activate-' + name );
		expect( result.result.data.status ).toBe(
			name === 'failed' ? 503 : 403
		);
		expect( result.writes ).toEqual( [] );
		if ( name !== 'failed' ) expect( result.activations ).toEqual( [] );
	} );
	it( 'requires matching SSO to enable and disables autoload', () => {
		expect( scenario( 'enable' ).writes ).toEqual( [
			[ 1, 'rrze_appointment_backend_sso_required', '1', false ],
		] );
		expect( scenario( 'disable-expired' ).writes ).toEqual( [] );
		expect( scenario( 'reset-site' ).writes ).toEqual( [] );
		expect( scenario( 'reset-network' ).writes ).toEqual( [
			[ 1, 'rrze_appointment_backend_sso_required', '0', false ],
		] );
	} );
	it( 'preserves protected managers while other settings can change', () => {
		expect( scenario( 'managers-expired' ).result ).toEqual( {
			appointment_manager_user_ids: [ 7 ],
			retention_days: 45,
		} );
		expect( scenario( 'managers-malformed' ).result ).toEqual( {
			appointment_manager_user_ids: [ 7 ],
			retention_days: 30,
		} );
		expect(
			scenario( 'managers-valid' ).result.appointment_manager_user_ids
		).toEqual( [ 9 ] );
	} );
	it.each( [ 'nonce', 'cross-site', 'array', 'return', 'forbidden', 'get' ] )(
		'rejects manipulated HTTP %s',
		( name ) => {
			const result = scenario( 'http-' + name );
			expect( result.result.data.status ).toBe( 403 );
			expect( result.writes ).toEqual( [] );
			expect( result.activations ).toEqual( [] );
		}
	);
	it( 'starts login only explicitly and returns to the fixed site-local management page', () => {
		const result = scenario( 'http-login' );
		expect( result.authCalls ).toEqual( [
			{
				ReturnTo:
					'https://site1.test/wp-admin/admin.php?page=rrze-appointment-bookings',
				KeepPost: false,
			},
		] );
		expect( result.redirect ).toBe( result.authCalls[ 0 ].ReturnTo );
		expect( result.writes ).toEqual( [] );
	} );
} );

describe( 'SSO failures in the administration UI', () => {
	it( 'treats integration outages as access failures and offers login only for an expired session', () => {
		expect(
			isAccessError( {
				code: 'rrze_appointment_sso_unavailable',
				data: { status: 503 },
			} )
		).toBe( true );
		expect(
			isAccessError( { code: 'other', data: { status: 503 } } )
		).toBe( false );
		expect(
			needsSsoLogin( { code: 'rrze_appointment_sso_required' } )
		).toBe( true );
		expect(
			needsSsoLogin( { code: 'rrze_appointment_sso_identity_mismatch' } )
		).toBe( false );
	} );
	it( 'keeps the protection option separate from ordinary settings', () => {
		expect(
			readFileSync(
				resolve( 'includes/Configuration/PluginSettings.php' ),
				'utf8'
			)
		).not.toContain( 'rrze_appointment_backend_sso_required' );
	} );
} );
