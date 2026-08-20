import { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { Trophy } from '@phosphor-icons/react';

const ConfettiParticle = ({ delay, color }) => {
    const angle = Math.random() * 360;
    const distance = 150 + Math.random() * 200;
    const x = Math.cos((angle * Math.PI) / 180) * distance;
    const y = Math.sin((angle * Math.PI) / 180) * distance;

    return (
        <motion.div
            initial={{ x: 0, y: 0, scale: 0, rotate: 0, opacity: 1 }}
            animate={{ 
                x, 
                y: -150 + y, 
                scale: [0, 1.5, 0.8],
                rotate: Math.random() * 1080 - 540,
                opacity: [1, 1, 0]
            }}
            transition={{ duration: 2.5, delay, ease: "easeOut" }}
            className="absolute w-3 h-3 rounded-full"
            style={{ backgroundColor: color, left: '50%', top: '50%' }}
        />
    );
};

const AchievementEntry = ({ entry, onDelete, onSave, processingId }) => {
    const [phase, setPhase] = useState('slideIn');
    const [showConfetti, setShowConfetti] = useState(false);
    const [showExplosion, setShowExplosion] = useState(false);

    useEffect(() => {
        const timers = [
            setTimeout(() => setPhase('float'), 1000),
            setTimeout(() => setPhase('hover'), 2000),
            setTimeout(() => setShowExplosion(true), 2500),
            setTimeout(() => setPhase('explode'), 2800),
            setTimeout(() => setShowConfetti(true), 3200),
            setTimeout(() => setPhase('settle'), 4500),
        ];
        return () => timers.forEach(clearTimeout);
    }, []);

    const confettiColors = [
        '#FFD700', '#FFA500', '#FFEC8B', '#FFE4B5', '#FFF8DC', 
        '#FF6347', '#00CED1', '#FF69B4', '#7FFF00', '#FF4500',
        '#FFD700', '#FFA500', '#FFEC8B', '#00FF7F', '#FF1493'
    ];

    const getAnimation = () => {
        switch (phase) {
            case 'slideIn':
                return { x: 0, y: 0, scale: 1 };
            case 'float':
                return { x: 0, y: -8, scale: 1.03 };
            case 'hover':
                return { x: 0, y: -6, scale: 1.04 };
            case 'explode':
                return { x: 0, y: -6, scale: 1.04 };
            case 'settle':
            default:
                return { x: 0, y: 0, scale: 1 };
        }
    };

    const getGlow = () => {
        switch (phase) {
            case 'explode':
                return '0 0 150px rgba(255,215,0,1), 0 0 250px rgba(255,152,0,0.8)';
            case 'float':
            case 'hover':
                return '0 0 60px rgba(255,215,0,0.7)';
            case 'settle':
                return '0 0 30px rgba(255,215,0,0.5)';
            default:
                return '0 0 40px rgba(255,215,0,0.5)';
        }
    };

    return (
        <div className="relative my-3" style={{ minHeight: '120px' }}>
            <div className="fixed inset-0 pointer-events-none" style={{ zIndex: 9999 }}>
                {showConfetti && [...Array(35)].map((_, i) => (
                    <ConfettiParticle
                        key={i}
                        delay={i * 0.04}
                        color={confettiColors[i % confettiColors.length]}
                    />
                ))}
                
                {showExplosion && (
                    <motion.div
                        style={{ 
                            position: 'fixed',
                            left: '50%', 
                            top: '50%', 
                            width: '100px', 
                            height: '100px',
                            marginLeft: '-50px',
                            marginTop: '-50px'
                        }}
                        initial={{ scale: 0, opacity: 0 }}
                        animate={{ scale: [0, 5, 8], opacity: [0, 1, 0] }}
                        transition={{ duration: 1.2, ease: "easeOut" }}
                    >
                        <div 
                            className="w-full h-full rounded-full"
                            style={{
                                background: 'radial-gradient(circle, rgba(255,255,255,1) 0%, rgba(255,215,0,0.9) 30%, rgba(255,152,0,0.6) 50%, transparent 70%)'
                            }}
                        />
                    </motion.div>
                )}
            </div>

            <motion.div
                initial={{ x: -400, opacity: 0 }}
                animate={{ ...getAnimation(), opacity: 1 }}
                transition={{ type: "spring", stiffness: 80, damping: 20 }}
                style={{ boxShadow: getGlow() }}
                className="relative z-20 rounded-xl border-2 border-yellow-500/70 overflow-hidden"
            >
                <div 
                    className="p-4"
                    style={{
                        background: phase === 'settle' 
                            ? 'linear-gradient(135deg, rgba(255,215,0,0.25) 0%, rgba(255,193,7,0.2) 50%, rgba(255,152,0,0.15) 100%)'
                            : 'linear-gradient(135deg, rgba(255,215,0,0.4) 0%, rgba(255,193,7,0.35) 50%, rgba(255,152,0,0.3) 100%)'
                    }}
                >
                    <div className="flex items-start gap-4">
                        <motion.div 
                            className="w-14 h-14 rounded-xl flex items-center justify-center border-2 border-yellow-400/70 bg-gradient-to-br from-yellow-500/40 to-orange-500/30 shrink-0"
                            animate={phase === 'explode' ? { scale: [1, 1.4, 1], rotate: [0, 20, -20, 0] } : {}}
                            transition={{ duration: 0.6 }}
                        >
                            <Trophy className="h-8 w-8 text-yellow-300" weight="fill" />
                        </motion.div>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-3 mb-2">
                                <span className="font-bold text-yellow-200 text-xl tracking-wide drop-shadow-lg">
                                    {entry.title}
                                </span>
                                {!entry.is_read && (
                                    <motion.span 
                                        className="w-3 h-3 rounded-full bg-yellow-400"
                                        animate={{ scale: [1, 1.4, 1], opacity: [1, 0.6, 1] }}
                                        transition={{ duration: 1.5, repeat: Infinity }}
                                    />
                                )}
                            </div>
                            <p className="text-base text-yellow-100 leading-relaxed mb-4 drop-shadow">
                                {entry.description}
                            </p>
                            <div className="flex gap-3 mt-3">
                                <button
                                    onClick={() => onDelete(entry.id)}
                                    disabled={processingId === entry.id}
                                    className="px-4 py-2 rounded-lg text-sm font-medium bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 transition disabled:opacity-50"
                                >
                                    Delete
                                </button>
                                <button
                                    onClick={() => onSave(entry.id)}
                                    disabled={processingId === entry.id}
                                    className="px-4 py-2 rounded-lg text-sm font-medium bg-yellow-600/40 hover:bg-yellow-500/50 text-yellow-200 border border-yellow-500/40 transition disabled:opacity-50"
                                >
                                    Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </motion.div>
        </div>
    );
};

export default AchievementEntry;
