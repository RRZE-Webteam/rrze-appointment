/**
 * Connects interactive controls on the plugin settings page.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	document
		.querySelectorAll( '[data-access-directory]' )
		.forEach( initializePersonFilter );
	document
		.querySelectorAll( '[data-access-owner-feedback]' )
		.forEach( initializeOwnerFeedback );

	if ( window.wp?.media ) {
		initializeIllustrationFields();
	}
} );

/**
 * Requires one responsible administrator across all rows in this role form.
 *
 * @param {HTMLElement} feedback Focusable live region with the localized instruction.
 */
function initializeOwnerFeedback( feedback ) {
	const form = feedback.closest( 'form' );
	const roles = Array.from(
		form.querySelectorAll( '[data-access-person] select' )
	);
	const submit = form.querySelector( '[type="submit"]' );

	/**
	 * Updates the instruction and keeps the submit button reachable by keyboard.
	 *
	 * @return {boolean} Whether the selection includes a responsible person.
	 */
	function updateFeedback() {
		const valid = roles.some(
			( role ) =>
				role.value === 'owners' || role.value === 'access_owners'
		);
		const message = valid ? '' : feedback.dataset.message;
		if ( feedback.textContent !== message ) {
			feedback.textContent = message;
		}
		submit?.setAttribute( 'aria-disabled', String( ! valid ) );
		return valid;
	}

	form.addEventListener( 'change', updateFeedback );
	form.addEventListener( 'submit', ( event ) => {
		if ( ! updateFeedback() ) {
			event.preventDefault();
			feedback.focus();
		}
	} );
	updateFeedback();
}

/**
 * Filters visible site members by their display name or login. Hidden rows keep their selected roles.
 *
 * @param {HTMLElement} directory Container with labeled person rows and an optional search field.
 */
function initializePersonFilter( directory ) {
	const filter = directory.querySelector( '[data-access-filter]' );
	const input = filter?.querySelector( 'input[type="search"]' );
	const rows = Array.from(
		directory.querySelectorAll( '[data-access-person]' )
	);
	const empty = directory.querySelector( '[data-access-empty]' );
	if ( ! input || ! empty || ! rows.length ) {
		return;
	}
	// Grant lists replace the full previous selection. Send only assigned roles on large sites.
	directory.closest( 'form' )?.addEventListener( 'formdata', ( event ) => {
		for ( const select of directory.querySelectorAll( 'select[name]' ) ) {
			if ( select.value === 'none' ) {
				event.formData.delete( select.name );
			}
		}
	} );
	filter.hidden = false;
	input.addEventListener( 'input', () => {
		const query = input.value.trim().toLocaleLowerCase();
		let matches = 0;
		for ( const row of rows ) {
			const name = row.querySelector( 'label' )?.textContent || '';
			row.hidden = ! name.toLocaleLowerCase().includes( query );
			if ( ! row.hidden ) {
				matches++;
			}
		}
		empty.hidden = matches !== 0;
	} );
}

/** Connects public-page illustration controls to the WordPress media library. */
const initializeIllustrationFields = () => {
	document
		.querySelectorAll( '[data-illustration-field]' )
		.forEach( ( field ) => {
			const input = field.querySelector( '[data-illustration-input]' );
			const preview = field.querySelector(
				'[data-illustration-preview]'
			);
			const status = field.querySelector( '[data-illustration-status]' );
			const selectButton = field.querySelector(
				'[data-illustration-select]'
			);
			const removeButton = field.querySelector(
				'[data-illustration-remove]'
			);

			if (
				! input ||
				! preview ||
				! status ||
				! selectButton ||
				! removeButton
			) {
				return;
			}

			selectButton.addEventListener( 'click', () => {
				const frame = window.wp.media( {
					title: selectButton.dataset.dialogTitle,
					button: { text: selectButton.dataset.dialogButton },
					library: { type: 'image' },
					multiple: false,
				} );

				frame.on( 'select', () => {
					const attachment = frame
						.state()
						.get( 'selection' )
						.first()
						.toJSON();
					const previewUrl =
						attachment.sizes?.medium?.url || attachment.url;

					input.value = String( attachment.id );
					preview.src = previewUrl;
					status.textContent = selectButton.dataset.customLabel;
					removeButton.hidden = false;
				} );

				frame.open();
			} );

			removeButton.addEventListener( 'click', () => {
				input.value = '0';
				preview.src = preview.dataset.defaultSrc;
				status.textContent = removeButton.dataset.defaultLabel;
				removeButton.hidden = true;
			} );
		} );
};
