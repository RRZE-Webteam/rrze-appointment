import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'frontend calendar legend', () => {
	const template = readProjectFile( 'src/render.php' );
	const styles = readProjectFile( 'src/styles/style.scss' );
	const builtTemplate = readProjectFile( 'build/render.php' );
	const builtStyles = readProjectFile( 'build/style-index.css' );

	it( 'appears before the calendar and labels every visual state', () => {
		expect(
			template.indexOf( 'rrze-appointment__calendar-legend' )
		).toBeLessThan(
			template.indexOf( 'rrze-appointment__calendar"></div>' )
		);
		expect( template ).toContain( "esc_attr__('Calendar legend'" );
		expect( template ).toContain( "esc_html_e('Available'" );
		expect( template ).toContain( "esc_html_e('Selected'" );
		expect( template ).toContain( "esc_html_e('Not yet bookable'" );
		expect( template ).toContain( "esc_html_e('Past or booked'" );
		expect( template ).toContain( "esc_html_e('Today'" );
	} );

	it( 'uses the same color variables as the corresponding calendar states', () => {
		expect( styles ).toContain( 'background: $cal-bg-available;' );
		expect( styles ).toContain( 'background: $cal-bg-active;' );
		expect( styles ).toContain( 'background: $cal-bg-not-open;' );
		expect( styles ).toContain( 'background: $cal-bg-booked;' );
		expect( styles ).toContain(
			'box-shadow: inset 0 0 0 3px $cal-today-outline;'
		);
	} );

	it( 'only explains advance-window appointments when that state is possible', () => {
		expect( template ).toContain(
			'<?php if ($bookingMaxAdvance > 0) : ?>'
		);
	} );

	it( 'is included in the distributed frontend build', () => {
		expect( builtTemplate ).toContain(
			'rrze-appointment__calendar-legend'
		);
		expect( builtStyles ).toContain( '.rrze-appointment__calendar-legend' );
	} );
} );
