import { createRoot } from '@wordpress/element';
import { ColorSpace, OKLCH } from 'colorjs.io/fn';
import { BookingsApp } from './admin/bookings-app';
import './styles/admin-bookings.scss';

// DataViews 17.3's bundled theme calls toGamut('css') without registering
// OKLCH first. Its filter and column menus need this color space at runtime.
ColorSpace.register( OKLCH );

const container = document.getElementById( 'rrze-appointment-admin' );
const config = window.rrzeAppointmentAdmin;
if ( container && config ) {
	createRoot( container ).render( <BookingsApp config={ config } /> );
}
