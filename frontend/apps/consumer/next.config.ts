import type { NextConfig } from 'next';

const apiOrigin = process.env.API_ORIGIN ?? 'http://127.0.0.1:8000';

const nextConfig: NextConfig = {
  // The browser only ever talks to this site. /api/* is forwarded to Laravel, so there is no
  // CORS and the cart cookie is first-party.
  async rewrites() {
    return [{ source: '/api/:path*', destination: `${apiOrigin}/api/:path*` }];
  },
};

export default nextConfig;