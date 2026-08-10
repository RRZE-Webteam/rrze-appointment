import { readFile } from 'node:fs/promises';

const outputPath = new URL( '../build/email/layout.html', import.meta.url );
const template = await readFile( outputPath, 'utf8' );
const markers = [
	'___RRZE_EMAIL_LANG___',
	'___RRZE_EMAIL_DIR___',
	'___RRZE_EMAIL_SUBJECT___',
	'___RRZE_EMAIL_SITE_URL___',
	'___RRZE_EMAIL_SITE_NAME___',
	'___RRZE_EMAIL_LOGO___',
	'___RRZE_EMAIL_CONTENT___',
	'___RRZE_EMAIL_FOOTER_LINKS___',
	'___RRZE_EMAIL_STATUS_ACCENT___',
	'___RRZE_EMAIL_STATUS_SURFACE___',
	'___RRZE_EMAIL_STATUS_TEXT___',
	'___RRZE_EMAIL_STATUS_LABEL___',
];

const missingMarkers = markers.filter(
	( marker ) => ! template.includes( marker )
);

if ( missingMarkers.length > 0 ) {
	throw new Error(
		`The compiled email layout is missing runtime markers: ${ missingMarkers.join(
			', '
		) }`
	);
}

if ( ! template.toLowerCase().includes( '<!doctype html>' ) ) {
	throw new Error( 'The compiled email layout is missing its HTML doctype.' );
}
