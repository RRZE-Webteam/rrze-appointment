import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

/** Executes isolated PHP fixtures, retaining exception arguments to verify redaction. */
const run = ( fixture: string, args: string[] = [] ): any =>
	JSON.parse(
		execFileSync(
			'php',
			[
				'-d',
				'zend.exception_ignore_args=0',
				resolve( process.cwd(), 'tests/fixtures', fixture ),
				...args,
			],
			{ encoding: 'utf8' }
		)
	);

describe( 'auxiliary booking data and private responses', () => {
	const result = run( 'auxiliary-storage.php' );
	it.each( [ 'encrypted', 'legacy' ] )(
		'uses opaque site/purpose-bound lock keys and bounded cleanup in %s mode',
		( mode ) => {
			expect( run( 'auxiliary-storage.php', [ mode ] ) ).toMatchObject( {
				opaqueLock: true,
				stableLock: true,
				purposeBound: true,
				siteBound: true,
				boundedCleanup: true,
				cleanupResumed: true,
				expiredCurrentLockRemoved: true,
				unrelatedLocksPreserved: true,
				bookingUnchanged: true,
			} );
		}
	);
	it( 'discards unexpected exception details and preserves deliberate domain messages', () => {
		expect( result ).toMatchObject( {
			safeFailure: true,
			domainPreserved: true,
		} );
	} );
	it( 'marks all token pages and booking AJAX private, including malformed token requests', () => {
		expect( result ).toMatchObject( {
			publicCacheUntouched: true,
			cachePluginFlag: true,
		} );
		for ( const headers of [
			...result.tokenHeaders,
			...result.adminHeaders,
			result.ajaxHeaders,
		] ) {
			expect( headers ).toEqual(
				expect.arrayContaining( [
					'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',
					'Referrer-Policy: no-referrer',
					'X-Robots-Tag: noindex, nofollow, noarchive',
				] )
			);
		}
	} );
	it( 'protects REST success, permission failures and storage errors without touching unrelated routes', () => {
		for ( const response of result.rest ) {
			expect( [ 200, 401, 403, 500, 503 ] ).toContain( response.status );
			if ( response.route.startsWith( '/unrelated/' ) ) {
				expect( response.headers ).toEqual( [] );
			} else {
				expect( response.headers[ 'Cache-Control' ] ).toContain(
					'no-store'
				);
				expect( response.headers[ 'Referrer-Policy' ] ).toBe(
					'no-referrer'
				);
			}
		}
	} );
} );

const phpMailerPath = resolve(
	process.cwd(),
	'../../../wp-includes/PHPMailer'
);
const nativeMailTest = existsSync( resolve( phpMailerPath, 'PHPMailer.php' ) )
	? it
	: it.skip;
nativeMailTest(
	'builds a real MIME calendar in memory and clears plugin state on success and failures without sending mail',
	() => {
		const result = run( 'mail-privacy.php', [ phpMailerPath ] );
		expect( result ).toMatchObject( {
			mimeHasCalendar: true,
			onlyStringAttachments: true,
			success: { failedSafely: false, cleared: true },
		} );
		for ( const mode of [ 'throw', 'false', 'template-error' ] ) {
			expect( result[ mode ] ).toEqual( {
				failedSafely: true,
				cleared: true,
			} );
		}
	}
);
