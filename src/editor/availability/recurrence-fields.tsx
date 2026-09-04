import {
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	getAvailabilityAppointmentCount,
	getAvailabilityDates,
} from '../../scheduling/availability';
import { formatDateDisplay, parseDateString } from '../../scheduling/dates';
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
import type { RecurrenceEndMode } from './validate-availability';

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

interface RecurrenceFieldsProps {
	draft: AvailabilityEntry;
	recurrenceEndMode: RecurrenceEndMode;
	splitsRangeIntoAppointments: boolean;
	onClearError: () => void;
	onDraftChange: ( draft: AvailabilityEntry ) => void;
	onRecurrenceEndModeChange: ( mode: RecurrenceEndMode ) => void;
}

function getAnchorWeekday( date: string ): RecurrenceWeekday {
	return ( parseDateString( date )?.getDay() || 0 ) as RecurrenceWeekday;
}

export function RecurrenceFields( {
	draft,
	recurrenceEndMode,
	splitsRangeIntoAppointments,
	onClearError,
	onDraftChange,
	onRecurrenceEndModeChange,
}: RecurrenceFieldsProps ) {
	const isRepeating = Boolean( draft.recurrence.freq );
	const selectedWeekdays = getRecurrenceWeekdays(
		draft.recurrence,
		draft.date
	);
	const requestedOccurrenceCount = Number( draft.recurrence.count );
	const recurrenceEndBeforeStart =
		recurrenceEndMode === 'date' &&
		Boolean( draft.recurrence.until ) &&
		( draft.recurrence.until || '' ) < draft.date;
	const hasValidRecurrenceEnd =
		recurrenceEndMode === 'date'
			? Boolean( draft.recurrence.until ) && ! recurrenceEndBeforeStart
			: Number.isInteger( requestedOccurrenceCount ) &&
			  requestedOccurrenceCount > 0;
	const recurrenceLimitExceeded =
		isRepeating &&
		hasValidRecurrenceEnd &&
		recurrenceExceedsLimit( draft.recurrence, draft.date );
	const occurrenceDates =
		isRepeating && hasValidRecurrenceEnd && ! recurrenceLimitExceeded
			? getAvailabilityDates( draft )
			: [];
	const lastOccurrenceDate =
		occurrenceDates[ occurrenceDates.length - 1 ] || '';
	const totalAppointmentCount = splitsRangeIntoAppointments
		? getAvailabilityAppointmentCount( draft )
		: occurrenceDates.length;
	const recurrenceSummary =
		occurrenceDates.length > 0 && lastOccurrenceDate
			? sprintf(
					/* translators: 1: number of appointment dates, 2: last date. */
					_n(
						'Creates %1$d appointment date through %2$s.',
						'Creates %1$d appointment dates through %2$s.',
						occurrenceDates.length,
						'rrze-appointment'
					),
					occurrenceDates.length,
					formatDateDisplay( lastOccurrenceDate )
			  )
			: '';

	const updateRecurrence = (
		recurrence: AvailabilityEntry[ 'recurrence' ]
	) => {
		onDraftChange( { ...draft, recurrence } );
		onClearError();
	};

	return (
		<>
			<FlexItem>
				<ToggleControl
					label={ __(
						'Repeat appointment times',
						'rrze-appointment'
					) }
					checked={ isRepeating }
					onChange={ ( repeats ) => {
						onRecurrenceEndModeChange( 'date' );
						updateRecurrence(
							repeats
								? {
										freq: 'weekly',
										anchor: draft.date,
										weekdays: [
											getAnchorWeekday( draft.date ),
										],
								  }
								: {}
						);
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
									label: __( 'Daily', 'rrze-appointment' ),
									value: 'daily',
								},
								{
									label: __( 'Weekly', 'rrze-appointment' ),
									value: 'weekly',
								},
								{
									label: __( 'Monthly', 'rrze-appointment' ),
									value: 'monthly',
								},
							] }
							onChange={ ( frequency ) => {
								const nextFrequency =
									frequency as RecurrenceFrequency;
								const nextRecurrence = {
									...draft.recurrence,
									freq: nextFrequency,
									anchor: draft.date,
								};
								if (
									nextFrequency === 'weekly' &&
									! Array.isArray( nextRecurrence.weekdays )
								) {
									nextRecurrence.weekdays = [
										getAnchorWeekday( draft.date ),
									];
								}
								if ( nextFrequency !== 'weekly' ) {
									delete nextRecurrence.weekdays;
								}
								updateRecurrence( nextRecurrence );
							} }
						/>
					</FlexItem>

					{ draft.recurrence.freq === 'weekly' && (
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
																(
																	selectedWeekday
																) =>
																	selectedWeekday !==
																	weekday.value
														  );
													updateRecurrence( {
														...draft.recurrence,
														anchor: draft.date,
														weekdays,
													} );
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
							<Flex direction="column" align="stretch" gap={ 3 }>
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
										const { count, until, ...recurrence } =
											draft.recurrence;
										onRecurrenceEndModeChange( nextMode );
										updateRecurrence(
											nextMode === 'count'
												? {
														...recurrence,
														count: count || 12,
												  }
												: {
														...recurrence,
														...( until
															? { until }
															: {} ),
												  }
										);
									} }
								/>

								{ recurrenceEndMode === 'date' ? (
									<TextControl
										label={ __(
											'Last date',
											'rrze-appointment'
										) }
										type="date"
										value={ draft.recurrence.until || '' }
										onChange={ ( until ) => {
											const { count, ...recurrence } =
												draft.recurrence;
											updateRecurrence( {
												...recurrence,
												anchor: draft.date,
												until,
											} );
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
											draft.recurrence.count === undefined
												? ''
												: String(
														draft.recurrence.count
												  )
										}
										onChange={ ( value ) => {
											const recurrence = {
												...draft.recurrence,
											};
											delete recurrence.until;
											delete recurrence.count;
											const count = Number( value );
											updateRecurrence( {
												...recurrence,
												anchor: draft.date,
												...( value !== '' &&
												Number.isFinite( count )
													? { count }
													: {} ),
											} );
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
		</>
	);
}
