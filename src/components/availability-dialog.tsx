import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	getAvailabilityDates,
	getAvailabilitySlotCount,
	hasAvailabilityDateConflict,
} from '../availability';
import type { AvailabilityEntry, RecurrenceFrequency } from '../types';
import { formatDate, minutesToTime, parseTimeToMinutes } from '../utils';

interface AvailabilityDialogProps {
	entries: AvailabilityEntry[];
	entry: AvailabilityEntry;
	originalDate?: string;
	onCancel: () => void;
	onSave: ( entry: AvailabilityEntry ) => void;
}

const DURATION_OPTIONS = [ 15, 30, 45, 60, 75, 90, 120 ].map( ( minutes ) => ( {
	label: `${ minutes } Minuten`,
	value: String( minutes ),
} ) );

const BREAK_OPTIONS = Array.from( { length: 12 }, ( _, index ) => {
	const minutes = index * 5;
	return {
		label: `${ minutes } Minuten`,
		value: String( minutes ),
	};
} );

function usesConsultationPattern( entry: AvailabilityEntry ): boolean {
	return getAvailabilitySlotCount( entry ) > 1 || entry.breakDuration > 0;
}

export function AvailabilityDialog( {
	entries,
	entry,
	originalDate = '',
	onCancel,
	onSave,
}: AvailabilityDialogProps ) {
	const [ draft, setDraft ] = useState< AvailabilityEntry >( entry );
	const [ error, setError ] = useState( '' );
	const [ usePattern, setUsePattern ] = useState(
		usesConsultationPattern( entry )
	);

	useEffect( () => {
		setDraft( entry );
		setError( '' );
		setUsePattern( usesConsultationPattern( entry ) );
	}, [ entry ] );

	const enablePattern = () => {
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
		setUsePattern( true );
		setError( '' );
	};

	const disablePattern = () => {
		const startMinutes = parseTimeToMinutes( draft.startTime ) || 9 * 60;
		const duration = Math.max( 15, draft.duration || 30 );
		setDraft( {
			...draft,
			endTime: minutesToTime(
				Math.min( startMinutes + duration, 23 * 60 + 45 )
			),
			duration,
			breakDuration: 0,
		} );
		setUsePattern( false );
		setError( '' );
	};

	const handleSave = () => {
		if ( ! draft.date ) {
			setError( __( 'Please select a date.', 'rrze-appointment' ) );
			return;
		}
		const today = formatDate( new Date() );
		if ( draft.date < today && draft.date !== originalDate ) {
			setError(
				__( 'The date must not be in the past.', 'rrze-appointment' )
			);
			return;
		}

		const startMinutes = parseTimeToMinutes( draft.startTime );
		const endMinutes = parseTimeToMinutes( draft.endTime );
		if (
			startMinutes === null ||
			endMinutes === null ||
			endMinutes <= startMinutes
		) {
			setError(
				__( 'End time must be after start time.', 'rrze-appointment' )
			);
			return;
		}

		const normalizedDraft = usePattern
			? draft
			: {
					...draft,
					duration: endMinutes - startMinutes,
					breakDuration: 0,
			  };
		if (
			normalizedDraft.duration <= 0 ||
			normalizedDraft.duration % 15 !== 0
		) {
			setError(
				__(
					'The appointment duration must be divisible by 15 minutes.',
					'rrze-appointment'
				)
			);
			return;
		}
		if ( getAvailabilitySlotCount( normalizedDraft ) === 0 ) {
			setError(
				__(
					'The selected pattern does not create a complete slot.',
					'rrze-appointment'
				)
			);
			return;
		}
		if (
			normalizedDraft.recurrence.until &&
			normalizedDraft.recurrence.until < normalizedDraft.date
		) {
			setError(
				__(
					'The recurrence end must not be before the start date.',
					'rrze-appointment'
				)
			);
			return;
		}
		if (
			hasAvailabilityDateConflict(
				entries,
				normalizedDraft,
				originalDate
			)
		) {
			setError(
				__(
					'An availability is already configured for at least one of these dates.',
					'rrze-appointment'
				)
			);
			return;
		}

		onSave( normalizedDraft );
	};

	const isRepeating = !! draft.recurrence.freq;
	const title = originalDate
		? __( 'Edit availability', 'rrze-appointment' )
		: __( 'Add availability', 'rrze-appointment' );

	return (
		<Modal
			className="rrze-appointment-block__availability-dialog"
			title={ title }
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
							setDraft( { ...draft, date } );
							setError( '' );
						} }
					/>
				</FlexItem>

				{ ! usePattern && (
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
								onClick={ enablePattern }
							>
								{ __(
									'Fill consultation hours from pattern',
									'rrze-appointment'
								) }
							</Button>
						</FlexItem>
					</>
				) }

				{ usePattern && (
					<Card size="small">
						<CardHeader>
							<Flex>
								<FlexBlock>
									<strong>
										{ __(
											'Consultation hours pattern',
											'rrze-appointment'
										) }
									</strong>
								</FlexBlock>
								<FlexItem>
									<Button
										variant="link"
										onClick={ disablePattern }
									>
										{ __(
											'Use individual appointment',
											'rrze-appointment'
										) }
									</Button>
								</FlexItem>
							</Flex>
						</CardHeader>
						<CardBody>
							<Flex direction="column" align="stretch" gap={ 3 }>
								<Flex gap={ 4 } align="flex-start" wrap>
									<FlexBlock>
										<TextControl
											label={ __(
												'From',
												'rrze-appointment'
											) }
											type="time"
											step={ 900 }
											value={ draft.startTime }
											onChange={ ( startTime ) => {
												setDraft( {
													...draft,
													startTime,
												} );
												setError( '' );
											} }
										/>
									</FlexBlock>
									<FlexBlock>
										<TextControl
											label={ __(
												'To',
												'rrze-appointment'
											) }
											type="time"
											step={ 900 }
											value={ draft.endTime }
											onChange={ ( endTime ) => {
												setDraft( {
													...draft,
													endTime,
												} );
												setError( '' );
											} }
										/>
									</FlexBlock>
								</Flex>
								<Flex gap={ 4 } align="flex-start" wrap>
									<FlexBlock>
										<SelectControl
											label={ __(
												'Slot duration',
												'rrze-appointment'
											) }
											value={ String( draft.duration ) }
											options={ DURATION_OPTIONS }
											onChange={ ( duration ) =>
												setDraft( {
													...draft,
													duration:
														Number( duration ),
												} )
											}
										/>
									</FlexBlock>
									<FlexBlock>
										<SelectControl
											label={ __(
												'Break between slots',
												'rrze-appointment'
											) }
											value={ String(
												draft.breakDuration
											) }
											options={ BREAK_OPTIONS }
											onChange={ ( breakDuration ) =>
												setDraft( {
													...draft,
													breakDuration:
														Number( breakDuration ),
												} )
											}
										/>
									</FlexBlock>
								</Flex>
								<Notice status="info" isDismissible={ false }>
									{ getAvailabilitySlotCount( draft ) }{ ' ' }
									{ __( 'Slots', 'rrze-appointment' ) }
								</Notice>
							</Flex>
						</CardBody>
					</Card>
				) }

				<FlexItem>
					<ToggleControl
						label={ __( 'Repeat', 'rrze-appointment' ) }
						checked={ isRepeating }
						onChange={ ( repeats ) =>
							setDraft( {
								...draft,
								recurrence: repeats
									? {
											freq: 'weekly',
											anchor: draft.date,
									  }
									: {},
							} )
						}
					/>
				</FlexItem>
				{ isRepeating && (
					<Flex gap={ 4 } align="flex-start" wrap>
						<FlexBlock>
							<SelectControl
								label={ __( 'Recurrence', 'rrze-appointment' ) }
								value={ draft.recurrence.freq || 'weekly' }
								options={ [
									{
										label: __(
											'Daily',
											'rrze-appointment'
										),
										value: 'daily',
									},
									{
										label: __(
											'Weekly',
											'rrze-appointment'
										),
										value: 'weekly',
									},
									{
										label: __(
											'Monthly',
											'rrze-appointment'
										),
										value: 'monthly',
									},
								] }
								onChange={ ( frequency ) =>
									setDraft( {
										...draft,
										recurrence: {
											...draft.recurrence,
											freq: frequency as RecurrenceFrequency,
											anchor: draft.date,
										},
									} )
								}
							/>
						</FlexBlock>
						<FlexBlock>
							<TextControl
								label={ __( 'Ends on', 'rrze-appointment' ) }
								type="date"
								value={ draft.recurrence.until || '' }
								help={ `${
									getAvailabilityDates( draft ).length
								} ${ __(
									'Occurrences',
									'rrze-appointment'
								) }` }
								onChange={ ( until ) =>
									setDraft( {
										...draft,
										recurrence: {
											...draft.recurrence,
											anchor: draft.date,
											until,
										},
									} )
								}
							/>
						</FlexBlock>
					</Flex>
				) }

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
							{ __( 'Save', 'rrze-appointment' ) }
						</Button>
					</FlexItem>
				</Flex>
			</Flex>
		</Modal>
	);
}
