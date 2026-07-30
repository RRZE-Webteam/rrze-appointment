import {
	Button,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { AvailabilityEntry } from '../types';
import { AvailabilityDataView } from './availability-data-view';

interface AvailabilityManagerDialogProps {
	entries: AvailabilityEntry[];
	onAdd: () => void;
	onClose: () => void;
	onDelete: ( entry: AvailabilityEntry ) => void;
	onEdit: ( entry: AvailabilityEntry ) => void;
}

export function AvailabilityManagerDialog( {
	entries,
	onAdd,
	onClose,
	onDelete,
	onEdit,
}: AvailabilityManagerDialogProps ) {
	return (
		<Modal
			className="rrze-appointment-data-view__modal"
			onRequestClose={ onClose }
			size="fill"
			title={ __( 'Manage availabilities', 'rrze-appointment' ) }
		>
			<Flex
				align="center"
				className="rrze-appointment-data-view__header"
				gap={ 4 }
				justify="space-between"
				wrap
			>
				<FlexBlock>
					<p>
						{ __(
							'Add individual appointments or optionally create consultation-hour slots from a pattern.',
							'rrze-appointment'
						) }
					</p>
				</FlexBlock>
				<FlexItem>
					<Button
						icon="plus-alt2"
						variant="primary"
						onClick={ onAdd }
					>
						{ __( 'Add availability', 'rrze-appointment' ) }
					</Button>
				</FlexItem>
			</Flex>

			<AvailabilityDataView
				entries={ entries }
				onDelete={ onDelete }
				onEdit={ onEdit }
			/>
		</Modal>
	);
}
