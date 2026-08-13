import {
	buildAvailabilityAttributes,
	getAvailabilityDates,
	getAvailabilityEntries,
	getAvailabilitySlotCount,
	hasAvailabilityConflict,
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

function createEntry(
	id: string,
	overrides: Partial< AvailabilityEntry > = {}
): AvailabilityEntry {
	return {
		id,
		date: '2026-08-03',
		startTime: '09:00',
		endTime: '10:00',
		duration: 30,
		breakDuration: 0,
		recurrence: {},
		...overrides,
	};
}

describe( 'availability editor model', () => {
	beforeAll( () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2026-08-01T12:00:00' ) );
	} );

	afterAll( () => {
		jest.useRealTimers();
	} );

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
			createEntry( 'weekly', {
				recurrence: {
					freq: 'weekly',
					until: '2026-08-17',
				},
			} ),
			createEntry( 'tuesday', {
				date: '2026-08-04',
				startTime: '13:00',
				endTime: '14:00',
				duration: 60,
			} ),
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

	it( 'can include an excluded appointment for exception management', () => {
		const excludedValue = '2026-08-10 09:00-09:30';
		const attributes = createAttributes( {
			availabilities: [
				createEntry( 'weekly', {
					recurrence: {
						freq: 'weekly',
						until: '2026-08-17',
					},
				} ),
			],
			dateOverrides: {
				'2026-08-10': {
					removedSlots: [ excludedValue ],
				},
			},
		} );

		expect(
			generateTimeSlots( attributes ).some(
				( slot ) => slot.value === excludedValue
			)
		).toBe( false );
		expect(
			generateTimeSlots( attributes, { includeExcluded: true } ).find(
				( slot ) => slot.value === excludedValue
			)?.isExcluded
		).toBe( true );
	} );

	it( 'detects overlapping dates between recurring entries', () => {
		const weeklyEntry = createEntry( 'weekly', {
			recurrence: {
				freq: 'weekly',
				until: '2026-08-17',
			},
		} );
		const conflictingEntry = createEntry( 'conflict', {
			date: '2026-08-10',
		} );

		expect(
			hasAvailabilityConflict( [ weeklyEntry ], conflictingEntry )
		).toBe( true );
	} );

	it( 'detects conflicts on a selected recurrence weekday', () => {
		const recurringEntry = createEntry( 'weekdays', {
			recurrence: {
				freq: 'weekly',
				until: '2026-08-14',
				weekdays: [ 1, 3 ],
			},
		} );
		const wednesdayEntry = createEntry( 'wednesday', {
			date: '2026-08-05',
		} );

		expect(
			hasAvailabilityConflict( [ recurringEntry ], wednesdayEntry )
		).toBe( true );
	} );

	it( 'allows adjacent time slots on the same recurrence date', () => {
		const earlyMonday = createEntry( 'early', {
			startTime: '08:00',
			endTime: '09:00',
			recurrence: {
				freq: 'weekly',
				until: '2026-08-17',
			},
		} );
		const weekdayMorning = createEntry( 'weekday-morning', {
			startTime: '09:00',
			endTime: '10:00',
			recurrence: {
				freq: 'weekly',
				until: '2026-08-17',
				weekdays: [ 1, 2, 3, 4, 5 ],
			},
		} );

		expect(
			hasAvailabilityConflict( [ earlyMonday ], weekdayMorning )
		).toBe( false );
	} );

	it( 'stores two independent recurrence series with the same start date', () => {
		const entries = [
			createEntry( 'early', {
				startTime: '08:00',
				endTime: '09:00',
				recurrence: {
					freq: 'weekly',
					until: '2026-08-17',
				},
			} ),
			createEntry( 'weekday-morning', {
				startTime: '09:00',
				endTime: '10:00',
				recurrence: {
					freq: 'weekly',
					until: '2026-08-17',
					weekdays: [ 1, 2, 3, 4, 5 ],
				},
			} ),
		];
		const attributes = createAttributes();
		const next = {
			...attributes,
			...buildAvailabilityAttributes( attributes, entries ),
		};

		expect( getAvailabilityEntries( next ) ).toHaveLength( 2 );
		const slotValues = generateTimeSlots( next ).map(
			( slot ) => slot.value
		);
		expect( slotValues ).toContain( '2026-08-10 08:00-08:30' );
		expect( slotValues ).toContain( '2026-08-10 09:00-09:30' );
	} );

	it( 'keeps the initial availability when its weekday is not repeated', () => {
		expect(
			getAvailabilityDates(
				createEntry( 'mixed-weekdays', {
					date: '2026-08-04',
					recurrence: {
						freq: 'weekly',
						until: '2026-08-12',
						weekdays: [ 1, 3 ],
					},
				} )
			)
		).toEqual( [ '2026-08-04', '2026-08-05', '2026-08-10', '2026-08-12' ] );
	} );

	it( 'counts one individual appointment', () => {
		expect(
			getAvailabilitySlotCount(
				createEntry( 'individual', {
					endTime: '09:30',
				} )
			)
		).toBe( 1 );
	} );

	it( 'counts consultation-hour slots including their breaks', () => {
		expect(
			getAvailabilitySlotCount(
				createEntry( 'pattern', {
					endTime: '11:00',
					breakDuration: 15,
				} )
			)
		).toBe( 3 );
	} );

	it( 'formats availability dates with their weekday', () => {
		expect( formatDateWithWeekdayDisplay( '2026-08-03' ) ).toBe(
			'Montag, 03.08.2026'
		);
	} );
} );
