import {
	buildAvailabilityAttributes,
	getAvailabilityEntries,
	getAvailabilitySlotCount,
	hasAvailabilityDateConflict,
} from '../src/availability';
import type { AppointmentAttributes, AvailabilityEntry } from '../src/types';
import { formatDateWithWeekdayDisplay, generateTimeSlots } from '../src/utils';

declare function describe( name: string, callback: () => void ): void;
declare function it( name: string, callback: () => void ): void;
declare function expect( actual: unknown ): {
	toBe: ( expected: unknown ) => void;
	toContain: ( expected: unknown ) => void;
	toEqual: ( expected: unknown ) => void;
	toHaveLength: ( expected: number ) => void;
};

function createAttributes(
	overrides: Partial< AppointmentAttributes > = {}
): AppointmentAttributes {
	return {
		title: '',
		selectedDates: [],
		startDate: '',
		useEndDate: false,
		endDate: '',
		startTime: '09:00',
		endTime: '17:00',
		dateOverrides: {},
		duration: 30,
		breakDuration: 0,
		location: '',
		locationUrl: '',
		description: '',
		recurrence: {},
		personId: 0,
		personName: '',
		personEmail: '',
		useConsultationHours: false,
		tplId: 0,
		bookingCutoff: 0,
		requireMessage: false,
		disableSso: false,
		hideWeekends: false,
		style: 'light',
		...overrides,
	};
}

describe( 'availability editor model', () => {
	it( 'adapts legacy recurrence attributes to one editable entry', () => {
		const attributes = createAttributes( {
			selectedDates: [ '2026-08-03', '2026-08-10', '2026-08-17' ],
			recurrence: {
				freq: 'weekly',
				anchor: '2026-08-03',
				until: '2026-08-17',
				dates: [ '2026-08-03', '2026-08-10', '2026-08-17' ],
			},
			dateOverrides: {
				'2026-08-03': {
					startTime: '10:00',
					endTime: '12:00',
					duration: 60,
				},
			},
		} );

		const entries = getAvailabilityEntries( attributes );

		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ].date ).toBe( '2026-08-03' );
		expect( entries[ 0 ].startTime ).toBe( '10:00' );
		expect( entries[ 0 ].duration ).toBe( 60 );
		expect( entries[ 0 ].recurrence.freq ).toBe( 'weekly' );
	} );

	it( 'adapts the former start and end date range', () => {
		const entries = getAvailabilityEntries(
			createAttributes( {
				startDate: '2026-08-03',
				endDate: '2026-08-05',
				useEndDate: true,
			} )
		);

		expect( entries.map( ( entry ) => entry.date ) ).toEqual( [
			'2026-08-03',
			'2026-08-04',
			'2026-08-05',
		] );
	} );

	it( 'stores independent entries in legacy-compatible attributes', () => {
		const entries: AvailabilityEntry[] = [
			{
				date: '2026-08-03',
				startTime: '09:00',
				endTime: '10:00',
				duration: 30,
				breakDuration: 0,
				recurrence: {
					freq: 'weekly',
					until: '2026-08-17',
				},
			},
			{
				date: '2026-08-04',
				startTime: '13:00',
				endTime: '14:00',
				duration: 60,
				breakDuration: 0,
				recurrence: {},
			},
		];
		const attributes = createAttributes();
		const nextAttributes = buildAvailabilityAttributes(
			attributes,
			entries
		);
		const next = { ...attributes, ...nextAttributes };

		expect( next.selectedDates ).toEqual( [
			'2026-08-03',
			'2026-08-04',
			'2026-08-10',
			'2026-08-17',
		] );
		expect( next.dateOverrides[ '2026-08-03' ].startTime ).toBe( '09:00' );
		expect( next.dateOverrides[ '2026-08-04' ].startTime ).toBe( '13:00' );

		const slotValues = generateTimeSlots( next ).map(
			( slot ) => slot.value
		);
		expect( slotValues ).toContain( '2026-08-10 09:00-09:30' );
		expect( slotValues ).toContain( '2026-08-04 13:00-14:00' );
	} );

	it( 'detects overlapping dates between recurring entries', () => {
		const weeklyEntry: AvailabilityEntry = {
			date: '2026-08-03',
			startTime: '09:00',
			endTime: '10:00',
			duration: 30,
			breakDuration: 0,
			recurrence: {
				freq: 'weekly',
				until: '2026-08-17',
			},
		};
		const conflictingEntry: AvailabilityEntry = {
			...weeklyEntry,
			date: '2026-08-10',
			recurrence: {},
		};

		expect(
			hasAvailabilityDateConflict( [ weeklyEntry ], conflictingEntry )
		).toBe( true );
	} );

	it( 'counts one individual appointment', () => {
		expect(
			getAvailabilitySlotCount( {
				date: '2026-08-03',
				startTime: '09:00',
				endTime: '09:30',
				duration: 30,
				breakDuration: 0,
				recurrence: {},
			} )
		).toBe( 1 );
	} );

	it( 'counts consultation-hour slots including their breaks', () => {
		expect(
			getAvailabilitySlotCount( {
				date: '2026-08-03',
				startTime: '09:00',
				endTime: '11:00',
				duration: 30,
				breakDuration: 15,
				recurrence: {},
			} )
		).toBe( 3 );
	} );

	it( 'formats availability dates with their weekday', () => {
		expect( formatDateWithWeekdayDisplay( '2026-08-03' ) ).toBe(
			'Montag, 03.08.2026'
		);
	} );
} );
