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
            manifest: true,
            outDir: 'public/build',
            rollupOptions: {
                output: {
                    assetFileNames: 'assets/[name]-[hash][extname]',
                    chunkFileNames: 'assets/[name]-[hash].js',
                    entryFileNames: 'assets/[name]-[hash].js',
                    manualChunks: {
                        'vendor-react': ['react', 'react-dom', '@inertiajs/react'],
                        'vendor-animation': ['framer-motion'],
                        'vendor-icons': ['@phosphor-icons/react'],
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