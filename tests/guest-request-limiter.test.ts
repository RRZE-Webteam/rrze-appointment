import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type Response = {
	success: boolean;
	status: number;
	headers: string[];
	data: string;
	pending: unknown[];
	subscriptions: unknown[];
	mails: string[];
};
type Row = { option_name: string; option_value: string; autoload: string };

function submit(
	requests: Record< string, unknown >[],
	limits?: unknown
): {
	responses: Response[];
	rows: Row[];
} {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/public-email-validation.php' ) ],
			{
				input: JSON.stringify( { requests, limits } ),
				encoding: 'utf8',
				maxBuffer: 8 * 1024 * 1024,
			}
		)
	);
}

const guest = { email: 'Max+Termin@example.org' };
function storage(
	operation: string,
	limit?: number
): { results: number[]; rows: Row[]; intermediate: Row[] } {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/guest-rate-limit.php' ) ],
			{
				input: JSON.stringify( { operation, limit } ),
				encoding: 'utf8',
			}
		)
	);
}
const repeat = ( request: Record< string, unknown >, count: number ) =>
	Array.from( { length: count }, () => request );

for ( const flow of [ 'booking', 'notification' ] ) {
	it( `${ flow }: blocks the sixth request before any reservation, subscription, or mail`, () => {
		const { responses } = submit( repeat( { ...guest, flow }, 6 ) );
		expect(
			responses.slice( 0, 5 ).every( ( item ) => item.success )
		).toBe( true );
		expect( responses[ 5 ] ).toMatchObject( {
			success: false,
			status: 429,
			headers: [ 'Retry-After: 900' ],
			data: 'Too many requests. Please wait a few minutes and try again.',
			pending: responses[ 4 ].pending,
			subscriptions: responses[ 4 ].subscriptions,
			mails: responses[ 4 ].mails,
		} );
	} );
}

it( 'shares the email quota across both forms, IPs, and normalized domain spellings', () => {
	const { responses } = submit(
		[
			{ email: 'Max+Termin@MÜLLER.DE', ip: '192.0.2.1' },
			{
				email: 'max+termin@xn--mller-kva.de',
				flow: 'notification',
				ip: '192.0.2.2',
			},
			{ email: 'MAX+TERMIN@mu\u0308ller.de', ip: '192.0.2.3' },
		],
		{ email: 2 }
	);
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		true,
		true,
		false,
	] );
	expect( responses[ 0 ].mails ).toEqual( [ 'Max+Termin@xn--mller-kva.de' ] );
} );

it( 'limits changing recipients at one IP and ignores spoofed forwarding headers', () => {
	const { responses } = submit(
		[
			{ email: 'a@example.org', forwarded: '192.0.2.10' },
			{
				email: 'b@example.org',
				forwarded: '192.0.2.11',
				flow: 'notification',
			},
			{ email: 'c@example.org', forwarded: '192.0.2.12' },
			{ email: 'c@example.org', ip: '192.0.2.2' },
		],
		{ ip: 2 }
	);
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		true,
		true,
		false,
		true,
	] );
} );

it( 'allows many distinct university users sharing one public IP', () => {
	const { responses } = submit(
		Array.from( { length: 40 }, ( _, index ) => ( {
			email: `user${ index }@example.org`,
		} ) )
	);
	expect( responses.every( ( item ) => item.success ) ).toBe( true );
} );

it( 'can disable the IP quota without disabling the recipient quota', () => {
	const { responses, rows } = submit(
		[
			{ email: 'a@example.org' },
			{ email: 'b@example.org' },
			{ email: 'a@example.org' },
		],
		{ ip: 0, email: 1 }
	);
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		true,
		true,
		false,
	] );
	expect( rows ).toHaveLength( 2 );
} );

it.each( [
	[ '192.0.2.1', '::ffff:192.0.2.1' ],
	[ '2001:db8:1::1', '2001:0db8:0001:0000:0000:0000:0000:0001' ],
	[ '2001:db8:1::1', '2001:db8:1::abcd' ],
	[ '', 'not-an-ip' ],
] )( 'shares the source quota for %s and %s', ( first, second ) => {
	const { responses } = submit(
		[
			{ email: 'a@example.org', ip: first },
			{ email: 'b@example.org', ip: second },
		],
		{ ip: 1 }
	);
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		true,
		false,
	] );
} );

it( 'does not group different IPv6 /64 networks together', () => {
	const { responses } = submit(
		[
			{ email: 'a@example.org', ip: '2001:db8:1::1' },
			{ email: 'b@example.org', ip: '2001:db8:2::1' },
		],
		{ ip: 1 }
	);
	expect( responses.every( ( item ) => item.success ) ).toBe( true );
} );

it( 'allows requests at expiry and does not extend a block on repeated attempts', () => {
	const { responses } = submit( [
		...repeat( guest, 5 ),
		{ ...guest, now: 1100 },
		{ ...guest, now: 1899 },
		{ ...guest, now: 1900 },
	] );
	expect( responses.slice( 5 ).map( ( item ) => item.headers ) ).toEqual( [
		[ 'Retry-After: 800' ],
		[ 'Retry-After: 1' ],
		[],
	] );
	expect( responses[ 7 ].success ).toBe( true );
} );

it( 'keeps quotas scoped to the current site in multisite', () => {
	const { responses } = submit( [ guest, guest, { ...guest, blog: 2 } ], {
		email: 1,
	} );
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		true,
		false,
		true,
	] );
} );

it( 'does not charge invalid emails or unavailable slots against quotas', () => {
	const { responses } = submit(
		[
			{ email: 'broken address' },
			{ ...guest, booked: [ '2099-01-01 10:00-10:30' ] },
			guest,
		],
		{ ip: 1, email: 1 }
	);
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		false,
		false,
		true,
	] );
} );

it( 'uses SSO identity without consuming guest quotas', () => {
	const { responses, rows } = submit(
		[
			guest,
			guest,
			{ email: 'forged invalid address', disableSso: false },
			{
				email: 'forged invalid address',
				disableSso: false,
				flow: 'notification',
			},
		],
		{ email: 1, ip: 1 }
	);
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		true,
		false,
		true,
		true,
	] );
	expect( responses[ 2 ].mails ).toEqual( [
		guest.email,
		'sso@example.test',
	] );
	expect( rows.map( ( row ) => row.option_value ) ).toEqual( [
		'1900:1',
		'1900:1',
	] );
} );

it( 'stores only non-autoloaded keyed hashes, expiries, and counts', () => {
	const { rows } = submit( [ guest ] );
	expect( rows ).toHaveLength( 2 );
	for ( const row of rows ) {
		expect( row.option_name ).toMatch(
			/^rrze_appt_guest_rate_[a-f0-9]{64}$/
		);
		expect( row.option_value ).toBe( '1900:1' );
		expect( row.autoload ).toBe( 'no' );
	}
} );

it( 'keeps safe defaults for invalid filter values', () => {
	const { responses } = submit( repeat( guest, 6 ), {
		email: 0,
		ip: -1,
		window: 'invalid',
	} );
	expect( responses.slice( 0, 5 ).every( ( item ) => item.success ) ).toBe(
		true
	);
	expect( responses[ 5 ].headers ).toEqual( [ 'Retry-After: 900' ] );
} );

it( 'supports an adjusted window without extending already active counters', () => {
	const { responses } = submit(
		[ guest, { ...guest, now: 1010 }, { ...guest, now: 1060 } ],
		{ email: 1, window: 60 }
	);
	expect( responses.map( ( item ) => item.success ) ).toEqual( [
		true,
		false,
		true,
	] );
	expect( responses[ 1 ].headers ).toEqual( [ 'Retry-After: 50' ] );
} );

it( 'allows 300 distinct recipients per source and blocks the next without creating a recipient row', () => {
	const { results, rows } = storage( 'defaultIpLimit' );
	expect( results.slice( 0, 300 ).every( ( retry ) => retry === 0 ) ).toBe(
		true
	);
	expect( results[ 300 ] ).toBe( 900 );
	expect( rows ).toHaveLength( 301 );
} );

it( 'does not bypass a full quota when another request creates the counter concurrently', () => {
	const { results, rows } = storage( 'insertRace' );
	expect( results ).toEqual( [ 900 ] );
	expect( rows[ 0 ].option_value ).toBe( '1900:1' );
} );

it( 'does not lose a concurrent increment when updating an existing counter', () => {
	const { results, rows } = storage( 'updateRace', 2 );
	expect( results ).toEqual( [ 0, 900 ] );
	expect( rows[ 0 ].option_value ).toBe( '1900:2' );
} );

it( 'retries a contended update when quota remains available', () => {
	const { results, rows } = storage( 'updateRace', 3 );
	expect( results ).toEqual( [ 0, 0 ] );
	expect( rows[ 0 ].option_value ).toBe( '1900:3' );
} );

it( 'refuses temporarily after repeated contention instead of allowing uncounted requests', () => {
	const { results, rows } = storage( 'contention' );
	expect( results ).toEqual( [ 0, 60 ] );
	expect( rows[ 0 ].option_value ).toBe( '1900:6' );
} );

it.each( [ 'readFailure', 'writeFailure', 'corrupt' ] )(
	'refuses temporarily on %s',
	( operation ) => {
		expect( storage( operation ).results ).toEqual( [ 60 ] );
	}
);

it( 'cleans up expired counters while retaining live counters and unrelated options', () => {
	const { intermediate, rows } = storage( 'cleanup' );
	expect( intermediate ).toHaveLength( 2 );
	expect( rows ).toEqual( [
		{
			option_name: 'rrzeXapptXguestXrateXunrelated',
			option_value: '1:1',
			autoload: 'no',
		},
	] );
} );
