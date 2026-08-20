import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'public confirmation templates accessibility', () => {
	const confirmationTemplate = readProjectFile(
		'templates/confirmation-page.php'
	);
	const errorTemplate = readProjectFile( 'templates/error-page.php' );

	it( 'shows appointment details with semantic description markup', () => {
		expect( confirmationTemplate ).toContain(
			'<section class="rrze-appointment-confirmation__details"'
		);
		expect( confirmationTemplate ).toContain( '<dl>' );
		expect( confirmationTemplate ).toContain(
			"esc_html_e('Appointment details', 'rrze-appointment')"
		);
	} );

	it( 'submits an explicit cancellation action', () => {
		expect( confirmationTemplate ).toContain(
			'name="rrze_appt_cancel_action" value="cancel"'
		);
	} );

	it( 'uses one predictable focus target for server-side errors', () => {
		expect( confirmationTemplate ).not.toContain( 'role="alert"' );
		expect( confirmationTemplate ).toContain(
			"$formErrorField === '' ? 'autofocus' : ''"
		);
		expect( confirmationTemplate ).toContain(
			"document.getElementById('rrze-appt-form-error').focus()"
		);
		expect( confirmationTemplate ).toContain(
			'aria-errormessage="rrze-appt-form-error" autofocus'
		);
	} );

	it( 'keeps visible requirement text without duplicating the required state', () => {
		expect( confirmationTemplate ).toContain(
			"$questionRequired ? 'aria-hidden=\"true\"' : ''"
		);
		expect( confirmationTemplate ).toContain(
			"$questionRequired ? 'required' : ''"
		);
	} );

	it( 'places the data-use notice below its question and describes the field', () => {
		expect( confirmationTemplate ).toContain(
			'$questionDataUse = sanitize_textarea_field'
		);
		expect( confirmationTemplate ).toContain(
			'class="rrze-appointment-confirmation__legal-notice"'
		);
		expect( confirmationTemplate ).toContain(
			'$describedByIds[] = $noticeId'
		);
		expect( confirmationTemplate ).toContain(
			"esc_html_e('Why we ask:', 'rrze-appointment')"
		);
	} );

	it( 'uses a high-contrast focus indicator on public links and actions', () => {
		expect( confirmationTemplate ).toContain(
			'outline: 3px solid #04316a;'
		);
		expect( errorTemplate ).toContain( 'outline: 3px solid #04316a;' );
		expect( confirmationTemplate ).not.toContain(
			'outline: 3px solid #ffca28;'
		);
		expect( errorTemplate ).not.toContain( 'outline: 3px solid #ffca28;' );
	} );

	it( 'keeps the waitlist opt-in action visually secondary', () => {
		expect( confirmationTemplate ).toContain(
			'.is-waitlist-optout .rrze-appointment-confirmation__action:not(.rrze-appointment-confirmation__action--secondary)'
		);
		expect( confirmationTemplate ).toContain(
			'button.rrze-appointment-confirmation__action--secondary'
		);
		expect( confirmationTemplate ).toContain(
			'border: 1px solid #04316a;'
		);
	} );
} );
