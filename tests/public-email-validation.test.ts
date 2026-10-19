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

function request(
	scenario: Record< string, unknown >,
	disableIdn = false
): Result {
	return JSON.parse(
		execFileSync(
			'php',
			[
				...( disableIdn
					? [ '-d', 'disable_functions=idn_to_ascii' ]
					: [] ),
				resolve( 'tests/fixtures/public-email-validation.php' ),
			],
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
const longUnicodeDomain = `${ Array( 3 )
	.fill( 'ü'.repeat( 57 ) )
	.join( '.' ) }.de`;
const longAsciiDomain = `${ Array( 3 )
	.fill( `xn--td${ 'a'.repeat( 57 ) }` )
	.join( '.' ) }.de`;

for ( const flow of [ 'booking', 'notification' ] ) {
	describe( `${ flow }: guest email validation`, () => {
		it.each( [
			'max mustermann@example.org',
			'mäx@example.org',
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
			'max@mü ller.de',
			'max@mü\u00a0ller.de',
			'max@mü\u00adller.de',
			'max@mü\u200bller.de',
			'max@mü\u200dller.de',
			'max@aא.de',
			'max@xn--.de',
			'max@xn--a.de',
			`max@${ 'ü'.repeat( 58 ) }.de`,
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
			`${ 'a'.repeat( 64 ) }@${ 'b'.repeat( 63 ) }.${ 'c'.repeat(
				63
			) }.${ 'd'.repeat( 46 ) }.müller.de`,
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
			[ '  Max+Termin@Example.ORG  ', 'Max+Termin@example.org' ],
			[ "o'connor@example.org", "o'connor@example.org" ],
			[ 'Max.Test+Termin@EXAMPLE.ORG', 'Max.Test+Termin@example.org' ],
			[ 'max@xn--mller-kva.de', 'max@xn--mller-kva.de' ],
			[ 'Max+Termin@MÜLLER.DE', 'Max+Termin@xn--mller-kva.de' ],
			[ 'max@XN--MLLER-KVA.DE', 'max@xn--mller-kva.de' ],
			[ 'max@mu\u0308ller.de', 'max@xn--mller-kva.de' ],
			[ 'max@faß.de', 'max@xn--fa-hia.de' ],
			[ 'max@fass.de', 'max@fass.de' ],
			[ 'max@例え.テスト', 'max@xn--r8jz45g.xn--zckzah' ],
			[ 'max@ab--cd.example', 'max@ab--cd.example' ],
			[ `max@${ longUnicodeDomain }`, `max@${ longAsciiDomain }` ],
			[ maxAddress, maxAddress ],
		] )(
			'normalizes the domain of %j while preserving the local part',
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
				const repeated = request( { flow, email: expected } );
				expect( repeated.success ).toBe( true );
				expect( repeated.checkedEmails ).toEqual( [ expected ] );
			}
		);

		it.each( [
			[ 'Max+Termin@Example.ORG', 'Max+Termin@example.org' ],
			[ 'Max+Termin@XN--MLLER-KVA.DE', 'Max+Termin@xn--mller-kva.de' ],
		] )(
			'still accepts %j when IDNA conversion is unavailable',
			( email, expected ) => {
				const result = request( { flow, email }, true );
				expect( result.success ).toBe( true );
				const stored =
					flow === 'booking' ? result.pending : result.subscriptions;
				expect( stored[ 0 ].meta.booker_email ).toBe( expected );
				expect( result.mails ).toEqual(
					flow === 'booking' ? [ expected ] : []
				);
			}
		);

		it( 'explains how to enter an IDN without conversion support, before any side effects', () => {
			const result = request(
				{ flow, email: 'Max+Termin@MÜLLER.DE' },
				true
			);
			expect( result.success ).toBe( false );
			expect( result.data ).toBe(
				'Please enter the email domain in Punycode format; this server cannot convert internationalized domains.'
			);
			expect( result.pending ).toEqual( [] );
			expect( result.subscriptions ).toEqual( [] );
			expect( result.mails ).toEqual( [] );
		} );

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
