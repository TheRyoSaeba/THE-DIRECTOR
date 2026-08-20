import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';


const ClockContext = createContext(null);

const parseServerUnix = (time) => {
    const parsed = Math.floor(new Date(time).getTime() / 1000);
    return Number.isFinite(parsed) ? parsed : Math.floor(Date.now() / 1000);
};

export function ClockProvider({ initialTime, children }) {
    const anchor = useRef({
        serverUnix: parseServerUnix(initialTime),
        perfNow: performance.now(),
    });

    const [serverClock, setServerClock] = useState(anchor.current.serverUnix);

    const readClock = useCallback(() => {
        const elapsed = performance.now() - anchor.current.perfNow;
        return anchor.current.serverUnix + Math.floor(elapsed / 1000);
    }, []);

    const syncServerClock = useCallback((time) => {
        anchor.current = {
            serverUnix: parseServerUnix(time),
            perfNow: performance.now(),
        };
        setServerClock(anchor.current.serverUnix);
    }, []);

    useEffect(() => {
        const tick = () => {
            setServerClock(readClock());
        };

        tick();
        const id = setInterval(tick, 1000);
        window.addEventListener('focus', tick);
        document.addEventListener('visibilitychange', tick);
        const removeRouterListener = router.on('success', (event) => {
            const serverTime = event.detail.page.props?.serverTime;
            if (serverTime) {
                syncServerClock(serverTime);
            }
        });

        return () => {
            clearInterval(id);
            window.removeEventListener('focus', tick);
            document.removeEventListener('visibilitychange', tick);
            removeRouterListener();
        };
    }, [readClock, syncServerClock]);

    return (
        <ClockContext.Provider value={serverClock}>
            {children}
        </ClockContext.Provider>
    );
}

export const useServerClock = () => {
    const ctx = useContext(ClockContext);
    if (ctx === null) throw new Error('useServerClock must be used within a ClockProvider');
    return ctx;
};

export default ClockContext;
