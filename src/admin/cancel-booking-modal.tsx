import { Button, Modal, Notice, TextareaControl } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { cancelBooking, isAccessError, requestErrorMessage } from './api';
import type { AdminBooking, AdminConfig } from './types';

interface CancelBookingModalProps {
	booking: AdminBooking;
	config: AdminConfig;
	showReason: boolean;
	onClose: () => void;
	onSuccess: ( message: string ) => void;
	onAccessError: ( message: string ) => void;
}

export function CancelBookingModal( {
	booking,
	config,
	showReason,
	onClose,
	onSuccess,
	onAccessError,
}: CancelBookingModalProps ) {
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const pending = useRef( false );

	async function submit(): Promise< void > {
		if ( pending.current ) {
			return;
		}
		pending.current = true;
		setSubmitting( true );
		setError( '' );
		try {
			const response = await cancelBooking(
				config,
				booking.id,
				showReason ? reason : ''
			);
			onSuccess( response.message );
		} catch ( requestError ) {
			const message = requestErrorMessage( requestError );
			if ( isAccessError( requestError ) ) {
				onAccessError( message );
			} else {
				setError( message );
			}
		} finally {
			pending.current = false;
			setSubmitting( false );
		}
	}

	return (
		<Modal
			title={ __( 'Cancel booking', 'rrze-appointment' ) }
			className="rrze-appointment-admin-cancel"
			onRequestClose={ () => {
				if ( ! pending.current ) {
					onClose();
				}
			} }
			isDismissible={ ! submitting }
			shouldCloseOnEsc={ ! submitting }
			shouldCloseOnClickOutside={ ! submitting }
		>
			<form
				onSubmit={ ( event ) => {
					event.preventDefault();
					void submit();
				} }
				aria-busy={ submitting }
			>
				<p>
					<strong>{ booking.title }</strong>
					<br />
					{ booking.dateLabel },{ ' ' }
					{ booking.time.replace( '-', ' – ' ) }
				</p>
				<p>
					{ __( 'Really cancel this booking?', 'rrze-appointment' ) }
				</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ showReason && (
					<TextareaControl
						label={
							__(
								'Reason for cancellation',
								'rrze-appointment'
							) +
							' ' +
							__( '(optional)', 'rrze-appointment' )
						}
						help={ __(
							'This reason will be included in the cancellation email.',
							'rrze-appointment'
						) }
						value={ reason }
						onChange={ setReason }
						maxLength={ 2000 }
						disabled={ submitting }
						__nextHasNoMarginBottom
					/>
				) }
				<div className="rrze-appointment-admin-cancel__actions">
					<Button
						variant="tertiary"
						onClick={ onClose }
						disabled={ submitting }
					>
						{ __( 'Keep booking', 'rrze-appointment' ) }
					</Button>
					<Button
						type="submit"
						variant="primary"
						isDestructive
						isBusy={ submitting }
						disabled={ submitting }
					>
						{ __( 'Cancel booking', 'rrze-appointment' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
