import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const indexSource = readFileSync(
	resolve( process.cwd(), 'src/index.tsx' ),
	'utf8'
);

describe( 'appointment block icon', () => {
	it( 'registers the custom appointment SVG as the block icon', () => {
		expect( indexSource ).toContain( 'const appointmentIcon = (' );
		expect( indexSource ).toContain( 'viewBox="0 -960 960 960"' );
		expect( indexSource ).toContain(
			'M440-120v-80h320v-284q0-117-81.5-198.5T480-764'
		);
		expect( indexSource ).toContain( 'icon: appointmentIcon' );
	} );
} );
