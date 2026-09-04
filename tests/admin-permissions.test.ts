describe( 'appointment permission-list controls', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		jest.resetModules();
	} );

	it( 'adds and removes users before the settings form is saved', () => {
		document.body.innerHTML = `
			<div
				data-appointment-permissions
				data-input-name="rrze_appointment_settings[appointment_manager_user_ids][]"
				data-remove-label="Remove"
			>
				<select data-permission-user-select>
					<option value="">Select user</option>
					<option value="1">Ada (ada, ada@example.test)</option>
					<option value="2" disabled>Grace (grace, grace@example.test)</option>
				</select>
				<button type="button" data-permission-add disabled>Add</button>
				<ul data-permission-list>
					<li data-permission-user data-user-id="2">
						<span>Grace (grace, grace@example.test)</span>
						<input
							type="hidden"
							name="rrze_appointment_settings[appointment_manager_user_ids][]"
							value="2"
						>
						<button type="button" data-permission-remove>Remove</button>
					</li>
					<li data-permission-empty hidden>No users</li>
				</ul>
			</div>`;

		require( '../assets/js/rrze-appointment-admin' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		const select = document.querySelector< HTMLSelectElement >(
			'[data-permission-user-select]'
		) as HTMLSelectElement;
		const add = document.querySelector< HTMLButtonElement >(
			'[data-permission-add]'
		) as HTMLButtonElement;
		const empty = document.querySelector< HTMLElement >(
			'[data-permission-empty]'
		) as HTMLElement;

		select.value = '1';
		select.dispatchEvent( new Event( 'change' ) );
		expect( add.disabled ).toBe( false );
		add.click();

		expect(
			document.querySelectorAll( '[data-permission-user]' )
		).toHaveLength( 2 );
		expect(
			document.querySelectorAll(
				'input[name="rrze_appointment_settings[appointment_manager_user_ids][]"]'
			)
		).toHaveLength( 2 );
		expect( select.options[ 1 ].disabled ).toBe( true );
		expect( empty.hidden ).toBe( true );

		document
			.querySelector< HTMLButtonElement >(
				'[data-user-id="2"] [data-permission-remove]'
			)
			?.click();
		expect( select.options[ 2 ].disabled ).toBe( false );

		document
			.querySelector< HTMLButtonElement >(
				'[data-user-id="1"] [data-permission-remove]'
			)
			?.click();
		expect(
			document.querySelectorAll( '[data-permission-user]' )
		).toHaveLength( 0 );
		expect( empty.hidden ).toBe( false );
	} );
} );
