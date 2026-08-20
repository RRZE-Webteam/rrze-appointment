import type {
	AppointmentAttributes,
	Recurrence,
	TimeSlot,
	WeekdayMonthGridCell,
} from './types';

export function parseTimeToMinutes( time: unknown ): number | null {
	if ( ! time || typeof time !== 'string' ) {
		return null;
	}
	const [ hours, minutes ] = time.split( ':' ).map( Number );
	if ( ! Number.isInteger( hours ) || ! Number.isInteger( minutes ) ) {
		return null;
	}
	if ( hours < 0 || hours > 23 || minutes < 0 || minutes > 59 ) {
		return null;
	}
	return hours * 60 + minutes;
}

export function minutesToTime( totalMinutes: number ): string {
	const hours = Math.floor( totalMinutes / 60 );
	const minutes = totalMinutes % 60;
	return `${ String( hours ).padStart( 2, '0' ) }:${ String(
		minutes
	).padStart( 2, '0' ) }`;
}

export function formatDate( dateObj: Date ): string {
	return `${ dateObj.getFullYear() }-${ String(
		dateObj.getMonth() + 1
	).padStart( 2, '0' ) }-${ String( dateObj.getDate() ).padStart( 2, '0' ) }`;
}

/**
 * Mo–Fr month grid without weekends: weekdays of the target month only, no placeholders for the previous month.
 * Leading empties align the first in-month weekday under Mo–Fr headers; trailing empties complete the last row.
 * @param year
 * @param monthIndex
 */
export function getWeekdayMonthGridCells(
	year: number,
	monthIndex: number
): WeekdayMonthGridCell[] {
	const daysInMonth = new Date( year, monthIndex + 1, 0 ).getDate();
	const monthEnd = new Date( year, monthIndex, daysInMonth );

	const firstWd = new Date( year, monthIndex, 1 );
	while ( firstWd <= monthEnd ) {
		const dow = firstWd.getDay();
		if ( dow !== 0 && dow !== 6 ) {
			break;
		}
		firstWd.setDate( firstWd.getDate() + 1 );
	}
	if ( firstWd > monthEnd ) {
		return [];
	}

	const firstDowMon0 = ( firstWd.getDay() + 6 ) % 7;
	const cells: WeekdayMonthGridCell[] = [];
	for ( let i = 0; i < firstDowMon0; i += 1 ) {
		cells.push( { type: 'empty' } );
	}

	const cursor = new Date( firstWd );
	while ( cursor <= monthEnd ) {
		const dow = cursor.getDay();
		if ( dow !== 0 && dow !== 6 ) {
			cells.push( {
				type: 'day',
				day: cursor.getDate(),
				dateString: formatDate( cursor ),
			} );
		}
		cursor.setDate( cursor.getDate() + 1 );
	}

	const trail = ( 5 - ( cells.length % 5 ) ) % 5;
	for ( let i = 0; i < trail; i += 1 ) {
		cells.push( { type: 'empty' } );
	}
	return cells;
}

export function parseDateString( value?: unknown ): Date | null {
	if ( ! value || typeof value !== 'string' ) {
		return null;
	}
	const [ year, month, day ] = value.split( '-' ).map( Number );
	if (
		! Number.isInteger( year ) ||
		! Number.isInteger( month ) ||
		! Number.isInteger( day )
	) {
		return null;
	}
	return new Date( year, month - 1, day );
}

export function formatDateDisplay(
	dateString: string,
	locale = 'de-DE'
): string {
	const dateObj = parseDateString( dateString );
	if ( ! dateObj ) {
		return dateString;
	}
	return dateObj.toLocaleDateString( locale, {
		day: '2-digit',
		month: '2-digit',
		year: 'numeric',
	} );
}

export function formatDateWithWeekdayDisplay(
	dateString: string,
	locale = 'de-DE'
): string {
	const dateObj = parseDateString( dateString );
	if ( ! dateObj ) {
		return dateString;
	}
	return dateObj.toLocaleDateString( locale, {
		weekday: 'long',
		day: '2-digit',
		month: '2-digit',
		year: 'numeric',
	} );
}

export function formatDateLongDisplay(
	dateString: string,
	locale = 'de-DE'
): string {
	const dateObj = parseDateString( dateString );
	if ( ! dateObj ) {
		return dateString;
	}
	return dateObj.toLocaleDateString( locale, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} );
}

export function normalizeDateList( values: unknown ): string[] {
	if ( ! Array.isArray( values ) ) {
		return [];
	}
	return [
		...new Set(
			values
				.map( ( value ) => {
					if ( typeof value === 'string' ) {
						return value.slice( 0, 10 );
					}
					if (
						value instanceof Date &&
						! Number.isNaN( value.getTime() )
					) {
						return formatDate( value );
					}
					return '';
				} )
				.filter( Boolean )
		),
	].sort();
}

export function getDateRange( startDate: string, endDate: string ): string[] {
	if ( ! startDate || ! endDate || endDate < startDate ) {
		return [];
	}
	const fromDate = new Date( `${ startDate }T00:00:00` );
	const toDate = new Date( `${ endDate }T00:00:00` );
	if (
		Number.isNaN( fromDate.getTime() ) ||
		Number.isNaN( toDate.getTime() )
	) {
		return [];
	}
	const dates = [];
	const currentDate = new Date( fromDate );
	while ( currentDate <= toDate ) {
		dates.push( formatDate( currentDate ) );
		currentDate.setDate( currentDate.getDate() + 1 );
		if ( dates.length >= 366 ) {
			break;
		}
	}
	return dates;
}

export function getCalendarDates(
	attributes: AppointmentAttributes
): string[] {
	const selectedDates = normalizeDateList( attributes.selectedDates );
	if ( selectedDates.length > 0 ) {
		return selectedDates;
	}
	if ( ! attributes.startDate ) {
		return [];
	}
	const selectedEndDate =
		attributes.useEndDate && attributes.endDate
			? attributes.endDate
			: attributes.startDate;
	return getDateRange( attributes.startDate, selectedEndDate );
}

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
	const globalPause = Number( attributes.breakDuration );

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
					: globalPause,
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
	includeExcluded?: boolean;
}

export function generateTimeSlots(
	attributes: AppointmentAttributes,
	options: GenerateTimeSlotsOptions = {}
): TimeSlot[] {
	const windows = getAvailabilityWindows( attributes );
	const now = new Date();
	const slots: TimeSlot[] = [];
	const slotMap = new Map< string, TimeSlot >();
	const processedExtraDates = new Set< string >();
	const includeExcluded = !! options.includeExcluded;

	const addSlot = ( slot: TimeSlot, removedSlots: Set< string > ) => {
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
		slots.push( nextSlot );
	};

	for ( const window of windows ) {
		const {
			date,
			duration,
			breakDuration,
			startTime,
			endTime,
			extraSlots,
		} = window;
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

		const removedSlots = new Set( window.removedSlots );

		let slotStart = startMinutes;
		while ( slotStart + duration <= endMinutes ) {
			const slotEnd = slotStart + duration;
			const startLabel = minutesToTime( slotStart );
			const endLabel = minutesToTime( slotEnd );
			addSlot(
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
			addSlot(
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

		if ( slots.length >= 1000 ) {
			break;
		}
	}

	return slots.sort(
		( a, b ) =>
			a.date.localeCompare( b.date ) ||
			a.startMinutes - b.startMinutes ||
			a.endMinutes - b.endMinutes
	);
}

/**
 * Expands a recurrence into date strings starting from startDate.
 * recurrence: { freq: 'daily'|'weekly'|'monthly', until: 'YYYY-MM-DD', count: number }
 * @param recurrence
 * @param startDate
 */
export const MAX_RECURRENCE_DATES = 730;
const DEFAULT_LEGACY_RECURRENCE_DATES = 52;

function getLegacyRecurrenceLimit(): number {
	const configuredLimit = Number(
		typeof window !== 'undefined'
			? window.rrze_appointment?.recurrenceLimit
			: DEFAULT_LEGACY_RECURRENCE_DATES
	);

	return Number.isInteger( configuredLimit ) && configuredLimit > 0
		? Math.min( configuredLimit, MAX_RECURRENCE_DATES )
		: DEFAULT_LEGACY_RECURRENCE_DATES;
}

function expandRecurrenceWithLimit(
	recurrence: Recurrence,
	startDate: string,
	maxDates: number
): string[] {
	if ( ! recurrence || ! recurrence.freq || ! startDate ) {
		return [];
	}

	const { freq, until } = recurrence;
	const anchor = parseDateString( startDate );
	if ( ! anchor ) {
		return [];
	}

	const untilDate = until ? parseDateString( until ) : null;
	const results: string[] = [];
	const requestedCount = Number( recurrence.count );
	let occurrenceLimit = maxDates;
	if ( ! untilDate ) {
		occurrenceLimit =
			Number.isInteger( requestedCount ) && requestedCount > 0
				? Math.min( requestedCount, maxDates )
				: Math.min( getLegacyRecurrenceLimit(), maxDates );
	}

	const weekdays =
		freq === 'weekly' && Array.isArray( recurrence.weekdays )
			? new Set< number >(
					recurrence.weekdays.filter(
						( weekday ) =>
							Number.isInteger( weekday ) &&
							weekday >= 0 &&
							weekday <= 6
					)
			  )
			: null;
	if ( weekdays ) {
		if ( weekdays.size === 0 ) {
			return [];
		}

		const current = new Date( anchor );
		while ( results.length < occurrenceLimit ) {
			if ( untilDate && current > untilDate ) {
				break;
			}
			if ( weekdays.has( current.getDay() ) ) {
				results.push( formatDate( current ) );
			}
			current.setDate( current.getDate() + 1 );
		}
		return results;
	}

	let current = new Date( anchor );
	let occurrenceIndex = 0;
	while ( results.length < occurrenceLimit ) {
		if ( untilDate && current > untilDate ) {
			break;
		}

		results.push( formatDate( current ) );
		occurrenceIndex += 1;

		if ( freq === 'daily' ) {
			current.setDate( current.getDate() + 1 );
		} else if ( freq === 'weekly' ) {
			current.setDate( current.getDate() + 7 );
		} else if ( freq === 'monthly' ) {
			const targetMonth = new Date(
				anchor.getFullYear(),
				anchor.getMonth() + occurrenceIndex,
				1
			);
			const lastDayOfTargetMonth = new Date(
				targetMonth.getFullYear(),
				targetMonth.getMonth() + 1,
				0
			).getDate();
			current = new Date(
				targetMonth.getFullYear(),
				targetMonth.getMonth(),
				Math.min( anchor.getDate(), lastDayOfTargetMonth )
			);
		} else {
			break;
		}
	}

	return results;
}

export function expandRecurrence(
	recurrence: Recurrence,
	startDate: string
): string[] {
	return expandRecurrenceWithLimit(
		recurrence,
		startDate,
		MAX_RECURRENCE_DATES
	);
}

export function recurrenceExceedsLimit(
	recurrence: Recurrence,
	startDate: string
): boolean {
	const requestedCount = Number( recurrence.count );
	if (
		! recurrence.until &&
		Number.isInteger( requestedCount ) &&
		requestedCount > MAX_RECURRENCE_DATES
	) {
		return true;
	}

	if ( ! recurrence.until ) {
		return false;
	}

	return (
		expandRecurrenceWithLimit(
			recurrence,
			startDate,
			MAX_RECURRENCE_DATES + 1
		).length > MAX_RECURRENCE_DATES
	);
}

export function groupSlotsByDate(
	slots: TimeSlot[]
): Record< string, TimeSlot[] > {
	return slots.reduce< Record< string, TimeSlot[] > >( ( acc, slot ) => {
		if ( ! acc[ slot.date ] ) {
			acc[ slot.date ] = [];
		}
		acc[ slot.date ].push( slot );
		return acc;
	}, {} );
}
