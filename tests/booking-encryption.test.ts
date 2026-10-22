import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const prefix = 'rrze-appointment:encrypted:';
const site = '00112233445566778899aabbccddeeff';
const otherSite = 'ffeeddccbbaa99887766554433221100';
const key = Buffer.from( Array.from( { length: 64 }, ( _, i ) => i ) ).toString(
	'base64'
);
const storage = 'rrze_appointment_booked_slots_meta';
const payload = {
	'2099-01-02 10:00-10:30': {
		booker_name: 'Ada Ähnliche',
		booker_email: 'ada@example.test',
		cancel_token: 'synthetic-secret-token',
		booker_waitlist: true,
		person_id: 17,
		waitlist_notified_slots: [],
	},
};

type RoundTrip = {
	first: string;
	second: string;
	payload: unknown;
	identical: boolean;
};
type Result< T = unknown > =
	| { ok: true; value: T }
	| {
			ok: false;
			value?: never;
			error: string;
			message: string;
			trace: string;
	  };

/** Executes real PHP/Sodium against public fixture data without loading WordPress. */
function run< T = RoundTrip >(
	scenario: Record< string, unknown >,
	phpOptions: string[] = []
): Result< T > {
	return JSON.parse(
		execFileSync(
			'php',
			[
				...phpOptions,
				resolve( 'tests/fixtures/booking-encryption.php' ),
			],
			{
				input: JSON.stringify( {
					key,
					site,
					storage,
					payload,
					...scenario,
				} ),
				encoding: 'utf8',
			}
		)
	);
}

/** Returns a successful fixture value with a typed result and useful failure diagnostics. */
function successful< T >( result: Result< T > ): T {
	if ( result.ok === false ) {
		throw new Error( result.message );
	}
	return result.value;
}

/** Asserts a domain failure instead of successful empty data or an uncaught runtime error. */
function expectFailure( result: Result ): void {
	expect( result ).toMatchObject( {
		ok: false,
		error: 'RRZE\\Appointment\\AppointmentException',
	} );
	expect( result.value ).toBeUndefined();
	if ( result.ok === false ) {
		expect( result.message ).toBeTruthy();
	}
}

it.each( [ 32, 64 ] )(
	'accepts a Base64 master key containing %i random bytes',
	( length ) => {
		const result = run< { id: string; bytes: number } >( {
			action: 'configuration',
			configuredKey: Buffer.alloc( length, 7 ).toString( 'base64' ),
		} );
		expect( result ).toMatchObject( { ok: true, value: { bytes: 32 } } );
		expect( successful( result ).id ).toMatch( /^[0-9a-f]{32}$/ );
	}
);

it( 'accepts wrapped OpenSSL output without exposing the key in debug output', () => {
	const wrapped = ` \t${ key.slice( 0, 64 ) }\r\n${ key.slice( 64 ) }\n`;
	const normal = run( { action: 'configuration', configuredKey: key } );
	expect(
		run( { action: 'configuration', configuredKey: wrapped } )
	).toEqual( normal );
	const debug = run( { action: 'debug' } );
	expect( debug.ok ).toBe( true );
	expect( debug.value ).not.toContain( key );
	expect( debug.value ).not.toContain( 'material' );
	expectFailure( run( { action: 'serialize' } ) );
} );

it.each( [
	undefined,
	null,
	false,
	123,
	'',
	'not-a-key',
	Buffer.alloc( 16 ).toString( 'base64' ),
	Buffer.alloc( 48 ).toString( 'base64' ),
	Buffer.alloc( 65 ).toString( 'base64' ),
	`${ key }!`,
	key.replace( /==$/, '' ),
] )(
	'rejects missing, malformed, or incorrectly sized key configuration (%p)',
	( configuredKey ) => {
		const result = run( { action: 'configuration', configuredKey } );
		expectFailure( result );
		if ( result.ok === false ) {
			expect( result.message ).not.toContain( key );
			expect( result.trace ).not.toContain( key );
		}
	}
);

it( 'round-trips booking data with fresh nonces and no plaintext in the stored value', () => {
	const result = successful( run( { action: 'encrypt' } ) );
	expect( result.payload ).toEqual( payload );
	expect( result.identical ).toBe( true );
	expect( result.first ).not.toEqual( result.second );
	for ( const stored of [ result.first, result.second ] ) {
		expect( stored.startsWith( prefix ) ).toBe( true );
		expect( stored ).not.toContain( 'ada@example.test' );
		expect( stored ).not.toContain( 'synthetic-secret-token' );
		expect( stored ).not.toContain( key );
	}
	const first = JSON.parse( result.first.slice( prefix.length ) );
	const second = JSON.parse( result.second.slice( prefix.length ) );
	expect( first.nonce ).not.toEqual( second.nonce );
	expect( Buffer.from( first.nonce, 'base64' ).length ).toBe( 24 );
} );

it.each( [
	'rrze_appointment_booked_slots',
	'rrze_appointment_booked_slots_meta',
	'rrze_appointment_pending_slots',
	'rrze_appointment_cancel_tokens',
	'rrze_appointment_booking_opening_notifications',
] )( 'supports the protected storage area %s', ( storageName ) => {
	expect( run( { action: 'encrypt', storage: storageName } ) ).toMatchObject(
		{
			ok: true,
			value: { identical: true },
		}
	);
} );

it( 'preserves empty and sparse arrays, scalar types, and Unicode', () => {
	expect( run( { action: 'encrypt', payload: [] } ) ).toMatchObject( {
		ok: true,
		value: { payload: [], identical: true },
	} );
	expect( run( { action: 'sparse' } ) ).toMatchObject( {
		ok: true,
		value: { identical: true },
	} );
} );

it( 'rejects other keys, websites, and storage areas', () => {
	const stored = successful( run( { action: 'encrypt' } ) ).first;
	for ( const changed of [
		{ key: Buffer.alloc( 64, 9 ).toString( 'base64' ) },
		{ site: otherSite },
		{ storage: 'rrze_appointment_cancel_tokens' },
	] ) {
		expectFailure( run( { action: 'decrypt', stored, ...changed } ) );
	}
} );

it( 'rejects modified ciphertext, nonce, key identifier, and format version', () => {
	const stored = successful( run( { action: 'encrypt' } ) ).first;
	const envelope = JSON.parse( stored.slice( prefix.length ) );
	const ciphertext = Buffer.from( envelope.ciphertext, 'base64' );
	ciphertext[ 0 ] ^= 1;
	for ( const change of [
		{ ciphertext: ciphertext.toString( 'base64' ) },
		{ nonce: Buffer.alloc( 24, 1 ).toString( 'base64' ) },
		{ key_id: '0'.repeat( 32 ) },
		{ version: 2 },
		{ version: '1' },
		{ nonce: 'invalid!' },
		{ nonce: Buffer.alloc( 23 ).toString( 'base64' ) },
		{ ciphertext: Buffer.alloc( 15 ).toString( 'base64' ) },
		{ ciphertext: null },
		{ extra: 'unexpected' },
	] ) {
		expectFailure(
			run( {
				action: 'decrypt',
				stored: prefix + JSON.stringify( { ...envelope, ...change } ),
			} )
		);
	}
} );

it.each( [
	'',
	'[]',
	prefix,
	`${ prefix }null`,
	`${ prefix }{broken`,
	`${ prefix }[]`,
] )( 'rejects plaintext or malformed envelopes (%p)', ( stored ) =>
	expectFailure( run( { action: 'decrypt', stored } ) )
);

it.each( [ 'object', 'resource', 'recursive', 'utf8', 'infinite' ] )(
	'rejects unsupported payloads without data coercion (%s)',
	( kind ) => expectFailure( run( { action: 'invalidPayload', kind } ) )
);

it( 'rejects invalid site contexts, unrelated options, and unavailable Sodium', () => {
	expectFailure( run( { action: 'encrypt', site: 'https://example.test' } ) );
	expectFailure(
		run( { action: 'encrypt', storage: 'rrze_appointment_settings' } )
	);
	expectFailure(
		run( { action: 'encrypt' }, [
			'-d',
			'disable_functions=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
		] )
	);
} );

/** Builds a frozen compatibility vector generated directly with PHP/Sodium, outside the codec. */
function versionOneVector( ciphertext: string ): string {
	// This fixed nonce and the public 0..63 master key are test-only values.
	return (
		prefix +
		JSON.stringify( {
			version: 1,
			key_id: 'e18679741e9cecec4af76ca8ce959e1c',
			nonce: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
			ciphertext,
		} )
	);
}

it( 'reads a frozen version-one value without requiring the current writer', () => {
	const stored = versionOneVector(
		'yR4pv+WYeSzgmh2OkT8CRL2yy228nzlpwDw920Ed2Ogyi1+VqvLH5TXry4vGM3i7x6Ci8c/6VSJ+juIfepJaWpd3Z/CTLMUV'
	);
	expect( run( { action: 'decrypt', stored } ) ).toEqual( {
		ok: true,
		value: { name: 'Ada', email: 'ada@example.test', enabled: true },
	} );
} );

it.each( [
	'3Ekrsg3HA2ELqei5836lHt/G5vA=', // Authenticated JSON null.
	'yV41seOYNYcf2Lw7c8838iVfZ1Y0sYM=', // Authenticated malformed JSON.
] )(
	'rejects invalid payloads even when the authentication tag is valid (%s)',
	( ciphertext ) => {
		expectFailure(
			run( { action: 'decrypt', stored: versionOneVector( ciphertext ) } )
		);
	}
);
