import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'additional question data-use requirements', () => {
	const questionEditor = readProjectFile(
		'src/components/questions-manager-dialog.tsx'
	);
	const appointmentBlock = readProjectFile( 'includes/AppointmentBlock.php' );
	const prePublishCheck = readProjectFile( 'src/pre-publish.js' );

	it( 'requires an explanation before the editor saves a question', () => {
		expect( questionEditor ).toContain(
			'const dataUse = draft.dataUse.trim()'
		);
		expect( questionEditor ).toContain( 'if ( ! dataUse )' );
		expect( questionEditor ).toContain( 'dataUse,' );
	} );

	it( 'rejects unsanitized questions without a data-use explanation', () => {
		expect( appointmentBlock ).toContain(
			'$dataUse = sanitize_textarea_field'
		);
		expect( appointmentBlock ).toContain( "$dataUse === ''" );
		expect( appointmentBlock ).toContain( "'dataUse' => $dataUse" );
	} );

	it( 'prevents publishing legacy questions until notices are supplied', () => {
		expect( prePublishCheck ).toContain( 'hasInvalidQuestionPurpose' );
		expect( prePublishCheck ).toMatch(
			/!\s+question\?\.dataUse\?\.trim\(\)/
		);
		expect( prePublishCheck ).toMatch(
			/editPost\(\s*{\s*status:\s*'draft'\s*}\s*\)/
		);
	} );
} );
