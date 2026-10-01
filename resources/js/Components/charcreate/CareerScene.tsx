import { useEffect, useId, useRef, useState, type CSSProperties } from 'react';
import CustomsScene from './CustomsScene';
import CorporationScene from './CorporationScene';
import TechnicianScene from './TechnicianScene';
import './scenes.css';

const SCENES = {
    customs: CustomsScene,
    corporation: CorporationScene,
    technician: TechnicianScene,
} as const;

export const hasCareerScene = (code: string): code is keyof typeof SCENES => code in SCENES;

export interface CareerSceneProps {
    code: string;
    /** `full` runs the whole story (hover / focus / selected); `ambient` is the slow idle loop. */
    mode: 'ambient' | 'full';
    /** Force-pause (e.g. hidden side tab). Offscreen scenes pause themselves. */
    paused?: boolean;
    /** SVG preserveAspectRatio — the panel picks how the 4:3 scene sits in its box. */
    align?: string;
    className?: string;
    style?: CSSProperties;
}

/**
 * Hand-authored SVG motion graphic per career. Animation is pure CSS keyframes
 * (scenes.css) on transform / opacity / stroke-dashoffset; this component only
 * flips `data-mode` and `data-paused`. prefers-reduced-motion shows the static
 * composed frame the markup draws on its own.
 */
export function CareerScene({ code, mode, paused = false, align = 'xMidYMid meet', className, style }: CareerSceneProps) {
    const ref = useRef<HTMLDivElement>(null);
    const [onscreen, setOnscreen] = useState(true);
    const uid = 'cc' + useId().replace(/[^A-Za-z0-9_-]/g, '');

    useEffect(() => {
        const el = ref.current;
        if (!el || typeof IntersectionObserver === 'undefined') return;
        const io = new IntersectionObserver(([entry]) => setOnscreen(entry.isIntersecting));
        io.observe(el);
        return () => io.disconnect();
    }, []);

    if (!hasCareerScene(code)) return null;
    const Scene = SCENES[code];

    return (
        <div ref={ref} style={style} className={`cc-scene ${className ?? ''}`} data-mode={mode === 'full' ? 'f' : 'a'} data-paused={paused || !onscreen ? '' : undefined}>
            <svg viewBox="0 0 400 300" preserveAspectRatio={align} className="block h-full w-full" aria-hidden focusable="false">
                <Scene uid={uid} />
            </svg>
        </div>
    );
}

export default CareerScene;
