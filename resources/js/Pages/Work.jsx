import React, { useState } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { Circle } from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';

const JobCard = ({ job, isSelected, onSelect, disabled }) => (
    <button
        onClick={() => onSelect(job.id)}
        disabled={disabled}
        className={`group grid min-h-11 w-full grid-cols-[20px_minmax(0,1fr)] items-center gap-2.5 border-b border-slate-800/50 py-2.5 text-left transition-colors sm:gap-3 ${disabled ? 'opacity-50 cursor-not-allowed' : 'hover:border-cyan-500/50'}`}
    >
        <div className={`shrink-0 transition-transform ${isSelected ? 'scale-110' : 'scale-100'}`}>
            {isSelected ? (
                <span className="block h-5 w-5 rounded-full border-2 border-cyan-300 bg-cyan-400 shadow-[inset_0_0_0_3px_rgb(15,23,42)]" />
            ) : (
                <Circle className="h-5 w-5 text-slate-600 group-hover:text-slate-400" />
            )}
        </div>

        <div className={`min-w-0 truncate text-sm font-bold uppercase tracking-wider transition-colors ${isSelected ? 'text-cyan-300' : 'text-white/80 group-hover:text-white'}`}>
            {job.title}
        </div>
    </button>
);

export default function Work({ jobs }) {
    const { auth } = usePage().props;
    const character = auth?.character;
    const [selectedJobId, setSelectedJobId] = useState(null);
    const [attempting, setAttempting] = useState(false);

    const handleDoJob = () => {
        if (!selectedJobId || attempting) return;

        setAttempting(true);
        localStorage.setItem('last_earn_id', String(selectedJobId));
        router.post(route('work.attempt'), {
            earn_id: selectedJobId
        }, {
            only: ['auth', 'flash'],
            preserveScroll: true,
            onFinish: () => setAttempting(false)
        });
    };



    return (
        <div className="w-full">
            <Head title="Work - TheDirector" />

            <div className="w-full max-w-4xl mx-auto space-y-8 py-4 sm:py-8 px-4">
                { }
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 border-b border-slate-800/50 pb-6">
                    <div>
                        <div className="flex items-center gap-2 mb-2">


                        </div>
                        <h1 className="text-3xl sm:text-4xl font-black text-white tracking-tight">
                            CAREER <span className="text-cyan-400 italic">JOBS</span>
                        </h1>

                    </div>
                    <div className="text-right">
                    </div>
                </div>
                <div className="w-full">
                    { }
                    {jobs.map((job) => (
                        <JobCard
                            key={job.id}
                            job={job}
                            isSelected={selectedJobId === job.id}
                            onSelect={setSelectedJobId}
                            disabled={attempting}
                        />
                    ))}

                    { }
                    <div className="w-full flex justify-center mt-4">
                        <button
                            onClick={handleDoJob}
                            disabled={!selectedJobId || attempting}
                            className={`group relative overflow-hidden h-10 px-8 rounded-lg font-bold text-xs uppercase tracking-wider transition-colors ${!selectedJobId || attempting
                                ? 'bg-slate-800/50 text-slate-500 border border-slate-800/50 cursor-not-allowed'
                                : 'bg-white text-slate-950 hover:bg-cyan-400 hover:shadow-[0_0_15px_rgba(6,182,212,0.3)]'
                                }`}
                        >
                            <div className="relative z-10 flex items-center justify-center gap-2">
                                {attempting ? (
                                    <>
                                        <div className="w-4 h-4 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" />
                                        <span>Working...</span>
                                    </>
                                ) : (
                                    <>

                                        <span>Work</span>
                                    </>
                                )}
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

Work.layout = page => <GameLayout children={page} />;
