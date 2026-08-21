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
	usesConsultationPattern,
} from '../../scheduling/availability';
import {
	getRecurrenceWeekdays,
	MAX_RECURRENCE_DATES,
	recurrenceExceedsLimit,
} from '../../scheduling/recurrence';
import type {
	AvailabilityEntry,
	RecurrenceFrequency,
	RecurrenceWeekday,
} from '../../scheduling/types';
import { formatDateDisplay, parseDateString } from '../../scheduling/dates';
import { minutesToTime, parseTimeToMinutes } from '../../scheduling/time';
import {
	validateAvailability,
	type RecurrenceEndMode,
} from './validate-availability';

interface AvailabilityDialogProps {
	entries: AvailabilityEntry[];
	entry: AvailabilityEntry;
	originalId?: string;
	onCancel: () => void;
	onSave: ( entry: AvailabilityEntry ) => void;
}

const DURATION_VALUES = [ 15, 30, 45, 60, 75, 90, 120 ];
const DURATION_OPTIONS = DURATION_VALUES.map( ( minutes ) => ( {
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

function usesCustomDuration( entry: AvailabilityEntry ): boolean {
	return ! DURATION_VALUES.includes( entry.duration );
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
	const [ useCustomDuration, setUseCustomDuration ] = useState(
		usesCustomDuration( entry )
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
		setUseCustomDuration( usesCustomDuration( entry ) );
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
		const result = validateAvailability( {
			draft,
			entries,
			originalDate,
			originalId,
			recurrenceEndMode,
			usePattern,
		} );
		if ( ! result.entry ) {
			setError( result.error );
			return;
		}
		onSave( result.entry );
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
										{ useCustomDuration ? (
											<TextControl
												label={ __(
													'Appointment duration',
													'rrze-appointment'
												) }
												type="number"
												min="1"
												step={ 1 }
												value={ String(
													draft.duration
												) }
												onChange={ ( duration ) => {
													setDraft( {
														...draft,
														duration:
															Number( duration ),
													} );
													setError( '' );
												} }
											/>
										) : (
											<SelectControl
												label={ __(
													'Appointment duration',
													'rrze-appointment'
												) }
												value={ String(
													draft.duration
												) }
												options={ DURATION_OPTIONS }
												onChange={ ( duration ) => {
													setDraft( {
														...draft,
														duration:
															Number( duration ),
													} );
													setError( '' );
												} }
											/>
										) }
										<Button
											variant="link"
											onClick={ () => {
												if (
													useCustomDuration &&
													! DURATION_VALUES.includes(
														draft.duration
													)
												) {
													setDraft( {
														...draft,
														duration: 30,
													} );
												}
												setUseCustomDuration(
													! useCustomDuration
												);
												setError( '' );
											} }
										>
											{ useCustomDuration
												? __(
														'Use standard durations',
														'rrze-appointment'
												  )
												: __(
														'Enter a custom duration',
														'rrze-appointment'
												  ) }
										</Button>
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
