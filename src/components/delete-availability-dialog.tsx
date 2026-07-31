import { Button, Flex, FlexItem, Modal, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getAvailabilityDates } from '../availability';
import type { AvailabilityEntry } from '../types';
import { formatDateDisplay } from '../utils';

interface DeleteAvailabilityDialogProps {
	entry: AvailabilityEntry;
	onCancel: () => void;
	onConfirm: () => void;
}

export function DeleteAvailabilityDialog( {
	entry,
	onCancel,
	onConfirm,
}: DeleteAvailabilityDialogProps ) {
	const occurrenceCount = getAvailabilityDates( entry ).length;
	/* translators: %s: first date of the appointment schedule. */
	const deleteQuestion = __(
		'Delete the appointment times starting on %s?',
		'rrze-appointment'
	).replace( '%s', formatDateDisplay( entry.date ) );
	/* translators: %d: number of occurrences in the recurrence series. */
	const recurrenceWarning = __(
		'This also removes all %d occurrences in the series.',
		'rrze-appointment'
	).replace( '%d', String( occurrenceCount ) );

	return (
		<Modal
			title={ __( 'Delete appointment times', 'rrze-appointment' ) }
			size="small"
			role="alertdialog"
			onRequestClose={ onCancel }
		>
			<p>{ deleteQuestion }</p>
			{ occurrenceCount > 1 && (
				<Notice status="warning" isDismissible={ false }>
					{ recurrenceWarning }
				</Notice>
			) }
			<Flex justify="flex-end">
				<FlexItem>
					<Button variant="secondary" onClick={ onCancel }>
						{ __( 'Cancel', 'rrze-appointment' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						isDestructive
						onClick={ onConfirm }
					>
						{ __( 'Delete', 'rrze-appointment' ) }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
}
