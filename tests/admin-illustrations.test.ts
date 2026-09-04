type MediaFrame = {
	on: jest.Mock;
	open: jest.Mock;
	state: jest.Mock;
};

type MediaWindow = Window & {
	wp?: { media: jest.Mock };
};

describe( 'admin illustration media controls', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		delete ( window as MediaWindow ).wp;
		jest.resetModules();
	} );

	it( 'selects a media image and restores the bundled default', () => {
		document.body.innerHTML = `
			<section data-illustration-field>
				<img data-illustration-preview data-default-src="default.png" src="default.png">
				<input data-illustration-input value="0">
				<p data-illustration-status>Default illustration</p>
				<button
					data-illustration-select
					data-dialog-title="Select illustration"
					data-dialog-button="Use this image"
					data-custom-label="Custom illustration"
				>Select</button>
				<button
					data-illustration-remove
					data-default-label="Default illustration"
					hidden
				>Default</button>
			</section>`;

		let selectHandler: null | ( () => void ) = null;
		const frame: MediaFrame = {
			on: jest.fn( ( event: string, callback: () => void ) => {
				if ( event === 'select' ) {
					selectHandler = callback;
				}
			} ),
			open: jest.fn(),
			state: jest.fn( () => ( {
				get: () => ( {
					first: () => ( {
						toJSON: () => ( {
							id: 42,
							url: 'full.jpg',
							sizes: { medium: { url: 'medium.jpg' } },
						} ),
					} ),
				} ),
			} ) ),
		};
		const media = jest.fn( () => frame );
		( window as MediaWindow ).wp = { media };

		require( '../assets/js/rrze-appointment-admin' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		const input = document.querySelector< HTMLInputElement >(
			'[data-illustration-input]'
		) as HTMLInputElement;
		const preview = document.querySelector< HTMLImageElement >(
			'[data-illustration-preview]'
		) as HTMLImageElement;
		const status = document.querySelector< HTMLElement >(
			'[data-illustration-status]'
		) as HTMLElement;
		const select = document.querySelector< HTMLButtonElement >(
			'[data-illustration-select]'
		) as HTMLButtonElement;
		const remove = document.querySelector< HTMLButtonElement >(
			'[data-illustration-remove]'
		) as HTMLButtonElement;

		select.click();
		expect( media ).toHaveBeenCalledWith(
			expect.objectContaining( {
				library: { type: 'image' },
				multiple: false,
			} )
		);
		expect( frame.open ).toHaveBeenCalled();
		selectHandler?.();
		expect( input.value ).toBe( '42' );
		expect( preview.src ).toContain( 'medium.jpg' );
		expect( status.textContent ).toBe( 'Custom illustration' );
		expect( remove.hidden ).toBe( false );

		remove.click();
		expect( input.value ).toBe( '0' );
		expect( preview.src ).toContain( 'default.png' );
		expect( status.textContent ).toBe( 'Default illustration' );
		expect( remove.hidden ).toBe( true );
	} );
} );
