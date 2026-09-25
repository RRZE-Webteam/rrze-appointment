import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type StoredRequest = { meta: { booker_email: string } };
type Result = {
	success: boolean;
	data: string | { message: string };
	pending: StoredRequest[];
	subscriptions: StoredRequest[];
	mails: string[];
	checkedEmails: string[];
};

function request( scenario: Record< string, unknown > ): Result {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/public-email-validation.php' ) ],
			{
				input: JSON.stringify( scenario ),
				encoding: 'utf8',
			}
		)
	);
}

const maxAddress = `${ 'a'.repeat( 64 ) }@${ 'b'.repeat( 63 ) }.${ 'c'.repeat(
	63
) }.${ 'd'.repeat( 61 ) }`;

for ( const flow of [ 'booking', 'notification' ] ) {
	describe( `${ flow }: guest email validation`, () => {
		it.each( [
			'max mustermann@example.org',
			'max@müller.de',
			'max@-example.org',
			'max@example..org',
			'max@example.org.',
			'max@exam_ple.org',
			'not-an-email',
			'max@@example.org',
			'Max <max@example.org>',
			'one@example.org,two@example.org',
			'max@example.org\r\nBcc: other@example.org',
			'\nmax@example.org',
			'max@example.org\r',
			'\tmax@example.org',
			'ma\x00x@example.org',
			'max@example.org\x7f',
		] )( 'rejects %j before storing data or sending mail', ( email ) => {
			const result = request( { flow, email } );
			expect( result.success ).toBe( false );
			expect( result.data ).toBe( 'Enter a valid email address.' );
			expect( result.pending ).toEqual( [] );
			expect( result.subscriptions ).toEqual( [] );
			expect( result.mails ).toEqual( [] );
		} );

		it.each( [
			'.max@example.org',
			'max.@example.org',
			'max..test@example.org',
			`${ 'a'.repeat( 65 ) }@example.org`,
			`a@${ 'b'.repeat( 64 ) }.org`,
			`${ maxAddress }d`,
		] )(
			'enforces dot and length rules beyond the core syntax check: %j',
			( email ) => {
				const result = request( {
					flow,
					email,
					coreAcceptsSyntax: true,
				} );
				expect( result.success ).toBe( false );
				expect( result.data ).toBe( 'Enter a valid email address.' );
				expect( result.pending ).toEqual( [] );
				expect( result.subscriptions ).toEqual( [] );
				expect( result.mails ).toEqual( [] );
			}
		);

		it.each( [ '', '   ', null, [ 'max@example.org' ] ] )(
			'requires a single nonempty email string: %j',
			( email ) => {
				const result = request( { flow, email } );
				expect( result.success ).toBe( false );
				expect( result.data ).toBe(
					'Please provide an email address.'
				);
				expect( result.pending ).toEqual( [] );
				expect( result.subscriptions ).toEqual( [] );
				expect( result.mails ).toEqual( [] );
			}
		);

		it.each( [
			[ '  Max+Termin@Example.ORG  ', 'Max+Termin@Example.ORG' ],
			[ "o'connor@example.org", "o'connor@example.org" ],
			[ 'max@xn--mller-kva.de', 'max@xn--mller-kva.de' ],
			[ maxAddress, maxAddress ],
		] )(
			'accepts %j without rewriting the recipient',
			( email, expected ) => {
				const result = request( { flow, email } );
				expect( result.success ).toBe( true );
				expect( result.checkedEmails ).toEqual( [ expected ] );
				const stored =
					flow === 'booking' ? result.pending : result.subscriptions;
				expect( stored ).toHaveLength( 1 );
				expect( stored[ 0 ].meta.booker_email ).toBe( expected );
				expect( result.mails ).toEqual(
					flow === 'booking' ? [ expected ] : []
				);
			}
		);

		it( 'continues to use the server SSO identity and ignores a forged form email', () => {
			const result = request( {
				flow,
				email: 'forged invalid address',
				disableSso: false,
			} );
			expect( result.success ).toBe( true );
			const stored =
				flow === 'booking' ? result.pending : result.subscriptions;
			expect( stored[ 0 ].meta.booker_email ).toBe( 'sso@example.test' );
		} );
	} );
}
