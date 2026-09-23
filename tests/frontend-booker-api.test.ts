import { loadCurrentBooker, requestBooker } from '../src/frontend/api';

const identity = {
	bookerName: 'Ada Lovelace',
	bookerEmail: 'ada@example.test',
};
const returnTo = window.location.origin + '/appointments';

function respond( payload: unknown, ok = true ): void {
	global.fetch = jest.fn().mockResolvedValue( {
		ok,
		json: jest.fn().mockResolvedValue( payload ),
	} );
}

afterEach( () => {
	delete global.fetch;
	delete window.rrze_appointment;
} );

it( 'loads identity using a same-origin request without caching', async () => {
	respond( { needsLogin: false, data: identity } );
	await expect( requestBooker( returnTo ) ).resolves.toEqual( {
		needsLogin: false,
		data: identity,
	} );
	expect( global.fetch ).toHaveBeenCalledWith(
		expect.any( String ),
		expect.objectContaining( {
			method: 'POST',
			credentials: 'same-origin',
			mode: 'same-origin',
			cache: 'no-store',
		} )
	);
} );

it( 'accepts a local login handoff', async () => {
	const payload = {
		needsLogin: true,
		loginUrl: window.location.origin + '/?rrze_appt_sso=1',
		data: {},
	};
	respond( payload );
	await expect( requestBooker( returnTo ) ).resolves.toEqual( payload );
} );

it.each( [
	null,
	'REST API support is restricted.',
	{},
	{ needsLogin: true, loginUrl: '' },
	{ needsLogin: true, loginUrl: 'https://evil.test/login' },
	{ needsLogin: true, loginUrl: 'javascript:alert(1)' },
	{ needsLogin: false, data: {} },
	{ needsLogin: false, data: identity, error: 'SSO failed' },
] )( 'rejects invalid or unsafe identity response %j', async ( payload ) => {
	respond( payload );
	await expect( requestBooker( returnTo ) ).rejects.toThrow();
} );

it( 'rejects HTTP failures even if the body resembles a valid identity', async () => {
	respond( { needsLogin: false, data: identity }, false );
	await expect( requestBooker( returnTo ) ).rejects.toThrow();
} );

it( 'rejects HTML instead of injecting it into the document', async () => {
	const write = jest.spyOn( document, 'write' );
	global.fetch = jest.fn().mockResolvedValue( {
		ok: true,
		json: jest
			.fn()
			.mockRejectedValue( new SyntaxError( 'Unexpected token <' ) ),
	} );
	await expect( requestBooker( returnTo ) ).rejects.toThrow();
	expect( write ).not.toHaveBeenCalled();
	write.mockRestore();
} );

it( 'loads the authenticated identity after returning from SSO', async () => {
	respond( { success: true, data: identity } );
	await expect( loadCurrentBooker() ).resolves.toEqual( identity );
} );

it.each( [ null, { success: false }, { success: true, data: {} } ] )(
	'rejects an unavailable identity after the login return: %j',
	async ( payload ) => {
		respond( payload );
		await expect( loadCurrentBooker() ).rejects.toThrow();
	}
);
