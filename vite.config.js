import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { visualizer } from 'rollup-plugin-visualizer';

export default defineConfig(({ mode }) => {
    const plugins = [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
            ],
            refresh: true,
        }),
        react(),
    ];

    if (mode === 'analyze') {
        plugins.push(
            visualizer({
                filename: 'bundle-analysis.html',
                open: true,
                gzipSize: true,
                brotliSize: true,
            })
        );
    }

    return {
        plugins,
        build: {
            outDir: 'public/build',
            rollupOptions: {
                output: {
                    assetFileNames: 'assets/[name]-[hash][extname]',
                    chunkFileNames: 'assets/[name]-[hash].js',
                    entryFileNames: 'assets/[name]-[hash].js',
                    // Function form so we control exactly which node_modules land where.
                    // react/jsx-runtime + scheduler must sit with React (not with
                    // framer-motion), and icons are NOT grouped: each Phosphor icon is
                    // bundled with the pages that actually use it.
                    manualChunks(id) {
                        if (!id.includes('/node_modules/')) {
                            return undefined;
                        }
                        if (/\/node_modules\/(react|react-dom|scheduler|@inertiajs\/[^/]+)\//.test(id)) {
                            return 'vendor-react';
                        }
                        if (/\/node_modules\/(framer-motion|motion-dom|motion-utils)\//.test(id)) {
                            return 'vendor-animation';
                        }
                        return undefined;
                    },
                },
            },
        },
        server: {
            host: 'localhost',
            port: 5173,
            strictPort: true,
            hmr: {
                host: 'localhost',
                protocol: 'ws',
            },
            cors: true,
            origin: 'http://localhost:5173',
            watch: {
                ignored: [
                    '**/storage/**',
                    '**/vendor/**',
                    '**/node_modules/**',
                ],
            },
        },
    };
});