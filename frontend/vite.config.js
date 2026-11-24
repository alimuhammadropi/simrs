import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const apiTarget = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8086';
  const servicesTarget = env.VITE_SERVICES_PROXY_TARGET || 'http://127.0.0.1:3010';

  return {
    plugins: [react()],
    server: {
      port: 5173,
      proxy: {
        '/api': {
          target: apiTarget,
          changeOrigin: true,
        },
        '/services': {
          target: servicesTarget,
          changeOrigin: true,
          rewrite: (path) => path.replace(/^\/services/, ''),
        },
      },
    },
  };
});
