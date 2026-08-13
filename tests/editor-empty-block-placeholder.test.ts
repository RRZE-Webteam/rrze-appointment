import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'empty appointment block placeholder', () => {
	const editor = readProjectFile( 'src/edit.tsx' );
	const editorStyles = readProjectFile( 'src/editor.scss' );
	const frontendStyles = readProjectFile( 'src/style.scss' );

	it( 'shows the setup UI until appointment times exist', () => {
		expect( editor ).toContain( 'function EmptyBlockPlaceholder' );
		expect( editor ).toContain( 'Placeholder,' );
		expect( editor ).toContain( '<Placeholder' );
		expect( editor ).toContain( 'isColumnLayout' );
		expect( editor ).toContain( 'availabilityEntries.length === 0' );
		expect( editor ).toContain( '<EmptyBlockPlaceholder' );
		expect( editor ).toContain( 'handleAddAvailability()' );
	} );

	it( 'opens the calendar preview when the first appointment times are added', () => {
		expect( editor ).toContain(
			'useState(\n\t\tavailabilityCount > 0\n\t)'
		);
		expect( editor ).toContain( 'previousAvailabilityCount.current === 0' );
		expect( editor ).toContain( 'setShowCalendarPreview( true )' );
	} );

	it( 'collects the initial title and short description', () => {
		expect( editor ).toContain(
			"label={ __( 'Appointment title', 'rrze-appointment' ) }"
		);
		expect( editor ).toContain(
			"label={ __( 'Short description', 'rrze-appointment' ) }"
		);
		expect( editor ).toContain( 'onTitleChange={ ( value ) =>' );
		expect( editor ).toContain( 'onDescriptionChange={ ( value ) =>' );
	} );

	it( 'uses the decorative illustration only in the responsive editor UI', () => {
		expect( editor ).toContain(
			"import emptyBlockIllustration from './illustrations/dream-3.png'"
		);
		expect( editor ).toContain( 'alt=""' );
		expect( editor ).toContain(
			'<span className="rrze-appointment-block__placeholder-media">'
		);
		expect( editorStyles ).toContain( '&__placeholder-illustration {' );
		expect( editorStyles ).toContain( '&__placeholder-media {' );
		expect( editorStyles ).toContain(
			'&__placeholder .components-placeholder__preview {'
		);
		expect( editorStyles ).toContain( 'background: none;' );
		expect( editorStyles ).toContain( 'content: none;' );
		expect( editorStyles ).toContain( '@media (max-width: 700px)' );
		expect( frontendStyles ).not.toContain( 'dream-3.png' );
	} );
} );
