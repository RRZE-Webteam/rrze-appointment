import type { AppointmentAttributes, WeekdayMonthGridCell } from './types';

export function formatDate( date: Date ): string {
	return `${ date.getFullYear() }-${ String( date.getMonth() + 1 ).padStart(
		2,
		'0'
	) }-${ String( date.getDate() ).padStart( 2, '0' ) }`;
}

export function getWeekdayMonthGridCells(
	year: number,
	monthIndex: number
): WeekdayMonthGridCell[] {
	const daysInMonth = new Date( year, monthIndex + 1, 0 ).getDate();
	const monthEnd = new Date( year, monthIndex, daysInMonth );
	const firstWeekday = new Date( year, monthIndex, 1 );

	while ( firstWeekday <= monthEnd ) {
		const dayOfWeek = firstWeekday.getDay();
		if ( dayOfWeek !== 0 && dayOfWeek !== 6 ) {
			break;
		}
		firstWeekday.setDate( firstWeekday.getDate() + 1 );
	}
	if ( firstWeekday > monthEnd ) {
		return [];
	}

	const cells: WeekdayMonthGridCell[] = [];
	const firstWeekdayIndex = ( firstWeekday.getDay() + 6 ) % 7;
	for ( let index = 0; index < firstWeekdayIndex; index += 1 ) {
		cells.push( { type: 'empty' } );
	}

	const cursor = new Date( firstWeekday );
	while ( cursor <= monthEnd ) {
		const dayOfWeek = cursor.getDay();
		if ( dayOfWeek !== 0 && dayOfWeek !== 6 ) {
			cells.push( {
				type: 'day',
				day: cursor.getDate(),
				dateString: formatDate( cursor ),
			} );
		}
		cursor.setDate( cursor.getDate() + 1 );
	}

	const trailingCells = ( 5 - ( cells.length % 5 ) ) % 5;
	for ( let index = 0; index < trailingCells; index += 1 ) {
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
	const date = parseDateString( dateString );
	return date
		? date.toLocaleDateString( locale, {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
		  } )
		: dateString;
}

export function formatDateWithWeekdayDisplay(
	dateString: string,
	locale = 'de-DE'
): string {
	const date = parseDateString( dateString );
	return date
		? date.toLocaleDateString( locale, {
				weekday: 'long',
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
		  } )
		: dateString;
}

export function formatDateLongDisplay(
	dateString: string,
	locale = 'de-DE'
): string {
	const date = parseDateString( dateString );
	return date
		? date.toLocaleDateString( locale, {
				weekday: 'long',
				day: 'numeric',
				month: 'long',
				year: 'numeric',
		  } )
		: dateString;
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
	const endDate =
		attributes.useEndDate && attributes.endDate
			? attributes.endDate
			: attributes.startDate;
	return getDateRange( attributes.startDate, endDate );
}
