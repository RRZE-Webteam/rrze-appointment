import {
	buildRecurrenceAttributes,
	createRecurrenceRule,
	getRecurrenceEditorState,
	getRecurrenceWeekdays,
	toggleRecurrenceDate,
} from '../src/recurrence';
import type { AppointmentAttributes, RecurrenceRules } from '../src/types';
import { expandRecurrence } from '../src/utils';

declare function describe( name: string, callback: () => void ): void;
declare function it( name: string, callback: () => void ): void;
declare function expect( actual: unknown ): {
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

describe( 'recurrence compatibility', () => {
	it( 'normalizes a legacy single recurrence without losing manual dates', () => {
		const state = getRecurrenceEditorState(
			createAttributes( {
				selectedDates: [
					'2026-08-03',
					'2026-08-05',
					'2026-08-10',
					'2026-08-17',
				],
				recurrence: {
					freq: 'weekly',
					anchor: '2026-08-03',
					until: '2026-08-17',
					dates: [ '2026-08-03', '2026-08-10', '2026-08-17' ],
				},
			} )
		);

		expect( state.manualDates ).toEqual( [ '2026-08-03', '2026-08-05' ] );
		expect( state.rules[ '2026-08-03' ].dates ).toEqual( [
			'2026-08-03',
			'2026-08-10',
			'2026-08-17',
		] );
	} );

	it( 'combines independent recurrence rules into selectedDates', () => {
		const mondayRule = createRecurrenceRule( '2026-08-03', {
			freq: 'weekly',
			until: '2026-08-17',
		} );
		const wednesdayRule = createRecurrenceRule( '2026-08-05', {
			freq: 'weekly',
			until: '2026-08-19',
		} );
		const rules: RecurrenceRules = {
			...( mondayRule ? { '2026-08-03': mondayRule } : {} ),
			...( wednesdayRule ? { '2026-08-05': wednesdayRule } : {} ),
		};

		const attributes = buildRecurrenceAttributes(
			[ '2026-08-03', '2026-08-05' ],
			rules
		);

		expect( attributes.selectedDates ).toEqual( [
			'2026-08-03',
			'2026-08-05',
			'2026-08-10',
			'2026-08-12',
			'2026-08-17',
			'2026-08-19',
		] );
		expect( Object.keys( attributes.recurrences || {} ) ).toHaveLength( 2 );
	} );

	it( 'keeps excluded occurrences out when a rule is recalculated', () => {
		const rule = createRecurrenceRule( '2026-08-03', {
			freq: 'weekly',
			until: '2026-08-24',
			excludedDates: [ '2026-08-17' ],
		} );

		expect( rule?.dates ).toEqual( [
			'2026-08-03',
			'2026-08-10',
			'2026-08-24',
		] );
	} );

	it( 'expands a weekly rule on multiple selected weekdays', () => {
		const rule = createRecurrenceRule( '2026-08-03', {
			freq: 'weekly',
			until: '2026-08-16',
			weekdays: [ 1, 3, 5 ],
		} );

		expect( rule?.dates ).toEqual( [
			'2026-08-03',
			'2026-08-05',
			'2026-08-07',
			'2026-08-10',
			'2026-08-12',
			'2026-08-14',
		] );
		expect( rule?.weekdays ).toEqual( [ 1, 3, 5 ] );
	} );

	it( 'uses the anchor weekday for legacy weekly rules', () => {
		expect(
			getRecurrenceWeekdays(
				{
					freq: 'weekly',
				},
				'2026-08-03'
			)
		).toEqual( [ 1 ] );
	} );

	it( 'removes and restores one generated occurrence as an exclusion', () => {
		const rule = createRecurrenceRule( '2026-08-03', {
			freq: 'weekly',
			until: '2026-08-17',
		} );
		const attributes = createAttributes( {
			manualDates: [ '2026-08-03' ],
			recurrences: rule ? { '2026-08-03': rule } : {},
			selectedDates: rule?.dates || [],
		} );

		const removed = toggleRecurrenceDate( attributes, '2026-08-10' );
		expect( removed.selectedDates ).toEqual( [
			'2026-08-03',
			'2026-08-17',
		] );
		expect( removed.recurrences?.[ '2026-08-03' ].excludedDates ).toEqual( [
			'2026-08-10',
		] );

		const restored = toggleRecurrenceDate(
			createAttributes( { ...attributes, ...removed } ),
			'2026-08-10'
		);
		expect( restored.selectedDates ).toEqual( [
			'2026-08-03',
			'2026-08-10',
			'2026-08-17',
		] );
		expect( restored.recurrences?.[ '2026-08-03' ].excludedDates ).toEqual(
			undefined
		);
	} );
} );

describe( 'monthly recurrence', () => {
	it( 'clamps month-end dates without shifting later occurrences', () => {
		expect(
			expandRecurrence(
				{
					freq: 'monthly',
					until: '2026-04-30',
				},
				'2026-01-31'
			)
		).toEqual( [ '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30' ] );
	} );
} );
