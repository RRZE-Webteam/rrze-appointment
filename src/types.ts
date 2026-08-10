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
	requireMessage: boolean;
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

export interface FaudirImportOptions {
	importContact: boolean;
	importLocation: boolean;
	importHours: boolean;
	hoursUntil: string;
}

export interface EditorI18n {
	requireMessageField?: string;
	requireMessageHelp?: string;
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
	closeDialog?: string;
	dialogIntro?: string;
	dialogTitle?: string;
	emailRequired?: string;
	message?: string;
	messageOptional?: string;
	messagePlaceholder?: string;
	messageRequired?: string;
	questionRequired?: string;
	selectOption?: string;
	namePlaceholder?: string;
	nameRequired?: string;
	networkError?: string;
	selectedAppointment?: string;
	successTitle?: string;
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
	data?:
		| string
		| {
				message?: string;
		  };
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
	faudir?: {
		available: boolean;
		personsPath?: string;
	};
	i18n?: FrontendI18n;
	nonce?: string;
	recurrenceLimit?: number;
	restUrl?: string;
}

declare global {
	interface Window {
		rrze_appointment?: AppointmentWindowConfig;
	}
}
