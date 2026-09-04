export interface EditorI18n {
	hideWeekendsField?: string;
	hideWeekendsHelp?: string;
}

export interface FrontendI18n {
	available?: string;
	availableOn?: string;
	appointmentsOn?: string;
	bookingAdvanceDay?: string;
	bookingAdvanceDays?: string;
	book?: string;
	booked?: string;
	booking?: string;
	bookingError?: string;
	cancel?: string;
	close?: string;
	closeDialog?: string;
	chooseDate?: string;
	dialogIntro?: string;
	dialogTitle?: string;
	emailRequired?: string;
	namePlaceholder?: string;
	nameRequired?: string;
	networkError?: string;
	nextMonth?: string;
	noSlotsAvailable?: string;
	notifyButton?: string;
	notifyDialogIntro?: string;
	notifyDialogTitle?: string;
	notifySending?: string;
	notifySuccess?: string;
	notifySuccessTitle?: string;
	previousMonth?: string;
	required?: string;
	selectedAppointment?: string;
	selected?: string;
	successTitle?: string;
	today?: string;
	unavailable?: string;
	notOpen?: string;
	bookingDetailsLoading?: string;
	waitlist?: string;
	yourAppointment?: string;
	yourEmail?: string;
	yourName?: string;
}

/** Configuration injected by PHP before the editor and frontend bundles run. */
export interface AppointmentWindowConfig {
	ajaxUrl?: string;
	bookedSlots?: string[];
	editorI18n?: EditorI18n;
	faudir?: {
		available: boolean;
		personsPath?: string;
	};
	i18n?: FrontendI18n;
	locale?: string;
	nonce?: string;
	recurrenceLimit?: number;
	restUrl?: string;
}

declare global {
	interface Window {
		rrze_appointment?: AppointmentWindowConfig;
	}
}
