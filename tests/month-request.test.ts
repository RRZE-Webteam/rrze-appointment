import { execFileSync } from 'node:child_process';
import {
	buildAvailabilityAttributes,
	getAvailabilityEntries,
} from '../src/scheduling/availability';
import type { AppointmentAttributes } from '../src/scheduling/types';

/**
 * Runs one isolated WordPress AJAX request with a known block, visitor and reservation state.
 *
 * @param scenario   Simulated visitor or request state.
 * @param attributes Optional saved appointment schedule.
 * @return Captured status and public response data.
 */
function requestMonth( scenario: string, attributes?: AppointmentAttributes ) {
	return JSON.parse(
		execFileSync(
			'php',
			[
				'tests/fixtures/month-request.php',
				scenario,
				...( attributes ? [ JSON.stringify( attributes ) ] : [] ),
			],
			{
				encoding: 'utf8',
			}
		)
	);
}

describe( 'monthly availability endpoint', () => {
	it.each( [
		[ 'need-password', 403 ],
		[ 'unpublished', 403 ],
		[ 'private-guest', 403 ],
		[ 'private-subscriber', 403 ],
		[ 'private-password', 403 ],
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
	it.each( [ 'visible', 'private-reader' ] )(
		'returns only visible appointments for %s',
		( scenario ) => {
			expect( requestMonth( scenario ) ).toEqual( {
				status: 200,
				data: {
					month: '2027-01',
					slots: {
						'2027-01-11 10:30-11:00':
							Date.parse( '2027-01-11T10:30:00+01:00' ) / 1000,
					},
				},
			} );
		}
	);

	it.each( [ 'legacy-booked', 'legacy-pending', 'removed' ] )(
		'keeps a 2.4.0 manual time unavailable when %s',
		( scenario ) => {
			const day = '2027-01-11';
			const attrs = {
				availabilities: [
					{
						date: day,
						startTime: '13:00',
						endTime: '13:30',
						duration: 30,
						breakDuration: 0,
					},
					{
						date: day,
						startTime: '09:00',
						endTime: '09:15',
						duration: 15,
						breakDuration: 0,
					},
				],
				dateOverrides: {
					[ day ]: {
						extraSlots: [ '16:00' ],
						removedSlots:
							scenario === 'removed'
								? [ day + ' 16:00-16:30' ]
								: [],
					},
				},
			} as unknown as AppointmentAttributes;
			const saved = buildAvailabilityAttributes(
				attrs,
				getAvailabilityEntries( attrs )
			);
			for ( const schedule of [ attrs, { ...attrs, ...saved } ] ) {
				const { data } = requestMonth( scenario, schedule );
				expect( Object.keys( data.slots ) ).toEqual( [
					day + ' 09:00-09:15',
					day + ' 13:00-13:30',
				] );
			}
		}
	);

	it( 'keeps booked and pending times unavailable on a legacy exception day after an unrelated edit', () => {
		const dates = [ '2027-01-04', '2027-01-11', '2027-01-18' ];
		const day = dates[ 1 ];
		const original = {
			selectedDates: dates,
			startTime: '08:00',
			endTime: '12:00',
			duration: 20,
			breakDuration: 5,
			recurrence: {
				anchor: dates[ 0 ],
				freq: 'weekly',
				until: dates[ 2 ],
				dates,
			},
			dateOverrides: {
				[ dates[ 0 ] ]: { startTime: '09:00' },
				[ day ]: { endTime: '11:00', duration: 30, breakDuration: 0 },
			},
		} as unknown as AppointmentAttributes;
		const february = {
			id: 'february',
			date: '2027-02-03',
			startTime: '13:00',
			endTime: '14:00',
			duration: 30,
			breakDuration: 0,
			recurrence: {},
		};
		const saved = {
			...original,
			...buildAvailabilityAttributes( original, [
				...getAvailabilityEntries( original ),
				february,
			] ),
		};
		const before = requestMonth( 'visible', original ).data.slots;
		expect(
			Object.keys( before ).filter( ( slot ) => slot.startsWith( day ) )
		).toEqual( [ day + ' 09:00-09:30', day + ' 10:30-11:00' ] );
		expect( requestMonth( 'visible', saved ).data.slots ).toEqual( before );
		const resaved = buildAvailabilityAttributes(
			saved,
			getAvailabilityEntries( saved )
		);
		expect(
			requestMonth( 'visible', { ...saved, ...resaved } ).data.slots
		).toEqual( before );
	} );
} );
