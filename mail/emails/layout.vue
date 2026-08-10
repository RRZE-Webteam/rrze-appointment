<template>
  <Html lang="___RRZE_EMAIL_LANG___" dir="___RRZE_EMAIL_DIR___">
    <Head>
      <title>___RRZE_EMAIL_SUBJECT___</title>
      <style>
        @import "@maizzle/tailwindcss";

        @theme {
          --color-brand: #04316a;
          --color-brand-light: #e9f2fb;
          --color-canvas: #f3f5f7;
          --color-border: #d7dde5;
          --color-ink: #1f2937;
          --color-muted: #5f6b7a;
        }
      </style>
      <style embed>
        @media only screen and (max-width: 640px) {
          .rrze-email-shell {
            padding: 16px 8px !important;
          }

          .rrze-email-header,
          .rrze-email-content,
          .rrze-email-footer {
            padding-left: 20px !important;
            padding-right: 20px !important;
          }

          .rrze-email-title {
            font-size: 24px !important;
            line-height: 30px !important;
          }

          .rrze-email-details th,
          .rrze-email-details td {
            display: block !important;
            width: auto !important;
          }

          .rrze-email-details th {
            padding-bottom: 0 !important;
            border-bottom: 0 !important;
          }

          .rrze-email-details td {
            padding-top: 4px !important;
          }
        }

        .rrze-email-details {
          width: 100%;
          border-collapse: collapse;
        }

        .rrze-email-details th,
        .rrze-email-details td {
          padding: 10px 12px;
          border-bottom: 1px solid #e5e9ef;
          text-align: left;
          vertical-align: top;
        }

        .rrze-email-details th {
          width: 34%;
          color: #5f6b7a;
          font-size: 13px;
          font-weight: 600;
        }

        .rrze-email-content a {
          color: #04316a;
          font-weight: 600;
          text-decoration: underline;
          text-underline-offset: 2px;
        }

        @media (prefers-color-scheme: dark) {
          .rrze-email-card,
          .rrze-email-header {
            background-color: #ffffff !important;
          }
        }
      </style>
    </Head>
    <Tailwind>
      <Body
        lang="___RRZE_EMAIL_LANG___"
        dir="___RRZE_EMAIL_DIR___"
        class="m-0 w-full bg-canvas p-0 font-sans text-ink"
      >
        <Preheader>___RRZE_EMAIL_SUBJECT___</Preheader>
        <div
          class="rrze-email-shell w-full bg-canvas px-4 py-8 sm:px-2 sm:py-4"
          role="article"
          aria-label="___RRZE_EMAIL_SUBJECT___"
        >
          <Container class="max-w-[640px]">
            <div
              class="rrze-email-header rounded-t-lg border border-b-0 border-border bg-white px-8 pb-6 pt-7 sm:px-5"
            >
              <a
                href="___RRZE_EMAIL_SITE_URL___"
                class="inline-block no-underline"
                target="_blank"
              >
                <Raw>___RRZE_EMAIL_LOGO___</Raw>
              </a>
            </div>

            <div
              class="rrze-email-card border border-border border-t-4 border-t-brand bg-white"
            >
              <div class="px-8 pb-3 pt-7 sm:px-5">
                <p
                  class="m-0 mb-2 text-xs font-bold uppercase tracking-[0.08em] text-brand"
                >
                  ___RRZE_EMAIL_SITE_NAME___
                </p>
                <h1
                  class="rrze-email-title m-0 text-[28px] font-bold leading-9 text-ink sm:text-2xl sm:leading-8"
                >
                  ___RRZE_EMAIL_SUBJECT___
                </h1>
              </div>

              <div
                class="rrze-email-content px-8 pb-8 pt-3 text-[15px] leading-6 text-ink sm:px-5"
              >
                <Raw>___RRZE_EMAIL_CONTENT___</Raw>
              </div>
            </div>

            <div
              class="rrze-email-footer rounded-b-lg border border-t-0 border-border bg-brand-light px-8 py-5 text-xs leading-5 text-muted sm:px-5"
            >
              <p class="m-0 mb-1">
                <a
                  href="___RRZE_EMAIL_SITE_URL___"
                  class="font-semibold text-brand no-underline"
                  target="_blank"
                >
                  ___RRZE_EMAIL_SITE_NAME___
                </a>
              </p>
              <div class="m-0 text-muted">
                <Raw>___RRZE_EMAIL_FOOTER_LINKS___</Raw>
              </div>
            </div>
          </Container>
        </div>
      </Body>
    </Tailwind>
  </Html>
</template>
