import {
	Button,
	Placeholder,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import emptyBlockIllustration from '../../assets/images/dream-3.png';
import { scheduleIcon } from './material-icons';

interface EmptyBlockPlaceholderProps {
	description: string;
	onAddTimes: () => void;
	onDescriptionChange: ( value: string ) => void;
	onTitleChange: ( value: string ) => void;
	title: string;
}

export function EmptyBlockPlaceholder( {
	description,
	onAddTimes,
	onDescriptionChange,
	onTitleChange,
	title,
}: EmptyBlockPlaceholderProps ) {
	return (
		<Placeholder
			className="rrze-appointment-block__placeholder"
			instructions={ __(
				'Add the basic information, then create your first appointment times.',
				'rrze-appointment'
			) }
			isColumnLayout
			label={ __( 'Set up appointment booking', 'rrze-appointment' ) }
			preview={
				<span className="rrze-appointment-block__placeholder-media">
					<img
						alt=""
						className="rrze-appointment-block__placeholder-illustration"
						src={ emptyBlockIllustration }
					/>
				</span>
			}
		>
			<div className="rrze-appointment-block__placeholder-fields">
				<TextControl
					label={ __( 'Appointment title', 'rrze-appointment' ) }
					placeholder={ __(
						'For example: Consultation hours',
						'rrze-appointment'
					) }
					value={ title }
					onChange={ onTitleChange }
					__nextHasNoMarginBottom
				/>
				<TextareaControl
					label={ __( 'Short description', 'rrze-appointment' ) }
					placeholder={ __(
						'Briefly explain what the appointment is about.',
						'rrze-appointment'
					) }
					rows={ 3 }
					value={ description }
					onChange={ onDescriptionChange }
					__nextHasNoMarginBottom
				/>
			</div>
			<Button
				className="rrze-appointment-block__placeholder-action"
				icon={ scheduleIcon }
				variant="primary"
				onClick={ onAddTimes }
			>
				{ __( 'Add first appointment times', 'rrze-appointment' ) }
			</Button>
		</Placeholder>
	);
}
