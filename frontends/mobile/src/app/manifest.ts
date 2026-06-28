import type { MetadataRoute } from 'next';

export default function manifest(): MetadataRoute.Manifest {
  return {
    id: '/',
    name: 'Sistema ERP Mobile',
    short_name: 'ERP Mobile',
    description: 'PWA mobile do Sistema ERP para atendimento e ordens de servico.',
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
