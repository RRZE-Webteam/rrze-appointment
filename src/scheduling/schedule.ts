import {
	formatDateDisplay,
	getCalendarDates,
	normalizeDateList,
	parseDateString,
} from './dates';
import { expandRecurrence } from './recurrence';
import { minutesToTime, parseTimeToMinutes } from './time';
import type { AppointmentAttributes, TimeSlot } from './types';

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

function getAvailabilityWindows(
	attributes: AppointmentAttributes
): AvailabilityWindow[] {
	const overrides =
		attributes.dateOverrides && typeof attributes.dateOverrides === 'object'
			? attributes.dateOverrides
			: {};

	if ( Array.isArray( attributes.availabilities ) ) {
		return attributes.availabilities.flatMap( ( entry ) => {
			if ( ! parseDateString( entry.date ) ) {
				return [];
			}
			const recurrenceDates = entry.recurrence?.freq
				? normalizeDateList(
						Array.isArray( entry.recurrence.dates )
							? entry.recurrence.dates
							: expandRecurrence( entry.recurrence, entry.date )
				  )
				: [ entry.date ];
			const excludedDates = new Set(
				normalizeDateList( entry.recurrence?.excludedDates )
			);

			return recurrenceDates
				.filter( ( date ) => ! excludedDates.has( date ) )
				.map( ( date ) => {
					const override = overrides[ date ] || {};
					return {
						date,
						startTime: entry.startTime,
						endTime: entry.endTime,
						duration: Number( entry.duration ),
						breakDuration: Number( entry.breakDuration ),
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

	const globalDuration = Number( attributes.duration );
	const globalBreakDuration = Number( attributes.breakDuration );

	return getCalendarDates( attributes ).map( ( date ) => {
		const recurrenceAnchor = getRecurrenceAnchor( attributes, date );
		const seriesOverride = recurrenceAnchor
			? overrides[ recurrenceAnchor ] || {}
			: {};
		const override = {
			...seriesOverride,
			...( overrides[ date ] || {} ),
		};

		return {
			date,
			startTime: override.startTime || attributes.startTime,
			endTime: override.endTime || attributes.endTime,
			duration:
				override.duration !== undefined
					? Number( override.duration )
					: globalDuration,
			breakDuration:
				override.breakDuration !== undefined
					? Number( override.breakDuration )
					: globalBreakDuration,
			removedSlots: Array.isArray( override.removedSlots )
				? override.removedSlots
				: [],
			extraSlots: Array.isArray( override.extraSlots )
				? override.extraSlots
				: [],
		};
	} );
}

interface GenerateTimeSlotsOptions {
	/** Include slots hidden by a date override and mark them as excluded. */
	includeExcluded?: boolean;
}

const MAX_GENERATED_SLOTS = 1000;

/**
 * Builds unique future appointment slots from the persisted scheduling data.
 * Past slots are omitted, and generation is capped to protect the editor from
 * unexpectedly large recurrence configurations.
 * @param attributes Persisted block scheduling data.
 * @param options    Controls whether hidden slots are included.
 */
export function generateTimeSlots(
	attributes: AppointmentAttributes,
	options: GenerateTimeSlotsOptions = {}
): TimeSlot[] {
	const availabilityWindows = getAvailabilityWindows( attributes );
	const now = new Date();
	const generatedSlots: TimeSlot[] = [];
	const slotMap = new Map< string, TimeSlot >();
	const processedExtraDates = new Set< string >();
	const includeExcluded = !! options.includeExcluded;

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
		const nextSlot = { ...slot, isExcluded };
		slotMap.set( slot.value, nextSlot );
		generatedSlots.push( nextSlot );
	};

	for ( const availabilityWindow of availabilityWindows ) {
		const {
			date,
			duration,
			breakDuration,
			startTime,
			endTime,
			extraSlots,
		} = availabilityWindow;
		if (
			! Number.isFinite( duration ) ||
			! Number.isInteger( duration ) ||
			duration <= 0 ||
			! Number.isFinite( breakDuration ) ||
			breakDuration < 0 ||
			breakDuration > 55 ||
			breakDuration % 5 !== 0
		) {
			continue;
		}
		const startMinutes = parseTimeToMinutes( startTime );
		const endMinutes = parseTimeToMinutes( endTime );
		if (
			startMinutes === null ||
			endMinutes === null ||
			endMinutes <= startMinutes
		) {
			continue;
		}

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
			const extraEndMinutes = extraEnd
				? parseTimeToMinutes( extraEnd )
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

		if ( generatedSlots.length >= MAX_GENERATED_SLOTS ) {
			break;
		}
	}

	return generatedSlots.sort(
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
