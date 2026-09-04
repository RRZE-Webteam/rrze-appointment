/**
 * Connects the illustration settings to the WordPress media library.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	if ( ! window.wp?.media ) {
		return;
	}

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
} );
