const fs = require( 'fs' );
const path = require( 'path' );

// Define paths
const baseDir = __dirname;
const packageJsonPath = path.join( baseDir, 'package.json' );
const readmePath = path.join( baseDir, 'readme.txt' );

// Automatically find the first .php file in the current directory
const pluginFilePath = fs
	.readdirSync( baseDir )
	.find( ( file ) => file.endsWith( '.php' ) );
const pluginFullPath = pluginFilePath
	? path.join( baseDir, pluginFilePath )
	: null;

// Increment the version based on the specified type
function incrementVersion( version, type ) {
	const [ major, minor, patch ] = version.split( '.' ).map( Number );
	if ( type === 'minor' ) return `${ major }.${ minor + 1 }.0`;
	if ( type === 'patch' ) return `${ major }.${ minor }.${ patch + 1 }`;
	console.error( `Unknown version increment type: ${ type }` );
	process.exit( 1 );
}

// Update version based on tag label (e.g., "Stable tag" or "Version") regardless of spacing
function updateVersionByTag( filePath, tagLabel, newVersion ) {
	try {
		const content = fs.readFileSync( filePath, 'utf8' );
		const regex = new RegExp( `(${ tagLabel }\\s*:\\s*)([\\d.]+)` );
		const updated = content.replace( regex, `$1${ newVersion }` );

		if ( updated === content ) {
			console.error(
				`No matching "${ tagLabel }" tag found in ${ filePath }.`
			);
			process.exit( 1 );
		}

		fs.writeFileSync( filePath, updated, 'utf8' );
		console.log( `Updated ${ tagLabel } in ${ filePath }` );
	} catch ( err ) {
		console.error(
			`Failed to update ${ tagLabel } in ${ filePath }: ${ err.message }`
		);
		process.exit( 1 );
	}
}

// Get the version increment type from command-line arguments
const incrementType = process.argv[ 2 ];
if ( ! [ 'minor', 'patch' ].includes( incrementType ) ) {
	console.error( 'Usage: node increment-version.js [minor|patch]' );
	process.exit( 1 );
}

// Read, increment, and update package.json
let newVersion;
try {
	const pkg = JSON.parse( fs.readFileSync( packageJsonPath, 'utf8' ) );
	const oldVersion = pkg.version;
	newVersion = incrementVersion( oldVersion, incrementType );
	pkg.version = newVersion;
	fs.writeFileSync( packageJsonPath, JSON.stringify( pkg, null, 2 ), 'utf8' );
	console.log(
		`Version updated from ${ oldVersion } to ${ newVersion } in package.json`
	);
} catch ( err ) {
	console.error( `Error processing package.json: ${ err.message }` );
	process.exit( 1 );
}

// Update Stable tag in readme.txt
if ( fs.existsSync( readmePath ) ) {
	updateVersionByTag( readmePath, 'Stable tag', newVersion );
} else {
	console.warn( `File ${ readmePath } not found.` );
}

// Update Version in plugin file
if ( pluginFullPath && fs.existsSync( pluginFullPath ) ) {
	updateVersionByTag( pluginFullPath, 'Version', newVersion );
} else {
	console.warn( `No .php plugin file found in the current directory.` );
}

console.log( `✔ Version successfully updated to ${ newVersion }` );
