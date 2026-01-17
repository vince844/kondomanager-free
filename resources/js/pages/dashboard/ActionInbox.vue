<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';
import { 
    Banknote, AlertTriangle, Wrench, CheckCircle, XCircle, 
    ArrowRight, Clock, Inbox, ChevronLeft, Loader2, CalendarDays, Building2, User
} from 'lucide-vue-next';

// --- PROPS ---
const props = defineProps<{
    tasks: { data: any[], links: any[] };
    counts: { all: number, urgent: number, payments: number, maintenance: number };
    activeFilter: string;
}>();

const isLoading = ref(false);

// --- DESIGN TOKENS ---
const STATUS_TOKENS: Record<string, { border: string, bg: string, iconBg: string, iconColor: string, text: string }> = {
    expired: { 
        border: 'border-l-red-500', 
        bg: 'hover:bg-red-50/30', 
        iconBg: 'bg-red-100',
        iconColor: 'text-red-600',
        text: 'text-red-700' 
    },
    pending_verification: { 
        border: 'border-l-amber-500', 
        bg: 'hover:bg-amber-50/30', 
        iconBg: 'bg-amber-100',
        iconColor: 'text-amber-600',
        text: 'text-amber-700' 
    },
    scheduled: { 
        border: 'border-l-blue-500', 
        bg: 'hover:bg-slate-50', 
        iconBg: 'bg-blue-100',
        iconColor: 'text-blue-600',
        text: 'text-slate-700' 
    }
};

// --- HELPERS ---
const setFilter = (filter: string) => {
    if (props.activeFilter === filter) return;
    isLoading.value = true;
    router.get(route('admin.inbox'), { filter }, { 
        preserveState: true, 
        preserveScroll: true,
        only: ['tasks', 'activeFilter'], 
        onFinish: () => setTimeout(() => isLoading.value = false, 300)
    });
};

const formatMoney = (val: any) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(val/100);

const getTaskStyle = (status: string) => {
    const token = STATUS_TOKENS[status] || STATUS_TOKENS.scheduled;
    return `${token.border} ${token.bg}`;
};

const getTaskIconStyle = (status: string) => {
    const token = STATUS_TOKENS[status] || STATUS_TOKENS.scheduled;
    return `${token.iconBg} ${token.iconColor}`;
};

const getDateLabel = (dateStr: string | null, status: string) => {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    if (status === 'expired') {
        const diffTime = Math.abs(new Date().getTime() - date.getTime());
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
        return diffDays === 1 ? 'Ieri' : `${diffDays}gg ritardo`;
    }
    return date.toLocaleDateString('it-IT', { day: '2-digit', month: 'short' });
};
</script>

<template>
    <Head title="Action Inbox" />

    <AppLayout>
        <div class="w-full p-6 max-w-7xl mx-auto flex flex-col gap-8 min-h-screen">
            
            <div class="w-full rounded-xl bg-slate-900 p-8 text-white shadow-lg">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
                    <div class="flex-1">
                        <Link :href="route('admin.dashboard')" class="inline-flex items-center text-sm text-slate-400 hover:text-white transition-colors mb-4 font-medium group">
                            <ChevronLeft class="w-4 h-4 mr-1 group-hover:-translate-x-1 transition-transform" />
                            Torna alla Dashboard
                        </Link>

                        <h1 class="text-3xl md:text-4xl font-bold tracking-tight text-white">
                            Action Inbox
                        </h1>
                        <p class="text-slate-400 mt-2 text-lg max-w-xl leading-relaxed">
                            Il tuo centro di comando. Gestisci scadenze e incassi da un unico punto.
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5 pl-6 border-l border-slate-700">
                        <span class="text-[10px] uppercase tracking-widest text-slate-500">
                            Attività in scadenza
                        </span>
                        <div class="flex items-center justify-between">
                            <span class="text-3xl font-semibold text-white tabular-nums">
                                {{ counts.all }}
                            </span>
                            <Inbox class="w-4 h-4 text-red-400" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 w-full">
                <button @click="setFilter('urgent')" 
                     class="group flex flex-col justify-between p-5 rounded-xl border bg-white dark:bg-slate-900 transition-all duration-200 text-left hover:border-red-300 hover:shadow-md"
                     :class="activeFilter === 'urgent' ? 'border-red-500 ring-1 ring-red-500 shadow-sm' : 'border-slate-200 dark:border-slate-800'">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-2 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400">
                            <AlertTriangle class="w-6 h-6" :class="{'animate-pulse': counts.urgent > 0}" />
                        </div>
                        <span class="text-3xl font-bold text-slate-900 dark:text-white">{{ counts.urgent }}</span>
                    </div>
                    <span class="text-sm font-semibold text-slate-500 group-hover:text-red-600 transition-colors">Scaduti / Urgenti</span>
                </button>

                <button @click="setFilter('payments')" 
                     class="group flex flex-col justify-between p-5 rounded-xl border bg-white dark:bg-slate-900 transition-all duration-200 text-left hover:border-amber-300 hover:shadow-md"
                     :class="activeFilter === 'payments' ? 'border-amber-500 ring-1 ring-amber-500 shadow-sm' : 'border-slate-200 dark:border-slate-800'">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400">
                            <Banknote class="w-6 h-6" />
                        </div>
                        <span class="text-3xl font-bold text-slate-900 dark:text-white">{{ counts.payments }}</span>
                    </div>
                    <span class="text-sm font-semibold text-slate-500 group-hover:text-amber-600 transition-colors">Verifiche Incassi</span>
                </button>

                <button @click="setFilter('maintenance')" 
                     class="group flex flex-col justify-between p-5 rounded-xl border bg-white dark:bg-slate-900 transition-all duration-200 text-left hover:border-blue-300 hover:shadow-md"
                     :class="activeFilter === 'maintenance' ? 'border-blue-500 ring-1 ring-blue-500 shadow-sm' : 'border-slate-200 dark:border-slate-800'">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400">
                            <Wrench class="w-6 h-6" />
                        </div>
                        <span class="text-3xl font-bold text-slate-900 dark:text-white">{{ counts.maintenance }}</span>
                    </div>
                    <span class="text-sm font-semibold text-slate-500 group-hover:text-blue-600 transition-colors">Ticket & Manut.</span>
                </button>

                <button @click="setFilter('all')" 
                     class="group flex flex-col justify-center items-center gap-2 p-5 rounded-xl border border-dashed border-slate-300 dark:border-slate-700 hover:border-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 transition-all text-center h-full">
                    <span class="text-sm font-bold text-slate-900 dark:text-white">Vedi Tutto</span>
                    <span class="text-xs text-slate-500">Reset filtri</span>
                </button>
            </div>

            <div class="w-full bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden relative min-h-[600px] flex flex-col">
                
                <div class="hidden md:grid grid-cols-12 gap-4 px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50 text-xs font-bold text-slate-500 uppercase tracking-wider w-full">
                    <div class="col-span-2">Scadenza</div>
                    <div class="col-span-3">Condominio</div>
                    <div class="col-span-4">Attività</div>
                    <div class="col-span-3 text-right">Azioni</div>
                </div>

                <div v-if="isLoading" 
                     class="absolute inset-0 z-20 bg-white dark:bg-slate-900 flex items-center justify-center transition-opacity duration-200">
                    <div class="flex flex-col items-center gap-3 animate-pulse">
                        <Loader2 class="w-8 h-8 text-indigo-600 animate-spin" />
                        <span class="text-xs text-slate-400 font-bold tracking-widest uppercase">Caricamento...</span>
                    </div>
                </div>

                <div class="w-full flex-1">
                    
                    <div v-if="tasks.data.length > 0">
                        <div v-for="(task, index) in tasks.data" :key="task.id" 
                             class="group relative grid grid-cols-1 md:grid-cols-12 gap-4 px-6 py-5 border-b border-slate-100 dark:border-slate-800 items-center transition-all border-l-[3px]"
                             :class="getTaskStyle(task.status)">
                            
                            <div class="col-span-2 flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0" 
                                     :class="getTaskIconStyle(task.status)">
                                    <Clock v-if="task.status === 'expired'" class="w-4 h-4" />
                                    <CalendarDays v-else class="w-4 h-4" />
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold tabular-nums leading-none" :class="STATUS_TOKENS[task.status]?.text">
                                        {{ getDateLabel(task.date, task.status) }}
                                    </span>
                                    <span v-if="task.status !== 'expired'" class="text-[11px] text-slate-400 mt-1 capitalize">
                                        {{ new Date(task.date).toLocaleDateString('it-IT', { weekday: 'short' }) }}
                                    </span>
                                </div>
                            </div>

                            <div class="col-span-3 flex items-center gap-2">
                                <Building2 class="w-4 h-4 text-slate-300" />
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300 line-clamp-1" :title="task.condominio">
                                    {{ task.condominio }}
                                </span>
                            </div>

                            <div class="col-span-4 flex flex-col justify-center py-1">
                                <div class="text-sm font-semibold text-slate-900 dark:text-white mb-1 leading-tight">
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
                                        <span class="truncate font-medium">
                                            {{ task.context.anagrafica_nome }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="col-span-3 flex justify-end items-center gap-2">
                                <template v-if="task.type === 'verifica_pagamento'">
                                    <Button size="sm" variant="ghost" class="text-slate-400 hover:text-red-600 hover:bg-red-50" title="Rifiuta">
                                        <XCircle class="w-4 h-4" />
                                    </Button>
                                    
                                    <Button 
                                        as-child 
                                        size="sm" 
                                        class="bg-slate-900 hover:bg-emerald-600 text-white shadow-sm transition-all"
                                        :disabled="!task.context.action_url"
                                    >
                                        <Link 
                                            :href="task.context.action_url || '#'" 
                                            :class="{ 'pointer-events-none opacity-50': !task.context.action_url }"
                                        >
                                            <CheckCircle class="w-3.5 h-3.5 mr-2" />
                                            Registra
                                        </Link>
                                    </Button>
                                </template>

                                <template v-else-if="task.context.action_url">
                                    <Button as-child size="sm" variant="outline" class="border-slate-300 text-slate-600 hover:text-indigo-600 hover:border-indigo-600 hover:bg-indigo-50">
                                        <a :href="task.context.action_url" class="flex items-center">
                                            Gestisci <ArrowRight class="w-3 h-3 ml-2" />
                                        </a>
                                    </Button>
                                </template>

                                <template v-else>
                                    <Button size="sm" variant="ghost" class="text-slate-500">Dettagli</Button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div v-if="tasks.data.length === 0" class="absolute inset-0 flex flex-col items-center justify-center text-center">
                        <div class="w-20 h-20 bg-slate-50 dark:bg-slate-800/50 rounded-full flex items-center justify-center mb-6 shadow-inner">
                            <Inbox class="w-10 h-10 text-slate-300" />
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white">Tutto pulito! 🚀</h3>
                        <p class="text-slate-500 mt-2 max-w-xs mx-auto text-sm leading-relaxed">
                            Nessuna attività urgente richiede attenzione.
                        </p>
                    </div>

                </div>
            </div>
            
            <div class="flex justify-center mt-4 pb-12 w-full">
                <span class="text-xs font-medium text-slate-400 bg-slate-100 dark:bg-slate-800 px-3 py-1 rounded-full" v-if="tasks.data.length > 0">
                    Mostrati {{ tasks.data.length }} risultati
                </span>
            </div>

        </div>
    </AppLayout>
</template>