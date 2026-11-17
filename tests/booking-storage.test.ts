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

describe( 'versioned booking records and lazy promotion', () => {
	it( 'keeps the internal record ID from reservation through confirmation while consuming pending links', () => {
		expect( run( 'lifecycle' ) ).toMatchObject( {
			expiry: true,
			confirmed: true,
			idPreserved: true,
			consumed: true,
		} );
	} );
	it( 'keeps the unconfigured installation in legacy mode', () => {
		expect( run( 'legacy' ) ).toEqual( {
			phase: 'legacy',
			written: true,
			marker: false,
		} );
	} );

	it( 'reads every old array and verifies mixed integrity without initiating a migration', () => {
		expect( run( 'read' ) ).toMatchObject( {
			phase: 'mixed',
			same: true,
			strictRejected: true,
			disabledMigrate: true,
			unchanged: true,
		} );
	} );

	it( 'encrypts one new reservation in its own row, leaving existing sources untouched', () => {
		expect( run( 'new' ) ).toEqual( {
			oldUnchanged: true,
			nonAutoloaded: true,
			protected: true,
			versions: [ 2, 2, 2, 1 ],
			persisted: true,
		} );
	} );

	it( 'moves the changed booking and its existing action tokens in one transaction', () => {
		expect( run( 'transfer' ) ).toMatchObject( {
			separate: true,
			relatedRemoved: true,
			otherSources: true,
			oldLinks: true,
			stable: true,
		} );
	} );

	it( 'hides a replayed legacy snapshot after promotion', () => {
		expect( run( 'replay' ) ).toMatchObject( {
			latest: true,
			oldLink: true,
			strictRejected: true,
		} );
	} );

	it( 'does not resurrect a consumed cancellation link when a slot is booked again', () => {
		expect( run( 'rebook' ) ).toMatchObject( {
			latest: true,
			newId: true,
			oldLink: false,
		} );
	} );

	it.each( [ 'v1', 'partial-v1' ] )(
		'reads %s sources and retains authenticated checkpoints on promotion',
		( scenario ) => {
			expect( run( scenario ) ).toMatchObject( {
				unchanged: true,
				linked: true,
				sourceFormats: true,
				untouched: true,
				strict: scenario === 'v1',
			} );
		}
	);

	it( 'rolls back an interrupted first promotion and preserves the original for retry', () => {
		expect( run( 'rollback' ) ).toMatchObject( {
			failure: true,
			unchanged: true,
			retry: true,
		} );
	} );

	it( 'checks unchanged source rows used for occupancy before accepting a write', () => {
		expect( run( 'readset' ) ).toMatchObject( {
			rejected: true,
			preserved: true,
		} );
	} );

	it.each( [
		'missing-index',
		'missing-record',
		'missing-marker',
		'orphan-record',
		'tampered-state',
		'tampered-v2',
		'unknown-version',
		'cross-site',
		'late-conflict',
		'wrong-key',
		'missing-key',
		'missing-v1-option',
		'foreign-v1-envelope',
		'downgraded-v1',
		'tamper-v1',
		'invalid-key',
		'invalid-legacy',
		'corrupt-legacy',
		'duplicate-slot',
		'lock-busy',
		'database-error',
	] )(
		'fails closed for %s without treating the data as an available slot',
		( scenario ) => {
			expect( run( scenario ).rejected ).toBe( true );
		}
	);
} );
