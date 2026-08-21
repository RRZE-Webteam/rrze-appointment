const MILLISECONDS_PER_MINUTE = 60 * 1000;

export function isBookingClosed(
	slotStart: Date,
	now: Date,
	bookingCutoff: number
): boolean {
	const cutoff = Math.max( 0, bookingCutoff || 0 );
	return (
		slotStart.getTime() - now.getTime() <= cutoff * MILLISECONDS_PER_MINUTE
	);
}

export function isBookingNotOpen(
	slotStart: Date,
	now: Date,
	bookingMaxAdvance: number
): boolean {
	const maxAdvance = Math.max( 0, bookingMaxAdvance || 0 );
	return (
		maxAdvance > 0 &&
		slotStart.getTime() - now.getTime() >
			maxAdvance * MILLISECONDS_PER_MINUTE
	);
}
