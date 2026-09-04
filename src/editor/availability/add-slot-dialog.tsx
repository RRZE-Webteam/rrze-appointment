import {
	Button,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { formatDateDisplay } from '../../scheduling/dates';

interface AddSlotDialogProps {
	date: string;
	endTime: string;
	error: string;
	onCancel: () => void;
	onConfirm: () => void;
	onEndTimeChange: ( value: string ) => void;
	onStartTimeChange: ( value: string ) => void;
	startTime: string;
}

export function AddSlotDialog( {
	date,
	endTime,
	error,
	onCancel,
	onConfirm,
	onEndTimeChange,
	onStartTimeChange,
	startTime,
}: AddSlotDialogProps ) {
	return (
		<Modal
			title={ `${ __(
				'New time for',
				'rrze-appointment'
			) } ${ formatDateDisplay( date ) }` }
			size="small"
			onRequestClose={ onCancel }
		>
			<Flex direction="column" align="stretch" gap={ 4 }>
				<Flex align="flex-start" gap={ 4 } wrap>
					<FlexBlock>
						<TextControl
							label={ __( 'Start time', 'rrze-appointment' ) }
							type="time"
							step={ 300 }
							value={ startTime }
							onChange={ onStartTimeChange }
						/>
					</FlexBlock>
					<FlexBlock>
						<TextControl
							label={ __( 'End time', 'rrze-appointment' ) }
							type="time"
							step={ 300 }
							value={ endTime }
							onChange={ onEndTimeChange }
						/>
					</FlexBlock>
				</Flex>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<Flex justify="flex-end">
					<FlexItem>
						<Button variant="secondary" onClick={ onCancel }>
							{ __( 'Cancel', 'rrze-appointment' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						<Button variant="primary" onClick={ onConfirm }>
							{ __( 'Add', 'rrze-appointment' ) }
						</Button>
					</FlexItem>
				</Flex>
			</Flex>
		</Modal>
	);
}
