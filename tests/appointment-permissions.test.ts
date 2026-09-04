import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type PermissionResult = {
	administrator: boolean;
	listedMember: boolean;
	unlistedMember: boolean;
	removedMember: boolean;
	invalidSetting: boolean;
};

const getPermissionResult = (): PermissionResult => {
	const permissionsPath = resolve(
		process.cwd(),
		'includes/Admin/AppointmentPermissions.php'
	);
	const php = `
		namespace RRZE\\Appointment\\Configuration {
			class PluginSettings {
				public static $allowed = [ 7 ];
				public static function get( string $key ) { return self::$allowed; }
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['user_id'] = 0;
			$GLOBALS['administrator'] = false;
			$GLOBALS['member'] = true;
			function current_user_can( $capability ) { return $GLOBALS['administrator']; }
			function get_current_user_id() { return $GLOBALS['user_id']; }
			function get_current_blog_id() { return 3; }
			function is_user_member_of_blog( $userId, $blogId ) { return $GLOBALS['member']; }
			function absint( $value ) { return abs( (int) $value ); }
			require ${ JSON.stringify( permissionsPath ) };

			$class = \\RRZE\\Appointment\\Admin\\AppointmentPermissions::class;
			$GLOBALS['administrator'] = true;
			$administratorAccess = $class::currentUserCanManage();
			$GLOBALS['administrator'] = false;
			$GLOBALS['user_id'] = 7;
			$listedMember = $class::currentUserCanManage();
			$GLOBALS['user_id'] = 8;
			$unlistedMember = $class::currentUserCanManage();
			$GLOBALS['user_id'] = 7;
			$GLOBALS['member'] = false;
			$removedMember = $class::currentUserCanManage();
			$GLOBALS['member'] = true;
			\\RRZE\\Appointment\\Configuration\\PluginSettings::$allowed = 'invalid';
			$invalidSetting = $class::currentUserCanManage();

			echo json_encode( compact(
				'administratorAccess',
				'listedMember',
				'unlistedMember',
				'removedMember',
				'invalidSetting'
			) );
		}
	`;

	const result = JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as Omit< PermissionResult, 'administrator' > & {
		administratorAccess: boolean;
	};

	const { administratorAccess, ...otherResults } = result;
	return { ...otherResults, administrator: administratorAccess };
};

describe( 'appointment management permissions', () => {
	it( 'always allows administrators and only delegates access to listed site members', () => {
		expect( getPermissionResult() ).toEqual( {
			administrator: true,
			listedMember: true,
			unlistedMember: false,
			removedMember: false,
			invalidSetting: false,
		} );
	} );
} );
