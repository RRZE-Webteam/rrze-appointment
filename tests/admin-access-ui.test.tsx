import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { BookingsApp } from '../src/admin/bookings-app';
import { loadBookings } from '../src/admin/api';

let mockModalProps: any;
jest.mock( '@wordpress/element', () => require( 'react' ), { virtual: true } );
jest.mock(
	'@wordpress/i18n',
	() => ( {
		__: ( text: string ) => text,
		sprintf: ( text: string ) => text,
	} ),
	{ virtual: true }
);
jest.mock( '@wordpress/icons', () => ( { Icon: () => null } ), {
	virtual: true,
} );
jest.mock(
	'@wordpress/components',
	() => ( {
		Button: ( { children, onClick, disabled }: any ) => (
			<button onClick={ onClick } disabled={ disabled }>
				{ children }
			</button>
		),
		Notice: ( { children }: any ) => <div>{ children }</div>,
		Spinner: () => null,
	} ),
	{ virtual: true }
);
jest.mock( '@wordpress/dataviews/wp', () => ( {
	filterSortAndPaginate: ( data: any ) => ( {
		data,
		paginationInfo: { totalItems: data.length },
	} ),
	DataViews: ( { data, actions }: any ) => (
		<div>
			{ data.map( ( item: any ) => (
				<button
					key={ item.id }
					onClick={ () => actions[ 0 ].callback( [ item ] ) }
				>
					{ item.bookerName }
				</button>
			) ) }
		</div>
	),
} ) );
jest.mock( '../src/admin/cancel-booking-modal', () => ( {
	CancelBookingModal: ( props: any ) => {
		mockModalProps = props;
		return <div>Open cancellation</div>;
	},
} ) );
jest.mock( '../src/admin/api', () => ( {
	...jest.requireActual( '../src/admin/api' ),
	loadBookings: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );

/** Clicks a rendered control as a user would, including React state updates. */
async function clickText( host: HTMLElement, text: string ) {
	const button = Array.from( host.querySelectorAll( 'button' ) ).find(
		( el ) => el.textContent === text
	);
	expect( button ).toBeDefined();
	await act( async () => {
		button!.click();
	} );
}

describe( 'appointment access loss in an open administration screen', () => {
	it( 'clears appointments and dialogs and ignores a late successful response after denial', async () => {
		( globalThis as any ).IS_REACT_ACT_ENVIRONMENT = true;
		const host = document.createElement( 'div' );
		document.body.appendChild( host );
		const root = createRoot( host );
		const response = {
			items: [
				{
					id: 'slot',
					date: '2099-01-01',
					time: '10:00-10:30',
					bookerName: 'Private patient',
				},
			],
			view: 'current',
			hasPastView: true,
			sensitiveMode: false,
			cancellationReasonEnabled: true,
		};
		let resolveLate!: ( value: unknown ) => void;
		( loadBookings as jest.Mock )
			.mockResolvedValueOnce( response )
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						resolveLate = resolve;
					} )
			);
		const config = {
			url: '/bookings',
			cancelUrl: '/cancel',
			nonce: 'test',
			accessMessages: {
				rrze_appointment_forbidden: {
					title: 'No access',
					description: 'Contact a responsible person.',
					action: 'dashboard',
				},
			},
		};
		try {
			await act( async () => {
				root.render( <BookingsApp config={ config } /> );
			} );
			expect( host.textContent ).toContain( 'Private patient' );
			await clickText( host, 'Private patient' );
			expect( host.textContent ).toContain( 'Open cancellation' );
			const pendingCancellation = mockModalProps.onAccessError;
			await clickText( host, 'Refresh' );
			await act( async () => {
				pendingCancellation( {
					code: 'rrze_appointment_forbidden',
					data: { status: 403 },
				} );
			} );
			await act( async () => {
				resolveLate( response );
			} );
			expect( host.textContent ).toContain( 'No access' );
			expect( host.textContent ).not.toContain( 'Private patient' );
			expect( host.textContent ).not.toContain( 'Open cancellation' );
			expect( document.activeElement ).toBe(
				host.querySelector( '[role="alert"]' )
			);
		} finally {
			await act( async () => {
				root.unmount();
			} );
			host.remove();
		}
	} );
} );
