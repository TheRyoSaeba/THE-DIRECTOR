import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Check, PencilSimple, Plus, Trash, PushPin, Lock, ChatTeardrop,BookOpenIcon } from '@phosphor-icons/react';

import {
    AdminInput,
    AdminModal,
    AdminToggle,
    Badge,
    FormActions,
    SubTabs,
    TableShell,
    TD,
    useConfirm,
} from '../Components';

import type { ForumCategory, ForumPostAdmin, ForumStats } from './types';

export function ForumSection({
    categories,
    posts,
    stats,
}: {
    categories: ForumCategory[];
    posts: ForumPostAdmin[];
    stats: ForumStats;
}) {
    const [tab, setTab] = useState<'categories' | 'posts'>('categories');
    const [catModal, setCatModal] = useState(false);
    const [postModal, setPostModal] = useState(false);
    const [editCat, setEditCat] = useState<ForumCategory | null>(null);
    const { confirm, ConfirmNode } = useConfirm();

    const catForm = useForm({
        name: '',
        description: '',
        icon: 'BookOpenIcon',
        sort_order: 0,
        is_active: true,
        admin_only: false,
    });

    const postForm = useForm({
        category_id: categories[0]?.id || '',
        title: '',
        body: '',
        is_pinned: true,
        is_locked: false,
    });

    const openPostModal = () => {
        postForm.reset();
        postForm.setData('category_id', categories[0]?.id || '');
        postForm.setData('is_pinned', true);
        setPostModal(true);
    };

    const submitPost = (e: React.FormEvent) => {
        e.preventDefault();
        postForm.post(route('admin.forum.posts.create'), {
            preserveScroll: true,
            onSuccess: () => setPostModal(false),
        });
    };

    const openCat = (c?: ForumCategory) => {
        if (c) {
            catForm.setData({
                name: c.name,
                description: c.description || '',
                icon: c.icon,
                sort_order: c.sort_order,
                is_active: c.is_active,
                admin_only: c.admin_only,
            });
            setEditCat(c);
        } else {
            catForm.reset();
            setEditCat(null);
        }
        setCatModal(true);
    };

    const submitCat = (e: React.FormEvent) => {
        e.preventDefault();
        const url = editCat
            ? route('admin.forum.categories.update', editCat.id)
            : route('admin.forum.categories.create');
        catForm.post(url, {
            preserveScroll: true,
            onSuccess: () => setCatModal(false),
        });
    };

    const deleteCat = (c: ForumCategory) => {
        confirm(`Delete category "${c.name}"?`, () => {
            router.delete(route('admin.forum.categories.delete', c.id), {
                preserveScroll: true,
            });
        });
    };

    const deletePost = (p: ForumPostAdmin) => {
        confirm(`Delete post "${p.title}"?`, () => {
            router.delete(route('admin.forum.posts.delete', p.id), {
                preserveScroll: true,
            });
        });
    };

    const togglePin = (p: ForumPostAdmin) => {
        router.post(route('admin.forum.posts.pin', p.id), {}, { preserveScroll: true });
    };

    const toggleLock = (p: ForumPostAdmin) => {
        router.post(route('admin.forum.posts.lock', p.id), {}, { preserveScroll: true });
    };

    const getCategoryName = (id: number) => categories.find(c => c.id === id)?.name || '—';

    return (
        <div className="space-y-6">
            {ConfirmNode}

            <div className="grid grid-cols-2 gap-4">
                <div className="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
                    <div className="text-[10px] text-slate-500 font-bold uppercase tracking-widest mb-1">Categories</div>
                    <div className="text-2xl font-black text-white">{stats.totalCategories}</div>
                </div>
                <div className="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
                    <div className="text-[10px] text-slate-500 font-bold uppercase tracking-widest mb-1">Total Posts</div>
                    <div className="text-2xl font-black text-white">{stats.totalPosts}</div>
                </div>
            </div>

            <SubTabs
                tabs={[
                    { id: 'categories', label: 'Categories' },
                    { id: 'posts', label: 'Recent Posts' },
                ]}
                active={tab}
                onChange={(t) => setTab(t as any)}
            />

            {tab === 'categories' && (
                <>
                    <div className="flex justify-end">
                        <button
                            onClick={() => openCat()}
                            className="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg text-[10px] font-bold uppercase tracking-widest transition"
                        >
                            <Plus size={12} /> New Category
                        </button>
                    </div>

                    <TableShell headers={['Name', 'Slug', 'Posts', 'Order', 'Active', 'Admin Only', '']}>
                        {categories.map((c) => (
                            <tr key={c.id} className="border-t border-slate-800/50 hover:bg-slate-800/20 transition">
                                <TD>
                                    <span className="font-bold text-white">{c.name}</span>
                                    {c.description && (
                                        <span className="block text-[10px] text-slate-500 mt-0.5">{c.description}</span>
                                    )}
                                </TD>
                                <TD><span className="text-slate-400 font-mono text-xs">{c.slug}</span></TD>
                                <TD><span className="font-mono">{c.posts_count}</span></TD>
                                <TD><span className="font-mono">{c.sort_order}</span></TD>
                                <TD>
                                    <Badge label={c.is_active ? 'Active' : 'Inactive'} variant={c.is_active ? 'green' : 'red'} />
                                </TD>
                                <TD>
                                    {c.admin_only
                                        ? <Badge label="Admin Only" variant="amber" />
                                        : <span className="text-slate-600 text-[11px]">—</span>}
                                </TD>
                                <TD>
                                    <div className="flex gap-1.5">
                                        <button
                                            onClick={() => openCat(c)}
                                            className="p-1.5 text-slate-500 hover:text-cyan-400 hover:bg-slate-800 rounded-lg transition"
                                        >
                                            <PencilSimple size={14} />
                                        </button>
                                        <button
                                            onClick={() => deleteCat(c)}
                                            className="p-1.5 text-slate-500 hover:text-red-400 hover:bg-slate-800 rounded-lg transition"
                                        >
                                            <Trash size={14} />
                                        </button>
                                    </div>
                                </TD>
                            </tr>
                        ))}
                        {categories.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-2 text-xs text-slate-400">
                                    <div className="text-center py-8 text-slate-600 text-sm">No categories yet</div>
                                </td>
                            </tr>
                        )}
                    </TableShell>
                </>
            )}

            {tab === 'posts' && (
                <>
                    <div className="flex justify-end">
                        <button
                            onClick={openPostModal}
                            disabled={categories.length === 0}
                            className="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-800 disabled:text-slate-600 text-white rounded-lg text-[10px] font-bold uppercase tracking-widest transition"
                        >
                            <Plus size={12} /> New Thread
                        </button>
                    </div>

                <TableShell headers={['Title', 'Author', 'Category', 'Status', '']}>
                    {posts.map((p) => (
                        <tr key={p.id} className="border-t border-slate-800/50 hover:bg-slate-800/20 transition">
                            <TD>
                                <span className="font-bold text-white">{p.title}</span>
                            </TD>
                            <TD><span className="text-slate-400">{p.author || '—'}</span></TD>
                            <TD><span className="text-slate-400">{getCategoryName(p.category_id)}</span></TD>
                            <TD>
                                <div className="flex gap-1">
                                    {p.is_pinned && <Badge label="Pinned" variant="amber" />}
                                    {p.is_locked && <Badge label="Locked" variant="red" />}
                                    {!p.is_pinned && !p.is_locked && <Badge label="Normal" variant="slate" />}
                                </div>
                            </TD>
                            <TD>
                                <div className="flex gap-1.5">
                                    <button
                                        onClick={() => togglePin(p)}
                                        className={`p-1.5 rounded-lg transition ${p.is_pinned ? 'text-amber-400 bg-amber-500/10' : 'text-slate-500 hover:text-amber-400 hover:bg-slate-800'}`}
                                        title={p.is_pinned ? 'Unpin' : 'Pin'}
                                    >
                                        <PushPin size={14} weight={p.is_pinned ? 'fill' : 'bold'} />
                                    </button>
                                    <button
                                        onClick={() => toggleLock(p)}
                                        className={`p-1.5 rounded-lg transition ${p.is_locked ? 'text-red-400 bg-red-500/10' : 'text-slate-500 hover:text-red-400 hover:bg-slate-800'}`}
                                        title={p.is_locked ? 'Unlock' : 'Lock'}
                                    >
                                        <Lock size={14} weight={p.is_locked ? 'fill' : 'bold'} />
                                    </button>
                                    <button
                                        onClick={() => deletePost(p)}
                                        className="p-1.5 text-slate-500 hover:text-red-400 hover:bg-slate-800 rounded-lg transition"
                                        title="Delete"
                                    >
                                        <Trash size={14} />
                                    </button>
                                </div>
                            </TD>
                        </tr>
                    ))}
                    {posts.length === 0 && (
                        <tr>
                            <td colSpan={5} className="px-4 py-2 text-xs text-slate-400">
                                <div className="text-center py-8 text-slate-600 text-sm">No posts yet</div>
                            </td>
                        </tr>
                    )}
                </TableShell>
                </>
            )}

            <AdminModal isOpen={catModal} onClose={() => setCatModal(false)} title={editCat ? 'Edit Category' : 'New Category'}>
                <form onSubmit={submitCat} className="space-y-4">
                    <AdminInput label="Name" value={catForm.data.name} onChange={e => catForm.setData('name', e.target.value)} required />
                    <AdminInput label="Description" value={catForm.data.description} onChange={e => catForm.setData('description', e.target.value)} />
                    <AdminInput label="Icon" value={catForm.data.icon} onChange={e => catForm.setData('icon', e.target.value)} />
                    <AdminInput label="Sort Order" type="number" value={catForm.data.sort_order} onChange={e => catForm.setData('sort_order', parseInt(e.target.value) || 0)} />
                    {editCat && (
                        <AdminToggle label="Active" checked={catForm.data.is_active} onChange={v => catForm.setData('is_active', v)} />
                    )}
                    <AdminToggle
                        label="Admin Only"
                        detail="Only administrators can create threads in this category. Players can still read and reply."
                        checked={catForm.data.admin_only}
                        onChange={v => catForm.setData('admin_only', v)}
                    />
                    <FormActions onCancel={() => setCatModal(false)} processing={catForm.processing} />
                </form>
            </AdminModal>

            <AdminModal isOpen={postModal} onClose={() => setPostModal(false)} title="New Admin Thread">
                <form onSubmit={submitPost} className="space-y-4">
                    <div>
                        <label className="text-[10px] text-slate-500 font-bold uppercase tracking-widest mb-1 block">Category</label>
                        <select
                            value={postForm.data.category_id}
                            onChange={e => postForm.setData('category_id', parseInt(e.target.value) || '')}
                            className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none transition"
                        >
                            {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                    <AdminInput label="Title" value={postForm.data.title} onChange={e => postForm.setData('title', e.target.value)} required />
                    <div>
                        <label className="text-[10px] text-slate-500 font-bold uppercase tracking-widest mb-1 block">Body</label>
                        <textarea
                            value={postForm.data.body}
                            onChange={e => postForm.setData('body', e.target.value)}
                            rows={4}
                            required
                            className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none transition resize-none"
                            placeholder="Thread content..."
                        />
                    </div>
                    <AdminToggle label="Pin to top" checked={postForm.data.is_pinned} onChange={v => postForm.setData('is_pinned', v)} />
                    <AdminToggle label="Lock thread" checked={postForm.data.is_locked} onChange={v => postForm.setData('is_locked', v)} />
                    <FormActions onCancel={() => setPostModal(false)} processing={postForm.processing} />
                </form>
            </AdminModal>
        </div>
    );
}
