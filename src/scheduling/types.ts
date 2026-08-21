export type RecurrenceFrequency = '' | 'daily' | 'weekly' | 'monthly';
export type RecurrenceWeekday = 0 | 1 | 2 | 3 | 4 | 5 | 6;

export interface Recurrence {
	freq?: RecurrenceFrequency;
	until?: string;
	count?: number;
	anchor?: string;
	dates?: string[];
	excludedDates?: string[];
	weekdays?: RecurrenceWeekday[];
}

export type RecurrenceRules = Record< string, Recurrence >;

export interface DateOverride {
	startTime?: string;
	endTime?: string;
	duration?: number;
	breakDuration?: number;
	removedSlots?: string[];
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

export interface AppointmentAttributes {
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
	manualDates?: string[];
	recurrences?: RecurrenceRules;
	availabilities?: AvailabilityEntry[];
	personId: number;
	personName: string;
	personEmail: string;
	useConsultationHours: boolean;
	tplId: number;
	bookingCutoff: number;
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
	timeRange: string;
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
