const fs = require( 'fs' );
const path = require( 'path' );

// Define paths
const baseDir = __dirname;
const packageJsonPath = path.join( baseDir, 'package.json' );
const packageLockPath = path.join( baseDir, 'package-lock.json' );
const readmePath = path.join( baseDir, 'readme.txt' );
const potPath = path.join( baseDir, 'languages', 'rrze-appointment.pot' );

// Automatically find the first .php file in the current directory
const pluginFilePath = fs
	.readdirSync( baseDir )
	.find( ( file ) => file.endsWith( '.php' ) );
const pluginFullPath = pluginFilePath
	? path.join( baseDir, pluginFilePath )
	: null;

/**
 * Calculates the next plugin version.
 *
 * @param {string} version Current semantic version.
 * @param {string} type    Patch or minor release.
 * @return {string} Incremented version.
 */
function incrementVersion( version, type ) {
	const [ major, minor, patch ] = version.split( '.' ).map( Number );
	return type === 'minor'
		? `${ major }.${ minor + 1 }.0`
		: `${ major }.${ minor }.${ patch + 1 }`;
}

/**
 * Updates one WordPress version header, preserving its formatting.
 *
 * @param {string} filePath    File containing the version header.
 * @param {string} tagLabel    Header to update.
 * @param {string} newVersion  Release version.
 * @param {string} valuePrefix Fixed text preceding the version in the header.
 */
function updateVersionByTag(
	filePath,
	tagLabel,
	newVersion,
	valuePrefix = ''
) {
	try {
		const content = fs.readFileSync( filePath, 'utf8' );
		const regex = new RegExp(
			`(${ tagLabel }\\s*:\\s*${ valuePrefix })[\\d.]+`
		);
		if ( ! regex.test( content ) ) {
			process.stderr.write(
				`No matching "${ tagLabel }" tag found in ${ filePath }.\n`
			);
			process.exit( 1 );
		}

		const updated = content.replace(
			regex,
			( _, prefix ) => prefix + newVersion
		);
		fs.writeFileSync( filePath, updated, 'utf8' );
		process.stdout.write( `Updated ${ tagLabel } in ${ filePath }\n` );
	} catch ( err ) {
		process.stderr.write(
			`Failed to update ${ tagLabel } in ${ filePath }: ${ err.message }\n`
		);
		process.exit( 1 );
	}
}

// Get the version increment type from command-line arguments
const incrementType = process.argv[ 2 ];
if ( ! [ 'minor', 'patch' ].includes( incrementType ) ) {
	process.stderr.write( 'Usage: node increment-version.js [minor|patch]\n' );
	process.exit( 1 );
}

// Read, increment, and update package.json
let newVersion;
try {
	const pkg = JSON.parse( fs.readFileSync( packageJsonPath, 'utf8' ) );
	const oldVersion = pkg.version;
	newVersion = incrementVersion( oldVersion, incrementType );
	pkg.version = newVersion;
	if ( fs.existsSync( packageLockPath ) ) {
		const lock = JSON.parse( fs.readFileSync( packageLockPath, 'utf8' ) );
		lock.version = newVersion;
		if ( lock.packages?.[ '' ] ) {
			lock.packages[ '' ].version = newVersion;
		}
		fs.writeFileSync(
			packageLockPath,
			JSON.stringify( lock, null, 2 ) + '\n',
			'utf8'
		);
	}
	fs.writeFileSync( packageJsonPath, JSON.stringify( pkg, null, 2 ), 'utf8' );
	process.stdout.write(
		`Version updated from ${ oldVersion } to ${ newVersion } in package.json\n`
	);
} catch ( err ) {
	process.stderr.write( `Error updating npm version: ${ err.message }\n` );
	process.exit( 1 );
}

// Update Stable tag in readme.txt
if ( fs.existsSync( readmePath ) ) {
	updateVersionByTag( readmePath, 'Stable tag', newVersion );
} else {
	process.stderr.write( `File ${ readmePath } not found.\n` );
}

// Update Version in plugin file
if ( pluginFullPath && fs.existsSync( pluginFullPath ) ) {
	updateVersionByTag( pluginFullPath, 'Version', newVersion );
} else {
	process.stderr.write(
		'No .php plugin file found in the current directory.\n'
	);
}

if ( fs.existsSync( potPath ) ) {
	updateVersionByTag(
		potPath,
		'Project-Id-Version',
		newVersion,
		'RRZE Appointment '
	);
}

process.stdout.write( `✔ Version successfully updated to ${ newVersion }\n` );
