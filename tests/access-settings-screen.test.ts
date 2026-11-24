import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/**
 * Renders or submits the production access screen with isolated site accounts and storage.
 *
 * @param name Name of the PHP fixture scenario.
 * @param data Posted fields for the requested stage.
 * @return Rendered markup or the recorded response to a submission.
 */
function request( name: string, data = new URLSearchParams() ) {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/backend-sso.php' ), 'screen-' + name ],
			{
				input: data.toString(),
				encoding: 'utf8',
			}
		)
	);
}

/**
 * Loads the returned form as a browser would, without running client scripts.
 *
 * @param name Name of the PHP fixture scenario.
 * @param data Posted fields for the requested stage.
 * @return The visible role form, if the acting account can edit grants.
 */
function render( name: string, data?: URLSearchParams ): HTMLFormElement {
	const result = request( name, data );
	expect( result.writes ).toEqual( [] );
	expect( result.reads ).toBe( 0 );
	document.body.innerHTML = result.html;
	return document.querySelector< HTMLFormElement >(
		'form:has([name="role_form"])'
	)!;
}

/**
 * Serializes native successful controls, including the clicked submit button.
 *
 * @param form  Role form being submitted.
 * @param stage Optional submit button value for reviewing or editing.
 * @return Posted fields in browser encoding.
 */
function payload( form: HTMLFormElement, stage?: string ) {
	const values = new URLSearchParams();
	const fields = new FormData( form );
	// jsdom includes disabled options that browsers omit from a native POST.
	for ( const field of form.querySelectorAll( 'select' ) ) {
		if ( field.selectedOptions[ 0 ]?.disabled ) {
			fields.delete( field.name );
		}
	}
	fields.forEach( ( value, key ) => values.append( key, String( value ) ) );
	if ( stage ) {
		values.set( 'access_stage', stage );
	}
	return values;
}

describe( 'access settings workflow', () => {
	it( 'supports the existing zero-argument settings renderer', () => {
		expect( render( 'network-no-callback' ) ).toBeTruthy();
		expect( document.body.textContent ).not.toContain(
			'Additional people with access'
		);
	} );

	it( 'renders unassigned accounts as unsuccessful controls on a large site and allows revoking a grant', () => {
		const editor = render( 'large-network-configured' );
		const selectors =
			editor.querySelectorAll< HTMLSelectElement >( 'select' );
		expect( selectors.length ).toBeGreaterThan( 1000 );
		const assigned = Array.from( selectors ).filter(
			( field ) => ! field.selectedOptions[ 0 ].disabled
		);
		const roles = assigned.map( ( field ) => [ field.name, field.value ] );
		expect( roles ).toEqual( [
			[ 'roles[8]', 'managers' ],
			[ 'roles[9]', 'owners' ],
		] );
		assigned[ 0 ].value = 'none';
		selectors[ selectors.length - 1 ].value = 'managers';
		const data = payload( editor );
		expect( data.get( 'roles[8]' ) ).toBe( 'none' );
		expect( data.get( 'roles_complete' ) ).toBe( '1' );
		const result = request( 'large-network-configured-submit', data );
		expect( result.writes[ 0 ][ 2 ] ).toMatchObject( {
			owners: [ 9 ],
			managers: [ 1018 ],
		} );
	} );

	it( 'reviews site-local gains and losses before a network admin applies the restriction without JavaScript', () => {
		const directory = render( 'network' );
		const step = directory.closest( 'details' )!;
		expect( step.open ).toBe( false );
		expect( directory.querySelectorAll( 'select' ) ).toHaveLength( 2 );
		expect( directory.querySelector( '[name="roles[7]"]' ) ).toBeNull();
		directory.querySelector< HTMLSelectElement >(
			'[name="roles[8]"]'
		)!.value = 'none';
		directory.querySelector< HTMLSelectElement >(
			'[name="roles[9]"]'
		)!.value = 'owners';
		const proposed = payload( directory, 'review' );
		const confirmation = render( 'network', proposed );
		expect( confirmation.closest( 'details' )!.open ).toBe( true );
		const groups = Array.from(
			document.querySelectorAll( 'dd' ),
			( group ) => group.textContent
		);
		expect( groups ).toEqual( [
			'User 9 (user9)',
			'No one',
			'User 8 (user8), User 10 (user10)',
		] );
		const reviewed = payload( confirmation );
		expect( reviewed.getAll( 'roles[8]' ) ).toEqual( [] );
		const edit = render( 'network', payload( confirmation, 'edit' ) );
		expect(
			edit.querySelector< HTMLSelectElement >( '[name="roles[9]"]' )!
				.value
		).toBe( 'owners' );
		const result = request( 'network-submit', reviewed );
		expect( result.writes[ 0 ][ 2 ] ).toMatchObject( {
			owners: [ 9 ],
			managers: [],
		} );
		expect( result.redirect ).toContain(
			'options-general.php?page=rrze-appointment-settings&tab=access'
		);
	} );

	it( 'keeps local setup available for a site administrator and requires network SSO when acting on behalf of others', () => {
		const local = render( 'member-expired' );
		expect( payload( local ).get( 'operation' ) ).toBe( 'setup' );
		expect( payload( local ).get( 'roles[7]' ) ).toBe( 'owners' );
		expect( render( 'network-expired' ) ).toBeNull();
		expect(
			document.querySelector( 'a[href="#rrze-appointment-sso-heading"]' )
		).toBeTruthy();
	} );

	it( 'does not apply truncated selections or unrecognized role values', () => {
		const editor = render( 'network-configured' );
		const data = payload( editor );
		data.delete( 'roles_complete' );
		expect( request( 'network-configured-submit', data ) ).toMatchObject( {
			status: 403,
			writes: [],
		} );
		data.set( 'roles_complete', '1' );
		data.set( 'roles[8]', 'unrecognized' );
		expect( request( 'network-configured-submit', data ) ).toMatchObject( {
			status: 403,
			writes: [],
		} );
	} );

	it( 'submits assigned roles only when the browser supports the formdata event', () => {
		const editor = render( 'network-configured' );
		require( '../assets/js/rrze-appointment-admin' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		editor.querySelector< HTMLSelectElement >(
			'[name="roles[8]"]'
		)!.value = 'none';
		const formData = new FormData( editor );
		editor.dispatchEvent(
			Object.assign( new Event( 'formdata' ), { formData } )
		);
		expect( formData.has( 'roles[8]' ) ).toBe( false );
		expect( formData.get( 'roles[9]' ) ).toBe( 'owners' );
		expect( formData.get( 'roles_complete' ) ).toBe( '1' );
	} );
} );
