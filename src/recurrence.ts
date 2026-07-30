import type {
	AppointmentAttributes,
	Recurrence,
	RecurrenceFrequency,
	RecurrenceRules,
} from './types';
import { expandRecurrence, normalizeDateList, parseDateString } from './utils';

const RECURRENCE_FREQUENCIES: RecurrenceFrequency[] = [
	'daily',
	'weekly',
	'monthly',
];

export interface RecurrenceEditorState {
	manualDates: string[];
	rules: RecurrenceRules;
}

function isRecurrenceFrequency(
	value: unknown
): value is Exclude< RecurrenceFrequency, '' > {
	return RECURRENCE_FREQUENCIES.includes( value as RecurrenceFrequency );
}

function normalizeRule( anchor: string, rule: Recurrence ): Recurrence | null {
	if ( ! parseDateString( anchor ) || ! isRecurrenceFrequency( rule.freq ) ) {
		return null;
	}

	const excludedDates = normalizeDateList( rule.excludedDates );
	const excludedSet = new Set( excludedDates );
	const normalizedRule: Recurrence = {
		freq: rule.freq,
		anchor,
		...( rule.until ? { until: rule.until } : {} ),
		...( excludedDates.length > 0 ? { excludedDates } : {} ),
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
