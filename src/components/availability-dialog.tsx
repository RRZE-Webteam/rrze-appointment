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
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	getAvailabilityAppointmentCount,
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
	formatDateDisplay,
	MAX_RECURRENCE_DATES,
	minutesToTime,
	parseDateString,
	parseTimeToMinutes,
	recurrenceExceedsLimit,
} from '../utils';

interface AvailabilityDialogProps {
	entries: AvailabilityEntry[];
	entry: AvailabilityEntry;
	originalId?: string;
	onCancel: () => void;
	onSave: ( entry: AvailabilityEntry ) => void;
}

type RecurrenceEndMode = 'date' | 'count';

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
	const [ recurrenceEndMode, setRecurrenceEndMode ] =
		useState< RecurrenceEndMode >(
			entry.recurrence.count !== undefined ? 'count' : 'date'
		);
	const originalDate =
		entries.find( ( currentEntry ) => currentEntry.id === originalId )
			?.date || '';

	useEffect( () => {
		setDraft( entry );
		setError( '' );
		setUsePattern( usesConsultationPattern( entry ) );
		setRecurrenceEndMode(
			entry.recurrence.count !== undefined ? 'count' : 'date'
		);
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
		const duration = Math.max( 1, draft.duration || 30 );
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
			! Number.isInteger( normalizedDraft.duration ) ||
			normalizedDraft.duration <= 0
		) {
			setError(
				__(
					'The appointment duration must be a positive whole number of minutes.',
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
		if ( normalizedDraft.recurrence.freq ) {
			if ( recurrenceEndMode === 'date' ) {
				if ( ! normalizedDraft.recurrence.until ) {
					setError(
						__(
							'Choose the date on which the series ends.',
							'rrze-appointment'
						)
					);
					return;
				}
				if ( normalizedDraft.recurrence.until < normalizedDraft.date ) {
					setError(
						__(
							'The last date must not be before the first date.',
							'rrze-appointment'
						)
					);
					return;
				}
			} else {
				const recurrenceCount = Number(
					normalizedDraft.recurrence.count
				);
				if (
					! Number.isInteger( recurrenceCount ) ||
					recurrenceCount < 1
				) {
					setError(
						__(
							'Enter how many appointment dates the series should contain.',
							'rrze-appointment'
						)
					);
					return;
				}
			}
			if (
				recurrenceExceedsLimit(
					normalizedDraft.recurrence,
					normalizedDraft.date
				)
			) {
				setError(
					sprintf(
						/* translators: %d: maximum number of dates in a recurrence series. */
						__(
							'A series can contain up to %d appointment dates. Choose an earlier end date or a smaller number.',
							'rrze-appointment'
						),
						MAX_RECURRENCE_DATES
					)
				);
				return;
			}
			if ( getAvailabilityDates( normalizedDraft ).length === 0 ) {
				setError(
					__(
						'The selected weekdays do not create an appointment within this date range.',
						'rrze-appointment'
					)
				);
				return;
			}
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
	const recurrenceCount = Number( draft.recurrence.count );
	const recurrenceEndBeforeStart =
		recurrenceEndMode === 'date' &&
		Boolean( draft.recurrence.until ) &&
		( draft.recurrence.until || '' ) < draft.date;
	const hasExplicitRecurrenceEnd =
		recurrenceEndMode === 'date'
			? Boolean( draft.recurrence.until ) && ! recurrenceEndBeforeStart
			: Number.isInteger( recurrenceCount ) && recurrenceCount > 0;
	const recurrenceLimitExceeded =
		isRepeating &&
		hasExplicitRecurrenceEnd &&
		recurrenceExceedsLimit( draft.recurrence, draft.date );
	const recurrenceDates =
		isRepeating && hasExplicitRecurrenceEnd && ! recurrenceLimitExceeded
			? getAvailabilityDates( draft )
			: [];
	const lastRecurrenceDate =
		recurrenceDates[ recurrenceDates.length - 1 ] || '';
	const totalAppointmentCount = usePattern
		? getAvailabilityAppointmentCount( draft )
		: recurrenceDates.length;
	const recurrenceSummary =
		recurrenceDates.length > 0 && lastRecurrenceDate
			? sprintf(
					/* translators: 1: number of appointment dates, 2: last date. */
					_n(
						'Creates %1$d appointment date through %2$s.',
						'Creates %1$d appointment dates through %2$s.',
						recurrenceDates.length,
						'rrze-appointment'
					),
					recurrenceDates.length,
					formatDateDisplay( lastRecurrenceDate )
			  )
			: '';
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
										<TextControl
											label={ __(
												'Appointment duration',
												'rrze-appointment'
											) }
											type="number"
											min="1"
											step={ 1 }
											value={ String( draft.duration ) }
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
						onChange={ ( repeats ) => {
							setRecurrenceEndMode( 'date' );
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
							} );
							setError( '' );
						} }
					/>
				</FlexItem>
				{ isRepeating && (
					<Flex direction="column" align="stretch" gap={ 4 }>
						<FlexItem>
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
									setError( '' );
								} }
							/>
						</FlexItem>

						{ draft.recurrence.freq === 'weekly' && (
							<Card size="small">
								<CardHeader>
									<strong>
										{ __(
											'Repeat on',
											'rrze-appointment'
										) }
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
														const weekdays =
															selected
																? [
																		...selectedWeekdays,
																		weekday.value,
																  ]
																: selectedWeekdays.filter(
																		(
																			value
																		) =>
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

						<Card size="small">
							<CardHeader>
								<strong>
									{ __( 'Series ends', 'rrze-appointment' ) }
								</strong>
							</CardHeader>
							<CardBody>
								<Flex
									direction="column"
									align="stretch"
									gap={ 3 }
								>
									<SelectControl
										label={ __(
											'Choose how this series ends',
											'rrze-appointment'
										) }
										value={ recurrenceEndMode }
										options={ [
											{
												label: __(
													'On a date',
													'rrze-appointment'
												),
												value: 'date',
											},
											{
												label: __(
													'After a number of appointment dates',
													'rrze-appointment'
												),
												value: 'count',
											},
										] }
										onChange={ ( value ) => {
											const nextMode =
												value as RecurrenceEndMode;
											const {
												count,
												until,
												...recurrence
											} = draft.recurrence;
											setRecurrenceEndMode( nextMode );
											setDraft( {
												...draft,
												recurrence:
													nextMode === 'count'
														? {
																...recurrence,
																count:
																	count || 12,
														  }
														: {
																...recurrence,
																...( until
																	? {
																			until,
																	  }
																	: {} ),
														  },
											} );
											setError( '' );
										} }
									/>

									{ recurrenceEndMode === 'date' ? (
										<TextControl
											label={ __(
												'Last date',
												'rrze-appointment'
											) }
											type="date"
											value={
												draft.recurrence.until || ''
											}
											onChange={ ( until ) => {
												const { count, ...recurrence } =
													draft.recurrence;
												setDraft( {
													...draft,
													recurrence: {
														...recurrence,
														anchor: draft.date,
														until,
													},
												} );
												setError( '' );
											} }
										/>
									) : (
										<TextControl
											label={ __(
												'Number of appointment dates',
												'rrze-appointment'
											) }
											type="number"
											help={ sprintf(
												/* translators: %d: maximum number of dates in a recurrence series. */
												__(
													'Enter a number from 1 to %d.',
													'rrze-appointment'
												),
												MAX_RECURRENCE_DATES
											) }
											value={
												draft.recurrence.count ===
												undefined
													? ''
													: String(
															draft.recurrence
																.count
													  )
											}
											onChange={ ( value ) => {
												const recurrence = {
													...draft.recurrence,
												};
												delete recurrence.until;
												delete recurrence.count;
												const count = Number( value );
												setDraft( {
													...draft,
													recurrence: {
														...recurrence,
														anchor: draft.date,
														...( value !==
															undefined &&
														value !== '' &&
														Number.isFinite( count )
															? { count }
															: {} ),
													},
												} );
												setError( '' );
											} }
										/>
									) }

									{ recurrenceEndBeforeStart && (
										<Notice
											status="error"
											isDismissible={ false }
										>
											{ __(
												'The last date must not be before the first date.',
												'rrze-appointment'
											) }
										</Notice>
									) }
									{ ! recurrenceEndBeforeStart &&
										recurrenceLimitExceeded && (
											<Notice
												status="error"
												isDismissible={ false }
											>
												{ sprintf(
													/* translators: %d: maximum number of dates in a recurrence series. */
													__(
														'A series can contain up to %d appointment dates. Choose an earlier end date or a smaller number.',
														'rrze-appointment'
													),
													MAX_RECURRENCE_DATES
												) }
											</Notice>
										) }
									{ ! recurrenceEndBeforeStart &&
										! recurrenceLimitExceeded &&
										recurrenceSummary && (
											<Notice
												status="info"
												isDismissible={ false }
											>
												{ recurrenceSummary }
												<br />
												<strong>
													{ sprintf(
														/* translators: %d: total number of bookable appointments generated by the schedule. */
														__(
															'Total bookable appointments: %d',
															'rrze-appointment'
														),
														totalAppointmentCount
													) }
												</strong>
											</Notice>
										) }
								</Flex>
							</CardBody>
						</Card>
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
