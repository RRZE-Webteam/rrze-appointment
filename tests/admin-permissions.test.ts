import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/** Renders the standard access form using a site directory with three distinct accounts. */
function renderAdditionalPeople(): HTMLFormElement {
	const result = JSON.parse(
		execFileSync(
			'php',
			[
				resolve( 'tests/fixtures/backend-sso.php' ),
				'screen-additional',
			],
			{ encoding: 'utf8' }
		)
	);
	document.body.innerHTML = result.html;
	return document.querySelector( 'form' )!;
}

describe( 'standard access grants', () => {
	it( 'submits the on-screen selection without JavaScript and keeps administrators out of the list', () => {
		const form = renderAdditionalPeople();
		const users = form.querySelectorAll< HTMLInputElement >(
			'input[type="checkbox"]'
		);
		expect( Array.from( users, ( user ) => user.value ) ).toEqual( [
			'8',
			'10',
		] );
		const field =
			'rrze_appointment_settings[appointment_manager_user_ids][]';
		expect( new FormData( form ).getAll( field ) ).toEqual( [ '10' ] );
		users[ 0 ].click();
		users[ 1 ].click();
		expect( new FormData( form ).getAll( field ) ).toEqual( [ '8' ] );
		users[ 0 ].click();
		const empty = new FormData( form );
		expect( empty.getAll( field ) ).toEqual( [] );
		expect(
			empty.get( 'rrze_appointment_settings[_settings_scope]' )
		).toBe( 'additional_people' );
		expect(
			empty.get( 'rrze_appointment_settings[_access_list_complete]' )
		).toBe( '1' );
	} );

	it( 'keeps checked people selected while the name filter hides their rows', () => {
		const form = renderAdditionalPeople();
		require( '../assets/js/rrze-appointment-admin' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		const search = form.querySelector< HTMLInputElement >(
			'input[type="search"]'
		)!;
		search.value = 'USER8';
		search.dispatchEvent( new Event( 'input' ) );
		expect(
			form.querySelectorAll( '[data-access-person]:not([hidden])' )
		).toHaveLength( 1 );
		expect(
			new FormData( form ).getAll(
				'rrze_appointment_settings[appointment_manager_user_ids][]'
			)
		).toEqual( [ '10' ] );
		search.value = 'unlisted';
		search.dispatchEvent( new Event( 'input' ) );
		expect(
			form.querySelector< HTMLElement >( '[data-access-empty]' )!.hidden
		).toBe( false );
	} );
} );
