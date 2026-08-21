import type { AppointmentAttributes, TimeSlot } from '../scheduling/types';

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
	setActiveDate: ( date: string ) => void;
	hideWeekends: boolean;
}

export interface EditProps {
	attributes: AppointmentAttributes;
	setAttributes: ( attributes: Partial< AppointmentAttributes > ) => void;
}
