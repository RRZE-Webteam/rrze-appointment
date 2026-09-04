import {
	createAvailabilityId,
	hasAvailabilityConflict,
} from '../../scheduling/availability';
import type { AvailabilityEntry } from '../../scheduling/types';
import type { ConsultationHour } from '../types';
import { formatDate } from '../../scheduling/dates';

interface FaudirAvailabilityOptions {
	breakDuration: number;
	duration: number;
	hoursUntil: string;
	today?: Date;
}

interface FaudirAvailabilityMerge {
	addedEntries: AvailabilityEntry[];
	entries: AvailabilityEntry[];
	skippedCount: number;
}

export function createFaudirAvailabilityEntries(
	hours: ConsultationHour[],
	options: FaudirAvailabilityOptions
): AvailabilityEntry[] {
	const today = options.today ? new Date( options.today ) : new Date();

	return hours.reduce< AvailabilityEntry[] >( ( entries, hour ) => {
		const firstDate = new Date( today );
		const daysAhead = ( Number( hour.weekday ) - today.getDay() + 7 ) % 7;
		firstDate.setDate( today.getDate() + daysAhead );
		const date = formatDate( firstDate );
		if ( date > options.hoursUntil ) {
			return entries;
		}

		entries.push( {
			id: createAvailabilityId(),
			date,
			startTime: hour.from || '09:00',
			endTime: hour.to || '17:00',
			duration: options.duration,
			breakDuration: options.breakDuration,
			recurrence: {
				freq: 'weekly',
				until: options.hoursUntil,
			},
		} );

		return entries;
	}, [] );
}

export function mergeFaudirAvailabilityEntries(
	existingEntries: AvailabilityEntry[],
	importedEntries: AvailabilityEntry[]
): FaudirAvailabilityMerge {
	const entries = [ ...existingEntries ];
	const addedEntries: AvailabilityEntry[] = [];
	let skippedCount = 0;

	importedEntries.forEach( ( candidate ) => {
		if ( hasAvailabilityConflict( entries, candidate ) ) {
			skippedCount += 1;
			return;
		}

		entries.push( candidate );
		addedEntries.push( candidate );
	} );

	return { addedEntries, entries, skippedCount };
}
