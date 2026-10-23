import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { AdminConfig } from './types';

/** Presents the same access states as the server-rendered gate and moves focus to the explanation. */
export function AccessErrorCard( {
	error,
	config,
}: {
	error: unknown;
	config: AdminConfig;
} ) {
	const card = useRef< HTMLElement >( null );
	useEffect( () => {
		card.current?.focus();
	}, [] );
	const rawCode =
		error && typeof error === 'object' && 'code' in error
			? String( error.code )
			: '';
	const code =
		rawCode === 'rest_cookie_invalid_nonce'
			? 'rrze_appointment_invalid_nonce'
			: rawCode;
	const message =
		config.accessMessages?.[ code ] ??
		config.accessMessages?.rrze_appointment_forbidden;
	const action = message?.action ?? 'reload';
	const url =
		action === 'login'
			? config.loginUrl
			: action === 'dashboard'
			? config.dashboardUrl
			: window.location.href;
	const label =
		action === 'login'
			? __( 'Sign in with SSO', 'rrze-appointment' )
			: action === 'dashboard'
			? __( 'Back to dashboard', 'rrze-appointment' )
			: __( 'Reload page', 'rrze-appointment' );
	return (
		<section
			ref={ card }
			tabIndex={ -1 }
			role="alert"
			aria-labelledby="rrze-access-title"
			className="rrze-appointment-access-card"
		>
			<span className="dashicons dashicons-lock" aria-hidden="true" />
			<h2 id="rrze-access-title">
				{ message?.title ??
					__(
						'No access to appointment management',
						'rrze-appointment'
					) }
			</h2>
			<p>
				{ message?.description ??
					__(
						'Reload this page and sign in to WordPress again if requested.',
						'rrze-appointment'
					) }
			</p>
			<a className="button button-primary" href={ url }>
				{ label }
			</a>
		</section>
	);
}
