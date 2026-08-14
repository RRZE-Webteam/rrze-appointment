import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'mail template HTML permissions', () => {
	const mailTemplatePost = readProjectFile( 'includes/MailTemplatePost.php' );
	const settings = readProjectFile( 'includes/Settings.php' );

	it( 'allows HTML editing on single sites and for multisite super admins', () => {
		expect( mailTemplatePost ).toContain(
			'return !is_multisite() || is_super_admin();'
		);
		expect( mailTemplatePost ).toContain( "$field === 'body_html'" );
		expect( mailTemplatePost ).toContain(
			"current_user_can('edit_posts') && self::canEditHtml()"
		);
	} );

	it( 'uses the same permission for rendering and saving HTML', () => {
		expect( settings ).toContain(
			'MailTemplatePost::save($_POST, $isDraft, MailTemplatePost::canEditHtml())'
		);
		expect( settings ).toContain(
			'$canEditHtml = MailTemplatePost::canEditHtml();'
		);
		expect( settings ).toContain( '<?php if (!$canEditHtml) : ?>' );
	} );

	it( 'does not overwrite protected HTML while saving plaintext changes', () => {
		expect( mailTemplatePost ).toContain( 'if ($canEditHtml) {' );
		expect( mailTemplatePost ).toContain(
			'update_post_meta($result, "tpl_{$key}_body_html"'
		);
	} );
} );
