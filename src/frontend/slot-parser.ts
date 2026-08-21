import type { FrontendDateMap, ParsedSlotValue } from './types';

export function parseSlotValue( value: unknown ): ParsedSlotValue {
	const slotString = String( value || '' ).trim();
	const match = slotString.match(
		/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})(?:-(\d{1,2}:\d{2}))?/
	);

	return {
		date: match?.[ 1 ] || '',
		time: match?.[ 2 ] || '',
		endTime: match?.[ 3 ] || '',
		value: slotString,
	};
}

function formatSlotLabel( value: unknown ): string {
	const parsed = parseSlotValue( value );
	if ( ! parsed.time ) {
		return '';
	}
	return parsed.endTime
		? `${ parsed.time } - ${ parsed.endTime }`
		: parsed.time;
}

export function buildSlotsByDate(
	inputs: HTMLInputElement[]
): FrontendDateMap {
	const slotsByDate: FrontendDateMap = new Map();

	inputs.forEach( ( input ) => {
		const value = input.value || '';
		const parsed = parseSlotValue( value );
		if ( ! parsed.date ) {
			return;
		}

		const label =
			input.dataset.label?.trim() ||
			input.closest( 'button' )?.textContent?.trim() ||
			input
				.closest( 'label' )
				?.querySelector( 'span' )
				?.textContent?.trim() ||
			formatSlotLabel( value ) ||
			value;
		const slots = slotsByDate.get( parsed.date ) || [];
		slots.push( { value, label, time: parsed.time } );
		slotsByDate.set( parsed.date, slots );
	} );

	return slotsByDate;
}
