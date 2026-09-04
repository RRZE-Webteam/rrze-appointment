import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

type Question = {
	id?: string;
	label?: string;
	dataUse?: string;
	type?: string;
	required?: boolean;
	options?: unknown[];
};

const normalizeQuestions = ( questions: Question[] ) => {
	const appointmentBlockPath = resolve(
		process.cwd(),
		'includes/Booking/AppointmentBlock.php'
	);
	const encodedQuestions = JSON.stringify( JSON.stringify( { questions } ) );
	const php = `
		define( 'ABSPATH', __DIR__ );
		function sanitize_key( $value ) {
			return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
		}
		function sanitize_text_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
		function sanitize_textarea_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
		require ${ JSON.stringify( appointmentBlockPath ) };
		$attributes = json_decode( ${ encodedQuestions }, true );
		echo json_encode( \\RRZE\\Appointment\\Booking\\AppointmentBlock::getQuestions( $attributes ) );
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	);
};

describe( 'additional question data-use requirements', () => {
	const questionEditor = readProjectFile(
		'src/editor/questions/questions-manager-dialog.tsx'
	);
	const prePublishCheck = readProjectFile(
		'src/editor/publish-validation.ts'
	);

	it( 'requires an explanation before the editor saves a question', () => {
		expect( questionEditor ).toContain(
			'const dataUse = draft.dataUse.trim()'
		);
		expect( questionEditor ).toContain( 'if ( ! dataUse )' );
		expect( questionEditor ).toContain( 'dataUse,' );
	} );

	it( 'normalizes valid questions and rejects incomplete definitions', () => {
		expect(
			normalizeQuestions( [
				{
					id: 'Contact Email',
					label: '<b>Contact preference</b>',
					dataUse: '<i>Used to prepare the appointment.</i>',
					type: 'select',
					required: true,
					options: [ 'Email', 'Email', '', { invalid: true } ],
				},
				{
					id: 'missing-purpose',
					label: 'Rejected question',
					dataUse: '',
				},
			] )
		).toEqual( [
			{
				id: 'contactemail',
				label: 'Contact preference',
				dataUse: 'Used to prepare the appointment.',
				type: 'select',
				required: true,
				options: [ 'Email' ],
			},
		] );
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
