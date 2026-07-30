import { Button } from '@wordpress/components';
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
		<div className="rrze-appointment-block__overlay">
			<div className="rrze-appointment-block__overlay-box">
				<p className="rrze-appointment-block__overlay-text">
					{ message }
				</p>
				<div className="rrze-appointment-block__overlay-actions">
					<Button variant="primary" onClick={ onConfirm }>
						{ __( 'Yes', 'rrze-appointment' ) }
					</Button>
					<Button variant="secondary" onClick={ onCancel }>
						{ __( 'No', 'rrze-appointment' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}
