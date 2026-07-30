import { Button, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { formatDateDisplay } from '../utils';

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
		<div className="rrze-appointment-block__overlay">
			<div className="rrze-appointment-block__overlay-box">
				<p className="rrze-appointment-block__overlay-title">
					<strong>
						{ __( 'New time for', 'rrze-appointment' ) }{ ' ' }
						{ formatDateDisplay( date ) }
					</strong>
				</p>
				<TextControl
					label={ __( 'Start time', 'rrze-appointment' ) }
					type="time"
					step={ 300 }
					value={ startTime }
					onChange={ onStartTimeChange }
				/>
				<TextControl
					label={ __( 'End time', 'rrze-appointment' ) }
					type="time"
					step={ 300 }
					value={ endTime }
					onChange={ onEndTimeChange }
				/>
				{ error && (
					<p className="rrze-appointment-block__overlay-error">
						{ error }
					</p>
				) }
				<div className="rrze-appointment-block__overlay-actions">
					<Button variant="primary" onClick={ onConfirm }>
						{ __( 'Add', 'rrze-appointment' ) }
					</Button>
					<Button variant="secondary" onClick={ onCancel }>
						{ __( 'Cancel', 'rrze-appointment' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}
