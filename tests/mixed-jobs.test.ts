import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

describe( 'durable jobs in mixed booking storage', () => {
	const result = JSON.parse(
		execFileSync(
			'php',
			[ resolve( process.cwd(), 'tests/fixtures/mixed-jobs.php' ) ],
			{ encoding: 'utf8' }
		)
	);

	it( 'adopts the original plaintext and V1 event times without rewriting the queue', () => {
		expect( result.adoptedTimes ).toEqual( [ 1800000900, 1800003600 ] );
		expect( result.adoptionIsReadOnly ).toBe( true );
		expect( result.foreignPreserved ).toBe( true );
		expect( result.adoptedCallbacksSendOncePerRole ).toBe( true );
	} );

	it( 'repairs only a participating site and ignores early, concurrent or repeated reminder callbacks', () => {
		expect( result ).toMatchObject( {
			inactive: true,
			repaired: true,
			early: true,
			singleDelivery: true,
		} );
	} );

	it( 'recovers a persisted claim through a key outage and a lost delivery acknowledgement', () => {
		expect( result ).toMatchObject( {
			persistedClaim: true,
			outage: true,
			lateAckIsNotReplayedEarly: true,
			uncertainDeliveryRetries: true,
		} );
	} );

	it( 'retains an opening retry made inside the callback and expires a pending owner only when due', () => {
		expect( result ).toMatchObject( {
			reschedulePreserved: true,
			openingSent: true,
			retryPromotesAtRequestedTime: true,
			pendingDeadline: true,
			earlyExpiryPreserved: true,
			expiryRemoved: true,
			invalidActionRejected: true,
		} );
	} );
} );
