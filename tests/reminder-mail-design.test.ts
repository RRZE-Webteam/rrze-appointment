import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'reminder mail design', () => {
	const reminder = readProjectFile( 'includes/Notification/Reminder.php' );
	const mailer = readProjectFile( 'includes/Mail/Mailer.php' );

	it( 'sends reminder HTML through the compiled Maizzle layout', () => {
		expect( mailer ).toContain(
			'MailTemplate::wrap($html, $subject, $status)'
		);
		expect( reminder ).toContain( 'Mailer::send(' );
		expect( reminder ).toContain(
			'MailTemplate::statusForType($templateType)'
		);
	} );

	it( 'renders host and booker reminder templates independently', () => {
		expect( reminder ).toContain(
			'$this->resolveTemplate($templateId, self::ADMIN_TEMPLATE_TYPE)'
		);
		expect( reminder ).toContain(
			'$this->resolveTemplate($templateId, self::BOOKER_TEMPLATE_TYPE)'
		);
		expect( reminder ).toContain( '$adminMail = $this->renderMail(' );
		expect( reminder ).toContain( '$bookerMail = $this->renderMail(' );
	} );
} );
