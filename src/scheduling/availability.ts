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

function getEffectiveOverride(
	attributes: AppointmentAttributes,
	date: string
): DateOverride {
	const overrides =
		attributes.dateOverrides && typeof attributes.dateOverrides === 'object'
			? attributes.dateOverrides
			: {};

	return overrides[ date ] || {};
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

function normalizeAvailabilityEntry(
	entry: Partial< AvailabilityEntry >,
	attributes: AppointmentAttributes,
	index: number
): AvailabilityEntry | null {
	if ( ! parseDateString( entry.date ) ) {
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

function sortAvailabilityEntries(
	entries: AvailabilityEntry[]
): AvailabilityEntry[] {
	return [ ...entries ].sort(
		( a, b ) =>
			a.date.localeCompare( b.date ) ||
			a.startTime.localeCompare( b.startTime ) ||
			a.id.localeCompare( b.id )
	);
}

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
	const editorDates = normalizeDateList( [
		...manualDates,
		...Object.keys( rules ),
	] );
	const entryDates =
		editorDates.length > 0 ? editorDates : getCalendarDates( attributes );

	return entryDates.map( ( date ) => {
		const override = getEffectiveOverride( attributes, date );

		return {
			id: `legacy-${ date }`,
			date,
			startTime: override.startTime || attributes.startTime,
			endTime: override.endTime || attributes.endTime,
			duration:
				override.duration !== undefined
					? Number( override.duration )
					: Number( attributes.duration ),
			breakDuration:
				override.breakDuration !== undefined
					? Number( override.breakDuration )
					: Number( attributes.breakDuration ),
			recurrence: rules[ date ] || {},
		};
	} );
}

export function getAvailabilityDates( entry: AvailabilityEntry ): string[] {
	if ( ! entry.recurrence.freq ) {
		return [ entry.date ];
	}

	const rule = createRecurrenceRule( entry.date, entry.recurrence );
	return normalizeDateList( rule?.dates );
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
	const currentOverrides =
		attributes.dateOverrides && typeof attributes.dateOverrides === 'object'
			? attributes.dateOverrides
			: {};
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
