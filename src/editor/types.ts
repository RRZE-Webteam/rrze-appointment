import type { AppointmentAttributes, TimeSlot } from '../scheduling/types';

/** Weekly consultation range returned by the FAUdir API. */
export interface ConsultationHour {
	/** JavaScript weekday number: Sunday is 0. */
	weekday: number;
	/** Start time supplied by FAUdir. */
	from?: string;
	/** End time supplied by FAUdir. */
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
	onToggleSlotVisibility?: ( slot: TimeSlot ) => void;
	onAddSlot?: ( date: string ) => void;
	activeDate: string;
	onActiveDateChange: ( date: string ) => void;
	hideWeekends: boolean;
}

export interface AppointmentEditorProps {
	attributes: AppointmentAttributes;
	setAttributes: ( attributes: Partial< AppointmentAttributes > ) => void;
}
