import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type {
	GroupedSlotsAccordionProps,
	SlotAccordionHandlers,
	TimeSlot,
} from './types';
import { formatDateDisplay, groupSlotsByDate } from './utils';

export function renderGroupedSlotsAccordion(
	slots: TimeSlot[],
	name: string,
	{ onRemoveSlot, onAddSlot }: SlotAccordionHandlers = {}
) {
	const groups = groupSlotsByDate( slots );

	if ( onRemoveSlot !== undefined || onAddSlot !== undefined ) {
		return (
			<GroupedSlotsAccordion
				slots={ slots }
				name={ name }
				onRemoveSlot={ onRemoveSlot }
				onAddSlot={ onAddSlot }
			/>
		);
	}

	// Static version for save.js (no hooks)
	return (
		<div
			className="rrze-appointment__accordion rrze-appointment__slots-grouped"
			data-accordion="open"
		>
			<button
				type="button"
				className="rrze-appointment__accordion-toggle"
				aria-expanded="true"
			>
				{ __( 'All appointments', 'rrze-appointment' ) }
			</button>
			<div className="rrze-appointment__accordion-content">
				{ Object.entries( groups ).map(
					( [ date, dateSlots ], index ) => (
						<div
							className="rrze-appointment__date-group"
							key={ date }
							data-accordion={ index === 0 ? 'open' : 'closed' }
						>
							<button
								type="button"
								className="rrze-appointment__date-group-toggle"
								aria-expanded={ index === 0 ? 'true' : 'false' }
							>
								{ formatDateDisplay( date ) }
							</button>
							<div
								className="rrze-appointment__slot-grid"
								data-date={ date }
							>
								{ dateSlots.map( ( slot ) => (
									<div
										className="rrze-appointment__slot-item"
										key={ slot.value }
									>
										<input
											className="rrze-appointment__slot-radio"
											type="radio"
											name={ name }
											value={ slot.value }
											data-label={ slot.timeRange }
											required
										/>
										<button
											type="button"
											className="rrze-appointment__slot-button"
											data-slot-value={ slot.value }
										>
											{ slot.timeRange }
										</button>
									</div>
								) ) }
							</div>
						</div>
					)
				) }
			</div>
		</div>
	);
}

function GroupedSlotsAccordion( {
	slots,
	name,
	onRemoveSlot,
	onAddSlot,
}: GroupedSlotsAccordionProps ) {
	const groups = groupSlotsByDate( slots );
	const [ outerOpen, setOuterOpen ] = useState( true );
	const firstDate = Object.keys( groups )[ 0 ];
	const [ openDates, setOpenDates ] = useState(
		() => new Set( firstDate ? [ firstDate ] : [] )
	);

	return (
		<div
			className="rrze-appointment__accordion rrze-appointment__slots-grouped"
			data-accordion={ outerOpen ? 'open' : 'closed' }
		>
			<button
				type="button"
				className="rrze-appointment__accordion-toggle"
				aria-expanded={ outerOpen ? 'true' : 'false' }
				onClick={ () => setOuterOpen( ( open ) => ! open ) }
			>
				{ __( 'All appointments', 'rrze-appointment' ) }
			</button>
			<div className="rrze-appointment__accordion-content">
				{ Object.entries( groups ).map( ( [ date, dateSlots ] ) => (
					<div
						className="rrze-appointment__date-group"
						key={ date }
						data-accordion={
							openDates.has( date ) ? 'open' : 'closed'
						}
					>
						<button
							type="button"
							className="rrze-appointment__date-group-toggle"
							aria-expanded={
								openDates.has( date ) ? 'true' : 'false'
							}
							onClick={ () =>
								setOpenDates( ( previousDates ) => {
									const nextDates = new Set( previousDates );
									if ( nextDates.has( date ) ) {
										nextDates.delete( date );
									} else {
										nextDates.add( date );
									}
									return nextDates;
								} )
							}
						>
							{ formatDateDisplay( date ) }
						</button>
						<div
							className="rrze-appointment__slot-grid"
							data-date={ date }
						>
							{ dateSlots.map( ( slot ) => (
								<div
									className="rrze-appointment__slot-item"
									key={ slot.value }
								>
									<input
										className="rrze-appointment__slot-radio"
										type="radio"
										name={ name }
										value={ slot.value }
										data-label={ slot.timeRange }
										required
									/>
									<button
										type="button"
										className="rrze-appointment__slot-button"
										data-slot-value={ slot.value }
									>
										{ slot.timeRange }
									</button>
									{ onRemoveSlot && (
										<button
											type="button"
											className="rrze-appointment__slot-delete"
											aria-label={ `Uhrzeit ${ slot.timeRange } löschen` }
											onClick={ () =>
												onRemoveSlot( slot )
											}
										>
											×
										</button>
									) }
								</div>
							) ) }
							{ onAddSlot && (
								<button
									type="button"
									className="rrze-appointment__slot-add"
									aria-label={ `Uhrzeit am ${ formatDateDisplay(
										date
									) } hinzufügen` }
									onClick={ () => onAddSlot( date ) }
								>
									+
								</button>
							) }
						</div>
					</div>
				) ) }
			</div>
		</div>
	);
}
