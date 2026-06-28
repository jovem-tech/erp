import type { NextConfig } from 'next';

const isProduction = process.env.NODE_ENV === 'production';

let apiOrigin = 'http://localhost:8000';
try {
  apiOrigin = new URL(process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:8000/api/v1').origin;
} catch {
  apiOrigin = 'http://localhost:8000';
}

let reverbOrigin = 'http://localhost:8090';
try {
  const reverbHost = process.env.NEXT_PUBLIC_REVERB_HOST ?? 'localhost';
  const reverbPort = process.env.NEXT_PUBLIC_REVERB_PORT ?? '8090';
  const reverbScheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? 'http';
  reverbOrigin = `${reverbScheme}://${reverbHost}:${reverbPort}`;
} catch {
  reverbOrigin = 'http://localhost:8090';
}

const wsOrigin = reverbOrigin.replace(/^http/, 'ws');

const connectSources = new Set<string>([
  "'self'",
  apiOrigin,
  reverbOrigin,
  wsOrigin,
]);

if (!isProduction) {
  connectSources.add('http://localhost:8000');
  connectSources.add('http://127.0.0.1:8000');
  connectSources.add('ws://localhost:8090');
}

// 'unsafe-inline' em script-src e necessario: o App Router hidrata via <script>
// inline real (self.__next_f.push(...)). Mesma decisao documentada em
// frontends/mobile/next.config.ts.
const scriptSource = isProduction ? "'self' 'unsafe-inline'" : "'self' 'unsafe-inline' 'unsafe-eval'";

// O overlay de Next.js DevTools (node_modules/next/dist/compiled/next-devtools) injeta
// estilo inline para se posicionar, e nao respeita devIndicators:false nesta versao do
// Next (15.5.19) — so' a parte visual do indicador e' escondida, a infraestrutura do
// overlay continua montando. Relaxado so' em dev; producao continua restrita.
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
  // O overlay de dev tools do Next injeta estilo inline, violando a CSP
  // restritiva (style-src 'self') e poluindo o console com avisos. Cosmetico,
  // mas removido a pedido — nao afeta build de producao.
  devIndicators: false,
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
