import type { Metadata, Viewport } from 'next';
import Script from 'next/script';
import './globals.css';
import { SessionProvider } from '@/components/session-provider';
import { PwaRegister } from '@/components/pwa-register';

export const metadata: Metadata = {
  applicationName: 'Sistema ERP Mobile',
  title: 'Sistema ERP Mobile',
  description: 'PWA mobile do Sistema ERP com backend central e sessão Bearer.',
  manifest: '/manifest.webmanifest',
  icons: {
    icon: [
      {
        url: '/icon-192.png',
        sizes: '192x192',
        type: 'image/png',
      },
      {
        url: '/icon-512.png',
        sizes: '512x512',
        type: 'image/png',
      },
    ],
    apple: [
      {
        url: '/apple-touch-icon.png',
        sizes: '180x180',
        type: 'image/png',
      },
    ],
  },
  appleWebApp: {
    capable: true,
    title: 'Sistema ERP Mobile',
    statusBarStyle: 'black-translucent',
  },
};

export const viewport: Viewport = {
  themeColor: '#06111f',
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="pt-BR" suppressHydrationWarning>
      <body>
        <Script src="/theme-bootstrap.js" strategy="beforeInteractive" />
        <SessionProvider>{children}</SessionProvider>
        <PwaRegister />
      </body>
    </html>
  );
}
