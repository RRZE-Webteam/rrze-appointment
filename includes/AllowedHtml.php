<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Extends WordPress' post-content allowlist for appointment markup.
 */
final class AllowedHtml
{
    /**
     * Adds microdata and form attributes required by the plugin templates.
     *
     * @param array<string, array<string, bool>> $allowedTags Allowed tags and attributes.
     * @param string                            $context     KSES sanitization context.
     * @return array<string, array<string, bool>>
     */
    public function filter(array $allowedTags, string $context): array
    {
        if ($context !== 'post') {
            return $allowedTags;
        }

        $schemaAttributes = [
            'itemscope' => true,
            'itemtype' => true,
            'itemprop' => true,
            'itemid' => true,
            'itemref' => true,
        ];
        $schemaTags = [
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
        ];

        foreach ($schemaTags as $tag) {
            $allowedTags[$tag] = array_merge($allowedTags[$tag] ?? [], $schemaAttributes);
        }

        $allowedTags['select'] = array_merge($allowedTags['select'] ?? [], [
            'name' => true,
            'id' => true,
            'class' => true,
            'multiple' => true,
            'size' => true,
        ]);
        $allowedTags['option'] = array_merge($allowedTags['option'] ?? [], [
            'value' => true,
            'selected' => true,
        ]);
        $allowedTags['input'] = array_merge($allowedTags['input'] ?? [], [
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
            'required' => true,
        ]);
        $allowedTags['form'] = array_merge($allowedTags['form'] ?? [], [
            'action' => true,
            'method' => true,
            'id' => true,
            'class' => true,
        ]);
        $allowedTags['fieldset'] = array_merge($allowedTags['fieldset'] ?? [], [
            'id' => true,
            'class' => true,
        ]);
        $allowedTags['legend'] = array_merge($allowedTags['legend'] ?? [], [
            'id' => true,
            'class' => true,
        ]);
        $allowedTags['label'] = array_merge($allowedTags['label'] ?? [], [
            'for' => true,
            'id' => true,
            'class' => true,
        ]);
        $allowedTags['button'] = array_merge($allowedTags['button'] ?? [], [
            'type' => true,
            'name' => true,
            'value' => true,
            'id' => true,
            'class' => true,
            'disabled' => true,
        ]);

        return $allowedTags;
    }
}
