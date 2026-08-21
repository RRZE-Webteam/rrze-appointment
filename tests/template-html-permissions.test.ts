import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'mail template HTML permissions', () => {
	const settings = readProjectFile( 'includes/Settings.php' );

	it( 'uses the same permission for rendering and saving HTML', () => {
		expect( settings ).toContain(
			'MailTemplatePost::save($_POST, $isDraft, MailTemplatePost::canEditHtml())'
		);
		expect( settings ).toContain(
			'$canEditHtml = MailTemplatePost::canEditHtml();'
		);
		expect( settings ).toContain( '<?php if (!$canEditHtml) : ?>' );
	} );
} );
