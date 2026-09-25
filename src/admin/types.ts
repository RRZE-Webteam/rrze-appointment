export type BookingView = 'current' | 'past';

export interface AdminBooking {
	id: string;
	date: string;
	dateLabel: string;
	time: string;
	title: string;
	personName: string;
	bookerName: string;
	bookerEmail: string;
	anonymized: boolean;
}

export interface BookingsResponse {
	items: AdminBooking[];
	view: BookingView;
	hasPastView: boolean;
	sensitiveMode: boolean;
	cancellationReasonEnabled: boolean;
}

export interface AdminConfig {
	url: string;
	cancelUrl: string;
	nonce: string;
}

declare global {
	interface Window {
		rrzeAppointmentAdmin?: AdminConfig;
	}
}
