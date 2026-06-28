import type { NextConfig } from 'next';

const isProduction = process.env.NODE_ENV === 'production';

let apiOrigin = 'http://127.0.0.1:8000';
try {
  apiOrigin = new URL(process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1').origin;
} catch {
  apiOrigin = 'http://127.0.0.1:8000';
}

const connectSources = new Set<string>([
  "'self'",
  apiOrigin,
]);

if (!isProduction) {
  connectSources.add('http://localhost:8000');
  connectSources.add('http://127.0.0.1:8000');
}

// 'unsafe-inline' em script-src é necessário: o App Router do Next.js
// hidrata via <script> inline real (self.__next_f.push(...), payload de RSC),
// não apenas <script type="application/json">. Confirmado inspecionando o
// HTML de build de produção em 2026-06-25 — sem isso a hidratação quebra e
// o app fica inoperante. Removê-lo de verdade exige o padrão de nonce por
// requisição documentado pelo Next.js (middleware gerando nonce + tag no
// header), que precisa de teste em navegador real antes de ir para produção.
const scriptSource = isProduction ? "'self' 'unsafe-inline'" : "'self' 'unsafe-inline' 'unsafe-eval'";
const styleSource = isProduction ? "'self'" : "'self' 'unsafe-inline'";

const contentSecurityPolicy = [
  "default-src 'self'",
  "base-uri 'self'",
  "font-src 'self' data:",
  "form-action 'self'",
  "frame-ancestors 'self'",
  `frame-src 'self' blob: ${apiOrigin}`,
  `img-src 'self' data: blob: ${apiOrigin}`,
  "manifest-src 'self'",
  "object-src 'none'",
  `style-src ${styleSource}`,
  `script-src ${scriptSource}`,
  "worker-src 'self'",
  `connect-src ${Array.from(connectSources).join(' ')}`,
].join('; ');

const nextConfig: NextConfig = {
  reactStrictMode: true,
  allowedDevOrigins: ['localhost', '127.0.0.1'],
  async headers() {
    return [
      {
        source: '/sw.js',
        headers: [
          {
            key: 'Content-Type',
            value: 'application/javascript; charset=utf-8',
          },
          {
            key: 'Cache-Control',
            value: 'no-cache, no-store, must-revalidate',
          },
        ],
      },
      {
        source: '/:path*',
        headers: [
          {
            key: 'Content-Security-Policy',
            value: contentSecurityPolicy,
          },
          {
            key: 'Referrer-Policy',
            value: 'strict-origin-when-cross-origin',
          },
          {
            key: 'X-Content-Type-Options',
            value: 'nosniff',
          },
          {
            key: 'X-Frame-Options',
            value: 'SAMEORIGIN',
          },
        ],
      },
    ];
  },
};

export default nextConfig;
