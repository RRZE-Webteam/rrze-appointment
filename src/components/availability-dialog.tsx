import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
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
	hasAvailabilityConflict,
} from '../availability';
import { getRecurrenceWeekdays } from '../recurrence';
import type {
	AvailabilityEntry,
	RecurrenceFrequency,
	RecurrenceWeekday,
} from '../types';
import {
	formatDate,
	minutesToTime,
	parseDateString,
	parseTimeToMinutes,
} from '../utils';

interface AvailabilityDialogProps {
	entries: AvailabilityEntry[];
	entry: AvailabilityEntry;
	originalId?: string;
	onCancel: () => void;
	onSave: ( entry: AvailabilityEntry ) => void;
}

const DURATION_OPTIONS = [ 15, 30, 45, 60, 75, 90, 120 ].map( ( minutes ) => ( {
	label: `${ minutes } min`,
	value: String( minutes ),
} ) );

const BREAK_OPTIONS = Array.from( { length: 12 }, ( _, index ) => {
	const minutes = index * 5;
	return {
		label: `${ minutes } min`,
		value: String( minutes ),
	};
} );

const WEEKDAY_OPTIONS: Array< {
	label: string;
	value: RecurrenceWeekday;
} > = [
	{ label: __( 'Monday', 'rrze-appointment' ), value: 1 },
	{ label: __( 'Tuesday', 'rrze-appointment' ), value: 2 },
	{ label: __( 'Wednesday', 'rrze-appointment' ), value: 3 },
	{ label: __( 'Thursday', 'rrze-appointment' ), value: 4 },
	{ label: __( 'Friday', 'rrze-appointment' ), value: 5 },
	{ label: __( 'Saturday', 'rrze-appointment' ), value: 6 },
	{ label: __( 'Sunday', 'rrze-appointment' ), value: 0 },
];

function getAnchorWeekday( date: string ): RecurrenceWeekday {
	return ( parseDateString( date )?.getDay() || 0 ) as RecurrenceWeekday;
}

function usesConsultationPattern( entry: AvailabilityEntry ): boolean {
	return getAvailabilitySlotCount( entry ) > 1 || entry.breakDuration > 0;
}

export function AvailabilityDialog( {
	entries,
	entry,
	originalId = '',
	onCancel,
	onSave,
}: AvailabilityDialogProps ) {
	const [ draft, setDraft ] = useState< AvailabilityEntry >( entry );
	const [ error, setError ] = useState( '' );
	const [ usePattern, setUsePattern ] = useState(
		usesConsultationPattern( entry )
	);
	const originalDate =
		entries.find( ( currentEntry ) => currentEntry.id === originalId )
			?.date || '';

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
					'The selected time range is too short for one complete appointment.',
					'rrze-appointment'
				)
			);
			return;
		}
		if (
			normalizedDraft.recurrence.freq === 'weekly' &&
			getRecurrenceWeekdays(
				normalizedDraft.recurrence,
				normalizedDraft.date
			).length === 0
		) {
			setError(
				__( 'Please select at least one weekday.', 'rrze-appointment' )
			);
			return;
		}
		if (
			normalizedDraft.recurrence.until &&
			normalizedDraft.recurrence.until < normalizedDraft.date
		) {
			setError(
				__(
					'The last date must not be before the first date.',
					'rrze-appointment'
				)
			);
			return;
		}
		if ( hasAvailabilityConflict( entries, normalizedDraft, originalId ) ) {
			setError(
				__(
					'These appointment times overlap with an existing schedule on at least one date.',
					'rrze-appointment'
				)
			);
			return;
		}

		onSave( normalizedDraft );
	};

	const isRepeating = !! draft.recurrence.freq;
	const selectedWeekdays = getRecurrenceWeekdays(
		draft.recurrence,
		draft.date
	);
	const title = originalId
		? __( 'Edit appointment times', 'rrze-appointment' )
		: __( 'Add appointment times', 'rrze-appointment' );

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
									'Split time range into appointments',
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
											'Appointment pattern',
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
											'Use one appointment',
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
												'Available from',
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
												'Available until',
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
												'Appointment duration',
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
												'Break between appointments',
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
									{ __(
										'bookable appointments per date',
										'rrze-appointment'
									) }
								</Notice>
							</Flex>
						</CardBody>
					</Card>
				) }

				<FlexItem>
					<ToggleControl
						label={ __(
							'Repeat appointment times',
							'rrze-appointment'
						) }
						checked={ isRepeating }
						onChange={ ( repeats ) =>
							setDraft( {
								...draft,
								recurrence: repeats
									? {
											freq: 'weekly',
											anchor: draft.date,
											weekdays: [
												getAnchorWeekday( draft.date ),
											],
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
								label={ __( 'Frequency', 'rrze-appointment' ) }
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
								onChange={ ( frequency ) => {
									const nextFrequency =
										frequency as RecurrenceFrequency;
									const recurrence = {
										...draft.recurrence,
										freq: nextFrequency,
										anchor: draft.date,
									};
									if (
										nextFrequency === 'weekly' &&
										! Array.isArray( recurrence.weekdays )
									) {
										recurrence.weekdays = [
											getAnchorWeekday( draft.date ),
										];
									}
									if ( nextFrequency !== 'weekly' ) {
										delete recurrence.weekdays;
									}
									setDraft( { ...draft, recurrence } );
								} }
							/>
						</FlexBlock>
						<FlexBlock>
							<TextControl
								label={ __( 'Last date', 'rrze-appointment' ) }
								type="date"
								value={ draft.recurrence.until || '' }
								help={ `${
									getAvailabilityDates( draft ).length
								} ${ __( 'dates', 'rrze-appointment' ) }` }
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
				{ isRepeating && draft.recurrence.freq === 'weekly' && (
					<Card size="small">
						<CardHeader>
							<strong>
								{ __( 'Repeat on', 'rrze-appointment' ) }
							</strong>
						</CardHeader>
						<CardBody>
							<Flex align="flex-start" gap={ 4 } wrap>
								{ WEEKDAY_OPTIONS.map( ( weekday ) => (
									<FlexItem key={ weekday.value }>
										<CheckboxControl
											label={ weekday.label }
											checked={ selectedWeekdays.includes(
												weekday.value
											) }
											onChange={ ( selected ) => {
												const weekdays = selected
													? [
															...selectedWeekdays,
															weekday.value,
													  ]
													: selectedWeekdays.filter(
															( value ) =>
																value !==
																weekday.value
													  );
												setDraft( {
													...draft,
													recurrence: {
														...draft.recurrence,
														anchor: draft.date,
														weekdays,
													},
												} );
												setError( '' );
											} }
										/>
									</FlexItem>
								) ) }
							</Flex>
						</CardBody>
					</Card>
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
