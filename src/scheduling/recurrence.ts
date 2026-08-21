import type {
	AppointmentAttributes,
	Recurrence,
	RecurrenceFrequency,
	RecurrenceRules,
	RecurrenceWeekday,
} from './types';
import { formatDate, normalizeDateList, parseDateString } from './dates';

const RECURRENCE_FREQUENCIES: RecurrenceFrequency[] = [
	'daily',
	'weekly',
	'monthly',
];

export const MAX_RECURRENCE_DATES = 730;
const DEFAULT_RECURRENCE_DATES = 52;

function getConfiguredRecurrenceLimit(): number {
	const configuredLimit = Number(
		typeof window !== 'undefined'
			? window.rrze_appointment?.recurrenceLimit
			: DEFAULT_RECURRENCE_DATES
	);

	return Number.isInteger( configuredLimit ) && configuredLimit > 0
		? Math.min( configuredLimit, MAX_RECURRENCE_DATES )
		: DEFAULT_RECURRENCE_DATES;
}

function expandRecurrenceWithLimit(
	recurrence: Recurrence,
	startDate: string,
	maxDates: number
): string[] {
	if ( ! recurrence.freq || ! startDate ) {
		return [];
	}

	const anchor = parseDateString( startDate );
	if ( ! anchor ) {
		return [];
	}

	const untilDate = recurrence.until
		? parseDateString( recurrence.until )
		: null;
	const requestedCount = Number( recurrence.count );
	let occurrenceLimit = Math.min( getConfiguredRecurrenceLimit(), maxDates );
	if ( untilDate ) {
		occurrenceLimit = maxDates;
	} else if ( Number.isInteger( requestedCount ) && requestedCount > 0 ) {
		occurrenceLimit = Math.min( requestedCount, maxDates );
	}
	const results: string[] = [];

	const weekdays =
		recurrence.freq === 'weekly' && Array.isArray( recurrence.weekdays )
			? new Set( normalizeRecurrenceWeekdays( recurrence.weekdays ) )
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
			if ( weekdays.has( current.getDay() as RecurrenceWeekday ) ) {
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

		if ( recurrence.freq === 'daily' ) {
			current.setDate( current.getDate() + 1 );
		} else if ( recurrence.freq === 'weekly' ) {
			current.setDate( current.getDate() + 7 );
		} else if ( recurrence.freq === 'monthly' ) {
			const targetMonth = new Date(
				anchor.getFullYear(),
				anchor.getMonth() + occurrenceIndex,
				1
			);
			const lastDay = new Date(
				targetMonth.getFullYear(),
				targetMonth.getMonth() + 1,
				0
			).getDate();
			current = new Date(
				targetMonth.getFullYear(),
				targetMonth.getMonth(),
				Math.min( anchor.getDate(), lastDay )
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

	return recurrence.until
		? expandRecurrenceWithLimit(
				recurrence,
				startDate,
				MAX_RECURRENCE_DATES + 1
		  ).length > MAX_RECURRENCE_DATES
		: false;
}

export interface RecurrenceEditorState {
	manualDates: string[];
	rules: RecurrenceRules;
}

function isRecurrenceFrequency(
	value: unknown
): value is Exclude< RecurrenceFrequency, '' > {
	return RECURRENCE_FREQUENCIES.includes( value as RecurrenceFrequency );
}

export function normalizeRecurrenceWeekdays(
	values: unknown
): RecurrenceWeekday[] {
	if ( ! Array.isArray( values ) ) {
		return [];
	}

	return [
		...new Set(
			values.filter(
				( value ): value is RecurrenceWeekday =>
					Number.isInteger( value ) && value >= 0 && value <= 6
			)
		),
	].sort( ( a, b ) => ( ( a + 6 ) % 7 ) - ( ( b + 6 ) % 7 ) );
}

export function getRecurrenceWeekdays(
	rule: Recurrence,
	anchor: string
): RecurrenceWeekday[] {
	if ( Array.isArray( rule.weekdays ) ) {
		return normalizeRecurrenceWeekdays( rule.weekdays );
	}

	const anchorDate = parseDateString( anchor );
	return anchorDate ? [ anchorDate.getDay() as RecurrenceWeekday ] : [];
}

function normalizeRule( anchor: string, rule: Recurrence ): Recurrence | null {
	if ( ! parseDateString( anchor ) || ! isRecurrenceFrequency( rule.freq ) ) {
		return null;
	}

	const excludedDates = normalizeDateList( rule.excludedDates );
	const excludedSet = new Set( excludedDates );
	const weekdays =
		rule.freq === 'weekly'
			? normalizeRecurrenceWeekdays( rule.weekdays )
			: [];
	const hasExplicitWeekdays =
		rule.freq === 'weekly' && Array.isArray( rule.weekdays );
	const count = Number( rule.count );
	const normalizedCount =
		! rule.until && Number.isInteger( count ) && count > 0
			? count
			: undefined;
	const normalizedRule: Recurrence = {
		freq: rule.freq,
		anchor,
		...( rule.until ? { until: rule.until } : {} ),
		...( normalizedCount ? { count: normalizedCount } : {} ),
		...( excludedDates.length > 0 ? { excludedDates } : {} ),
		...( hasExplicitWeekdays ? { weekdays } : {} ),
	};
	const dates = expandRecurrence( normalizedRule, anchor ).filter(
		( date ) => date === anchor || ! excludedSet.has( date )
	);

	return { ...normalizedRule, dates };
}

function normalizeRules( rules: unknown ): RecurrenceRules {
	if ( ! rules || typeof rules !== 'object' || Array.isArray( rules ) ) {
		return {};
	}

	return Object.entries( rules as RecurrenceRules ).reduce< RecurrenceRules >(
		( normalizedRules, [ anchor, rule ] ) => {
			const normalizedRule = normalizeRule( anchor, rule );
			if ( normalizedRule ) {
				normalizedRules[ anchor ] = normalizedRule;
			}
			return normalizedRules;
		},
		{}
	);
}

function getLegacyRule( attributes: AppointmentAttributes ): RecurrenceRules {
	const legacyRule = attributes.recurrence;
	if ( ! legacyRule || ! isRecurrenceFrequency( legacyRule.freq ) ) {
		return {};
	}

	const selectedDates = normalizeDateList( attributes.selectedDates );
	const anchor =
		legacyRule.anchor ||
		normalizeDateList( legacyRule.dates )[ 0 ] ||
		attributes.startDate ||
		selectedDates[ 0 ];
	const normalizedRule = anchor ? normalizeRule( anchor, legacyRule ) : null;

	return normalizedRule && anchor ? { [ anchor ]: normalizedRule } : {};
}

function collectRuleDates( rules: RecurrenceRules ): string[] {
	return Object.values( rules ).reduce< string[] >( ( dates, rule ) => {
		dates.push( ...normalizeDateList( rule.dates ) );
		return dates;
	}, [] );
}

export function getRecurrenceEditorState(
	attributes: AppointmentAttributes
): RecurrenceEditorState {
	const hasNewRules = attributes.recurrences !== undefined;
	const rules = hasNewRules
		? normalizeRules( attributes.recurrences )
		: getLegacyRule( attributes );

	if ( attributes.manualDates !== undefined ) {
		return {
			manualDates: normalizeDateList( attributes.manualDates ),
			rules,
		};
	}

	const generatedDates = new Set( collectRuleDates( rules ) );
	const anchors = new Set( Object.keys( rules ) );
	const manualDates = normalizeDateList( attributes.selectedDates ).filter(
		( date ) => ! generatedDates.has( date ) || anchors.has( date )
	);

	return { manualDates, rules };
}

export function createRecurrenceRule(
	anchor: string,
	settings: Recurrence
): Recurrence | null {
	return normalizeRule( anchor, { ...settings, anchor } );
}

export function isGeneratedByRule( date: string, rule: Recurrence ): boolean {
	if ( ! rule.anchor || ! rule.freq ) {
		return false;
	}
	return expandRecurrence( rule, rule.anchor ).includes( date );
}

export function buildRecurrenceAttributes(
	manualDates: string[],
	rules: RecurrenceRules
): Partial< AppointmentAttributes > {
	const normalizedRules = normalizeRules( rules );
	const normalizedManualDates = normalizeDateList( manualDates );
	const selectedDates = normalizeDateList( [
		...normalizedManualDates,
		...collectRuleDates( normalizedRules ),
	] );
	const firstRule =
		normalizedRules[ Object.keys( normalizedRules ).sort()[ 0 ] ];

	return {
		manualDates: normalizedManualDates,
		recurrences: normalizedRules,
		// Keep the former single-rule attribute as a compatibility mirror.
		recurrence: firstRule || {},
		selectedDates,
		startDate: selectedDates[ 0 ] || '',
		endDate: selectedDates[ selectedDates.length - 1 ] || '',
		useEndDate: selectedDates.length > 1,
	};
}

export function toggleRecurrenceDate(
	attributes: AppointmentAttributes,
	date: string
): Partial< AppointmentAttributes > {
	const { manualDates, rules } = getRecurrenceEditorState( attributes );
	const isSelected = normalizeDateList( attributes.selectedDates ).includes(
		date
	);
	const nextManualDates = new Set( manualDates );
	const nextRules: RecurrenceRules = {};

	if ( isSelected ) {
		nextManualDates.delete( date );
		Object.entries( rules ).forEach( ( [ anchor, rule ] ) => {
			if ( anchor === date ) {
				return;
			}
			if ( isGeneratedByRule( date, rule ) ) {
				const excludedDates = new Set( rule.excludedDates || [] );
				excludedDates.add( date );
				const nextRule = createRecurrenceRule( anchor, {
					...rule,
					excludedDates: Array.from( excludedDates ),
				} );
				if ( nextRule ) {
					nextRules[ anchor ] = nextRule;
				}
				return;
			}
			nextRules[ anchor ] = rule;
		} );
	} else {
		let restoredOccurrence = false;
		Object.entries( rules ).forEach( ( [ anchor, rule ] ) => {
			if (
				rule.excludedDates?.includes( date ) &&
				isGeneratedByRule( date, rule )
			) {
				const nextRule = createRecurrenceRule( anchor, {
					...rule,
					excludedDates: rule.excludedDates.filter(
						( excludedDate ) => excludedDate !== date
					),
				} );
				if ( nextRule ) {
					nextRules[ anchor ] = nextRule;
				}
				restoredOccurrence = true;
				return;
			}
			nextRules[ anchor ] = rule;
		} );
		if ( ! restoredOccurrence ) {
			nextManualDates.add( date );
		}
	}

	return buildRecurrenceAttributes(
		Array.from( nextManualDates ),
		nextRules
	);
}
