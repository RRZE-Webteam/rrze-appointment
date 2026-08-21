import {
	Button,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usesAppointmentPattern } from '../../scheduling/availability';
import type { AvailabilityEntry } from '../../scheduling/types';
import { minutesToTime, parseTimeToMinutes } from '../../scheduling/time';
import {
	AppointmentPatternFields,
	usesCustomAppointmentDuration,
} from './appointment-pattern-fields';
import { RecurrenceFields } from './recurrence-fields';
import {
	validateAvailability,
	type RecurrenceEndMode,
} from './validate-availability';

interface AvailabilityDialogProps {
	existingEntries: AvailabilityEntry[];
	entry: AvailabilityEntry;
	originalEntryId?: string;
	onCancel: () => void;
	onSave: ( entry: AvailabilityEntry ) => void;
}

export function AvailabilityDialog( {
	existingEntries,
	entry,
	originalEntryId = '',
	onCancel,
	onSave,
}: AvailabilityDialogProps ) {
	const [ draft, setDraft ] = useState< AvailabilityEntry >( entry );
	const [ error, setError ] = useState( '' );
	const [ splitsRangeIntoAppointments, setSplitsRangeIntoAppointments ] =
		useState( usesAppointmentPattern( entry ) );
	const [ useCustomDuration, setUseCustomDuration ] = useState(
		usesCustomAppointmentDuration( entry )
	);
	const [ recurrenceEndMode, setRecurrenceEndMode ] =
		useState< RecurrenceEndMode >(
			entry.recurrence.count !== undefined ? 'count' : 'date'
		);
	const originalEntryDate =
		existingEntries.find(
			( currentEntry ) => currentEntry.id === originalEntryId
		)?.date || '';

	useEffect( () => {
		setDraft( entry );
		setError( '' );
		setSplitsRangeIntoAppointments( usesAppointmentPattern( entry ) );
		setUseCustomDuration( usesCustomAppointmentDuration( entry ) );
		setRecurrenceEndMode(
			entry.recurrence.count !== undefined ? 'count' : 'date'
		);
	}, [ entry ] );

	const splitRangeIntoAppointments = () => {
		const startMinutes = parseTimeToMinutes( draft.startTime ) || 9 * 60;
		const currentEndMinutes =
			parseTimeToMinutes( draft.endTime ) || startMinutes;
		const suggestedEndMinutes =
			currentEndMinutes - startMinutes > draft.duration
				? currentEndMinutes
				: Math.min( startMinutes + 8 * 60, 23 * 60 + 45 );

		setDraft( {
			...draft,
			endTime: minutesToTime( suggestedEndMinutes ),
			duration: draft.duration || 30,
			breakDuration: 0,
		} );
		setSplitsRangeIntoAppointments( true );
		setError( '' );
	};

	const useSingleAppointment = () => {
		const startMinutes = parseTimeToMinutes( draft.startTime ) || 9 * 60;
		const duration = Math.max( 1, draft.duration || 30 );
		setDraft( {
			...draft,
			endTime: minutesToTime(
				Math.min( startMinutes + duration, 23 * 60 + 45 )
			),
			duration,
			breakDuration: 0,
		} );
		setSplitsRangeIntoAppointments( false );
		setError( '' );
	};

	const handleSave = () => {
		const validationResult = validateAvailability( {
			draft,
			existingEntries,
			originalEntryDate,
			originalEntryId,
			recurrenceEndMode,
			splitsRangeIntoAppointments,
		} );
		if ( ! validationResult.entry ) {
			setError( validationResult.error );
			return;
		}
		onSave( validationResult.entry );
	};

	const dialogTitle = originalEntryId
		? __( 'Edit appointment times', 'rrze-appointment' )
		: __( 'Add appointment times', 'rrze-appointment' );

	return (
		<Modal
			className="rrze-appointment-block__availability-dialog"
			title={ dialogTitle }
			size="medium"
			onRequestClose={ onCancel }
		>
			<Flex direction="column" align="stretch" gap={ 4 }>
				<FlexItem>
					<TextControl
						label={ __( 'Date', 'rrze-appointment' ) }
						type="date"
						value={ draft.date }
						onChange={ ( date ) => {
							setDraft( {
								...draft,
								date,
								recurrence: draft.recurrence.freq
									? {
											...draft.recurrence,
											anchor: date,
									  }
									: draft.recurrence,
							} );
							setError( '' );
						} }
					/>
				</FlexItem>

				{ ! splitsRangeIntoAppointments && (
					<>
						<Flex gap={ 4 } align="flex-start" wrap>
							<FlexBlock>
								<TextControl
									label={ __(
										'Start time',
										'rrze-appointment'
									) }
									type="time"
									step={ 900 }
									value={ draft.startTime }
									onChange={ ( startTime ) => {
										setDraft( { ...draft, startTime } );
										setError( '' );
									} }
								/>
							</FlexBlock>
							<FlexBlock>
								<TextControl
									label={ __(
										'End time',
										'rrze-appointment'
									) }
									type="time"
									step={ 900 }
									value={ draft.endTime }
									onChange={ ( endTime ) => {
										setDraft( { ...draft, endTime } );
										setError( '' );
									} }
								/>
							</FlexBlock>
						</Flex>
						<FlexItem>
							<Button
								variant="secondary"
								icon="clock"
								onClick={ splitRangeIntoAppointments }
							>
								{ __(
									'Split time range into appointments',
									'rrze-appointment'
								) }
							</Button>
						</FlexItem>
					</>
				) }

				{ splitsRangeIntoAppointments && (
					<AppointmentPatternFields
						draft={ draft }
						useCustomDuration={ useCustomDuration }
						onClearError={ () => setError( '' ) }
						onDraftChange={ setDraft }
						onUseCustomDurationChange={ setUseCustomDuration }
						onUseSingleAppointment={ useSingleAppointment }
					/>
				) }

				<RecurrenceFields
					draft={ draft }
					recurrenceEndMode={ recurrenceEndMode }
					splitsRangeIntoAppointments={ splitsRangeIntoAppointments }
					onClearError={ () => setError( '' ) }
					onDraftChange={ setDraft }
					onRecurrenceEndModeChange={ setRecurrenceEndMode }
				/>

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
						<Button variant="primary" onClick={ handleSave }>
							{ __(
								'Save appointment times',
								'rrze-appointment'
							) }
						</Button>
					</FlexItem>
				</Flex>
			</Flex>
		</Modal>
	);
}
