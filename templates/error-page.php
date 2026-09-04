<?php

defined('ABSPATH') || exit;

$pageTitle = sprintf(
    /* translators: 1: error message, 2: website name. */
    __('%1$s – %2$s', 'rrze-appointment'),
    $errorMessage,
    $siteName
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, noarchive">
    <title><?php echo esc_html($pageTitle); ?></title>
    <style>
        :root {
            color-scheme: light;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: clamp(1rem, 4vw, 3rem);
            background: #f6f7f7;
            color: #1e1e1e;
            gap: 1.5rem;
        }

        .rrze-appointment-error {
            display: grid;
            align-items: center;
            width: min(70rem, 100%);
            padding: clamp(2rem, 6vw, 5rem);
            border: 1px solid #e0e0e0;
            border-radius: 1.5rem;
            background: #fff;
            box-shadow: 0 1.5rem 4rem rgba(30, 30, 30, 0.1);
            gap: clamp(2rem, 6vw, 5rem);
            grid-template-columns: minmax(16rem, 0.9fr) minmax(18rem, 1.1fr);
        }

        .rrze-appointment-error__illustration {
            display: flex;
            justify-content: center;
        }

        .rrze-appointment-error__illustration img {
            display: block;
            width: min(100%, 30rem);
            height: auto;
            max-height: min(60vh, 30rem);
            object-fit: contain;
        }

        .rrze-appointment-error__status {
            display: inline-flex;
            align-items: center;
            min-height: 2rem;
            margin: 0 0 1.25rem;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            background: #ffebee;
            color: #b71c1c;
            font-size: 0.875rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .rrze-appointment-error h1 {
            max-width: 18ch;
            margin: 0;
            color: #1e1e1e;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1.08;
            text-wrap: balance;
        }

        .rrze-appointment-error__message {
            max-width: 38rem;
            margin: 1.5rem 0 0;
            color: #50575e;
            font-size: clamp(1rem, 2vw, 1.2rem);
            line-height: 1.7;
        }

        .rrze-appointment-error__action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 3rem;
            margin-top: 2rem;
            padding: 0.75rem 1.25rem;
            border-radius: 0.35rem;
            background: #04316a;
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        .rrze-appointment-error__action:hover {
            background: #021f46;
        }

        .rrze-appointment-error__action:focus-visible,
        .rrze-appointment-public-footer a:focus-visible {
            border-radius: 0.15rem;
            outline: 3px solid #04316a;
            outline-offset: 3px;
        }

        .rrze-appointment-public-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            width: min(70rem, 100%);
            padding: 0 0.75rem;
            color: #646970;
            gap: 0.75rem 1.5rem;
            font-size: 0.875rem;
        }

        .rrze-appointment-public-footer__links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1.25rem;
        }

        .rrze-appointment-public-footer a {
            color: #50575e;
            text-underline-offset: 0.2em;
        }

        .rrze-appointment-public-footer a:hover {
            color: #1e1e1e;
        }

        .rrze-appointment-public-footer__credit {
            margin: 0;
            font-size: 0.8125rem;
            font-style: italic;
        }

        @media (max-width: 48rem) {
            .rrze-appointment-error {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .rrze-appointment-error__illustration img {
                width: min(100%, 22rem);
            }

            .rrze-appointment-error h1,
            .rrze-appointment-error__message {
                margin-right: auto;
                margin-left: auto;
            }

            .rrze-appointment-public-footer,
            .rrze-appointment-public-footer__links {
                justify-content: center;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <main class="rrze-appointment-error">
        <div class="rrze-appointment-error__illustration" aria-hidden="true">
            <img src="<?php echo esc_url($illustrationUrl); ?>" alt="">
        </div>
        <div class="rrze-appointment-error__content">
            <p class="rrze-appointment-error__status">
                <?php esc_html_e('Link unavailable', 'rrze-appointment'); ?>
            </p>
            <h1><?php echo esc_html($errorMessage); ?></h1>
            <p class="rrze-appointment-error__message">
                <?php esc_html_e('No changes were made. You can safely return to the website.', 'rrze-appointment'); ?>
            </p>
            <a class="rrze-appointment-error__action" href="<?php echo esc_url($homeUrl); ?>">
                <?php esc_html_e('Back to website', 'rrze-appointment'); ?>
            </a>
        </div>
    </main>
    <?php require __DIR__ . '/public-page-footer.php'; ?>
</body>
</html>
