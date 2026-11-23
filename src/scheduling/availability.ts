import {
	buildRecurrenceAttributes,
	createRecurrenceRule,
	getRecurrenceEditorState,
} from './recurrence';
import type {
	AppointmentAttributes,
	AvailabilityEntry,
	DateOverride,
	DateOverrides,
	Recurrence,
	RecurrenceRules,
	TimeSlot,
} from './types';
import { getCalendarDates, normalizeDateList, parseDateString } from './dates';
import { parseTimeToMinutes } from './time';
import {
	getLegacyAvailabilityWindow,
	preserveManualSlotEnds,
} from './schedule';

interface AvailabilitySlotInterval {
	end: number;
	start: number;
}

let availabilityIdCounter = 0;

export function createAvailabilityId(): string {
	availabilityIdCounter += 1;
	return `availability-${ Date.now().toString(
		36
	) }-${ availabilityIdCounter.toString( 36 ) }`;
}

/**
 * Builds an editable window with the exact timing of a published legacy day.
 *
 * @param attributes Saved legacy block.
 * @param date       Calendar day or series anchor.
 * @param anchor     Supply a series' own anchor when constructing its base window.
 * @return A single-date availability.
 */
function getLegacyEntry(
	attributes: AppointmentAttributes,
	date: string,
	anchor?: string
): AvailabilityEntry {
	const { startTime, endTime, duration, breakDuration } =
		getLegacyAvailabilityWindow( attributes, date, anchor );
	return {
		id: `legacy-${ date }`,
		date,
		startTime,
		endTime,
		duration,
		breakDuration,
		recurrence: {},
	};
}

function normalizeRecurrence( date: string, recurrence: unknown ): Recurrence {
	if (
		! recurrence ||
		typeof recurrence !== 'object' ||
		Array.isArray( recurrence )
	) {
		return {};
	}

	return createRecurrenceRule( date, recurrence as Recurrence ) || {};
}

/**
 * Prepares one saved or edited window for the availability editor.
 *
 * @param entry      Window data.
 * @param attributes Block defaults.
 * @param index      Original position for a missing identifier.
 * @return Editable window, or null for an invalid entry.
 */
function normalizeAvailabilityEntry(
	entry: Partial< AvailabilityEntry >,
	attributes: AppointmentAttributes,
	index: number
): AvailabilityEntry | null {
	if ( ! parseDateString( entry?.date ) ) {
		return null;
	}

	const date = entry.date as string;
	const startTime =
		typeof entry.startTime === 'string'
			? entry.startTime
			: attributes.startTime;
	const endTime =
		typeof entry.endTime === 'string' ? entry.endTime : attributes.endTime;
	const duration = Number( entry.duration );
	const breakDuration = Number( entry.breakDuration );
	const id =
		typeof entry.id === 'string' && entry.id
			? entry.id
			: `legacy-${ date }-${ startTime }-${ index }`;

	return {
		id,
		date,
		startTime,
		endTime,
		duration: Number.isFinite( duration )
			? duration
			: Number( attributes.duration ),
		breakDuration: Number.isFinite( breakDuration )
			? breakDuration
			: Number( attributes.breakDuration ),
		recurrence: normalizeRecurrence( date, entry.recurrence ),
	};
}

/**
 * Orders windows for display and for saving an edited schedule.
 *
 * @param entries Availability entries in their stored order.
 * @return Entries in the editor's canonical order.
 */
export function sortAvailabilityEntries(
	entries: AvailabilityEntry[]
): AvailabilityEntry[] {
	return [ ...entries ].sort( ( left, right ) => {
		for ( const field of [
			'date',
			'startTime',
			'id',
			'endTime',
		] as const ) {
			const first =
				typeof left[ field ] === 'string' ? left[ field ] : '';
			const second =
				typeof right[ field ] === 'string' ? right[ field ] : '';
			if ( first !== second ) {
				return first < second ? -1 : 1;
			}
		}
		return Number( left.duration ?? 30 ) - Number( right.duration ?? 30 );
	} );
}

/**
 * Reads editor windows, detaching legacy occurrence days with their own timing.
 *
 * @param attributes Existing block attributes, including legacy series.
 * @return Editable series and single-date windows in display order.
 */
export function getAvailabilityEntries(
	attributes: AppointmentAttributes
): AvailabilityEntry[] {
	if ( Array.isArray( attributes.availabilities ) ) {
		return sortAvailabilityEntries(
			attributes.availabilities.reduce< AvailabilityEntry[] >(
				( entries, entry, index ) => {
					const normalizedEntry = normalizeAvailabilityEntry(
						entry,
						attributes,
						index
					);
					if ( normalizedEntry ) {
						entries.push( normalizedEntry );
					}
					return entries;
				},
				[]
			)
		);
	}

	const { manualDates, rules } = getRecurrenceEditorState( attributes );
	const publishedDates = getCalendarDates( attributes );
	const dates = publishedDates.length
		? publishedDates
		: normalizeDateList( [
				...manualDates,
				...Object.values( rules ).flatMap(
					( rule ) => rule.dates || []
				),
		  ] );
	const singleDays = new Map(
		dates.map( ( date ) => [ date, getLegacyEntry( attributes, date ) ] )
	);
	const series: AvailabilityEntry[] = [];

	for ( const [ anchor, rule ] of Object.entries( rules ) ) {
		const entry = getLegacyEntry( attributes, anchor, anchor );
		const excluded = new Set( rule.excludedDates );
		for ( const date of rule.dates || [] ) {
			const day = singleDays.get( date );
			if (
				day &&
				! excluded.has( date ) &&
				day.startTime === entry.startTime &&
				day.endTime === entry.endTime &&
				day.duration === entry.duration &&
				day.breakDuration === entry.breakDuration
			) {
				singleDays.delete( date );
			} else {
				excluded.add( date );
			}
		}
		series.push( {
			...entry,
			id: `legacy-series-${ anchor }`,
			recurrence: {
				...rule,
				dates: rule.dates?.filter( ( date ) => ! excluded.has( date ) ),
				...( excluded.size ? { excludedDates: [ ...excluded ] } : {} ),
			},
		} );
	}
	return sortAvailabilityEntries( [ ...series, ...singleDays.values() ] );
}

/**
 * Returns the actual days of an availability, including any exception at the anchor.
 *
 * @param entry Editable availability window.
 * @return Sorted occurrence dates retained by the published schedule.
 */
export function getAvailabilityDates( entry: AvailabilityEntry ): string[] {
	if ( ! entry.recurrence.freq ) {
		return [ entry.date ];
	}

	const rule = createRecurrenceRule( entry.date, entry.recurrence );
	const excluded = new Set( rule?.excludedDates );
	return normalizeDateList( rule?.dates ).filter(
		( date ) => ! excluded.has( date )
	);
}

export function getAvailabilitySlotIntervals(
	entry: AvailabilityEntry
): AvailabilitySlotInterval[] {
	const startMinutes = parseTimeToMinutes( entry.startTime );
	const endMinutes = parseTimeToMinutes( entry.endTime );
	if (
		startMinutes === null ||
		endMinutes === null ||
		endMinutes <= startMinutes ||
		entry.duration <= 0
	) {
		return [];
	}

	const intervals: AvailabilitySlotInterval[] = [];
	let slotStart = startMinutes;
	while ( slotStart + entry.duration <= endMinutes ) {
		intervals.push( {
			start: slotStart,
			end: slotStart + entry.duration,
		} );
		slotStart += entry.duration + Math.max( 0, entry.breakDuration );
	}
	return intervals;
}

export function getAvailabilitySlotCount( entry: AvailabilityEntry ): number {
	return getAvailabilitySlotIntervals( entry ).length;
}

export function usesAppointmentPattern( entry: AvailabilityEntry ): boolean {
	const slotCount = getAvailabilitySlotCount( entry );
	const startMinutes = parseTimeToMinutes( entry.startTime );
	const endMinutes = parseTimeToMinutes( entry.endTime );
	const hasUnusedTime =
		slotCount === 1 &&
		startMinutes !== null &&
		endMinutes !== null &&
		entry.duration !== endMinutes - startMinutes;

	return slotCount > 1 || entry.breakDuration > 0 || hasUnusedTime;
}

export function getAvailabilityAppointmentCount(
	entry: AvailabilityEntry
): number {
	return (
		getAvailabilityDates( entry ).length * getAvailabilitySlotCount( entry )
	);
}

export function setDateSlotsExcluded(
	dateOverrides: DateOverrides,
	slots: TimeSlot[],
	date: string,
	excluded: boolean
): DateOverrides {
	const nextOverrides = { ...dateOverrides };
	const currentOverride = nextOverrides[ date ] || {};
	const nextOverride = { ...currentOverride };

	if ( excluded ) {
		const removedSlots = new Set(
			Array.isArray( currentOverride.removedSlots )
				? currentOverride.removedSlots
				: []
		);

		slots.forEach( ( slot ) => {
			if ( slot.date === date ) {
				removedSlots.add( slot.value );
			}
		} );

		if ( removedSlots.size > 0 ) {
			nextOverride.removedSlots = Array.from( removedSlots );
		}
	} else {
		delete nextOverride.removedSlots;
	}

	if ( Object.keys( nextOverride ).length === 0 ) {
		delete nextOverrides[ date ];
	} else {
		nextOverrides[ date ] = nextOverride;
	}

	return nextOverrides;
}

/**
 * Removes a manual appointment and its exclusion without changing the schedule.
 * Also accepts legacy extras containing only a start time. Persisted bookings
 * are separate from these block attributes and are not cancelled here.
 *
 * @param dateOverrides Existing date-specific scheduling settings.
 * @param slot          Manual appointment to remove.
 * @return Updated overrides, or the original object for a non-manual slot.
 */
export function removeExtraSlot(
	dateOverrides: DateOverrides,
	slot: TimeSlot
): DateOverrides {
	const currentOverride = dateOverrides[ slot.date ];
	if ( ! slot.isExtra || ! Array.isArray( currentOverride?.extraSlots ) ) {
		return dateOverrides;
	}
	const extraSlots = currentOverride.extraSlots.filter( ( entry ) => {
		const [ start, end ] = entry.split( '|' );
		return (
			parseTimeToMinutes( start ) !== slot.startMinutes ||
			( end !== undefined &&
				parseTimeToMinutes( end, true ) !== slot.endMinutes )
		);
	} );
	if ( extraSlots.length === currentOverride.extraSlots.length ) {
		return dateOverrides;
	}
	const nextOverride: DateOverride = { ...currentOverride, extraSlots };
	if ( extraSlots.length === 0 ) {
		delete nextOverride.extraSlots;
	}
	if ( Array.isArray( nextOverride.removedSlots ) ) {
		nextOverride.removedSlots = nextOverride.removedSlots.filter(
			( value ) => value !== slot.value
		);
		if ( nextOverride.removedSlots.length === 0 ) {
			delete nextOverride.removedSlots;
		}
	}
	const nextOverrides = { ...dateOverrides };
	if ( Object.keys( nextOverride ).length === 0 ) {
		delete nextOverrides[ slot.date ];
	} else {
		nextOverrides[ slot.date ] = nextOverride;
	}
	return nextOverrides;
}

/**
 * Builds edited schedule attributes while preserving existing manual appointment IDs.
 *
 * @param attributes Original saved block.
 * @param entries    Edited or imported availability windows.
 * @return Updated attributes and legacy schedule mirrors.
 */
export function buildAvailabilityAttributes(
	attributes: AppointmentAttributes,
	entries: AvailabilityEntry[]
): Partial< AppointmentAttributes > {
	const normalizedEntries = sortAvailabilityEntries(
		entries.reduce< AvailabilityEntry[] >( ( normalized, entry, index ) => {
			const normalizedEntry = normalizeAvailabilityEntry(
				entry,
				attributes,
				index
			);
			if ( normalizedEntry ) {
				normalized.push( normalizedEntry );
			}
			return normalized;
		}, [] )
	);
	const rules: RecurrenceRules = {};

	normalizedEntries.forEach( ( entry ) => {
		if ( ! entry.recurrence.freq || rules[ entry.date ] ) {
			return;
		}
		rules[ entry.date ] = entry.recurrence;
	} );

	const recurrenceAttributes = buildRecurrenceAttributes(
		normalizeDateList( normalizedEntries.map( ( entry ) => entry.date ) ),
		rules
	);
	const selectedDateList = normalizeDateList(
		normalizedEntries.flatMap( getAvailabilityDates )
	);
	const selectedDates = new Set( selectedDateList );
	const currentOverrides = preserveManualSlotEnds( attributes );
	const dateOverrides = Object.entries(
		currentOverrides
	).reduce< DateOverrides >( ( nextOverrides, [ date, override ] ) => {
		if ( selectedDates.has( date ) ) {
			nextOverrides[ date ] = { ...override };
		}
		return nextOverrides;
	}, {} );
	const mirroredDates = new Set< string >();

	normalizedEntries.forEach( ( entry ) => {
		if ( mirroredDates.has( entry.date ) ) {
			return;
		}
		mirroredDates.add( entry.date );
		dateOverrides[ entry.date ] = {
			...( dateOverrides[ entry.date ] || {} ),
			startTime: entry.startTime,
			endTime: entry.endTime,
			duration: entry.duration,
			breakDuration: entry.breakDuration,
		};
	} );

	const firstEntry = normalizedEntries[ 0 ];

	return {
		...recurrenceAttributes,
		availabilities: normalizedEntries,
		selectedDates: selectedDateList,
		startDate: selectedDateList[ 0 ] || '',
		endDate: selectedDateList[ selectedDateList.length - 1 ] || '',
		useEndDate: selectedDateList.length > 1,
		dateOverrides,
		...( firstEntry
			? {
					startTime: firstEntry.startTime,
					endTime: firstEntry.endTime,
					duration: firstEntry.duration,
					breakDuration: firstEntry.breakDuration,
			  }
			: {} ),
	};
}

export function hasAvailabilityConflict(
	entries: AvailabilityEntry[],
	candidate: AvailabilityEntry,
	excludedEntryId = ''
): boolean {
	const candidateDates = new Set( getAvailabilityDates( candidate ) );
	const candidateSlots = getAvailabilitySlotIntervals( candidate );

	return entries.some( ( entry ) => {
		if ( entry.id === excludedEntryId ) {
			return false;
		}
		const sharesDate = getAvailabilityDates( entry ).some( ( date ) =>
			candidateDates.has( date )
		);
		if ( ! sharesDate ) {
			return false;
		}

		const existingSlots = getAvailabilitySlotIntervals( entry );
		return existingSlots.some( ( existingSlot ) =>
			candidateSlots.some(
				( candidateSlot ) =>
					existingSlot.start < candidateSlot.end &&
					candidateSlot.start < existingSlot.end
			)
		);
	} );
}
