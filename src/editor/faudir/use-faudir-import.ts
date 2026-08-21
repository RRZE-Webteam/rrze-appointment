import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { buildAvailabilityAttributes } from '../../scheduling/availability';
import type {
	AppointmentAttributes,
	AvailabilityEntry,
} from '../../scheduling/types';
import type {
	AppointmentEditorProps,
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
	onActiveDateChange: ( date: string ) => void;
	setAttributes: AppointmentEditorProps[ 'setAttributes' ];
}

export function useFaudirImport( {
	attributes,
	availabilityEntries,
	onActiveDateChange,
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

		let addedTimeRangeCount = 0;
		let skippedTimeRangeCount = 0;
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
			addedTimeRangeCount = mergeResult.addedEntries.length;
			skippedTimeRangeCount = mergeResult.skippedCount;

			if ( addedTimeRangeCount > 0 ) {
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
					onActiveDateChange( firstEntry.date );
				}
			}
		}

		setAttributes( nextAttributes );
		let nextNotice: string = __(
			'FAUdir information imported.',
			'rrze-appointment'
		);
		if ( options.importHours && skippedTimeRangeCount > 0 ) {
			nextNotice = sprintf(
				/* translators: 1: Number of imported time ranges. 2: Number of skipped overlaps. */
				__(
					'FAUdir information imported. %1$d time ranges added; %2$d overlaps skipped.',
					'rrze-appointment'
				),
				addedTimeRangeCount,
				skippedTimeRangeCount
			);
		} else if ( options.importHours ) {
			nextNotice = sprintf(
				/* translators: %d: Number of imported time ranges. */
				__(
					'FAUdir information imported. %d time ranges added.',
					'rrze-appointment'
				),
				addedTimeRangeCount
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
