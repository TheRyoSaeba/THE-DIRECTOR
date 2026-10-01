import '../css/app.css';

import { createRoot } from 'react-dom/client'
import { createInertiaApp, router } from '@inertiajs/react'
import { ClockProvider } from '@/contexts/ClockContext'
import { MotionConfig } from 'framer-motion'


// @ts-ignore
router.on('before', (event) => {
    // @ts-ignore
    if (event.detail.visit.url.pathname === '/logout') {
        localStorage.removeItem('last_earn_id');
    }
})

// @ts-ignore
router.on('httpException', (event) => {
    // @ts-ignore
    const response = event.detail.response

    if (response?.status === 419) {
        event.preventDefault()

        const key = 'inertia_419_reload_at'
        const lastReload = Number(sessionStorage.getItem(key) || 0)
        const now = Date.now()

        if (now - lastReload > 5000) {
            sessionStorage.setItem(key, String(now))
            window.location.reload()
        }
    }
})

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.{jsx,tsx}', { eager: false })
        const path = `./Pages/${name}.jsx`
        const tsxPath = `./Pages/${name}.tsx`
        const page = pages[path] ?? pages[tsxPath]
        if (!page) {
            throw new Error(`Inertia page not found: ${name}`)
        }
        // @ts-ignore — Vite's glob loader returns () => Promise<Module>
        return (page as () => Promise<{ default: any }>)().then(m => m.default)
    },
    setup({ el, App, props }) {
        const initialServerTime = props.initialPage?.props?.serverTime || new Date().toISOString()
        createRoot(el).render(
            // reducedMotion="user": framer-motion drops transform/layout
            // animations (keeps opacity) when the OS asks for reduced motion.
            <MotionConfig reducedMotion="user">
                <ClockProvider initialTime={initialServerTime}>
                    <App {...props} />
                </ClockProvider>
            </MotionConfig>
        )
    },
    progress: {
        color: '#06b6d4',
        delay: 2000,
        showSpinner: false,
    },
})
