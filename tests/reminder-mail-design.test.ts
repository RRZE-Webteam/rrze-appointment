import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'reminder mail design', () => {
	const reminder = readProjectFile( 'includes/Reminder.php' );
	const settings = readProjectFile( 'includes/Settings.php' );

	it( 'sends reminder HTML through the compiled Maizzle layout', () => {
		expect( settings ).toContain(
			'MailTemplate::wrap($html, $subject, $status)'
		);
		expect( reminder ).toContain( 'Settings::sendMail(' );
		expect( reminder ).toContain(
			"MailTemplate::statusForType('reminder_admin')"
		);
		expect( reminder ).toContain(
			"MailTemplate::statusForType('reminder_booker')"
		);
	} );

	it( 'renders host and booker reminder templates independently', () => {
		expect( reminder ).toContain(
			"$this->resolveTemplate($tplId, 'reminder_admin')"
		);
		expect( reminder ).toContain(
			"$this->resolveTemplate($tplId, 'reminder_booker')"
		);
		expect( reminder ).toContain( "$adminMail['subject']" );
		expect( reminder ).toContain( "$bookerMail['subject']" );
	} );
} );
