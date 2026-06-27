<script setup lang="ts">

import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, InfiniteScroll, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import {
    Banknote, AlertTriangle, Wrench, CheckCircle2, XCircle, ArrowRight,
    Inbox, ChevronLeft, Loader2, Building2, User,
    ArrowUpFromLine, ArrowDownToLine, MessageSquare, TriangleAlert,
    CalendarClock, ChevronDown, X, Zap
} from 'lucide-vue-next';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';

// --- PROPS ---
const props = defineProps<{
    tasks: { data: any[], links: any[], total: number };
    counts: { all: number, urgent: number, payments: number, maintenance: number };
    activeFilter: string;
    condomini: Array<{ id: number; nome: string }>;
    condominioFilter: number | null;
}>();

const isLoading = ref(false);
const isRejectModalOpen = ref(false);
const taskToReject = ref<any>(null);
const isCondominioDropdownOpen = ref(false);

// Chiude il dropdown se si clicca fuori
const handleOutsideClick = () => { isCondominioDropdownOpen.value = false; };
onMounted(() => document.addEventListener('click', handleOutsideClick));
onUnmounted(() => document.removeEventListener('click', handleOutsideClick));

const rejectForm = useForm({ reason: '' });

const openRejectModal = (task: any) => {
    taskToReject.value = task;
    rejectForm.reason = '';
    rejectForm.clearErrors();
    isRejectModalOpen.value = true;
};

const closeRejectModal = () => {
    isRejectModalOpen.value = false;
    setTimeout(() => taskToReject.value = null, 300);
};

const confirmReject = () => {
    if (!taskToReject.value) return;
    rejectForm.post(route('admin.inbox.reject', taskToReject.value.id), {
        preserveScroll: true,
        onSuccess: () => closeRejectModal(),
    });
};

const completeTask = (taskId: number) => {
    router.patch(route('admin.inbox.complete', { task: taskId }), {}, { preserveScroll: true });
};

// --- DESIGN TOKENS ---
const STATUS_TOKENS: Record<string, { border: string, bg: string, iconBg: string, iconColor: string, text: string }> = {
    expired: {
        border: 'border-l-red-500',
        bg: 'hover:bg-red-50/30',
        iconBg: 'bg-red-100',
        iconColor: 'text-red-600',
        text: 'text-red-700'
    },
    controllo_incassi: {
        border: 'border-l-purple-500',
        bg: 'hover:bg-purple-50/30',
        iconBg: 'bg-purple-100',
        iconColor: 'text-purple-600',
        text: 'text-purple-700'
    },
    emissione_rata: {
        border: 'border-l-blue-500',
        bg: 'hover:bg-blue-50/30',
        iconBg: 'bg-blue-100',
        iconColor: 'text-blue-600',
        text: 'text-blue-700'
    },
    verifica_pagamento: {
        border: 'border-l-amber-500',
        bg: 'hover:bg-amber-50/30',
        iconBg: 'bg-amber-100',
        iconColor: 'text-amber-600',
        text: 'text-amber-700'
    },
    commento: {
        border: 'border-l-indigo-500',
        bg: 'hover:bg-indigo-50/30',
        iconBg: 'bg-indigo-100',
        iconColor: 'text-indigo-600',
        text: 'text-indigo-700'
    },
    segnalazione_guasto: {
        border: 'border-l-orange-500',
        bg: 'hover:bg-orange-50/30',
        iconBg: 'bg-orange-100',
        iconColor: 'text-orange-600',
        text: 'text-orange-700'
    },
    scheduled: {
        border: 'border-l-slate-400',
        bg: 'hover:bg-slate-50',
        iconBg: 'bg-slate-100',
        iconColor: 'text-slate-600',
        text: 'text-slate-700'
    }
};

// --- FILTRI ---
const setFilter = (filter: string) => {
    if (props.activeFilter === filter) return;
    isLoading.value = true;
    router.get(route('admin.inbox'), {
        filter,
        condominio_id: props.condominioFilter ?? undefined
    }, {
        preserveState: true,
        preserveScroll: false,
        onFinish: () => setTimeout(() => isLoading.value = false, 300)
    });
};

const setCondominioFilter = (condominioId: number | null) => {
    isCondominioDropdownOpen.value = false;
    isLoading.value = true;
    router.get(route('admin.inbox'), {
        filter: props.activeFilter,
        condominio_id: condominioId ?? undefined,
    }, {
        preserveState: true,
        preserveScroll: false,
        onFinish: () => setTimeout(() => isLoading.value = false, 300),
    });
};

const selectedCondominioNome = computed(() => {
    if (!props.condominioFilter) return null;
    return props.condomini.find(c => c.id === props.condominioFilter)?.nome ?? null;
});

const formatMoney = (val: any) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(val / 100);

// --- COLORI ---
const getNativeToken = (task: any) => STATUS_TOKENS[task.type] || STATUS_TOKENS[task.status] || STATUS_TOKENS.scheduled;
const getUrgencyToken = (task: any) => task.status === 'expired' ? STATUS_TOKENS.expired : getNativeToken(task);
const getTaskStyle = (task: any) => { const t = getUrgencyToken(task); return `${t.border} ${t.bg}`; };
const getTaskIconStyle = (task: any) => { const t = task.status === 'expired' ? STATUS_TOKENS.expired : getNativeToken(task); return `${t.iconBg} ${t.iconColor}`; };
const getTaskTextColor = (task: any) => getNativeToken(task).text;

const getDateLabel = (dateStr: string | null) => {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('it-IT', { day: '2-digit', month: 'long' });
};
</script>

<template>
    <Head title="Action Inbox" />

    <AppLayout>
        <div class="px-6 py-8 space-y-6">

            <!-- TOP BAR -->
            <div class="flex flex-col-reverse md:flex-row justify-between items-stretch md:items-center gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Centro operativo</p>
                    <div class="flex items-center gap-3">
                        <h1 class="text-xl font-black text-slate-800 dark:text-slate-100 tracking-tight">
                            Action Inbox
                        </h1>
                        <Badge
                            v-if="tasks.total > 0"
                            variant="destructive"
                            class="font-black rounded-md text-[10px] h-5 px-1.5"
                        >
                            {{ tasks.total }}
                        </Badge>
                        <span v-if="condominioFilter" class="text-[10px] font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-md dark:bg-indigo-900/20 dark:border-indigo-700 dark:text-indigo-400">
                            {{ selectedCondominioNome }}
                        </span>
                    </div>
                </div>

                <!-- Destra: back button + filtro condominio -->
                <div class="flex items-center gap-2 w-full md:w-auto sm:justify-end">

                    <!-- Back button — stile outline compatto come da pattern gestionale -->
                    <Link :href="route('admin.dashboard')">
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 text-[11px] font-bold uppercase tracking-wide gap-1.5 text-slate-600"
                        >
                            <ChevronLeft class="w-3.5 h-3.5" />
                            Dashboard
                        </Button>
                    </Link>
                    <div class="relative">
                        <button
                            @click.stop="isCondominioDropdownOpen = !isCondominioDropdownOpen"
                            class="inline-flex items-center gap-2 h-9 px-3 rounded-lg border text-sm font-semibold transition-all"
                            :class="condominioFilter
                                ? 'bg-indigo-50 border-indigo-300 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/20 dark:border-indigo-700 dark:text-indigo-400'
                                : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300'"
                        >
                            <Building2 class="w-3.5 h-3.5" />
                            <span class="max-w-[160px] truncate text-[12px]">{{ selectedCondominioNome ?? 'Tutti i condomini' }}</span>
                            <ChevronDown class="w-3.5 h-3.5 opacity-50 transition-transform" :class="{ 'rotate-180': isCondominioDropdownOpen }" />
                        </button>

                        <Transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="opacity-0 scale-95"
                            enter-to-class="opacity-100 scale-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="opacity-100 scale-100"
                            leave-to-class="opacity-0 scale-95"
                        >
                            <div
                                v-if="isCondominioDropdownOpen"
                                @click.stop
                                class="absolute top-full right-0 mt-1.5 w-64 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl z-30 overflow-hidden"
                            >
                                <div class="p-1.5 max-h-64 overflow-y-auto custom-scrollbar">
                                    <button
                                        @click="setCondominioFilter(null)"
                                        class="w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
                                        :class="!condominioFilter ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                                    >
                                        <Inbox class="w-3.5 h-3.5 opacity-50" /> Tutti i condomini
                                    </button>
                                    <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
                                    <button
                                        v-for="c in condomini" :key="c.id"
                                        @click="setCondominioFilter(c.id)"
                                        class="w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
                                        :class="condominioFilter === c.id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                                    >
                                        <Building2 class="w-3.5 h-3.5 opacity-50" />
                                        <span class="truncate">{{ c.nome }}</span>
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <button
                        v-if="condominioFilter"
                        @click="setCondominioFilter(null)"
                        class="inline-flex items-center gap-1 h-9 px-2.5 rounded-lg text-[11px] font-bold text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 transition-colors dark:bg-red-900/20 dark:border-red-800"
                    >
                        <X class="w-3 h-3" /> Rimuovi
                    </button>
                </div>
            </div>

            <!-- KPI CARDS — stesso stile delle card Dashboard.vue admin -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

                <!-- Scaduti / Urgenti -->
                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    :class="activeFilter === 'urgent'
                        ? 'border-red-400 ring-1 ring-red-400'
                        : counts.urgent > 0 ? 'border-red-200 dark:border-red-900/40' : 'border-sidebar-border/70'"
                    @click="setFilter('urgent')"
                >
                    <div class="absolute -right-5 -top-5 pointer-events-none transition-colors"
                         :class="counts.urgent > 0 ? 'text-red-100 dark:text-red-900/30' : 'text-slate-100 dark:text-slate-800'">
                        <TriangleAlert class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10">
                        <div class="flex items-center gap-2 mb-3">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Scaduti / Urgenti</p>
                            <span v-if="counts.urgent > 0" class="flex h-1.5 w-1.5 rounded-full bg-red-500 animate-pulse"></span>
                        </div>
                        <p class="text-3xl font-black" :class="counts.urgent > 0 ? 'text-red-600 dark:text-red-500' : 'text-slate-700 dark:text-slate-200'">
                            {{ counts.urgent }}
                        </p>
                    </div>
                    <div class="border-t px-4 py-2.5 flex items-center justify-between transition-colors"
                         :class="counts.urgent > 0 ? 'border-red-100 dark:border-red-900/30 bg-red-50/50 dark:bg-red-900/10' : 'border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50'">
                        <span class="text-[10px] font-medium" :class="counts.urgent > 0 ? 'text-red-600/80' : 'text-slate-400'">
                            {{ counts.urgent > 0 ? 'Azione richiesta' : 'Nessun ritardo' }}
                        </span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

                <!-- Verifiche incassi -->
                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    :class="activeFilter === 'payments'
                        ? 'border-purple-400 ring-1 ring-purple-400'
                        : 'border-sidebar-border/70'"
                    @click="setFilter('payments')"
                >
                    <div class="absolute -right-5 -top-5 text-slate-100 dark:text-slate-800 pointer-events-none">
                        <ArrowDownToLine class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3">Verifiche incassi</p>
                        <p class="text-3xl font-black text-slate-700 dark:text-slate-200">{{ counts.payments }}</p>
                    </div>
                    <div class="border-t border-slate-100 dark:border-slate-800 px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">Segnalazioni condòmini</span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

                <!-- Ticket & Manutenzione -->
                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    :class="activeFilter === 'maintenance'
                        ? 'border-orange-400 ring-1 ring-orange-400'
                        : 'border-sidebar-border/70'"
                    @click="setFilter('maintenance')"
                >
                    <div class="absolute -right-5 -top-5 text-slate-100 dark:text-slate-800 pointer-events-none">
                        <Wrench class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3">Ticket & Manut.</p>
                        <p class="text-3xl font-black text-slate-700 dark:text-slate-200">{{ counts.maintenance }}</p>
                    </div>
                    <div class="border-t border-slate-100 dark:border-slate-800 px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">Guasti e segnalazioni</span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

                <!-- Tutte le attività -->
                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    :class="activeFilter === 'all'
                        ? 'border-slate-500 ring-1 ring-slate-400'
                        : 'border-sidebar-border/70'"
                    @click="setFilter('all')"
                >
                    <div class="absolute -right-5 -top-5 text-slate-100 dark:text-slate-800 pointer-events-none">
                        <Zap class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3">Tutte le attività</p>
                        <p class="text-3xl font-black text-slate-700 dark:text-slate-200">{{ counts.all }}</p>
                    </div>
                    <div class="border-t border-slate-100 dark:border-slate-800 px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">Reset filtri</span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

            </div>

            <!-- LISTA TASK -->
            <div class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden relative">

                <!-- Header colonne -->
                <div class="hidden md:grid grid-cols-12 gap-4 px-6 py-3.5 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                    <div class="col-span-2">Scadenza</div>
                    <div class="col-span-3">Condominio</div>
                    <div class="col-span-4">Attività</div>
                    <div class="col-span-3 text-right">Azioni</div>
                </div>

                <!-- Loading overlay -->
                <div v-if="isLoading"
                     class="absolute inset-0 z-20 bg-white/80 dark:bg-slate-900/80 backdrop-blur-sm flex items-center justify-center">
                    <div class="flex flex-col items-center gap-3">
                        <Loader2 class="w-7 h-7 text-indigo-600 animate-spin" />
                        <span class="text-xs text-slate-400 font-bold tracking-widest uppercase">Caricamento...</span>
                    </div>
                </div>

                <!-- Lista con InfiniteScroll -->
                <div v-if="tasks.data.length > 0">
                    <InfiniteScroll data="tasks" preserve-url>
                        <div v-for="task in tasks.data" :key="task.id"
                             class="group relative grid grid-cols-1 md:grid-cols-12 gap-4 px-6 py-5 border-b border-slate-100 dark:border-slate-800 items-center transition-all border-l-[3px]"
                             :class="getTaskStyle(task)">

                            <!-- Scadenza -->
                            <div class="col-span-2 flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
                                     :class="getTaskIconStyle(task)">
                                    <TriangleAlert v-if="task.status === 'expired'" class="w-4 h-4" />
                                    <ArrowDownToLine v-else-if="task.type === 'controllo_incassi'" class="w-4 h-4" />
                                    <ArrowUpFromLine v-else-if="task.type === 'emissione_rata'" class="w-4 h-4" />
                                    <Banknote v-else-if="task.type === 'verifica_pagamento'" class="w-4 h-4" />
                                    <MessageSquare v-else-if="task.type === 'commento'" class="w-4 h-4" />
                                    <Wrench v-else-if="task.type === 'segnalazione_guasto'" class="w-4 h-4" />
                                    <CalendarClock v-else class="w-4 h-4" />
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold tabular-nums leading-none"
                                          :class="task.status === 'expired' ? 'text-red-600' : getTaskTextColor(task)">
                                        {{ getDateLabel(task.date) }}
                                    </span>
                                    <span v-if="task.status === 'expired'" class="text-[10px] font-bold text-red-500 uppercase flex items-center gap-1 mt-0.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse inline-block"></span> SCADUTO {{ task.days_pending > 0 ? `DA ${task.days_pending} GIORN${task.days_pending === 1 ? 'O' : 'I'}` : '' }}
                                    </span>
                                    <span v-else class="text-[11px] text-slate-400 mt-1 capitalize">
                                        {{ new Date(task.date).toLocaleDateString('it-IT', { weekday: 'short' }) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Condominio -->
                            <div class="col-span-3 flex items-center gap-2">
                                <Building2 class="w-4 h-4 text-slate-300 shrink-0" />
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300 line-clamp-1" :title="task.condominio">
                                    {{ task.condominio }}
                                </span>
                            </div>

                            <!-- Attività -->
                            <div class="col-span-4 flex flex-col justify-center py-1">
                                <div class="text-sm font-bold mb-1 leading-tight"
                                     :class="task.status === 'expired' ? 'text-red-700 dark:text-red-400' : getTaskTextColor(task)">
                                    {{ task.title }}
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400 leading-snug line-clamp-2 mb-2" :title="task.description">
                                    {{ task.description }}
                                </p>
                                <div class="flex items-center gap-3">
                                    <span v-if="task.amount" class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-100 shrink-0 shadow-sm">
                                        {{ formatMoney(task.amount) }}
                                    </span>
                                    <div v-if="task.context.anagrafica_nome" class="flex items-center text-[10px] text-slate-400 truncate">
                                        <User class="w-3 h-3 mr-1.5 shrink-0" />
                                        <span class="truncate font-medium">{{ task.context.anagrafica_nome }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Azioni -->
                            <div class="col-span-3 flex justify-end items-center gap-2">

                                <!-- Verifica pagamento (segnalazione condòmino) -->
                                <template v-if="task.type === 'verifica_pagamento'">
                                    <button
                                        size="sm"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-slate-200 text-slate-400 bg-white hover:text-red-600 hover:border-red-200 hover:bg-red-50 shadow-sm transition-all dark:bg-slate-800 dark:border-slate-700"
                                        title="Rifiuta segnalazione"
                                        @click="openRejectModal(task)"
                                    >
                                        <XCircle class="w-4 h-4" />
                                    </button>
                                    <a
                                        :href="task.context.action_url || '#'"
                                        :class="{ 'pointer-events-none opacity-50': !task.context.action_url }"
                                        class="inline-flex items-center justify-center h-8 px-3 text-xs font-bold transition-all rounded-md bg-white border border-slate-200 shadow-sm text-slate-700 hover:bg-emerald-50 hover:text-emerald-700 hover:border-emerald-300 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-200 dark:hover:text-emerald-400"
                                    >
                                        Registra <ArrowRight class="w-3.5 h-3.5 ml-1.5" />
                                    </a>
                                </template>

                                <!-- Tutti gli altri: bottone completa (sempre) + link risolvi (se action_url) -->
                                <template v-else>
                                    <button
                                        @click="completeTask(task.id)"
                                        title="Segna come completato"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-slate-200 text-slate-400 bg-white hover:text-emerald-600 hover:border-emerald-200 hover:bg-emerald-50 shadow-sm transition-all dark:bg-slate-800 dark:border-slate-700"
                                    >
                                        <CheckCircle2 class="w-4 h-4" />
                                    </button>
                                    <a v-if="task.context.action_url"
                                       :href="task.context.action_url"
                                       class="inline-flex items-center justify-center h-8 px-3 text-xs font-bold transition-all rounded-md bg-white border shadow-sm dark:bg-slate-800"
                                       :class="task.status === 'expired'
                                           ? 'border-red-200 text-red-600 hover:bg-red-50 hover:border-red-300 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/30'
                                           : 'border-slate-200 text-slate-700 hover:bg-slate-50 hover:text-indigo-700 hover:border-indigo-300 dark:border-slate-700 dark:text-slate-200 dark:hover:text-indigo-400'"
                                    >
                                        Risolvi <ArrowRight class="w-3.5 h-3.5 ml-1.5" />
                                    </a>
                                </template>
                            </div>
                        </div>

                        <template #loading>
                            <div class="py-6 flex items-center justify-center text-slate-400 border-t border-slate-100 dark:border-slate-800">
                                <Loader2 class="w-5 h-5 animate-spin mr-2" />
                                <span class="text-xs font-medium">Caricamento...</span>
                            </div>
                        </template>
                    </InfiniteScroll>
                </div>

                <!-- Stato vuoto -->
                <div v-if="tasks.data.length === 0 && !isLoading" class="flex flex-col items-center justify-center text-center py-20 px-8">
                    <div class="w-20 h-20 bg-slate-50 dark:bg-slate-800/50 rounded-full flex items-center justify-center mb-6 shadow-inner">
                        <Inbox class="w-10 h-10 text-slate-300" />
                    </div>
                    <h3 class="text-base font-black text-slate-800 dark:text-white uppercase tracking-widest">Inbox vuota 🚀</h3>
                    <p class="text-slate-500 mt-2 max-w-xs mx-auto text-sm leading-relaxed">
                        <template v-if="condominioFilter">
                            Nessuna attività per <strong>{{ selectedCondominioNome }}</strong>.
                        </template>
                        <template v-else>
                            Nessuna attività urgente richiede attenzione.
                        </template>
                    </p>
                    <button v-if="condominioFilter" @click="setCondominioFilter(null)"
                            class="mt-4 text-xs font-bold text-indigo-600 hover:underline">
                        Mostra tutti i condomini
                    </button>
                </div>
            </div>

        </div>

        <!-- Modal Rifiuto -->
        <Dialog v-model:open="isRejectModalOpen">
            <DialogContent class="sm:max-w-[450px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2 text-red-600">
                        <AlertTriangle class="w-5 h-5" />
                        Rifiuta segnalazione
                    </DialogTitle>
                    <DialogDescription>
                        Stai per rifiutare il pagamento segnalato da
                        <span class="font-bold text-slate-900">{{ taskToReject?.context?.anagrafica_nome || 'Condòmino' }}</span>.
                        <strong> Attenzione: questa azione sarà irreversibile.</strong>
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label htmlFor="reason" class="text-slate-900">Motivazione (visibile all'utente)</Label>
                        <Textarea
                            id="reason"
                            v-model="rejectForm.reason"
                            placeholder="Es: Bonifico non trovato nell'estratto conto..."
                            class="resize-none min-h-[100px]"
                            :class="{ 'border-red-500 focus-visible:ring-red-500': rejectForm.errors.reason }"
                        />
                        <p v-if="rejectForm.errors.reason" class="text-[11px] text-red-600 font-medium">
                            {{ rejectForm.errors.reason }}
                        </p>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="isRejectModalOpen = false" :disabled="rejectForm.processing">Annulla</Button>
                    <Button
                        variant="destructive"
                        @click="confirmReject"
                        :disabled="rejectForm.processing || !rejectForm.reason"
                    >
                        <Loader2 v-if="rejectForm.processing" class="w-4 h-4 mr-2 animate-spin" />
                        Conferma rifiuto
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
</style>