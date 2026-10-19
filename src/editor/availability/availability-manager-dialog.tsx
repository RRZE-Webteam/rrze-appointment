import {
	Button,
	ButtonGroup,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import type { AvailabilityEntry, TimeSlot } from '../../scheduling/types';
import { AppointmentDataView } from './appointment-data-view';
import { AvailabilityDataView } from './availability-data-view';

interface AvailabilityManagerDialogProps {
	entries: AvailabilityEntry[];
	slots: TimeSlot[];
	onAdd: () => void;
	onClose: () => void;
	onDelete: ( entry: AvailabilityEntry ) => void;
	onEdit: ( entry: AvailabilityEntry ) => void;
	onSetDateExcluded: ( date: string, excluded: boolean ) => void;
	onToggleSlotExclusion: ( slot: TimeSlot ) => void;
	onRemoveSlot: ( slot: TimeSlot ) => void;
}

/**
 * Renders appointment management controls.
 *
 * @param root0                       Component properties.
 * @param root0.entries               Configured appointment schedules.
 * @param root0.slots                 Generated and manual appointments.
 * @param root0.onAdd                 Opens schedule creation.
 * @param root0.onClose               Closes the manager.
 * @param root0.onDelete              Requests schedule deletion.
 * @param root0.onEdit                Opens a schedule for editing.
 * @param root0.onSetDateExcluded     Updates visibility for a whole date.
 * @param root0.onToggleSlotExclusion Toggles a single appointment exception.
 * @param root0.onRemoveSlot          Removes a manually added appointment.
 * @return Appointment management interface.
 */
export function AvailabilityManagerDialog( {
	entries,
	slots,
	onAdd,
	onClose,
	onDelete,
	onEdit,
	onSetDateExcluded,
	onToggleSlotExclusion,
	onRemoveSlot,
}: AvailabilityManagerDialogProps ) {
	const [ activeView, setActiveView ] = useState<
		'schedules' | 'appointments'
	>( 'schedules' );

	return (
		<Modal
			className="rrze-appointment-data-view__modal"
			onRequestClose={ onClose }
			size="fill"
			title={ __( 'Manage appointment times', 'rrze-appointment' ) }
		>
			<ButtonGroup className="rrze-appointment-data-view__navigation">
				<Button
					isPressed={ activeView === 'schedules' }
					variant="secondary"
					onClick={ () => setActiveView( 'schedules' ) }
				>
					{ __( 'Schedules', 'rrze-appointment' ) }
				</Button>
				<Button
					isPressed={ activeView === 'appointments' }
					variant="secondary"
					onClick={ () => setActiveView( 'appointments' ) }
				>
					{ __( 'All appointments', 'rrze-appointment' ) }
				</Button>
			</ButtonGroup>

			<Flex
				align="center"
				className="rrze-appointment-data-view__header"
				gap={ 4 }
				justify="space-between"
				wrap
			>
				<FlexBlock>
					{ activeView === 'schedules' ? (
						<p>
							{ __(
								'Set up one-time or recurring appointment times. A time range can be split into several bookable appointments.',
								'rrze-appointment'
							) }
						</p>
					) : (
						<p>
							{ __(
								'Review every upcoming appointment. Add an exception when you are unavailable for a single appointment in a series.',
								'rrze-appointment'
							) }
						</p>
					) }
				</FlexBlock>
				{ activeView === 'schedules' && entries.length > 0 && (
					<FlexItem>
						<Button
							icon="plus-alt2"
							variant="primary"
							onClick={ onAdd }
						>
							{ __(
								'Add appointment times',
								'rrze-appointment'
							) }
						</Button>
					</FlexItem>
				) }
			</Flex>

			{ activeView === 'schedules' ? (
				<AvailabilityDataView
					entries={ entries }
					onAdd={ onAdd }
					onDelete={ onDelete }
					onEdit={ onEdit }
				/>
			) : (
				<AppointmentDataView
					slots={ slots }
					onAdd={ onAdd }
					onSetDateExcluded={ onSetDateExcluded }
					onToggleSlotExclusion={ onToggleSlotExclusion }
					onRemoveSlot={ onRemoveSlot }
				/>
			) }
		</Modal>
	);
}
