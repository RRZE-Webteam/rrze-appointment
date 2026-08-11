<?php

defined('ABSPATH') || exit;

$pageTitle = sprintf(
    /* translators: 1: confirmation page title, 2: website name. */
    __('%1$s – %2$s', 'rrze-appointment'),
    __('Appointment confirmed', 'rrze-appointment'),
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
            display: grid;
            min-height: 100vh;
            margin: 0;
            padding: clamp(1rem, 4vw, 3rem);
            background: #f6f7f7;
            color: #1e1e1e;
            place-items: center;
        }

        .rrze-appointment-confirmation {
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

        .rrze-appointment-confirmation__illustration {
            display: flex;
            justify-content: center;
        }

        .rrze-appointment-confirmation__illustration img {
            display: block;
            width: min(100%, 30rem);
            height: auto;
        }

        .rrze-appointment-confirmation__status {
            display: inline-flex;
            align-items: center;
            min-height: 2rem;
            margin: 0 0 1.25rem;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            background: #e8f5e9;
            color: #1b5e20;
            font-size: 0.875rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .rrze-appointment-confirmation h1 {
            max-width: 13ch;
            margin: 0;
            color: #1e1e1e;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1.08;
            text-wrap: balance;
        }

        .rrze-appointment-confirmation__message {
            max-width: 38rem;
            margin: 1.5rem 0 0;
            color: #50575e;
            font-size: clamp(1rem, 2vw, 1.2rem);
            line-height: 1.7;
        }

        .rrze-appointment-confirmation__action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 3rem;
            margin-top: 2rem;
            padding: 0.75rem 1.25rem;
            border-radius: 0.35rem;
            background: #2e7d32;
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        .rrze-appointment-confirmation__action:hover {
            background: #1b5e20;
        }

        .rrze-appointment-confirmation__action:focus-visible {
            outline: 3px solid #ffca28;
            outline-offset: 3px;
        }

        @media (max-width: 48rem) {
            .rrze-appointment-confirmation {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .rrze-appointment-confirmation__illustration img {
                width: min(100%, 22rem);
            }

            .rrze-appointment-confirmation h1,
            .rrze-appointment-confirmation__message {
                margin-right: auto;
                margin-left: auto;
            }
        }
    </style>
</head>
<body>
    <main class="rrze-appointment-confirmation">
        <div class="rrze-appointment-confirmation__illustration" aria-hidden="true">
            <img src="<?php echo esc_url($illustrationUrl); ?>" alt="">
        </div>
        <div class="rrze-appointment-confirmation__content">
            <p class="rrze-appointment-confirmation__status">
                <?php esc_html_e('Successfully confirmed', 'rrze-appointment'); ?>
            </p>
            <h1><?php esc_html_e('Your appointment is confirmed', 'rrze-appointment'); ?></h1>
            <p class="rrze-appointment-confirmation__message">
                <?php esc_html_e('Thank you for confirming your appointment. We have sent the appointment details and a calendar invitation to your email address.', 'rrze-appointment'); ?>
            </p>
            <a class="rrze-appointment-confirmation__action" href="<?php echo esc_url($homeUrl); ?>">
                <?php esc_html_e('Back to website', 'rrze-appointment'); ?>
            </a>
        </div>
    </main>
</body>
</html>
