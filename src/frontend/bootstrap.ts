import { initializeAppointmentForms } from './appointment-form';

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initializeAppointmentForms );
} else {
	initializeAppointmentForms();
}
