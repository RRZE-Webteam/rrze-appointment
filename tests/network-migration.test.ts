import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/** Loads the real bootstrap retirement filter without a database or another site's context. */
const run = ( scope: string ) =>
	JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/network-migration.php' ), scope ],
			{ encoding: 'utf8' }
		)
	);

it( 'retires previously queued network jobs and passes other scheduling decisions through', () => {
	expect( run( 'network' ) ).toEqual( {
		registered: [ 'pre_reschedule_event' ],
		retired: true,
		foreign: null,
		foreignDecision: false,
	} );
	expect( run( 'single' ).registered ).toEqual( [] );
} );
