const MILLISECONDS_PER_MINUTE = 60 * 1000;

export function isBookingClosed(
	slotStart: Date,
	now: Date,
	bookingCutoffMinutes: number
): boolean {
	const normalizedCutoffMinutes = Math.max( 0, bookingCutoffMinutes || 0 );
	return (
		slotStart.getTime() - now.getTime() <=
		normalizedCutoffMinutes * MILLISECONDS_PER_MINUTE
	);
}

export function isBookingNotOpen(
	slotStart: Date,
	now: Date,
	bookingMaxAdvanceMinutes: number
): boolean {
	const normalizedMaxAdvanceMinutes = Math.max(
		0,
		bookingMaxAdvanceMinutes || 0
	);
	return (
		normalizedMaxAdvanceMinutes > 0 &&
		slotStart.getTime() - now.getTime() >
			normalizedMaxAdvanceMinutes * MILLISECONDS_PER_MINUTE
	);
}
