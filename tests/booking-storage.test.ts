import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/** Runs real PHP storage, cryptography and domain workflows on synthetic SQL data. */
const run = ( scenario: string ): Record< string, any > =>
	JSON.parse(
		execFileSync(
			'php',
			[
				resolve( process.cwd(), 'tests/fixtures/booking-storage.php' ),
				JSON.stringify( { scenario } ),
			],
			{ encoding: 'utf8' }
		)
	);

describe( 'central booking storage and controlled migration', () => {
	it( 'retains legacy mode when no key or migration marker exists', () => {
		const result = run( 'legacy' );
		expect( result.status.phase ).toBe( 'legacy' );
		expect( result ).toMatchObject( {
			data: true,
			legacyWrite: true,
			noState: true,
		} );
	} );

	it.each( [ 'automatic', 'first-access' ] )(
		'starts encryption on %s without a customer action',
		( scenario ) => {
			expect( run( scenario ) ).toMatchObject( {
				status: { phase: 'encrypted', completed: 5, total: 5 },
				allEncrypted: true,
				payloadPreserved: true,
				rawClean: true,
				noRepeatedWrites: true,
			} );
		}
	);

	it( 'encrypts all five stores and cron references, preserving old links and jobs', () => {
		const result = run( 'migrate' );
		expect( result.status ).toEqual( {
			phase: 'encrypted',
			completed: 5,
			total: 5,
		} );
		for ( const flag of [
			'allEncrypted',
			'autoloadOff',
			'payloadPreserved',
			'publicUntouched',
			'otherCronUntouched',
			'rawClean',
			'referencesResolve',
			'oldCancellation',
			'oldUrl',
			'oldOptout',
			'oldConfirmation',
			'pendingConsumed',
			'newJobOpaque',
			'dispatchWorks',
			'idempotentState',
		] ) {
			expect( [ flag, result[ flag ] ] ).toEqual( [ flag, true ] );
		}
	} );

	it.each( [ 'resume', 'checkpoint-failure' ] )(
		'resumes %s without losing intervening bookings',
		( scenario ) => {
			const result = run( scenario );
			expect( result.interrupted.rejected ).toBe( true );
			expect( result.partialStatus.phase ).toBe( 'migrating' );
			expect( result.status.phase ).toBe( 'encrypted' );
			expect( result.newWriteEncrypted ).toBe( true );
			expect( result.automaticallyResumed ).toBe( true );
			expect( result.newBookingPreserved ).toBe( true );
			expect( result.rawClean ).toBe( true );
		}
	);

	it.each( [
		'wrong-key',
		'missing-key',
		'invalid-key',
		'tamper',
		'missing-option',
		'plaintext-replacement',
		'missing-state',
		'invalid-state',
		'swapped',
		'downgraded-state',
		'corrupt-legacy',
		'object-legacy',
		'database-error',
		'lock-busy',
		'lost-lock',
	] )( 'fails closed for %s without destructive cleanup', ( scenario ) => {
		expect( run( scenario ) ).toMatchObject( {
			rejected: true,
			unchanged: true,
		} );
	} );

	it( 'rejects stale writes instead of overwriting a concurrent booking', () => {
		expect( run( 'write-conflict' ) ).toMatchObject( {
			rejected: true,
			newerPreserved: true,
		} );
	} );

	it( 'retries shared cron changes and retains other plugins’ events', () => {
		expect( run( 'cron-conflict' ) ).toMatchObject( {
			concurrentCronPreserved: true,
			otherCronUntouched: true,
			rawClean: true,
		} );
	} );

	it( 'creates encrypted empty stores so later row loss cannot look like an empty installation', () => {
		expect( run( 'empty' ) ).toMatchObject( {
			allEncrypted: true,
			payloadPreserved: true,
		} );
	} );

	it( 'isolates data, migration contexts and locks in multisite', () => {
		expect( run( 'multisite' ) ).toMatchObject( {
			separateContext: true,
			separateData: true,
			separateLocks: true,
			firstSitePreserved: true,
		} );
	} );
} );

it( 'defers opaque cron jobs during storage errors and repairs missing opening jobs', () => {
	expect( run( 'job-outage' ) ).toMatchObject( {
		opaqueRetry: true,
		noPlaintextToken: true,
		missingJobRepaired: true,
	} );
} );

it( 'does not report completion until cron conversion succeeds', () => {
	expect( run( 'cron-failure' ) ).toMatchObject( {
		failure: { rejected: true },
		phaseAfterFailure: 'migrating',
		completedAfterFailure: 5,
		phaseAfterResume: 'encrypted',
	} );
} );

it( 'evicts stale caches even when verification fails after a committed write', () => {
	expect( run( 'verification-failure' ) ).toMatchObject( {
		rejected: true,
		writeReachedDatabase: true,
		cacheEvicted: true,
	} );
} );

it( 'blocks a first booking write when automatic migration fails instead of falling back to plaintext', () => {
	expect( run( 'automatic-write-failure' ) ).toMatchObject( {
		rejected: true,
		secondAttempt: { rejected: true },
		originalPendingUntouched: true,
		migrationStarted: true,
	} );
} );
