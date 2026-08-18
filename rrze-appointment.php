<?php

/*
Plugin Name:        RRZE Appointment
Plugin URI:         https://github.com/RRZE-Webteam/rrze-appointment
Version:            2.0.1
Description:        Appointments the easy way.
Author:             RRZE Webteam
Author URI:         https://www.wp.rrze.fau.de/
License:            GNU General Public License Version 3
License URI:        https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:        rrze-appointment
Domain Path:        /languages
Requires at least:  6.8
Requires PHP:       8.2
*/

namespace RRZE\Appointment;

use RRZE\Appointment\Main;
use RRZE\Appointment\Common\Plugin\Plugin;

defined('ABSPATH') || exit;


/**
 * ------------------------------------------------------------
 * PSR-4-ish autoloader for /includes
 * ------------------------------------------------------------
 */
spl_autoload_register(function ($class) {
    $prefix  = __NAMESPACE__;
    $baseDir = __DIR__ . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * ------------------------------------------------------------
 * Hooks (IMPORTANT: activation hook must be registered at top-level)
 * ------------------------------------------------------------
 */


// Deactivation (optional cleanup).
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivation');

// Normal bootstrap.
add_action('plugins_loaded', __NAMESPACE__ . '\loaded');


/**
 * ------------------------------------------------------------
 * Lifecycle
 * ------------------------------------------------------------
 */

/**
 * Deactivation callback function.
 */
function deactivation(): void
{
    // Cleanup could go here if needed.
}


function plugin(): Plugin
{
    static $instance;

    if (null === $instance) {
        $instance = new Plugin(__FILE__);
    }

    return $instance;
}

function main(): Main
{
    static $instance;

    if (null === $instance) {
        $instance = new Main();
    }

    return $instance;
}

function load_textdomain(): void
{
    load_plugin_textdomain(
        'rrze-appointment',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}

function register_blocks(): void
{
    $editor_style_path = __DIR__ . '/build/index.css';
    if (file_exists($editor_style_path)) {
        $editor_style_handle = generate_block_asset_handle('rrze/appointment', 'editorStyle');
        wp_register_style(
            $editor_style_handle,
            plugins_url('build/index.css', __FILE__),
            ['wp-components'],
            (string) filemtime($editor_style_path)
        );
        if (is_rtl() && file_exists(__DIR__ . '/build/index-rtl.css')) {
            wp_style_add_data($editor_style_handle, 'rtl', 'replace');
        }
    }

    register_block_type( __DIR__ . '/build' );
    $script_handle = generate_block_asset_handle( 'rrze/appointment', 'editorScript' );
    wp_set_script_translations( $script_handle, 'rrze-appointment', plugin_dir_path( __FILE__ ) . 'languages' );
}

/**
 * Make the appointment block available only in post and page editors.
 *
 * The block remains registered so that existing content can still be rendered.
 *
 * @param bool|string[]         $allowedBlockTypes Allowed block types.
 * @param \WP_Block_Editor_Context $editorContext  Current block editor context.
 * @return bool|string[]
 */
function restrict_appointment_block_to_content($allowedBlockTypes, $editorContext)
{
    $post = $editorContext->post ?? null;
    if (
        $post instanceof \WP_Post
        && in_array($post->post_type, ['post', 'page'], true)
    ) {
        return $allowedBlockTypes;
    }

    if ($allowedBlockTypes === true) {
        $allowedBlockTypes = array_keys(
            \WP_Block_Type_Registry::get_instance()->get_all_registered()
        );
    }

    if (!is_array($allowedBlockTypes)) {
        return $allowedBlockTypes;
    }

    return array_values(
        array_diff($allowedBlockTypes, ['rrze/appointment'])
    );
}

/**
 * Handle the loading of the plugin.
 */
function loaded(): void
{
    // Trigger the 'loaded' method of the main plugin instance.
    plugin()->loaded();

    // Load the plugin textdomain for translations.
    add_action('init', __NAMESPACE__ . '\load_textdomain');

    $wpCompatibe   = is_wp_version_compatible(plugin()->getRequiresWP());
    $phpCompatible = is_php_version_compatible(plugin()->getRequiresPHP());

    // Check system requirements.
    if (!$wpCompatibe || !$phpCompatible) {
        add_action('init', function () use ($wpCompatibe, $phpCompatible) {
            if (!current_user_can('activate_plugins')) {
                return;
            }

            $pluginName = plugin()->getName();

            $error = '';
            if (!$wpCompatibe) {
                $error = sprintf(
                    __('The server is running WordPress version %1$s. The plugin requires at least WordPress version %2$s.', 'rrze-appointment'),
                    wp_get_wp_version(),
                    plugin()->getRequiresWP()
                );
            } elseif (!$phpCompatible) {
                $error = sprintf(
                    __('The server is running PHP version %1$s. The plugin requires at least PHP version %2$s.', 'rrze-appointment'),
                    PHP_VERSION,
                    plugin()->getRequiresPHP()
                );
            }

            add_action('admin_notices', function () use ($pluginName, $error) {
                printf(
                    '<div class="notice notice-error"><p>' .
                    esc_html__('Plugins: %1$s: %2$s', 'rrze-appointment') .
                    '</p></div>',
                    esc_html($pluginName),
                    esc_html($error)
                );
            });
        });

        return;
    }

    // Initialize plugin.
    main();

    add_action('init', __NAMESPACE__ . '\register_blocks');
    add_filter(
        'allowed_block_types_all',
        __NAMESPACE__ . '\restrict_appointment_block_to_content',
        10,
        2
    );
}
