import { Button, Flex, FlexItem, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { HoursOverlay } from '../types';

interface HoursImportDialogProps {
	hoursOverlay: HoursOverlay;
	onCancel: () => void;
	onConfirm: () => void;
}

export function HoursImportDialog( {
	hoursOverlay,
	onCancel,
	onConfirm,
}: HoursImportDialogProps ) {
	const message =
		hoursOverlay.type === 'consultation'
			? __(
					'Consultation hours found in FAUdir. Should the appointments be created accordingly?',
					'rrze-appointment'
			  )
			: __(
					'No consultation hours found in FAUdir, but office hours. Should the appointments be created from those?',
					'rrze-appointment'
			  );

	return (
		<Modal
			title={ __( 'Import consultation hours', 'rrze-appointment' ) }
			size="small"
			onRequestClose={ onCancel }
		>
			<p>{ message }</p>
			<Flex justify="flex-end">
				<FlexItem>
					<Button variant="secondary" onClick={ onCancel }>
						{ __( 'No', 'rrze-appointment' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button variant="primary" onClick={ onConfirm }>
						{ __( 'Yes', 'rrze-appointment' ) }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
}
