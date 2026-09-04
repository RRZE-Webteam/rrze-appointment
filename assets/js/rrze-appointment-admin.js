/**
 * Connects interactive controls on the plugin settings page.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	document
		.querySelectorAll( '[data-appointment-permissions]' )
		.forEach( ( permissions ) => {
			const select = permissions.querySelector(
				'[data-permission-user-select]'
			);
			const addButton = permissions.querySelector(
				'[data-permission-add]'
			);
			const list = permissions.querySelector( '[data-permission-list]' );
			const empty = permissions.querySelector(
				'[data-permission-empty]'
			);

			if (
				! select ||
				! addButton ||
				! list ||
				! empty ||
				! permissions.dataset.inputName
			) {
				return;
			}

			const findOption = ( userId ) =>
				Array.from( select.options ).find(
					( option ) => option.value === userId
				);
			const updateState = () => {
				const option = select.selectedOptions[ 0 ];
				addButton.disabled =
					! option || option.value === '' || option.disabled;
				empty.hidden = Boolean(
					list.querySelector( '[data-permission-user]' )
				);
			};
			const removeUser = ( item ) => {
				const option = findOption( item.dataset.userId );
				if ( option ) {
					option.disabled = false;
				}
				item.remove();
				updateState();
			};
			const bindRemoveButton = ( item ) => {
				item.querySelector(
					'[data-permission-remove]'
				)?.addEventListener( 'click', () => removeUser( item ) );
			};

			list.querySelectorAll( '[data-permission-user]' ).forEach(
				bindRemoveButton
			);
			select.addEventListener( 'change', updateState );
			addButton.addEventListener( 'click', () => {
				const option = select.selectedOptions[ 0 ];
				if ( ! option || option.value === '' || option.disabled ) {
					return;
				}

				const item = document.createElement( 'li' );
				item.dataset.permissionUser = '';
				item.dataset.userId = option.value;

				const label = document.createElement( 'span' );
				label.textContent = option.textContent.trim();
				item.append( label );

				const input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = permissions.dataset.inputName;
				input.value = option.value;
				item.append( input );

				const removeButton = document.createElement( 'button' );
				removeButton.type = 'button';
				removeButton.className = 'button-link-delete';
				removeButton.dataset.permissionRemove = '';
				removeButton.textContent = permissions.dataset.removeLabel;
				item.append( removeButton );

				bindRemoveButton( item );
				list.append( item );
				option.disabled = true;
				select.value = '';
				updateState();
			} );
			updateState();
		} );

	if ( window.wp?.media ) {
		initializeIllustrationFields();
	}
} );

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
