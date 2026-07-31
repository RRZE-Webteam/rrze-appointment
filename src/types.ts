export type RecurrenceFrequency = '' | 'daily' | 'weekly' | 'monthly';
export type RecurrenceWeekday = 0 | 1 | 2 | 3 | 4 | 5 | 6;

export interface Recurrence {
	freq?: RecurrenceFrequency;
	until?: string;
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
	requireMessage: boolean;
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

export interface ConsultationHour {
	weekday: number;
	from?: string;
	to?: string;
}

export interface FaudirPerson {
	id: number;
	label?: string;
	honorificPrefix?: string;
	givenName?: string;
	familyName?: string;
	email?: string;
	location?: string;
	locationUrl?: string;
	consultationHours?: ConsultationHour[];
	hoursType?: 'office' | 'consultation';
}

export interface FaudirResponse {
	error: boolean;
	message?: string;
	data: FaudirPerson[];
}

export interface EditorI18n {
	requireMessageField?: string;
	requireMessageHelp?: string;
	disableSsoField?: string;
	disableSsoHelp?: string;
	hideWeekendsField?: string;
	hideWeekendsHelp?: string;
}

export interface FrontendI18n {
	availableOn?: string;
	book?: string;
	booked?: string;
	booking?: string;
	bookingError?: string;
	cancel?: string;
	close?: string;
	emailRequired?: string;
	message?: string;
	messageOptional?: string;
	messageRequired?: string;
	nameRequired?: string;
	networkError?: string;
	waitlist?: string;
	yourAppointment?: string;
	yourEmail?: string;
	yourName?: string;
}

export interface MailTemplatePost {
	id: number;
	title: {
		rendered: string;
	};
}

export interface MailTemplateOption {
	value: number;
	label: string;
}

export interface HoursOverlay {
	person: FaudirPerson;
	type: 'consultation' | 'office';
}

export interface PreviewCalendarProps {
	slots: TimeSlot[];
	selectedDates: string[];
	onRemoveSlot?: ( slot: TimeSlot ) => void;
	onAddSlot?: ( date: string ) => void;
	activeDate: string;
	setActiveDate: ( date: string ) => void;
	hideWeekends: boolean;
}

export interface EditProps {
	attributes: AppointmentAttributes;
	setAttributes: ( attributes: Partial< AppointmentAttributes > ) => void;
}

export interface ParsedSlotValue {
	date: string;
	time: string;
	endTime: string;
	value: string;
}

export interface FrontendSlot {
	value: string;
	label: string;
	time: string;
}

export type FrontendDateMap = Map< string, FrontendSlot[] >;

export interface Booker {
	bookerEmail?: string;
	bookerName?: string;
}

export interface BookingResponse {
	success: boolean;
	data?: string;
}

export interface BookerResponse {
	needsLogin?: boolean;
	loginUrl?: string;
	data?: Booker;
}

export interface BookerAjaxResponse {
	success: boolean;
	data?: Booker;
}

export interface AppointmentWindowConfig {
	ajaxUrl?: string;
	bookedSlots?: string[];
	editorI18n?: EditorI18n;
	i18n?: FrontendI18n;
	nonce?: string;
	persons?: FaudirResponse;
	recurrenceLimit?: number;
	restUrl?: string;
}

declare global {
	interface Window {
		rrze_appointment?: AppointmentWindowConfig;
	}
}
