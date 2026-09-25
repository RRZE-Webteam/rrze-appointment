import {
	DataViews,
	filterSortAndPaginate,
	type Action,
	type Field,
	type View,
} from '@wordpress/dataviews/wp';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { notAllowed, update } from '@wordpress/icons';
import { loadBookings, requestErrorMessage } from './api';
import { CancelBookingModal } from './cancel-booking-modal';
import type {
	AdminBooking,
	AdminConfig,
	BookingsResponse,
	BookingView,
} from './types';

function initialView( mode: BookingView ): View {
	return {
		type: 'table',
		page: 1,
		perPage: 25,
		sort: { field: 'date', direction: mode === 'past' ? 'desc' : 'asc' },
		titleField: 'title',
		fields: [ 'date', 'time', 'personName', 'bookerName', 'bookerEmail' ],
		layout: { density: 'comfortable' },
	};
}

export function BookingsApp( { config }: { config: AdminConfig } ) {
	const query = new URLSearchParams( window.location.search );
	const [ mode, setMode ] = useState< BookingView >(
		query.get( 'view' ) === 'past' ? 'past' : 'current'
	);
	const [ view, setView ] = useState< View >( () => initialView( mode ) );
	const [ response, setResponse ] = useState< BookingsResponse | null >(
		null
	);
	const [ hasPastView, setHasPastView ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ revision, setRevision ] = useState( 0 );
	const [ selectedBooking, setSelectedBooking ] =
		useState< AdminBooking | null >( null );
	const feedbackRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		if ( notice || error ) {
			feedbackRef.current?.focus();
		}
	}, [ notice, error ] );

	useEffect( () => {
		const controller = new AbortController();
		setResponse( null );
		setLoading( true );
		setError( '' );
		loadBookings( config, mode, controller.signal )
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				setHasPastView( result.hasPastView );
				if ( result.view !== mode ) {
					setMode( result.view );
					setView( initialView( result.view ) );
					return;
				}
				setResponse( result );
			} )
			.catch( ( requestError: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setError( requestErrorMessage( requestError ) );
					setResponse( null );
				}
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setLoading( false );
				}
			} );
		return () => controller.abort();
	}, [ config, mode, revision ] );

	const fields = useMemo< Field< AdminBooking >[] >( () => {
		const result: Field< AdminBooking >[] = [
			{
				id: 'date',
				label: __( 'Date', 'rrze-appointment' ),
				type: 'date',
				render: ( { item } ) => item.dateLabel,
				enableHiding: false,
				enableGlobalSearch: true,
			},
			{
				id: 'time',
				label: __( 'Time', 'rrze-appointment' ),
				type: 'text',
				render: ( { item } ) => item.time.replace( '-', ' – ' ),
				enableGlobalSearch: true,
			},
			{
				id: 'title',
				label: __( 'Title', 'rrze-appointment' ),
				type: 'text',
				enableGlobalSearch: true,
			},
		];
		if ( ! response?.sensitiveMode ) {
			const hosts = Array.from(
				new Set(
					( response?.items || [] )
						.map( ( item ) => item.personName )
						.filter( Boolean )
				)
			).sort();
			const privateValue = ( value: string, anonymized: boolean ) =>
				anonymized ? (
					<span
						title={ __(
							'Sensitive appointment details remain hidden.',
							'rrze-appointment'
						) }
					>
						—
					</span>
				) : (
					value || '—'
				);
			result.push(
				{
					id: 'personName',
					label: __( 'Person', 'rrze-appointment' ),
					type: 'text',
					elements: hosts.map( ( name ) => ( {
						value: name,
						label: name,
					} ) ),
					filterBy: { operators: [ 'isAny' ] },
					enableGlobalSearch: true,
					render: ( { item } ) =>
						privateValue( item.personName, item.anonymized ),
				},
				{
					id: 'bookerName',
					label: __( 'Booker', 'rrze-appointment' ),
					type: 'text',
					enableGlobalSearch: true,
					render: ( { item } ) =>
						privateValue( item.bookerName, item.anonymized ),
				},
				{
					id: 'bookerEmail',
					label: __( 'Email', 'rrze-appointment' ),
					type: 'text',
					enableGlobalSearch: true,
					render: ( { item } ) =>
						privateValue( item.bookerEmail, item.anonymized ),
				}
			);
		}
		return result;
	}, [ response ] );

	const visibleFields = new Set( fields.map( ( field ) => field.id ) );
	const safeView: View = {
		...view,
		fields: view.fields?.filter( ( field ) => visibleFields.has( field ) ),
		filters: view.filters?.filter( ( filter ) =>
			visibleFields.has( filter.field )
		),
		sort:
			view.sort && visibleFields.has( view.sort.field )
				? view.sort
				: initialView( mode ).sort,
	};
	const filtered = filterSortAndPaginate(
		response?.items || [],
		safeView,
		fields
	);
	const actions: Action< AdminBooking >[] =
		mode === 'past'
			? []
			: [
					{
						id: 'cancel',
						label: __( 'Cancel booking', 'rrze-appointment' ),
						icon: notAllowed,
						isPrimary: true,
						supportsBulk: false,
						callback: ( items ) => setSelectedBooking( items[ 0 ] ),
					},
			  ];

	function changeMode( next: BookingView ) {
		setResponse( null );
		setSelectedBooking( null );
		setNotice( '' );
		setLoading( true );
		setMode( next );
		setView( ( current ) => ( {
			...current,
			page: 1,
			sort: initialView( next ).sort,
		} ) );
		const url = new URL( window.location.href );
		url.searchParams.set( 'view', next );
		window.history.replaceState( null, '', url );
	}

	function resetFilters() {
		setView( initialView( mode ) );
	}

	return (
		<>
			<div className="rrze-appointment-admin__toolbar">
				<div
					role="group"
					aria-label={ __( 'Appointment views', 'rrze-appointment' ) }
					className="rrze-appointment-admin__views"
				>
					<Button
						variant={ mode === 'current' ? 'primary' : 'tertiary' }
						aria-pressed={ mode === 'current' }
						onClick={ () => {
							if ( mode !== 'current' ) {
								changeMode( 'current' );
							}
						} }
					>
						{ __( 'Current appointments', 'rrze-appointment' ) }
					</Button>
					{ hasPastView && (
						<Button
							variant={ mode === 'past' ? 'primary' : 'tertiary' }
							aria-pressed={ mode === 'past' }
							onClick={ () => {
								if ( mode !== 'past' ) {
									changeMode( 'past' );
								}
							} }
						>
							{ __( 'Past appointments', 'rrze-appointment' ) }
						</Button>
					) }
				</div>
				<Button
					icon={ update }
					variant="secondary"
					disabled={ loading }
					onClick={ () => setRevision( ( value ) => value + 1 ) }
				>
					{ __( 'Refresh', 'rrze-appointment' ) }
				</Button>
			</div>
			<p className="description rrze-appointment-admin__description">
				{ mode === 'past'
					? __(
							'Completed appointments are shown until the configured retention period expires.',
							'rrze-appointment'
					  )
					: __(
							'Upcoming and ongoing appointments are shown here.',
							'rrze-appointment'
					  ) }
			</p>
			<div ref={ feedbackRef } tabIndex={ -1 }>
				{ notice && (
					<Notice status="success" onRemove={ () => setNotice( '' ) }>
						{ notice }
					</Notice>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
			</div>
			{ loading && (
				<div role="status" className="rrze-appointment-admin__loading">
					<Spinner />
					{ __( 'Loading appointments…', 'rrze-appointment' ) }
				</div>
			) }
			{ response && ! loading && (
				<>
					{ response.sensitiveMode && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Sensitive appointment mode is active. Only appointment dates, times and titles are shown.',
								'rrze-appointment'
							) }
						</Notice>
					) }
					<div className="rrze-appointment-admin__table">
						<DataViews
							data={ filtered.data }
							fields={ fields }
							view={ safeView }
							onChangeView={ setView }
							paginationInfo={ filtered.paginationInfo }
							defaultLayouts={ { table: {} } }
							actions={ actions }
							onReset={ resetFilters }
							searchLabel={ __(
								'Search appointments',
								'rrze-appointment'
							) }
							empty={
								<p>
									{ mode === 'past'
										? __(
												'No past appointments found.',
												'rrze-appointment'
										  )
										: __(
												'No appointments found.',
												'rrze-appointment'
										  ) }
								</p>
							}
						/>
					</div>
				</>
			) }
			{ selectedBooking && response && (
				<CancelBookingModal
					booking={ selectedBooking }
					config={ config }
					showReason={ response.cancellationReasonEnabled }
					onClose={ () => setSelectedBooking( null ) }
					onSuccess={ ( message ) => {
						setSelectedBooking( null );
						setNotice( message );
						setView( ( current ) => ( { ...current, page: 1 } ) );
						setRevision( ( value ) => value + 1 );
					} }
					onAccessError={ ( message ) => {
						setSelectedBooking( null );
						setResponse( null );
						setError( message );
						setNotice( '' );
					} }
				/>
			) }
		</>
	);
}
