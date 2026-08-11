<?php

defined('ABSPATH') || exit;

$locale = function_exists('determine_locale') ? determine_locale() : get_locale();
$isGerman = preg_match('/^de(?:_|-|$)/i', (string) $locale) === 1;
$legalLinks = $isGerman
    ? [
        'Impressum' => 'impressum',
        'Datenschutz' => 'datenschutz',
        'Barrierefreiheit' => 'barrierefreiheit',
    ]
    : [
        'Imprint' => 'imprint',
        'Privacy' => 'privacy',
        'Accessibility' => 'accessibility',
    ];
?>
<footer class="rrze-appointment-public-footer">
    <nav
        class="rrze-appointment-public-footer__links"
        aria-label="<?php echo esc_attr($isGerman ? 'Rechtliche Informationen' : 'Legal information'); ?>"
    >
        <?php foreach ($legalLinks as $label => $path) : ?>
            <a href="<?php echo esc_url(home_url('/' . $path . '/')); ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <p class="rrze-appointment-public-footer__credit">
        Illustrations by <a href="https://www.manypixels.co/">manypixels.co</a>
    </p>
</footer>
