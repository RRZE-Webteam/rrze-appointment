<?php

/*
Plugin Name:        RRZE Answers
Plugin URI:         https://github.com/RRZE-Webteam/rrze-answers
Version:            1.0.24
Description:        Explain your content with FAQ, glossary and placeholders.
Author:             RRZE Webteam
Author URI:         https://www.wp.rrze.fau.de/
License:            GNU General Public License Version 3
License URI:        https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:        rrze-answers
Domain Path:        /languages
Requires at least:  6.8
Requires PHP:       8.2
*/

namespace RRZE\Answers;

use RRZE\Answers\Main;
use RRZE\Answers\Common\Tools;
use RRZE\Answers\Common\Plugin\Plugin;

defined('ABSPATH') || exit;

const RRZE_ANSWERS_PLUGIN = 'rrze-answers/rrze-answers.php';
const MIGRATE_DONE_KEY    = 'rrze_answers_migrate_multisite_done';
const MIGRATE_REPORT_KEY  = 'rrze_answers_migrate_multisite_report';

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

// Only ONE activation hook. It runs the multisite migration ONLY when network-activated.
register_activation_hook(__FILE__, __NAMESPACE__ . '\rrze_answers_on_activate_network');

// Deactivation (optional cleanup).
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivation');

// Normal bootstrap.
add_action('plugins_loaded', __NAMESPACE__ . '\loaded');

// Persisted migration report notice (survives activation redirects).
add_action('network_admin_notices', __NAMESPACE__ . '\rrze_answers_migrate_multisite_notice');

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

/**
 * Activation callback for multisite "Network Activate".
 *
 * Requirements:
 * - Migration is triggered ONLY by clicking "Network Activate".
 * - No pending flag/state machine: run migration immediately in activation.
 * - RRZE-Answers MUST NOT remain network-activated after migration.
 *
 * IMPORTANT:
 * - On multisite, WordPress passes $network_wide to activation hooks.
 * - In some contexts WP may call hooks without args; therefore $network_wide is optional.
 */
function rrze_answers_on_activate_network($network_wide = false): void
{
    $network_wide = (bool) $network_wide;

    // Only run for multisite "Network Activate".
    if (!is_multisite() || !$network_wide) {
        return;
    }

    rrze_answers_ensure_plugin_functions();

    // Do not run twice.
    if (get_site_option(MIGRATE_DONE_KEY)) {
        rrze_answers_store_report([
            'type'   => 'info',
            'title'  => 'RRZE-Answers',
            'intro'  => __('Migration already marked as done. No changes were made.', 'rrze-answers'),
            'items'  => [],
            'footer' => '',
        ]);
        return;
    }

    /**
     * PRECONDITION:
     * RRZE-Answers must NOT be network-activated, otherwise it becomes active on all sites.
     * The user just network-activated it, so immediately undo that.
     */
    rrze_answers_force_network_deactivate(RRZE_ANSWERS_PLUGIN);

    // Hard abort if we cannot ensure it is NOT network-active.
    if (rrze_answers_is_network_active(RRZE_ANSWERS_PLUGIN)) {
        rrze_answers_store_report([
            'type'   => 'error',
            'title'  => 'RRZE-Answers',
            'intro'  => __('Migration aborted: RRZE-Answers could not be deactivated network-wide during activation.', 'rrze-answers'),
            'items'  => [],
            'footer' => __('No site changes were made. Please deactivate RRZE-Answers network-wide manually and retry.', 'rrze-answers'),
        ]);
        return;
    }

    // Run the migration.
    $result = rrze_answers_migrate_multisite_core();

    /**
     * FINAL POSTCONDITION:
     * Ensure RRZE-Answers is NOT network-activated after migration.
     * If it is, abort and do NOT mark migration as done.
     */
    rrze_answers_force_network_deactivate(RRZE_ANSWERS_PLUGIN);

    if (rrze_answers_is_network_active(RRZE_ANSWERS_PLUGIN)) {
        rrze_answers_store_report([
            'type'   => 'error',
            'title'  => 'RRZE-Answers',
            'intro'  => __('Migration failed: RRZE-Answers is network-activated after migration.', 'rrze-answers'),
            'items'  => $result['items'] ?? [],
            'footer' => __('The migration was NOT marked as done. Please ensure RRZE-Answers is not network-activated and retry.', 'rrze-answers'),
        ]);
        return; // IMPORTANT: do NOT set MIGRATE_DONE_KEY
    }

    // Mark migration as done ONLY when final invariant holds.
    update_site_option(MIGRATE_DONE_KEY, 1);

    // Store report (success/info) for display after activation redirect.
    if (!empty($result['report'])) {
        rrze_answers_store_report($result['report']);
    }
}

/**
 * ------------------------------------------------------------
 * Plugin bootstrap (your existing structure preserved)
 * ------------------------------------------------------------
 */

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
        'rrze-answers',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}

function register_blocks(): void
{
    register_block_type_from_metadata(__DIR__ . '/blocks/faq');
    register_block_type_from_metadata(__DIR__ . '/blocks/faq-widget');
    register_block_type_from_metadata(__DIR__ . '/blocks/glossary');
    register_block_type_from_metadata(__DIR__ . '/blocks/placeholder');

    $faq_handle         = generate_block_asset_handle('rrze-answers/faq', 'editorScript');
    $faq_widget_handle  = generate_block_asset_handle('rrze-answers/faq-widget', 'editorScript');
    $glossary_handle    = generate_block_asset_handle('rrze-answers/glossary', 'editorScript');
    $placeholder_handle = generate_block_asset_handle('rrze-answers/placeholder', 'editorScript');

    $path = plugin_dir_path(__FILE__) . 'languages';

    wp_set_script_translations($faq_handle, 'rrze-answers', $path);
    wp_set_script_translations($faq_widget_handle, 'rrze-answers', $path);
    wp_set_script_translations($glossary_handle, 'rrze-answers', $path);
    wp_set_script_translations($placeholder_handle, 'rrze-answers', $path);
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
                    __('The server is running WordPress version %1$s. The plugin requires at least WordPress version %2$s.', 'rrze-answers'),
                    wp_get_wp_version(),
                    plugin()->getRequiresWP()
                );
            } elseif (!$phpCompatible) {
                $error = sprintf(
                    __('The server is running PHP version %1$s. The plugin requires at least PHP version %2$s.', 'rrze-answers'),
                    PHP_VERSION,
                    plugin()->getRequiresPHP()
                );
            }

            add_action('admin_notices', function () use ($pluginName, $error) {
                printf(
                    '<div class="notice notice-error"><p>' .
                    esc_html__('Plugins: %1$s: %2$s', 'rrze-answers') .
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
    add_action('init', __NAMESPACE__ . '\rrze_update_glossary_cpt');
    add_action('init', __NAMESPACE__ . '\rrze_update_placeholder_cpt');
    add_action('init', __NAMESPACE__ . '\rrze_migrate_domains');
}

/**
 * ------------------------------------------------------------
 * Your existing migrations (kept as-is, minimal edits)
 * ------------------------------------------------------------
 */

function rrze_update_glossary_cpt(): void
{
    global $wpdb;

    if (get_option('rrze_update_glossary_cpt_done')) {
        return;
    }

    $wpdb->query("
        UPDATE {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
        INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
        SET tt.taxonomy = 'rrze_glossary_category'
        WHERE p.post_type = 'glossary'
        AND tt.taxonomy = 'glossary_category'
    ");

    $wpdb->query("
        UPDATE {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
        INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
        SET tt.taxonomy = 'rrze_glossary_tag'
        WHERE p.post_type = 'glossary'
        AND tt.taxonomy = 'glossary_tag'
    ");

    $wpdb->update(
        $wpdb->posts,
        ['post_type' => 'rrze_glossary'],
        ['post_type' => 'glossary']
    );

    wp_cache_flush();
    flush_rewrite_rules();

    update_option('rrze_update_glossary_cpt_done', 1);
}

function rrze_update_placeholder_cpt(): void
{
    global $wpdb;

    if (get_option('rrze_update_placeholder_cpt_done')) {
        return;
    }

    $wpdb->update(
        $wpdb->posts,
        ['post_type' => 'rrze_placeholder'],
        ['post_type' => 'placeholder']
    );

    wp_cache_flush();
    flush_rewrite_rules();

    update_option('rrze_update_placeholder_cpt_done', 1);
}

function rrze_migrate_domains(): void
{
    if (get_option('rrze_migrate_domains_done')) {
        return;
    }

    $domains = [];
    $source_options = ['rrze-faq', 'rrze-glossary'];

    foreach ($source_options as $option_name) {
        $option = get_option($option_name);

        if (!empty($option['registeredDomains'])) {
            foreach ($option['registeredDomains'] as $shortname => $url) {
                $identifier = Tools::getIdentifier($url);
                $domains[$identifier] = $url;
            }
        }
    }

    $answers_option = get_option('rrze-answers', []);
    $answers_option['registeredDomains'] = $domains;

    delete_option('rrze-answers');
    add_option('rrze-answers', $answers_option);

    update_option('rrze_migrate_domains_done', 1);
}

/**
 * ------------------------------------------------------------
 * Multisite plugin migration (FAQ/Glossary/Synonym -> Answers)
 * ------------------------------------------------------------
 */

/**
 * Old plugins to replace.
 */
function rrze_answers_migrate_targets(): array
{
    return [
        'rrze-faq/rrze-faq.php',
        'rrze-glossary/rrze-glossary.php',
        'rrze-synonym/rrze-synonym.php',
    ];
}

/**
 * Ensure WP's plugin API functions exist.
 */
function rrze_answers_ensure_plugin_functions(): void
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
}

/**
 * Refresh plugin-related caches.
 */
function rrze_answers_refresh_plugin_caches(): void
{
    if (function_exists('wp_clean_plugins_cache')) {
        wp_clean_plugins_cache(true);
    }

    // active_sitewide_plugins is stored in the site-options cache group.
    wp_cache_delete('active_sitewide_plugins', 'site-options');
    wp_cache_delete('active_plugins', 'options');
}

/**
 * Robust "is network active" check using the site option as source of truth.
 * (Helpful on installations with persistent object cache.)
 */
function rrze_answers_is_network_active(string $plugin_basename): bool
{
    $sitewide = (array) get_site_option('active_sitewide_plugins', []);
    if (isset($sitewide[$plugin_basename])) {
        return true;
    }

    // Fallback to core helper if available.
    return function_exists('is_plugin_active_for_network')
        ? is_plugin_active_for_network($plugin_basename)
        : false;
}

/**
 * Force network deactivation and verify with cache refresh.
 */
function rrze_answers_force_network_deactivate(string $plugin_basename): void
{
    if (!rrze_answers_is_network_active($plugin_basename)) {
        return;
    }

    deactivate_plugins($plugin_basename, false, true);

    rrze_answers_refresh_plugin_caches();
}

/**
 * Store report for display after activation redirect.
 */
function rrze_answers_store_report(array $payload): void
{
    set_site_transient(MIGRATE_REPORT_KEY, $payload, 10 * MINUTE_IN_SECONDS);
}

/**
 * One-time migration report notice in Network Admin.
 */
function rrze_answers_migrate_multisite_notice(): void
{
    if (!is_multisite() || !is_network_admin()) {
        return;
    }

    $payload = get_site_transient(MIGRATE_REPORT_KEY);
    if (empty($payload) || !is_array($payload)) {
        return;
    }

    delete_site_transient(MIGRATE_REPORT_KEY);

    $type   = $payload['type']   ?? 'info'; // info|success|warning|error
    $title  = $payload['title']  ?? 'RRZE-Answers';
    $intro  = $payload['intro']  ?? '';
    $items  = $payload['items']  ?? [];
    $footer = $payload['footer'] ?? '';

    $class = match ($type) {
        'success' => 'notice notice-success',
        'warning' => 'notice notice-warning',
        'error'   => 'notice notice-error',
        default   => 'notice notice-info',
    };

    echo '<div class="' . esc_attr($class) . '"><p><strong>' . esc_html($title) . '</strong>';
    if ($intro !== '') {
        echo ' ' . esc_html($intro);
    }
    echo '</p>';

    if (!empty($items)) {
        echo '<ul style="margin-left:1.2em">';
        foreach ($items as $row) {
            echo '<li>' . wp_kses_post($row) . '</li>';
        }
        echo '</ul>';
    }

    if ($footer !== '') {
        echo '<p>' . esc_html($footer) . '</p>';
    }

    echo '</div>';
}

/**
 * Core migration logic (called only from rrze_answers_on_activate_network()).
 *
 * Returns ['items' => array, 'report' => array]
 */
function rrze_answers_migrate_multisite_core(): array
{
    // In activation context we might not be on a screen, but the user must have capability.
    if (!current_user_can('manage_network_plugins')) {
        $report = [
            'type'   => 'error',
            'title'  => 'RRZE-Answers',
            'intro'  => __('Migration aborted: insufficient permissions (manage_network_plugins).', 'rrze-answers'),
            'items'  => [],
            'footer' => '',
        ];
        return ['items' => [], 'report' => $report];
    }

    rrze_answers_ensure_plugin_functions();

    // Safety: do not proceed if network-active.
    if (rrze_answers_is_network_active(RRZE_ANSWERS_PLUGIN)) {
        $report = [
            'type'   => 'error',
            'title'  => 'RRZE-Answers',
            'intro'  => __('Migration aborted: RRZE-Answers is network-activated. Please deactivate it network-wide and retry.', 'rrze-answers'),
            'items'  => [],
            'footer' => '',
        ];
        return ['items' => [], 'report' => $report];
    }

    $targets = rrze_answers_migrate_targets();

    $items = [];

    foreach (get_sites(['number' => 0]) as $site) {
        $blog_id = (int) $site->blog_id;

        switch_to_blog($blog_id);

        try {
            // Check if any old plugin is active on this site.
            $has_target = false;
            foreach ($targets as $p) {
                if (is_plugin_active($p)) {
                    $has_target = true;
                    break;
                }
            }

            if (!$has_target) {
                continue;
            }

            // Deactivate old plugins on this site.
            $deactivated = [];
            foreach ($targets as $p) {
                if (is_plugin_active($p)) {
                    deactivate_plugins($p, false, false);
                    $deactivated[] = dirname($p);
                }
            }

            // Activate RRZE-Answers on this site only.
            $activated_now = false;
            $activation_error = '';

            if (!is_plugin_active(RRZE_ANSWERS_PLUGIN)) {
                // silent=true prevents redirects/exits that would interrupt migration.
                $res = activate_plugin(RRZE_ANSWERS_PLUGIN, '', false, true);

                if (is_wp_error($res)) {
                    $activation_error = $res->get_error_message();
                } else {
                    $activated_now = true;
                }
            }

            // Report line.
            $label = get_bloginfo('name') . ' (' . home_url() . ')';

            $parts = [];
            $parts[] = !empty($deactivated)
                ? sprintf('%s %s.', esc_html__('Deactivated:', 'rrze-answers'), esc_html(implode(', ', $deactivated)))
                : esc_html__('Deactivated: none.', 'rrze-answers');

            if ($activation_error !== '') {
                $parts[] = sprintf(
                    '<strong style="color:#b32d2e">%s</strong> %s',
                    esc_html__('RRZE-Answers activation failed:', 'rrze-answers'),
                    esc_html($activation_error)
                );
            } else {
                $parts[] = $activated_now
                    ? esc_html__('RRZE-Answers activated.', 'rrze-answers')
                    : esc_html__('RRZE-Answers already active (no change).', 'rrze-answers');
            }

            $items[] = '<strong>' . esc_html($label) . '</strong>: ' . implode(' ', $parts);
        } finally {
            restore_current_blog();
        }
    }

    $report = !empty($items)
        ? [
            'type'   => 'success',
            'title'  => 'RRZE-Answers',
            'intro'  => __('Migration result (old plugins deactivated, RRZE-Answers activated where needed):', 'rrze-answers'),
            'items'  => $items,
            'footer' => '',
        ]
        : [
            'type'   => 'info',
            'title'  => 'RRZE-Answers',
            'intro'  => __('No sites required changes.', 'rrze-answers'),
            'items'  => [],
            'footer' => '',
        ];

    return ['items' => $items, 'report' => $report];
}
