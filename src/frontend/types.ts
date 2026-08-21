/** Parsed representation of `YYYY-MM-DD HH:MM-HH:MM`. */
export interface ParsedSlotValue {
	date: string;
	time: string;
	endTime: string;
	value: string;
}

export interface FrontendSlot {
	/** Serialized slot value submitted to the booking endpoint. */
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
				redirectUrl?: string;
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
