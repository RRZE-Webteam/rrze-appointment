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

export function formatDateDisplay( dateString: string ): string {
	const dateObj = parseDateString( dateString );
	if ( ! dateObj ) {
		return dateString;
	}
	return dateObj.toLocaleDateString( 'de-DE', {
		day: '2-digit',
		month: '2-digit',
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

export function generateTimeSlots(
	attributes: AppointmentAttributes
): TimeSlot[] {
	const { startTime, endTime, dateOverrides, duration, breakDuration } =
		attributes;
	const calendarDates = getCalendarDates( attributes );
	if ( calendarDates.length === 0 ) {
		return [];
	}

	const globalDuration = Number( duration );
	const globalPause = Number( breakDuration );

	if (
		! Number.isFinite( globalDuration ) ||
		globalDuration <= 0 ||
		globalDuration % 15 !== 0 ||
		! Number.isFinite( globalPause ) ||
		globalPause < 0 ||
		globalPause > 55 ||
		globalPause % 5 !== 0
	) {
		return [];
	}

	const overrides =
		dateOverrides && typeof dateOverrides === 'object' ? dateOverrides : {};
	const now = new Date();
	const slots: TimeSlot[] = [];

	for ( const dateString of calendarDates ) {
		const override = overrides[ dateString ] || {};
		const slotDuration =
			override.duration !== null && override.duration !== undefined
				? Number( override.duration )
				: globalDuration;
		const pauseMinutes =
			override.breakDuration !== null &&
			override.breakDuration !== undefined
				? Number( override.breakDuration )
				: globalPause;
		const startMinutes = parseTimeToMinutes(
			override.startTime || startTime
		);
		const endMinutes = parseTimeToMinutes( override.endTime || endTime );
		if (
			startMinutes === null ||
			endMinutes === null ||
			endMinutes <= startMinutes
		) {
			continue;
		}

		const removedSlots = new Set(
			Array.isArray( override.removedSlots ) ? override.removedSlots : []
		);
		const extraSlots = Array.isArray( override.extraSlots )
			? override.extraSlots
			: [];
		const dateSlots: TimeSlot[] = [];
		const slotMap = new Map< string, TimeSlot >();

		const addSlot = ( slot: TimeSlot ) => {
			const slotStart = new Date(
				`${ slot.date }T${ slot.startTime }:00`
			);
			if ( ! Number.isNaN( slotStart.getTime() ) && slotStart <= now ) {
				return;
			}
			if ( removedSlots.has( slot.value ) || slotMap.has( slot.value ) ) {
				return;
			}
			slotMap.set( slot.value, slot );
			dateSlots.push( slot );
		};

		let slotStart = startMinutes;
		while ( slotStart + slotDuration <= endMinutes ) {
			const slotEnd = slotStart + slotDuration;
			const startLabel = minutesToTime( slotStart );
			const endLabel = minutesToTime( slotEnd );
			addSlot( {
				date: dateString,
				startTime: startLabel,
				endTime: endLabel,
				startMinutes: slotStart,
				endMinutes: slotEnd,
				timeRange: `${ startLabel } - ${ endLabel }`,
				value: `${ dateString } ${ startLabel }-${ endLabel }`,
				label: `${ formatDateDisplay( dateString ) } ${ startLabel }`,
				isExtra: false,
			} );
			slotStart += slotDuration + pauseMinutes;
		}

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
				: extraStartMinutes + slotDuration;
			if ( extraEndMinutes === null || extraEndMinutes > 24 * 60 ) {
				return;
			}
			const startLabel = minutesToTime( extraStartMinutes );
			const endLabel = minutesToTime( extraEndMinutes );
			addSlot( {
				date: dateString,
				startTime: startLabel,
				endTime: endLabel,
				startMinutes: extraStartMinutes,
				endMinutes: extraEndMinutes,
				timeRange: `${ startLabel } - ${ endLabel }`,
				value: `${ dateString } ${ startLabel }-${ endLabel }`,
				label: `${ formatDateDisplay( dateString ) } ${ startLabel }`,
				isExtra: true,
			} );
		} );

		dateSlots.sort( ( a, b ) => a.startMinutes - b.startMinutes );
		slots.push( ...dateSlots );
		if ( slots.length >= 1000 ) {
			break;
		}
	}

	return slots;
}

/**
 * Expands a recurrence into date strings starting from startDate.
 * recurrence: { freq: 'daily'|'weekly'|'monthly', until: 'YYYY-MM-DD' }
 * @param recurrence
 * @param startDate
 */
export function expandRecurrence(
	recurrence: Recurrence,
	startDate: string
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
	const current = new Date( anchor );

	const limit =
		( typeof window !== 'undefined' &&
			window.rrze_appointment?.recurrenceLimit ) ||
		52;
	while ( results.length < 730 ) {
		if ( untilDate && current > untilDate ) {
			break;
		}

		results.push( formatDate( current ) );

		if ( freq === 'daily' ) {
			current.setDate( current.getDate() + 1 );
		} else if ( freq === 'weekly' ) {
			current.setDate( current.getDate() + 7 );
		} else if ( freq === 'monthly' ) {
			current.setMonth( current.getMonth() + 1 );
		} else {
			break;
		}

		if ( ! untilDate && results.length >= limit ) {
			break;
		}
	}

	return results;
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
