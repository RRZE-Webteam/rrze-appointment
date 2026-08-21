import { __, sprintf } from '@wordpress/i18n';
import {
	getAvailabilityDates,
	getAvailabilitySlotCount,
	hasAvailabilityConflict,
} from '../../scheduling/availability';
import { formatDate } from '../../scheduling/dates';
import {
	getRecurrenceWeekdays,
	MAX_RECURRENCE_DATES,
	recurrenceExceedsLimit,
} from '../../scheduling/recurrence';
import { parseTimeToMinutes } from '../../scheduling/time';
import type { AvailabilityEntry } from '../../scheduling/types';

export type RecurrenceEndMode = 'date' | 'count';

interface ValidationOptions {
	draft: AvailabilityEntry;
	entries: AvailabilityEntry[];
	originalDate: string;
	originalId: string;
	recurrenceEndMode: RecurrenceEndMode;
	usePattern: boolean;
}

interface ValidationResult {
	entry: AvailabilityEntry | null;
	error: string;
}

function failure( error: string ): ValidationResult {
	return { entry: null, error };
}

export function validateAvailability( {
	draft,
	entries,
	originalDate,
	originalId,
	recurrenceEndMode,
	usePattern,
}: ValidationOptions ): ValidationResult {
	if ( ! draft.date ) {
		return failure( __( 'Please select a date.', 'rrze-appointment' ) );
	}
	if (
		draft.date < formatDate( new Date() ) &&
		draft.date !== originalDate
	) {
		return failure(
			__( 'The date must not be in the past.', 'rrze-appointment' )
		);
	}

	const startMinutes = parseTimeToMinutes( draft.startTime );
	const endMinutes = parseTimeToMinutes( draft.endTime );
	if (
		startMinutes === null ||
		endMinutes === null ||
		endMinutes <= startMinutes
	) {
		return failure(
			__( 'End time must be after start time.', 'rrze-appointment' )
		);
	}

	const entry = usePattern
		? draft
		: { ...draft, duration: endMinutes - startMinutes, breakDuration: 0 };
	if ( ! Number.isInteger( entry.duration ) || entry.duration <= 0 ) {
		return failure(
			__(
				'The appointment duration must be a positive whole number of minutes.',
				'rrze-appointment'
			)
		);
	}
	if ( getAvailabilitySlotCount( entry ) === 0 ) {
		return failure(
			__(
				'The selected time range is too short for one complete appointment.',
				'rrze-appointment'
			)
		);
	}
	if (
		entry.recurrence.freq === 'weekly' &&
		getRecurrenceWeekdays( entry.recurrence, entry.date ).length === 0
	) {
		return failure(
			__( 'Please select at least one weekday.', 'rrze-appointment' )
		);
	}

	if ( entry.recurrence.freq ) {
		if ( recurrenceEndMode === 'date' ) {
			if ( ! entry.recurrence.until ) {
				return failure(
					__(
						'Choose the date on which the series ends.',
						'rrze-appointment'
					)
				);
			}
			if ( entry.recurrence.until < entry.date ) {
				return failure(
					__(
						'The last date must not be before the first date.',
						'rrze-appointment'
					)
				);
			}
		} else {
			const count = Number( entry.recurrence.count );
			if ( ! Number.isInteger( count ) || count < 1 ) {
				return failure(
					__(
						'Enter how many appointment dates the series should contain.',
						'rrze-appointment'
					)
				);
			}
		}

		if ( recurrenceExceedsLimit( entry.recurrence, entry.date ) ) {
			return failure(
				sprintf(
					/* translators: %d: maximum number of dates in a recurrence series. */
					__(
						'A series can contain up to %d appointment dates. Choose an earlier end date or a smaller number.',
						'rrze-appointment'
					),
					MAX_RECURRENCE_DATES
				)
			);
		}
		if ( getAvailabilityDates( entry ).length === 0 ) {
			return failure(
				__(
					'The selected weekdays do not create an appointment within this date range.',
					'rrze-appointment'
				)
			);
		}
	}

	if ( hasAvailabilityConflict( entries, entry, originalId ) ) {
		return failure(
			__(
				'These appointment times overlap with an existing schedule on at least one date.',
				'rrze-appointment'
			)
		);
	}

	return { entry, error: '' };
}
