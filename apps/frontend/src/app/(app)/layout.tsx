import { SentryComponent } from '@gitroom/frontend/components/layout/sentry.component';
import { getBackendUrl } from '@gitroom/helpers/utils/backend-url';

export const dynamic = 'force-dynamic';
import '../global.scss';
import 'react-tooltip/dist/react-tooltip.css';
import '@copilotkit/react-ui/styles.css';
import LayoutContext from '@gitroom/frontend/components/layout/layout.context';
import { ReactNode } from 'react';
import PlausibleProvider from 'next-plausible';
import clsx from 'clsx';
import { VariableContextComponent } from '@gitroom/react/helpers/variable.context';
import { PHProvider } from '@gitroom/react/helpers/posthog';
import UtmSaver from '@gitroom/helpers/utils/utm.saver';
import { DubAnalytics } from '@gitroom/frontend/components/layout/dubAnalytics';
import { FacebookComponent } from '@gitroom/frontend/components/layout/facebook.component';
import { headers } from 'next/headers';
import { headerName } from '@gitroom/react/translation/i18n.config';
import { HtmlComponent } from '@gitroom/frontend/components/layout/html.component';
// import dynamicLoad from 'next/dynamic';
// const SetTimezone = dynamicLoad(
//   () => import('@gitroom/frontend/components/layout/set.timezone'),
//   {
//     ssr: false,
//   }
// );

// Plus Jakarta Sans loaded via link (build-time fetch removed so build works offline)
const fontFamily = '"Plus Jakarta Sans", ui-sans-serif, sans-serif';

// Safe string for env inlining (avoids invalid JS from quotes/newlines in .env)
function envStr(value: string | undefined): string {
  return typeof value === 'string' ? value : '';
}

export default async function AppLayout({ children }: { children: ReactNode }) {
  const allHeaders = headers();
  const usePlausible = !!process.env.STRIPE_PUBLISHABLE_KEY;
  return (
    <html suppressHydrationWarning>
      <head>
        <link rel="icon" href="/favicon.ico" sizes="any" />
        <link
          href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,500;0,600;0,700;1,500;1,600;1,700&display=swap"
          rel="stylesheet"
        />
      </head>
      <body
        className={clsx('dark text-primary !bg-primary')}
        style={{ fontFamily }}
        suppressHydrationWarning
      >
        <VariableContextComponent
          storageProvider={
            (envStr(process.env.STORAGE_PROVIDER) || 'local') as
              | 'local'
              | 'cloudflare'
          }
          environment={envStr(process.env.NODE_ENV) || 'development'}
          backendUrl={getBackendUrl()}
          plontoKey={envStr(process.env.NEXT_PUBLIC_POLOTNO)}
          stripeClient={envStr(process.env.STRIPE_PUBLISHABLE_KEY)}
          billingEnabled={!!process.env.STRIPE_PUBLISHABLE_KEY}
          discordUrl={envStr(process.env.NEXT_PUBLIC_DISCORD_SUPPORT)}
          frontEndUrl={envStr(process.env.FRONTEND_URL)}
          isGeneral={!!process.env.IS_GENERAL}
          genericOauth={!!process.env.POSTIZ_GENERIC_OAUTH}
          oauthLogoUrl={envStr(process.env.NEXT_PUBLIC_POSTIZ_OAUTH_LOGO_URL)}
          oauthDisplayName={envStr(
            process.env.NEXT_PUBLIC_POSTIZ_OAUTH_DISPLAY_NAME
          )}
          uploadDirectory={envStr(
            process.env.NEXT_PUBLIC_UPLOAD_STATIC_DIRECTORY
          )}
          dub={!!process.env.STRIPE_PUBLISHABLE_KEY}
          facebookPixel={envStr(process.env.NEXT_PUBLIC_FACEBOOK_PIXEL)}
          telegramBotName={envStr(process.env.TELEGRAM_BOT_NAME)}
          neynarClientId={envStr(process.env.NEYNAR_CLIENT_ID)}
          isSecured={!process.env.NOT_SECURED}
          disableImageCompression={!!process.env.DISABLE_IMAGE_COMPRESSION}
          disableXAnalytics={!!process.env.DISABLE_X_ANALYTICS}
          sentryDsn={envStr(process.env.NEXT_PUBLIC_SENTRY_DSN)}
          language={allHeaders.get(headerName)}
          transloadit={
            process.env.TRANSLOADIT_AUTH && process.env.TRANSLOADIT_TEMPLATE
              ? [
                  envStr(process.env.TRANSLOADIT_AUTH),
                  envStr(process.env.TRANSLOADIT_TEMPLATE),
                ]
              : []
          }
        >
          <SentryComponent>
            {/*<SetTimezone />*/}
            <HtmlComponent />
            <DubAnalytics />
            <FacebookComponent />
            {usePlausible ? (
              <PlausibleProvider
                domain={!!process.env.IS_GENERAL ? 'postiz.com' : 'gitroom.com'}
              >
                <PHProvider
                  phkey={envStr(process.env.NEXT_PUBLIC_POSTHOG_KEY)}
                  host={envStr(process.env.NEXT_PUBLIC_POSTHOG_HOST)}
                >
                <LayoutContext>
                  <UtmSaver />
                  {children}
                </LayoutContext>
                </PHProvider>
              </PlausibleProvider>
            ) : (
              <PHProvider
                phkey={envStr(process.env.NEXT_PUBLIC_POSTHOG_KEY)}
                host={envStr(process.env.NEXT_PUBLIC_POSTHOG_HOST)}
              >
                <LayoutContext>
                  <UtmSaver />
                  {children}
                </LayoutContext>
              </PHProvider>
            )}
          </SentryComponent>
        </VariableContextComponent>
      </body>
    </html>
  );
}
