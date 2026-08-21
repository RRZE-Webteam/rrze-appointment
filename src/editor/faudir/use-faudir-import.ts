import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { buildAvailabilityAttributes } from '../../scheduling/availability';
import type {
	AppointmentAttributes,
	AvailabilityEntry,
} from '../../scheduling/types';
import type {
	EditProps,
	FaudirImportOptions,
	FaudirPerson,
	FaudirResponse,
} from '../types';
import {
	createFaudirAvailabilityEntries,
	mergeFaudirAvailabilityEntries,
} from './import';

interface UseFaudirImportOptions {
	attributes: AppointmentAttributes;
	availabilityEntries: AvailabilityEntry[];
	setActiveDate: ( date: string ) => void;
	setAttributes: EditProps[ 'setAttributes' ];
}

export function useFaudirImport( {
	attributes,
	availabilityEntries,
	setActiveDate,
	setAttributes,
}: UseFaudirImportOptions ) {
	const available = !! window.rrze_appointment?.faudir?.available;
	const [ persons, setPersons ] = useState< FaudirPerson[] >( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ isOpen, setOpen ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	const open = () => {
		setOpen( true );
		if ( loaded || loading ) {
			return;
		}

		setLoading( true );
		setError( '' );
		apiFetch< FaudirResponse >( {
			path:
				window.rrze_appointment?.faudir?.personsPath ||
				'/rrze/v2/appointment/persons',
		} )
			.then( ( response ) => {
				if ( response.error ) {
					setError(
						response.message ||
							__(
								'FAUdir data could not be loaded.',
								'rrze-appointment'
							)
					);
					return;
				}
				setLoaded( true );
				setPersons(
					Array.isArray( response.data ) ? response.data : []
				);
			} )
			.catch( () =>
				setError(
					__(
						'FAUdir data could not be loaded. Please try again.',
						'rrze-appointment'
					)
				)
			)
			.finally( () => setLoading( false ) );
	};

	const importPerson = (
		person: FaudirPerson,
		options: FaudirImportOptions
	) => {
		const nextAttributes: Partial< AppointmentAttributes > = {
			personId: person.id,
		};
		if ( options.importContact ) {
			nextAttributes.personName =
				[ person.honorificPrefix, person.givenName, person.familyName ]
					.filter( Boolean )
					.join( ' ' ) ||
				person.label ||
				'';
			nextAttributes.personEmail = person.email || '';
		}
		if ( options.importLocation ) {
			nextAttributes.location = person.location || '';
			nextAttributes.locationUrl = person.locationUrl || '';
		}

		let addedHours = 0;
		let skippedHours = 0;
		if ( options.importHours ) {
			const importedEntries = createFaudirAvailabilityEntries(
				person.consultationHours || [],
				{
					hoursUntil: options.hoursUntil,
					duration: attributes.duration || 30,
					breakDuration: attributes.breakDuration || 0,
				}
			);
			const mergeResult = mergeFaudirAvailabilityEntries(
				availabilityEntries,
				importedEntries
			);
			addedHours = mergeResult.addedEntries.length;
			skippedHours = mergeResult.skippedCount;

			if ( addedHours > 0 ) {
				Object.assign(
					nextAttributes,
					buildAvailabilityAttributes(
						attributes,
						mergeResult.entries
					),
					{ useConsultationHours: true }
				);
				const firstEntry = mergeResult.addedEntries[ 0 ];
				if ( firstEntry ) {
					setActiveDate( firstEntry.date );
				}
			}
		}

		setAttributes( nextAttributes );
		let nextNotice: string = __(
			'FAUdir information imported.',
			'rrze-appointment'
		);
		if ( options.importHours && skippedHours > 0 ) {
			nextNotice = sprintf(
				/* translators: 1: Number of imported time ranges. 2: Number of skipped overlaps. */
				__(
					'FAUdir information imported. %1$d time ranges added; %2$d overlaps skipped.',
					'rrze-appointment'
				),
				addedHours,
				skippedHours
			);
		} else if ( options.importHours ) {
			nextNotice = sprintf(
				/* translators: %d: Number of imported time ranges. */
				__(
					'FAUdir information imported. %d time ranges added.',
					'rrze-appointment'
				),
				addedHours
			);
		}
		setNotice( nextNotice );
		setOpen( false );
	};

	return {
		available,
		error,
		importPerson,
		isOpen,
		loading,
		notice,
		open,
		persons,
		setOpen,
	};
}
