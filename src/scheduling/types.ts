export type RecurrenceFrequency = '' | 'daily' | 'weekly' | 'monthly';

/** JavaScript weekday number: Sunday is 0 and Saturday is 6. */
export type RecurrenceWeekday = 0 | 1 | 2 | 3 | 4 | 5 | 6;

/**
 * Persisted recurrence rule. Dates use the local `YYYY-MM-DD` format.
 * `freq` and `until` retain their serialized names for block compatibility.
 */
export interface Recurrence {
	/** Recurrence frequency. An empty value means that the entry does not repeat. */
	freq?: RecurrenceFrequency;
	/** Inclusive last occurrence date. Mutually exclusive with `count`. */
	until?: string;
	/** Maximum number of generated appointment dates. */
	count?: number;
	/** First date of the recurrence series. */
	anchor?: string;
	/** Materialized occurrence dates retained in the saved block attributes. */
	dates?: string[];
	/** Generated dates removed manually by an editor. */
	excludedDates?: string[];
	weekdays?: RecurrenceWeekday[];
}

export type RecurrenceRules = Record< string, Recurrence >;

export interface DateOverride {
	/** Local time in `HH:MM` format. */
	startTime?: string;
	/** Local time in `HH:MM` format. */
	endTime?: string;
	/** Appointment length in minutes. */
	duration?: number;
	/** Break between generated appointments, in minutes. */
	breakDuration?: number;
	/** Serialized slot values hidden for this date. */
	removedSlots?: string[];
	/** Manual slots serialized as `HH:MM|HH:MM`. */
	extraSlots?: string[];
}

export type DateOverrides = Record< string, DateOverride >;

export interface AvailabilityEntry {
	id: string;
	date: string;
	startTime: string;
	endTime: string;
	duration: number;
	breakDuration: number;
	recurrence: Recurrence;
}

export type AppointmentQuestionType = 'text' | 'select';

export interface AppointmentQuestion {
	id: string;
	label: string;
	dataUse: string;
	type: AppointmentQuestionType;
	required: boolean;
	options: string[];
}

/**
 * Attributes persisted in the appointment block. Some singular date and
 * recurrence fields are compatibility mirrors for previously saved content.
 */
export interface AppointmentAttributes {
	[ attributeName: string ]: unknown;
	title: string;
	selectedDates: string[];
	startDate: string;
	useEndDate: boolean;
	endDate: string;
	startTime: string;
	endTime: string;
	dateOverrides: DateOverrides;
	duration: number;
	breakDuration: number;
	location: string;
	locationUrl: string;
	description: string;
	recurrence: Recurrence;
	/** Explicitly selected dates; retained alongside the materialized date list. */
	manualDates?: string[];
	/** Current multi-series recurrence representation. */
	recurrences?: RecurrenceRules;
	availabilities?: AvailabilityEntry[];
	personId: number;
	personName: string;
	personEmail: string;
	useConsultationHours: boolean;
	/** Persisted mail-template identifier; serialized as `tplId` for compatibility. */
	tplId: number;
	/** Minutes before a slot starts when new bookings close. */
	bookingCutoff: number;
	/** Maximum number of minutes before a slot when booking becomes available. */
	bookingMaxAdvance: number;
	questions: AppointmentQuestion[];
	disableSso: boolean;
	hideWeekends: boolean;
	color?: string;
	style: string;
}

export interface TimeSlot {
	date: string;
	startTime: string;
	endTime: string;
	startMinutes: number;
	endMinutes: number;
	/** Human-readable start and end time. */
	timeRange: string;
	/** Stable serialized value: `YYYY-MM-DD HH:MM-HH:MM`. */
	value: string;
	label: string;
	isExtra: boolean;
	isExcluded?: boolean;
}

export type WeekdayMonthGridCell =
	| {
			type: 'empty';
	  }
	| {
			type: 'day';
			day: number;
			dateString: string;
	  };
