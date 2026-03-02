<?php

namespace RRZE\Appointment;

use function RRZE\Appointment\plugin;

use RRZE\Appointment\Defaults;

use RRZE\Appointment\Common\Settings\Settings;


defined('ABSPATH') || exit;

/**
 * Main class
 * 
 * This class serves as the entry point for the plugin.
 * It can be extended to include additional functionality or components as needed.
 * 
 * @package RRZE\Appointment\Common
 * @since 1.0.0
 */
class Main
{
    public $defaults;
    public $settings;

    public function __construct()
    {
        add_action('init', [$this, 'onInit']);
        add_filter('wp_kses_allowed_html', [$this, 'my_custom_allowed_html'], 10, 2);
    }

    public function onInit()
    {
        $this->defaults = new Defaults();
        // $this->settings();

        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('enqueue_block_assets', [$this, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }



    /**
     * Allow needed HTML on post content sanitized by wp_kses_post().
     *
     * @param array  $allowed_tags The current allowed tags/attributes for the given context.
     * @param string $context      KSES context; wp_kses_post() uses 'post'.
     * @return array               Modified allowed tags/attributes.
     */
    function my_custom_allowed_html($allowed_tags, $context)
    {
        // Only alter the 'post' context used by wp_kses_post()
        if ($context !== 'post') {
            return $allowed_tags;
        }

        // 1) Schema.org microdata attributes we want to allow on various elements
        $schema_attrs = [
            'itemscope' => true, // boolean attribute (no value needed)
            'itemtype' => true, // URL to schema type, e.g. https://schema.org/FAQPage
            'itemprop' => true, // property name within the item
            'itemid' => true, // global identifier
            'itemref' => true, // references other elements by ID
        ];

        // 2) HTML5 elements that may carry microdata in your templates/shortcodes
        $tags_to_extend = [
            'div',
            'span',
            'p',
            'a',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'ul',
            'ol',
            'li',
            'section',
            'article',
            'header',
            'footer',
            'main',
            'nav',
            'details',
            'summary'
        ];

        // Ensure details/summary exist with common attributes for accordion UI
        if (!isset($allowed_tags['details'])) {
            $allowed_tags['details'] = [];
        }
        $allowed_tags['details'] = array_merge($allowed_tags['details'], [
            'id' => true,
            'class' => true,
            'open' => true, // render expanded by default
        ]);

        if (!isset($allowed_tags['summary'])) {
            $allowed_tags['summary'] = [];
        }
        $allowed_tags['summary'] = array_merge($allowed_tags['summary'], [
            'id' => true,
            'class' => true,
        ]);

        // 3) Add Schema.org attributes to the listed tags without removing existing ones
        foreach ($tags_to_extend as $tag) {
            if (!isset($allowed_tags[$tag])) {
                $allowed_tags[$tag] = [];
            }
            $allowed_tags[$tag] = array_merge($allowed_tags[$tag], $schema_attrs);
        }

        // 4) (Optional) keep your form elements if you output any in content
        $allowed_tags['select'] = array_merge($allowed_tags['select'] ?? [], [
            'name' => true,
            'id' => true,
            'class' => true,
            'multiple' => true,
            'size' => true,
        ]);

        $allowed_tags['option'] = array_merge($allowed_tags['option'] ?? [], [
            'value' => true,
            'selected' => true,
        ]);

        $allowed_tags['input'] = array_merge($allowed_tags['input'] ?? [], [
            'type' => true,
            'name' => true,
            'id' => true,
            'class' => true,
            'value' => true,
            'placeholder' => true,
            'checked' => true,
            'disabled' => true,
            'readonly' => true,
            'maxlength' => true,
            'size' => true,
            'min' => true,
            'max' => true,
            'step' => true,
        ]);

        return $allowed_tags;
    }


    /**
     * Settings method
     * 
     * This method sets up the plugin settings using the Settings class.
     * It defines the settings sections and options that will be available in the WordPress admin area
     * and provides validation and sanitization for the settings.
     * 
     * @return void
     */


    public function settings()
    {
        $this->settings = new Settings($this->defaults->get('settings')['page_title']);

        $this->settings->setCapability($this->defaults->get('settings')['capability'])
            ->setOptionName($this->defaults->get('settings')['option_name'])
            ->setMenuTitle($this->defaults->get('settings')['menu_title'])
            ->setMenuPosition(6)
            ->setMenuParentSlug('options-general.php');

        foreach ($this->defaults->get('sections') as $section) {
            $tab = $this->settings->addTab(__($section['title'], 'rrze-appointment'), $section['id']);
            $sec = $tab->addSection(__($section['title'], 'rrze-appointment'), $section['id']);

            foreach ($this->defaults->get('fields')[$section['id']] as $field) {
                $sec->addOption($field['type'], array_intersect_key(
                    $field,
                    array_flip(['name', 'label', 'description', 'options', 'default', 'sanitize', 'validate', 'placeholder'])
                ));
            }
        }

        $this->settings->build();
    }

    /**
     * Enqueue der globale Skripte.
     */
    public function enqueueAssets()
    {

    }

    public function enqueueAdminAssets()
    {
    }

}


