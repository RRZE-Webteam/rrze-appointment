import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/**
 * Renders the access screen for an isolated site with sample members and roles.
 *
 * @param scenario Access fixture scenario.
 */
function renderAccessScreen( scenario: string ): void {
	const result = JSON.parse(
		execFileSync(
			'php',
			[
				resolve( 'tests/fixtures/backend-sso.php' ),
				'screen-' + scenario,
			],
			{ encoding: 'utf8' }
		)
	);
	document.body.innerHTML = result.html;
}

describe( 'standard access grants', () => {
	it( 'submits the on-screen selection without JavaScript and keeps administrators out of the list', () => {
		renderAccessScreen( 'additional' );
		const form = document.querySelector( 'form' )!;
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
		renderAccessScreen( 'additional' );
		const form = document.querySelector( 'form' )!;
		require( '../assets/js/rrze-appointment-admin' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		expect(
			form.querySelector( '[data-access-owner-feedback]' )
		).toBeNull();
		expect( form.querySelector( '[aria-disabled="true"]' ) ).toBeNull();
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

describe( 'responsibility feedback', () => {
	it( 'counts both responsibility roles across filtered rows and focuses the instruction on an invalid submission', () => {
		renderAccessScreen( 'network-configured' );
		const form = document.querySelector< HTMLFormElement >(
			'form:has([name="role_form"])'
		)!;
		form.closest( 'details' )?.setAttribute( 'open', '' );
		require( '../assets/js/rrze-appointment-admin' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		const feedback = form.querySelector< HTMLElement >(
			'[data-access-owner-feedback]'
		)!;
		const submit =
			form.querySelector< HTMLButtonElement >( '[type="submit"]' )!;
		const owner = Array.from( form.querySelectorAll( 'select' ) ).find(
			( field ) => field.value === 'owners'
		)!;
		expect( feedback.getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( feedback.textContent ).toBe( '' );

		owner.value = 'managers';
		owner.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( feedback.textContent ).toBe(
			'Select at least one responsible person.'
		);
		expect( submit.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( submit.disabled ).toBe( false );
		const submission = new Event( 'submit', {
			bubbles: true,
			cancelable: true,
		} );
		expect( form.dispatchEvent( submission ) ).toBe( false );
		expect( feedback.ownerDocument.activeElement ).toBe( feedback );

		for ( const role of [ 'access_owners', 'owners' ] ) {
			owner.value = role;
			owner.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			expect( feedback.textContent ).toBe( '' );
			expect( submit.getAttribute( 'aria-disabled' ) ).toBe( 'false' );
		}
		const search =
			form.querySelector< HTMLInputElement >( '[type="search"]' )!;
		search.value = 'no matching account';
		search.dispatchEvent( new Event( 'input' ) );
		expect(
			owner.closest< HTMLElement >( '[data-access-person]' )!.hidden
		).toBe( true );
		expect(
			form.dispatchEvent( new Event( 'submit', { cancelable: true } ) )
		).toBe( true );
	} );
} );
