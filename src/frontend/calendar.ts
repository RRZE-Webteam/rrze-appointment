export interface WeekdayLabel {
	short: string;
	long: string;
}

export function resolveFrontendLocale(): string {
	const requestedLocale =
		window.rrze_appointment?.locale ||
		document.documentElement.lang ||
		'de-DE';

	try {
		new Intl.DateTimeFormat( requestedLocale ).format();
		return requestedLocale;
	} catch ( error ) {
		return 'de-DE';
	}
}

export function getWeekdayLabels( locale: string ): WeekdayLabel[] {
	return Array.from( { length: 7 }, ( unused, index ) => {
		const date = new Date( 2024, 0, index + 1 );
		return {
			short: date.toLocaleDateString( locale, { weekday: 'short' } ),
			long: date.toLocaleDateString( locale, { weekday: 'long' } ),
		};
	} );
}

export function formatMonthTitle( date: Date, locale: string ): string {
	return date.toLocaleDateString( locale, {
		month: 'long',
		year: 'numeric',
	} );
}

export function toDateString(
	year: number,
	monthIndex: number,
	day: number
): string {
	return `${ year }-${ String( monthIndex + 1 ).padStart(
		2,
		'0'
	) }-${ String( day ).padStart( 2, '0' ) }`;
}
