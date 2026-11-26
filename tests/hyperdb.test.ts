import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/**
 * Executes a storage workflow with independent simulated HyperDB connections.
 *
 * @param scenario Name of the fixture workflow.
 * @return Observed connection state and persisted option values.
 */
function run( scenario: string ) {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/hyperdb.php' ), scenario ],
			{
				encoding: 'utf8',
			}
		)
	);
}

describe( 'booking storage on HyperDB', () => {
	it( 'holds each site lock on its own primary while ordinary reads use the stale replica', () => {
		expect( run( 'affinity' ) ).toEqual( {
			heldLocks: [ 1, 1, 0 ],
			ordinaryRead: 'Replica title',
			protectedRead: 'current 50%',
			inventory: { rrze_appointment_v2_record_synthetic: 'booked 100%' },
			otherSiteInserted: true,
			expired: null,
			values: [ 'booked 100%', 'site two', 'stale' ],
			ordinaryHandleRestored: true,
			released: true,
		} );
	} );

	it( 'rolls back a partial transaction on its original primary', () => {
		expect( run( 'rollback' ) ).toMatchObject( {
			failure: { rejected: true },
			primary: [],
			replica: [],
			attemptedWrites: 2,
			released: true,
		} );
	} );

	it( 'stops before writing when the pinned session switches its selected database', () => {
		expect( run( 'schema-changed' ) ).toMatchObject( {
			failure: { rejected: true },
			writes: 0,
		} );
	} );

	it( 'stops writes and releases safely after its pinned connection is lost', () => {
		expect( run( 'disconnect' ) ).toMatchObject( {
			failure: { rejected: true },
			writes: 0,
			replicaLocks: [],
			ordinaryHandleRestored: true,
		} );
	} );

	it( 'rejects unavailable writers before acquiring a lock on the replica', () => {
		expect( run( 'no-primary' ) ).toMatchObject( {
			failure: { rejected: true },
			replicaLocks: [],
		} );
	} );
} );

describe( 'network storage maintenance on HyperDB', () => {
	it( 'finds a dormant site and its stored data even when both are missing on the replica', () => {
		expect( run( 'network-maintenance' ) ).toEqual( {
			failure: { rejected: false },
			reports: [
				{ phase: 'preflight', site_id: 1, result: 'ok' },
				{ phase: 'preflight', site_id: 2, result: 'ok' },
				{ phase: 'preflight', checked: 2, skipped: 0, failed: 0 },
			],
			peakLocks: [ 2, 1, 0 ],
			released: true,
		} );
	} );

	it( 'stops the inspection when another site switches the network session to a different database', () => {
		expect( run( 'network-schema-changed' ) ).toEqual( {
			failure: {
				rejected: true,
				message: 'The appointment network maintenance lock was lost.',
			},
			reports: [ { phase: 'preflight', site_id: 1, result: 'ok' } ],
			peakLocks: [ 2, 0, 0 ],
			released: true,
		} );
	} );
} );
