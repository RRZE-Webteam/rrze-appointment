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
	RecurrenceRules,
} from './types';
import {
	getCalendarDates,
	normalizeDateList,
	parseTimeToMinutes,
} from './utils';

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

export function getAvailabilityEntries(
	attributes: AppointmentAttributes
): AvailabilityEntry[] {
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
	return rule?.dates || [ entry.date ];
}

export function getAvailabilitySlotCount( entry: AvailabilityEntry ): number {
	const startMinutes = parseTimeToMinutes( entry.startTime );
	const endMinutes = parseTimeToMinutes( entry.endTime );
	if (
		startMinutes === null ||
		endMinutes === null ||
		endMinutes <= startMinutes ||
		entry.duration <= 0
	) {
		return 0;
	}

	let count = 0;
	let slotStart = startMinutes;
	while ( slotStart + entry.duration <= endMinutes ) {
		count += 1;
		slotStart += entry.duration + Math.max( 0, entry.breakDuration );
	}
	return count;
}

export function buildAvailabilityAttributes(
	attributes: AppointmentAttributes,
	entries: AvailabilityEntry[]
): Partial< AppointmentAttributes > {
	const sortedEntries = [ ...entries ].sort( ( a, b ) =>
		a.date.localeCompare( b.date )
	);
	const rules: RecurrenceRules = {};

	sortedEntries.forEach( ( entry ) => {
		if ( ! entry.recurrence.freq ) {
			return;
		}
		const rule = createRecurrenceRule( entry.date, entry.recurrence );
		if ( rule ) {
			rules[ entry.date ] = rule;
		}
	} );

	const recurrenceAttributes = buildRecurrenceAttributes(
		sortedEntries.map( ( entry ) => entry.date ),
		rules
	);
	const selectedDates = new Set( recurrenceAttributes.selectedDates || [] );
	const entryDates = new Set( sortedEntries.map( ( entry ) => entry.date ) );
	const previousEntryDates = new Set(
		getAvailabilityEntries( attributes ).map( ( entry ) => entry.date )
	);
	const currentOverrides =
		attributes.dateOverrides && typeof attributes.dateOverrides === 'object'
			? attributes.dateOverrides
			: {};
	const dateOverrides = Object.entries(
		currentOverrides
	).reduce< DateOverrides >( ( nextOverrides, [ date, override ] ) => {
		if (
			selectedDates.has( date ) &&
			( ! previousEntryDates.has( date ) || entryDates.has( date ) )
		) {
			nextOverrides[ date ] = { ...override };
		}
		return nextOverrides;
	}, {} );

	sortedEntries.forEach( ( entry ) => {
		dateOverrides[ entry.date ] = {
			...( dateOverrides[ entry.date ] || {} ),
			startTime: entry.startTime,
			endTime: entry.endTime,
			duration: entry.duration,
			breakDuration: entry.breakDuration,
		};
	} );

	const firstEntry = sortedEntries[ 0 ];

	return {
		...recurrenceAttributes,
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

export function hasAvailabilityDateConflict(
	entries: AvailabilityEntry[],
	candidate: AvailabilityEntry,
	originalDate = ''
): boolean {
	const candidateDates = new Set( getAvailabilityDates( candidate ) );

	return entries.some( ( entry ) => {
		if ( entry.date === originalDate ) {
			return false;
		}
		return getAvailabilityDates( entry ).some( ( date ) =>
			candidateDates.has( date )
		);
	} );
}
