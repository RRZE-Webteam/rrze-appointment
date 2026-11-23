import {
	formatDateDisplay,
	getCalendarDates,
	normalizeDateList,
	parseDateString,
} from './dates';
import { expandRecurrence } from './recurrence';
import { minutesToTime, parseTimeToMinutes } from './time';
import type { AppointmentAttributes, DateOverrides, TimeSlot } from './types';

/**
 * Finds the saved series whose timing overrides apply to a legacy date.
 *
 * @param attributes Saved block data.
 * @param date       ISO date of the occurrence.
 * @return Matching series anchor.
 */
function getRecurrenceAnchor(
	attributes: AppointmentAttributes,
	date: string
): string {
	const rules =
		attributes.recurrences && typeof attributes.recurrences === 'object'
			? attributes.recurrences
			: {};
	const matchingRule = Object.entries( rules ).find(
		( [ , rule ] ) =>
			Array.isArray( rule.dates ) && rule.dates.includes( date )
	);
	if ( matchingRule ) {
		return matchingRule[ 0 ];
	}

	const legacyRule = attributes.recurrence;
	if (
		Array.isArray( legacyRule?.dates ) &&
		legacyRule.dates.includes( date )
	) {
		return legacyRule.anchor || legacyRule.dates[ 0 ] || '';
	}

	return '';
}

interface AvailabilityWindow {
	breakDuration: number;
	date: string;
	duration: number;
	endTime: string;
	extraSlots: string[];
	removedSlots: string[];
	startTime: string;
}

/**
 * Resolves the single time window published for a legacy calendar day.
 * Per-day settings take precedence over the series and block defaults.
 *
 * @param attributes Saved legacy block.
 * @param date       Occurrence date in YYYY-MM-DD format.
 * @param anchor     Series supplying defaults, normally the first saved match.
 * @return Effective timing and slot exceptions for that day.
 */
export function getLegacyAvailabilityWindow(
	attributes: AppointmentAttributes,
	date: string,
	anchor = getRecurrenceAnchor( attributes, date )
): AvailabilityWindow {
	const overrides = attributes.dateOverrides || {};
	const override = {
		...( anchor ? overrides[ anchor ] : {} ),
		...overrides[ date ],
	};
	return {
		date,
		startTime: override.startTime ?? attributes.startTime ?? '09:00',
		endTime: override.endTime ?? attributes.endTime ?? '17:00',
		duration: Number( override.duration ?? attributes.duration ?? 30 ),
		breakDuration: Number(
			override.breakDuration ?? attributes.breakDuration ?? 0
		),
		removedSlots: Array.isArray( override.removedSlots )
			? override.removedSlots
			: [],
		extraSlots: Array.isArray( override.extraSlots )
			? override.extraSlots
			: [],
	};
}

/**
 * Selects occurrence windows intersecting the requested inclusive ISO dates.
 *
 * @param attributes     Saved block data.
 * @param options        Inclusive date bounds.
 * @param savedDatesOnly Use the occurrences recognized by the published PHP schedule.
 * @return Occurrence windows for all applicable series.
 */
function getAvailabilityWindows(
	attributes: AppointmentAttributes,
	options: GenerateTimeSlotsOptions,
	savedDatesOnly: boolean
): AvailabilityWindow[] {
	const { from = '', until = '9999-12-31' } = options;
	const overrides =
		attributes.dateOverrides && typeof attributes.dateOverrides === 'object'
			? attributes.dateOverrides
			: {};

	if ( Array.isArray( attributes.availabilities ) ) {
		const entries = attributes.availabilities.filter( ( entry ) =>
			parseDateString( entry?.date )
		);
		return entries.flatMap( ( entry ) => {
			let recurrenceDates = [ entry.date ];
			if ( entry.recurrence?.freq ) {
				if ( Array.isArray( entry.recurrence.dates ) ) {
					recurrenceDates = normalizeDateList(
						entry.recurrence.dates
					);
				} else if ( ! savedDatesOnly ) {
					recurrenceDates = normalizeDateList(
						expandRecurrence( entry.recurrence, entry.date )
					);
				}
			}
			const excludedDates = new Set(
				normalizeDateList( entry.recurrence?.excludedDates )
			);

			return recurrenceDates
				.filter(
					( date ) =>
						date >= from &&
						date <= until &&
						! excludedDates.has( date )
				)
				.map( ( date ) => {
					const override = overrides[ date ] || {};
					return {
						date,
						startTime: entry.startTime ?? '09:00',
						endTime: entry.endTime ?? '17:00',
						duration: Number( entry.duration ?? 30 ),
						breakDuration: Number( entry.breakDuration ?? 0 ),
						removedSlots: Array.isArray( override.removedSlots )
							? override.removedSlots
							: [],
						extraSlots: Array.isArray( override.extraSlots )
							? override.extraSlots
							: [],
					};
				} );
		} );
	}

	return getCalendarDates( attributes )
		.filter( ( date ) => date >= from && date <= until )
		.map( ( date ) => getLegacyAvailabilityWindow( attributes, date ) );
}

interface GenerateTimeSlotsOptions {
	/** Inclusive ISO dates, omitted for the full schedule. */
	from?: string;
	until?: string;
	/** Include slots hidden by a date override and mark them as excluded. */
	includeExcluded?: boolean;
}

/**
 * Yields valid windows in the order used by published 2.4.0 schedules.
 *
 * @param attributes     Saved scheduling data.
 * @param options        Inclusive occurrence dates.
 * @param savedDatesOnly Ignore editor-only recurrence projections when restoring an old ID.
 * @return Windows with validated clock boundaries.
 */
function* validWindows(
	attributes: AppointmentAttributes,
	options: GenerateTimeSlotsOptions,
	savedDatesOnly = false
) {
	for ( const window of getAvailabilityWindows(
		attributes,
		options,
		savedDatesOnly
	) ) {
		const { duration, breakDuration } = window;
		const startMinutes = parseTimeToMinutes( window.startTime );
		const endMinutes = parseTimeToMinutes( window.endTime );
		if (
			Number.isInteger( duration ) &&
			duration > 0 &&
			Number.isInteger( breakDuration ) &&
			breakDuration >= 0 &&
			breakDuration <= 55 &&
			breakDuration % 5 === 0 &&
			startMinutes !== null &&
			endMinutes !== null &&
			endMinutes > startMinutes
		) {
			yield { ...window, startMinutes, endMinutes };
		}
	}
}

/**
 * Freezes inherited manual ends before editing or importing reorders the saved windows.
 * Legacy series can also pass their manual times and exclusions to individual occurrences.
 *
 * @param attributes Original block data, before the schedule edit.
 * @param options    Optional date bounds when rendering one part of a schedule.
 * @return Per-date overrides with the original manual appointment identifiers.
 */
export function preserveManualSlotEnds(
	attributes: AppointmentAttributes,
	options: GenerateTimeSlotsOptions = {}
): DateOverrides {
	const overrides = attributes.dateOverrides || {};
	const legacy = ! Array.isArray( attributes.availabilities );
	const needsResolution = Object.values( overrides ).some(
		( override ) =>
			( legacy &&
				Array.isArray( override?.removedSlots ) &&
				override.removedSlots.length > 0 ) ||
			( Array.isArray( override?.extraSlots ) &&
				override.extraSlots.some(
					( value ) =>
						typeof value === 'string' &&
						( legacy || ! value.includes( '|' ) )
				) )
	);
	if ( ! needsResolution ) {
		return overrides;
	}
	const result = { ...overrides };
	const processed = new Set< string >();
	for ( const { date, extraSlots, removedSlots, duration } of validWindows(
		attributes,
		options,
		true
	) ) {
		if ( processed.has( date ) ) {
			continue;
		}
		processed.add( date );
		if ( legacy && ! Array.isArray( overrides[ date ]?.removedSlots ) ) {
			const inherited = removedSlots.filter(
				( slot ) =>
					typeof slot === 'string' && slot.startsWith( date + ' ' )
			);
			if ( inherited.length ) {
				result[ date ] = { ...result[ date ], removedSlots: inherited };
			}
		}
		if ( ! extraSlots.length ) {
			continue;
		}
		result[ date ] = {
			...result[ date ],
			extraSlots: extraSlots.map( ( value ) => {
				if ( typeof value !== 'string' || value.includes( '|' ) ) {
					return value;
				}
				const start = parseTimeToMinutes( value );
				return start !== null && start + duration <= 1440
					? value + '|' + minutesToTime( start + duration )
					: value;
			} ),
		};
	}
	return result;
}

/**
 * Builds unique future appointment slots from the persisted scheduling data.
 * Only the requested dates are expanded into individual appointments.
 * @param attributes Persisted block scheduling data.
 * @param options    Date range and whether to include hidden slots.
 */
export function generateTimeSlots(
	attributes: AppointmentAttributes,
	options: GenerateTimeSlotsOptions = {}
): TimeSlot[] {
	const dateOverrides = preserveManualSlotEnds( attributes, options );
	const availabilityWindows = validWindows(
		{ ...attributes, dateOverrides },
		options
	);
	const now = new Date();
	const slotMap = new Map< string, TimeSlot >();
	const processedExtraDates = new Set< string >();
	const includeExcluded = !! options.includeExcluded;

	/**
	 * Registers one future time, honoring the editor's exception visibility setting.
	 * @param slot         Candidate from a series or a manual appointment.
	 * @param removedSlots Exceptions for its calendar day.
	 */
	const addUniqueFutureSlot = (
		slot: TimeSlot,
		removedSlots: Set< string >
	) => {
		const slotStart = new Date( `${ slot.date }T${ slot.startTime }:00` );
		if ( ! Number.isNaN( slotStart.getTime() ) && slotStart <= now ) {
			return;
		}
		const isExcluded = removedSlots.has( slot.value );
		if (
			( isExcluded && ! includeExcluded ) ||
			slotMap.has( slot.value )
		) {
			return;
		}
		slotMap.set( slot.value, { ...slot, isExcluded } );
	};

	for ( const availabilityWindow of availabilityWindows ) {
		const {
			date,
			duration,
			breakDuration,
			startMinutes,
			endMinutes,
			extraSlots,
		} = availabilityWindow;
		const removedSlots = new Set( availabilityWindow.removedSlots );

		let slotStart = startMinutes;
		while ( slotStart + duration <= endMinutes ) {
			const slotEnd = slotStart + duration;
			const startLabel = minutesToTime( slotStart );
			const endLabel = minutesToTime( slotEnd );
			addUniqueFutureSlot(
				{
					date,
					startTime: startLabel,
					endTime: endLabel,
					startMinutes: slotStart,
					endMinutes: slotEnd,
					timeRange: `${ startLabel } - ${ endLabel }`,
					value: `${ date } ${ startLabel }-${ endLabel }`,
					label: `${ formatDateDisplay( date ) } ${ startLabel }`,
					isExtra: false,
				},
				removedSlots
			);
			slotStart += duration + breakDuration;
		}

		if ( processedExtraDates.has( date ) ) {
			continue;
		}
		processedExtraDates.add( date );
		extraSlots.forEach( ( extraEntry ) => {
			const hasPipe =
				typeof extraEntry === 'string' && extraEntry.includes( '|' );
			const extraStart = hasPipe
				? extraEntry.split( '|' )[ 0 ]
				: extraEntry;
			const extraEnd = hasPipe ? extraEntry.split( '|' )[ 1 ] : null;
			const extraStartMinutes = parseTimeToMinutes( extraStart );
			if ( extraStartMinutes === null ) {
				return;
			}
			const extraEndMinutes =
				extraEnd !== null
					? parseTimeToMinutes( extraEnd, true )
					: extraStartMinutes + duration;
			if ( extraEndMinutes === null || extraEndMinutes > 24 * 60 ) {
				return;
			}
			const startLabel = minutesToTime( extraStartMinutes );
			const endLabel = minutesToTime( extraEndMinutes );
			addUniqueFutureSlot(
				{
					date,
					startTime: startLabel,
					endTime: endLabel,
					startMinutes: extraStartMinutes,
					endMinutes: extraEndMinutes,
					timeRange: `${ startLabel } - ${ endLabel }`,
					value: `${ date } ${ startLabel }-${ endLabel }`,
					label: `${ formatDateDisplay( date ) } ${ startLabel }`,
					isExtra: true,
				},
				removedSlots
			);
		} );
	}

	return Array.from( slotMap.values() ).sort(
		( a, b ) =>
			a.date.localeCompare( b.date ) ||
			a.startMinutes - b.startMinutes ||
			a.endMinutes - b.endMinutes
	);
}

export function groupSlotsByDate(
	slots: TimeSlot[]
): Record< string, TimeSlot[] > {
	return slots.reduce< Record< string, TimeSlot[] > >(
		( slotsByDate, slot ) => {
			if ( ! slotsByDate[ slot.date ] ) {
				slotsByDate[ slot.date ] = [];
			}
			slotsByDate[ slot.date ].push( slot );
			return slotsByDate;
		},
		{}
	);
}
