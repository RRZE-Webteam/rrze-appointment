import {
	createFaudirAvailabilityEntries,
	mergeFaudirAvailabilityEntries,
} from '../src/faudir';
import type { AvailabilityEntry } from '../src/types';

function entry(
	id: string,
	startTime: string,
	endTime: string
): AvailabilityEntry {
	return {
		id,
		date: '2026-08-03',
		startTime,
		endTime,
		duration: 30,
		breakDuration: 0,
		recurrence: { freq: 'weekly', until: '2026-09-28' },
	};
}

describe( 'FAUdir availability import', () => {
	it( 'keeps multiple time ranges on the same weekday', () => {
		const entries = createFaudirAvailabilityEntries(
			[
				{ weekday: 1, from: '09:00', to: '11:00' },
				{ weekday: 1, from: '14:00', to: '16:00' },
			],
			{
				today: new Date( 2026, 6, 31 ),
				hoursUntil: '2026-09-28',
				duration: 30,
				breakDuration: 0,
			}
		);

		expect( entries ).toHaveLength( 2 );
		expect( entries.map( ( item ) => item.startTime ) ).toEqual( [
			'09:00',
			'14:00',
		] );
	} );

	it( 'retains existing schedules and skips only overlapping imports', () => {
		const existing = entry( 'existing', '09:00', '11:00' );
		const overlapping = entry( 'overlapping', '10:00', '12:00' );
		const separate = entry( 'separate', '14:00', '16:00' );

		const result = mergeFaudirAvailabilityEntries(
			[ existing ],
			[ overlapping, separate ]
		);

		expect( result.entries.map( ( item ) => item.id ) ).toEqual( [
			'existing',
			'separate',
		] );
		expect( result.addedEntries ).toEqual( [ separate ] );
		expect( result.skippedCount ).toBe( 1 );
	} );
} );
