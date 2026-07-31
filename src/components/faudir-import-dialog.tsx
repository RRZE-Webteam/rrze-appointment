import {
	Button,
	CheckboxControl,
	Flex,
	FlexItem,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FaudirImportOptions, FaudirPerson } from '../types';

interface FaudirImportDialogProps {
	error: string;
	isLoading: boolean;
	onCancel: () => void;
	onConfirm: ( person: FaudirPerson, options: FaudirImportOptions ) => void;
	persons: FaudirPerson[];
}

function getPersonName( person: FaudirPerson ): string {
	return (
		[ person.honorificPrefix, person.givenName, person.familyName ]
			.filter( Boolean )
			.join( ' ' ) ||
		person.label ||
		''
	);
}

function formatLocalDate( date: Date ): string {
	return [
		date.getFullYear(),
		String( date.getMonth() + 1 ).padStart( 2, '0' ),
		String( date.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}

function getTodayDate(): string {
	return formatLocalDate( new Date() );
}

function getDefaultUntilDate(): string {
	const until = new Date();
	until.setDate( until.getDate() + 56 );
	return formatLocalDate( until );
}

function getWeekdayLabel( weekday: number ): string {
	const referenceSunday = new Date( 2024, 0, 7 );
	referenceSunday.setDate( referenceSunday.getDate() + weekday );
	return new Intl.DateTimeFormat( undefined, { weekday: 'long' } ).format(
		referenceSunday
	);
}

export function FaudirImportDialog( {
	error,
	isLoading,
	onCancel,
	onConfirm,
	persons,
}: FaudirImportDialogProps ) {
	const sortedPersons = useMemo(
		() =>
			[ ...persons ].sort( ( a, b ) =>
				getPersonName( a ).localeCompare( getPersonName( b ), 'de' )
			),
		[ persons ]
	);
	const [ personId, setPersonId ] = useState( 0 );
	const [ importContact, setImportContact ] = useState( true );
	const [ importLocation, setImportLocation ] = useState( true );
	const [ importHours, setImportHours ] = useState( false );
	const [ hoursUntil, setHoursUntil ] = useState( getDefaultUntilDate );
	const person =
		sortedPersons.find( ( candidate ) => candidate.id === personId ) ||
		null;
	const hours = person?.consultationHours || [];
	const hasLocation = !! ( person?.location || person?.locationUrl );
	const shouldImportLocation = importLocation && hasLocation;
	const todayDate = getTodayDate();

	useEffect( () => {
		if (
			personId === 0 &&
			sortedPersons.length === 1 &&
			sortedPersons[ 0 ]
		) {
			setPersonId( sortedPersons[ 0 ].id );
		}
	}, [ personId, sortedPersons ] );

	const canImport =
		!! person &&
		( importContact || shouldImportLocation || importHours ) &&
		( ! importHours || ( hours.length > 0 && hoursUntil >= todayDate ) );

	return (
		<Modal
			title={ __( 'Import from FAUdir', 'rrze-appointment' ) }
			size="medium"
			onRequestClose={ onCancel }
		>
			<p>
				{ __(
					'Choose a person and decide which information to copy into this appointment.',
					'rrze-appointment'
				) }
			</p>

			{ isLoading && (
				<p>
					<Spinner />
					{ __( 'Loading people…', 'rrze-appointment' ) }
				</p>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! isLoading && ! error && sortedPersons.length === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No published people are available in FAUdir.',
						'rrze-appointment'
					) }
				</Notice>
			) }

			{ sortedPersons.length > 0 && (
				<SelectControl
					label={ __( 'Person', 'rrze-appointment' ) }
					value={ String( personId ) }
					options={ [
						{
							label: __( 'Select a person', 'rrze-appointment' ),
							value: '0',
						},
						...sortedPersons.map( ( candidate ) => ( {
							label: getPersonName( candidate ),
							value: String( candidate.id ),
						} ) ),
					] }
					onChange={ ( value ) => setPersonId( Number( value ) ) }
				/>
			) }

			{ person && (
				<div className="rrze-appointment-block__faudir-preview">
					<h3>
						{ __( 'Information to import', 'rrze-appointment' ) }
					</h3>
					<CheckboxControl
						label={ sprintf(
							/* translators: %s: Person name. */
							__( 'Name and email: %s', 'rrze-appointment' ),
							[ getPersonName( person ), person.email ]
								.filter( Boolean )
								.join( ' · ' )
						) }
						checked={ importContact }
						onChange={ setImportContact }
					/>
					<CheckboxControl
						label={
							person.location
								? sprintf(
										/* translators: %s: Location. */
										__(
											'Location: %s',
											'rrze-appointment'
										),
										person.location
								  )
								: __(
										'Location (no information available)',
										'rrze-appointment'
								  )
						}
						checked={ shouldImportLocation }
						disabled={ ! hasLocation }
						onChange={ setImportLocation }
					/>
					<CheckboxControl
						label={
							hours.length > 0
								? sprintf(
										/* translators: %d: Number of weekly time ranges. */
										__(
											'Weekly hours (%d time ranges)',
											'rrze-appointment'
										),
										hours.length
								  )
								: __(
										'Weekly hours (none available)',
										'rrze-appointment'
								  )
						}
						checked={ importHours }
						disabled={ hours.length === 0 }
						onChange={ setImportHours }
					/>
					{ importHours && (
						<>
							<ul className="rrze-appointment-block__faudir-hours">
								{ hours.map( ( hour, index ) => (
									<li
										key={ `${ hour.weekday }-${ hour.from }-${ index }` }
									>
										{ getWeekdayLabel( hour.weekday ) }:{ ' ' }
										{ hour.from || '09:00' } –{ ' ' }
										{ hour.to || '17:00' }
									</li>
								) ) }
							</ul>
							<TextControl
								type="date"
								min={ todayDate }
								label={ __(
									'Create recurring times through',
									'rrze-appointment'
								) }
								value={ hoursUntil }
								onChange={ setHoursUntil }
							/>
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Imported hours are added to the existing appointment times. Existing times and exceptions remain unchanged; overlapping imported hours are skipped.',
									'rrze-appointment'
								) }
							</Notice>
						</>
					) }
				</div>
			) }

			<Flex justify="flex-end">
				<FlexItem>
					<Button variant="tertiary" onClick={ onCancel }>
						{ __( 'Cancel', 'rrze-appointment' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						disabled={ ! canImport }
						onClick={ () => {
							if ( person ) {
								onConfirm( person, {
									importContact,
									importLocation: shouldImportLocation,
									importHours,
									hoursUntil,
								} );
							}
						} }
					>
						{ __(
							'Import selected information',
							'rrze-appointment'
						) }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
}
