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
import type { AvailabilityEntry, TimeSlot } from '../types';
import { AppointmentDataView } from './appointment-data-view';
import { AvailabilityDataView } from './availability-data-view';

interface AvailabilityManagerDialogProps {
	entries: AvailabilityEntry[];
	slots: TimeSlot[];
	onAdd: () => void;
	onClose: () => void;
	onDelete: ( entry: AvailabilityEntry ) => void;
	onEdit: ( entry: AvailabilityEntry ) => void;
	onToggleException: ( slot: TimeSlot ) => void;
}

export function AvailabilityManagerDialog( {
	entries,
	slots,
	onAdd,
	onClose,
	onDelete,
	onEdit,
	onToggleException,
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
					onToggleException={ onToggleException }
				/>
			) }
		</Modal>
	);
}
