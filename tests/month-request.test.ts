import { execFileSync } from 'node:child_process';

/**
 * Runs one isolated WordPress AJAX request with a known block, visitor and reservation state.
 *
 * @param scenario Simulated visitor or request state.
 * @return Captured status and public response data.
 */
function requestMonth( scenario: string ) {
	return JSON.parse(
		execFileSync( 'php', [ 'tests/fixtures/month-request.php', scenario ], {
			encoding: 'utf8',
		} )
	);
}

describe( 'monthly availability endpoint', () => {
	it.each( [
		[ 'need-password', 403 ],
		[ 'unpublished', 403 ],
		[ 'stale-block', 404 ],
		[ 'invalid-month', 400 ],
	] )(
		'returns the appropriate response for %s',
		( scenario: string, status: number ) => {
			expect( requestMonth( scenario ) ).toEqual( {
				status,
				data: null,
			} );
		}
	);

	it( 'includes weekend appointments in list mode when the calendar hides weekends', () => {
		const { data } = requestMonth( 'list' );
		expect(
			Object.keys( data.slots ).filter( ( slot ) =>
				slot.startsWith( '2027-01-16' )
			)
		).toHaveLength( 4 );
	} );
	it( 'returns only the requested month with site-zone timestamps and respects reservations and the inclusive cutoff', () => {
		expect( requestMonth( 'visible' ) ).toEqual( {
			status: 200,
			data: {
				month: '2027-01',
				slots: {
					'2027-01-11 10:30-11:00':
						Date.parse( '2027-01-11T10:30:00+01:00' ) / 1000,
				},
			},
		} );
	} );
} );
