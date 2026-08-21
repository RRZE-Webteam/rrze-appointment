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
