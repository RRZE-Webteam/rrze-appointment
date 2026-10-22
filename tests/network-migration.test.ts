import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/** Runs the real network worker and storage crypto against synthetic site tables. */
const run = ( scenario: string ): Record< string, any > =>
	JSON.parse(
		execFileSync(
			'php',
			[
				resolve(
					process.cwd(),
					'tests/fixtures/network-migration.php'
				),
				scenario,
			],
			{ encoding: 'utf8' }
		)
	);

describe( 'automatic multisite migration', () => {
	it( 'schedules on the network main site even when initialization starts on a subsite', () => {
		expect( run( 'subsite-start' ) ).toMatchObject( {
			owner: 1,
			noSubsiteEvent: true,
			stillOnSubsite: true,
			mainScheduled: true,
		} );
	} );
	it( 'does not report a failed site query as an empty completed network', () => {
		expect( run( 'site-query-failure' ) ).toMatchObject( {
			afterFailure: { error: 'worker', finished_at: 0, scanned: 0 },
			afterRecovery: { error: '', scanned: 10, encrypted: 10 },
		} );
	} );
	it( 'migrates 1005 sites in bounded batches and retries failures without rewriting successful sites', () => {
		const result = run( 'scale' );
		expect( result ).toMatchObject( {
			registered: true,
			scheduled: true,
			noEagerMigration: true,
			firstPass: {
				scanned: 1005,
				encrypted: 1004,
				failed: 1,
				last_failed: 3,
			},
			retryPass: { scanned: 1006, encrypted: 1006, failed: 0 },
			batches: 101,
			maxSitesPerBatch: 10,
			peakLocks: 2,
			released: true,
			restored: true,
			payloadsIntact: true,
			uniqueContexts: 1004,
			noRepeatedEncryption: true,
			otherNetworkUntouched: true,
			locksReleased: true,
			siteRestored: true,
			noticeRestricted: true,
		} );
	} );
	it( 'stops between sites when the time budget expires', () => {
		expect( run( 'budget' ) ).toMatchObject( {
			afterBatch: { scanned: 1, encrypted: 1 },
			duplicateDidNoWork: true,
			locksReleased: true,
			siteRestored: true,
		} );
	} );
	it( 'leaves a competing coordinator alone', () => {
		expect( run( 'contention' ) ).toMatchObject( {
			unchanged: true,
			locksReleased: true,
		} );
	} );
	it( 'does not modify booking data when a checkpoint cannot be saved', () => {
		expect( run( 'state-failure' ) ).toMatchObject( {
			noBookingWrite: true,
			locksReleased: true,
		} );
	} );
	it( 'moves past a crashed site and retries it in the next pass', () => {
		expect( run( 'crash' ) ).toMatchObject( {
			claimed: 2,
			afterCrash: {
				scanned: 15,
				encrypted: 14,
				failed: 1,
				last_failed: 2,
			},
			recovered: { scanned: 15, encrypted: 15, failed: 0 },
			locksReleased: true,
			siteRestored: true,
		} );
	} );
	it( 'also protects dormant stores but skips empty sites where the plugin is inactive', () => {
		expect( run( 'individual' ) ).toMatchObject( {
			afterBatch: { scanned: 10, encrypted: 9, skipped: 1, failed: 0 },
			duplicateDidNoWork: true,
		} );
	} );
	it( 'replaces a coordinator where the plugin was deactivated', () => {
		expect( run( 'reelect' ) ).toMatchObject( {
			owner: 2,
			newScheduled: true,
			oldRemoved: true,
			oldDoesNoWork: true,
			locksReleased: true,
			siteRestored: true,
		} );
	} );
	it( 'does not let scheduling health checks mask a stalled worker', () => {
		expect( run( 'stalled' ).stalledVisible ).toBe( true );
	} );
	it( 'does not schedule encryption without a configured key', () => {
		expect( run( 'no-key' ) ).toMatchObject( {
			scheduled: false,
			noState: true,
			noEagerMigration: true,
		} );
	} );
	it( 'stops on invalid key configuration before visiting sites', () => {
		const result = run( 'invalid-key' );
		expect( result.afterBatch.error ).toBe( 'configuration' );
		expect( result.afterBatch.scanned ).toBeUndefined();
		expect( result.notice ).toContain( 'needs attention' );
	} );
} );
