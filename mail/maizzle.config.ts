import { defineConfig } from '@maizzle/framework';

export default defineConfig( {
	content: [ 'mail/emails/**/*.vue' ],
	output: {
		path: 'build/email',
		extension: 'html',
	},
	css: {
		inline: true,
		purge: true,
		safe: true,
		shorthand: true,
	},
	html: {
		decodeEntities: true,
		format: true,
		minify: false,
	},
} );
