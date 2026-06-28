import type { MetadataRoute } from 'next';

export default function manifest(): MetadataRoute.Manifest {
  return {
    id: '/',
    name: 'Sistema ERP - Central de Atendimento',
    short_name: 'ERP Chat',
    description: 'Central de Atendimento do Sistema ERP — inbox de conversas em tempo real.',
    start_url: '/',
    scope: '/',
    display: 'standalone',
    background_color: '#06111f',
    theme_color: '#06111f',
    lang: 'pt-BR',
    icons: [
      {
        src: '/icon-192.png',
        sizes: '192x192',
        type: 'image/png',
        purpose: 'any',
      },
      {
        src: '/icon-512.png',
        sizes: '512x512',
        type: 'image/png',
        purpose: 'maskable',
      },
    ],
  };
}
