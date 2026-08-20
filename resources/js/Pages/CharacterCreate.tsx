import { useForm, Head, usePage } from '@inertiajs/react';
import { User, MapPin, Briefcase, UserCircle, ArrowRight } from '@phosphor-icons/react';
import { route } from 'ziggy-js';
import { motion } from 'framer-motion';
import Picker from '@/Components/picker';

interface City {
    id: number;
    name: string;
    description?: string;
    image_url?: string | null;
}

interface Career {
    id: number;
    name: string;
    description?: string | null;
}

interface Props {
    cities: City[];
    careers: Career[];
}

interface PageProps {
    flash?: { error?: string; success?: string };
    [key: string]: any;
}

export default function CharacterCreate({ cities, careers }: Props) {
    const { flash } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        display_name: '',
        gender: 'Male' as 'Male' | 'Female',
        city_id: cities[0]?.id ?? null,
        career_id: careers[0]?.id ?? null,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('character.store'));
    };

    const selectedCity = cities.find(c => c.id === data.city_id);
    const cityImageMap: Record<string, string> = {
        'New York': 'https://images.thedirector.app/newyork.jpeg',
        'Tokyo': 'https://images.thedirector.app/tokyo.jpeg',
        'Seoul': 'https://images.thedirector.app/seoul.jpeg',
    };
    const cityImage = selectedCity ? cityImageMap[selectedCity.name] || selectedCity.image_url || '/bg1.jpg' : '/bg1.jpg';

    const cityOptions = cities.map(city => ({ value: city.id, label: city.name }));
    const careerOptions = careers.map(career => ({ value: career.id, label: career.name }));

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white flex flex-col">
            <div className="flex-1 flex items-center justify-center p-4">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_rgba(245,158,11,0.05)_0%,_transparent_70%)] pointer-events-none" />

                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5 }}
                    className="relative z-10 w-full max-w-6xl"
                >
                    <div className="bg-slate-900/80 backdrop-blur-xl border border-slate-800/50 rounded-3xl overflow-hidden shadow-2xl flex flex-col md:flex-row">
                        <div className="md:w-2/5 relative overflow-hidden min-h-[300px] md:min-h-full">
                            <div className="absolute inset-0">
                                <img
                                    src={cityImage}
                                    alt="City"
                                    className="w-full h-full object-cover transition-transform duration-1000 hover:scale-105"
                                />
                                <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/40 to-transparent" />
                            </div>
                            <div className="relative p-8 h-full flex flex-col justify-end">
                                <div className="backdrop-blur-md bg-black/30 p-5 rounded-2xl border border-slate-700/50 shadow-xl">
                                    <div className="flex items-center gap-2 text-amber-400 mb-2">
                                        <MapPin size={16} weight="bold" />
                                        <span className="text-[10px] font-black uppercase tracking-widest">Destination</span>
                                    </div>
                                    <h3 className="text-2xl font-bold text-white mb-1">
                                        {selectedCity?.name || 'Select a city'}
                                    </h3>
                                    <p className="text-xs text-slate-300/80 leading-relaxed">
                                        {selectedCity?.description || 'A strategic location with moderate resource abundance.'}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="md:w-3/5 p-8 md:p-10 space-y-6">
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {flash?.error && (
                                    <div className="px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">
                                        {flash.error}
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <label className="flex items-center gap-2 text-xs font-bold text-amber-400 uppercase tracking-wider">
                                        <UserCircle size={14} weight="bold" /> Name
                                    </label>
                                    <div className="relative">
                                        <input
                                            type="text"
                                            value={data.display_name}
                                            onChange={e => setData('display_name', e.target.value)}
                                            placeholder="ENTER NAME"
                                            maxLength={15}
                                            disabled={processing}
                                            className="w-full pl-4 pr-16 py-3 bg-slate-950/70 border border-slate-800 rounded-xl text-white placeholder:text-slate-700 focus:outline-none focus:border-amber-500/50 focus:ring-1 focus:ring-amber-500/30 transition-all font-mono"
                                        />
                                        <div className="absolute right-3 top-3 text-xs text-slate-600 font-mono">
                                            {data.display_name.length}/15
                                        </div>
                                    </div>
                                    {errors.display_name && <p className="text-xs text-red-400">{errors.display_name}</p>}
                                    <p className="text-[9px] text-slate-500">Max 15 chars. Letters, numbers and underscores only.</p>
                                </div>

                                <div className="space-y-2">
                                    <label className="flex items-center gap-2 text-xs font-bold text-amber-400 uppercase tracking-wider">
                                        <User size={14} weight="bold" /> Gender
                                    </label>
                                    <div className="flex gap-3">
                                        {['Male', 'Female'].map(gender => (
                                            <button
                                                key={gender}
                                                type="button"
                                                onClick={() => setData('gender', gender as 'Male' | 'Female')}
                                                disabled={processing}
                                                className={`flex-1 py-3 rounded-xl border transition-all text-xs font-bold uppercase tracking-wider ${data.gender === gender
                                                    ? 'border-amber-500 bg-amber-500/10 text-white shadow-[0_0_15px_rgba(245,158,11,0.2)]'
                                                    : 'border-slate-800 bg-slate-950/30 text-slate-500 hover:border-slate-600 hover:text-slate-300'
                                                    }`}
                                            >
                                                {gender}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <label className="flex items-center gap-2 text-xs font-bold text-amber-400 uppercase tracking-wider">
                                        <MapPin size={14} weight="bold" /> Home City
                                    </label>
                                    <Picker
                                        options={cityOptions}
                                        value={data.city_id}
                                        onChange={(val: number) => setData('city_id', val)}
                                        placeholder="Select a city"
                                        className="w-full"
                                    />
                                    {errors.city_id && <p className="text-xs text-red-400">{errors.city_id}</p>}
                                </div>

                                <div className="space-y-2">
                                    <label className="flex items-center gap-2 text-xs font-bold text-amber-400 uppercase tracking-wider">
                                        <Briefcase size={14} weight="bold" /> Career Path
                                    </label>
                                    <Picker
                                        options={careerOptions}
                                        value={data.career_id}
                                        onChange={(val: number) => setData('career_id', val)}
                                        placeholder="Select a career"
                                        className="w-full"
                                    />
                                    {errors.career_id && <p className="text-xs text-red-400">{errors.career_id}</p>}
                                </div>

                                {data.career_id && (
                                    <motion.div
                                        initial={{ opacity: 0, y: -10 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        className="p-4 rounded-xl bg-slate-800/30 border border-amber-500/20"
                                    >
                                        <p className="text-sm text-slate-300 leading-relaxed">
                                            {careers.find(c => c.id === data.career_id)?.description || 'Initialize career path.'}
                                        </p>
                                    </motion.div>
                                )}

                                <div className="pt-8 flex justify-end">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-8 py-3 rounded-xl bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-white font-black text-sm uppercase tracking-widest transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-3"
                                    >
                                        JOIN <ArrowRight size={16} weight="bold" />
                                    </button>
                                </div>
                            </form>


                        </div>
                    </div>

                    <div className="mt-6 text-center text-[10px] font-bold uppercase tracking-[0.18em] text-white/45">
                        &copy; {new Date().getFullYear()} TheDirector - Beta Gameplay.
                    </div>
                </motion.div>
            </div>
        </div>
    );
}
