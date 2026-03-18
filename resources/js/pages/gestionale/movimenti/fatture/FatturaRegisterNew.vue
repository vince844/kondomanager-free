<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    FileText, Plus, Trash2, AlertTriangle, User,
    ShieldAlert, Save, AlertOctagon,
    TriangleAlert, Receipt, TrendingDown, Zap, ArrowRightLeft, 
    Briefcase, History, ChevronDown, CheckCircle,
} from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import WidgetDoubleLock from '@/components/gestionale/movimenti/fatture/WidgetDoubleLock.vue';
import ModalSopravvenienza from '@/components/gestionale/movimenti/fatture/ModalSopravvenienza.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';

// ---------------------------------------------------------------------------
// Interfaces & Props
// ---------------------------------------------------------------------------
const { euro } = useCurrencyFormatter();
const { generateRoute } = usePermission();

const moneyOptions = ref({
    prefix: '€ ',
    suffix: '',
    thousands: '.',
    decimal: ',',
    precision: 2,
    allowBlank: false,
    masked: false 
});

interface Fornitore {
    id: number; 
    ragione_sociale: string; 
    piva?: string;
    codice_fiscale?: string;
    soggetto_ritenuta: boolean;
    perc_ritenuta?: number; 
    perc_imponibile_ritenuta?: number; 
    giorni_scadenza?: number;
    iban_principale?: string; 
    modalita_pagamento_default?: string; 
    codice_tributo?: string;
}

interface Condominio { 
    id: number; 
    nome: string; 
}

interface Esercizio { 
    id: number; 
    nome: string; 
    stato: string; 
    data_inizio?: string;
}

interface Gestione { 
    id: number; 
    nome: string; 
    tipo: string; 
    esercizio_ids?: number[]; 
}

interface Conto { 
    id: number
    nome: string
    parent_nome?: string | null
    residuo_budget?: number
    is_capiente?: boolean
    ultimi_movimenti?: {
        data: string
        fornitore: string
        documento: string
        importo: number
        is_pregresso?: boolean
    }[]
}

interface Banca { 
    id: number; 
    nome: string; 
    saldo_attuale?: number; 
}

interface Immobile { 
    id: number; 
    label: string; 
}

interface DebitoPatrimoniale {
    id: number;
    fornitore_id: number;
    descrizione: string;
    importo_iniziale: number;
    importo_disponibile: number; // in centesimi!
    fatture_collegate?: any[];
}

interface FondoRiserva {
    id: number;
    nome: string;
    saldo_attuale: number; // in centesimi!
}

const props = defineProps<{
    condominio: Condominio; 
    condomini: Condominio[];
    esercizio: Esercizio;
    esercizi: Esercizio[];
    gestioni: Gestione[]; 
    fornitori: Fornitore[];
    conti: Conto[]; 
    banche: Banca[]; 
    immobili: Immobile[];
    debiti_patrimoniali: DebitoPatrimoniale[];
    fatture_pregresse_registrate: any[];
    fondi_riserva: FondoRiserva[];
    capienza_rata_zero: number;
    incassato_rata_zero: number;
}>();

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
const fileInput = ref<HTMLInputElement | null>(null);
const showOverrideModal   = ref(false);
const overrideMotivazione = ref('');
const showSuccessModal = ref(false);

const form = useForm({
    fornitore_id:       null as number | null,
    esercizio_id:       props.esercizio?.id || null,
    gestione_id:        null as number | null,
    tipo_documento:     'fattura',
    is_pregresso:       false,
    data_competenza_originaria: '',
    saldo_patrimoniale_id: null as number | null,
    coperture: [] as { tipo_copertura: string; importo: number; fonte_id: number | null; nota_amministratore?: string }[],
    
    // NUOVI CAMPI PER FATTURA PREGRESSA
    imponibile_pregresso: 0,
    aliquota_iva_pregressa: 22,

    numero_documento:   '',
    data_documento:     new Date().toISOString().substring(0, 10),
    data_scadenza:      '',
    conto_corrente_id:  null as number | null,
    modalita_pagamento: 'bonifico',
    iban_fornitore:     '',
    
    dati_extra: { 
        fiscal: { cig: '', cup: '' }, 
        competenza: { dal: '', al: '' }, 
        override_budget: null as any,
        log_legale_sopravvenienza: null as any 
    },
    
    stato_approvazione: 'approvata',
    righe: [{ descrizione: '', conto_id: null as number | null, immobile_id: null as number | null, importo_imponibile: 0, aliquota_iva: 22 }],
    file: null as File | null,
});

// ---------------------------------------------------------------------------
// Computed
// ---------------------------------------------------------------------------
const selectedFornitore = computed(() => props.fornitori.find(f => f.id === form.fornitore_id));

const totali = computed(() => {
    let imponibile = 0, iva = 0;
    
    if (form.is_pregresso) {
        // Calcolo per Fattura Pregressa (usa i campi diretti)
        imponibile = Number(form.imponibile_pregresso) || 0;
        iva = imponibile * (Number(form.aliquota_iva_pregressa) || 0) / 100;
    } else {
        // Calcolo per Fattura Normale (somma le righe)
        form.righe.forEach(r => {
            imponibile += Number(r.importo_imponibile) || 0;
            iva        += (Number(r.importo_imponibile) * (Number(r.aliquota_iva) || 0) / 100);
        });
    }

    let ritenuta = 0;
    if (selectedFornitore.value?.soggetto_ritenuta && form.tipo_documento !== 'nota_credito') {
        const base = imponibile * (Number(selectedFornitore.value.perc_imponibile_ritenuta) || 100) / 100;
        ritenuta   = base * (Number(selectedFornitore.value.perc_ritenuta) || 0) / 100;
    }
    
    return { 
        imponibile: Math.round(imponibile * 100) / 100, 
        iva: Math.round(iva * 100) / 100, 
        ritenuta: Math.round(ritenuta * 100) / 100, 
        netto: Math.round((imponibile + iva - ritenuta) * 100) / 100 
    };
});

const totaleDocLordoEuro = computed(() => {
    return totali.value.imponibile + totali.value.iva;
});

// Tiene traccia di quali conti hanno lo storico espanso
const expandedHistory = ref<Record<number, boolean>>({});
const toggleHistory = (contoId: number) => { expandedHistory.value[contoId] = !expandedHistory.value[contoId]; };

// Calcoli Budget INTERAMENTE IN CENTESIMI
const budgetImpacts = computed(() => {
    // FIX: Se è pregressa, l'impatto sul budget corrente è ZERO.
    if (form.is_pregresso) return [];

    const grouped = new Map<number, { id: number; nome: string; speso_cents: number; residuo_cents: number; ultimi_movimenti: any[] }>();

    form.righe.forEach(r => {
        if (!r.conto_id) return;
        const c = props.conti.find(c => c.id === r.conto_id);
        if (!c) return;
        
        const residuoCents = c.residuo_budget || 0;
        const spesaCents = Math.round((Number(r.importo_imponibile) || 0) * 100);

        const cur = grouped.get(r.conto_id) || { 
            id: c.id, 
            nome: c.nome, 
            speso_cents: 0, 
            residuo_cents: residuoCents,
            ultimi_movimenti: c.ultimi_movimenti || [] 
        };
        cur.speso_cents += spesaCents;
        grouped.set(r.conto_id, cur);
    });

    return Array.from(grouped.values()).map(i => ({ 
        ...i, 
        isOk: i.speso_cents <= i.residuo_cents, 
        delta_cents: i.residuo_cents - i.speso_cents 
    }));
});

const bancheNormalizzate = computed(() =>
    props.banche.map(b => ({ ...b, saldo_attuale_cents: b.saldo_attuale || 0 }))
);

const bankForecast = computed(() => {
    if (!form.conto_corrente_id) return null;
    const b = bancheNormalizzate.value.find(b => b.id === form.conto_corrente_id);
    if (!b) return null;
    
    const attualeCents = b.saldo_attuale_cents;
    const spesaCents = Math.round(totali.value.netto * 100); 
    const postCents = attualeCents - spesaCents;
    
    return { 
        attuale_cents: attualeCents, 
        post_cents: postCents, 
        isRed: postCents < 0 
    };
});

// Smart Date Check
watch(() => form.data_documento, (newDate) => {
    if (newDate && props.esercizio?.data_inizio) {
        const inizioEsercizio = props.esercizio.data_inizio.substring(0, 10);
        form.is_pregresso = newDate < inizioEsercizio;
    }
}, { immediate: true });

const transactionStatus = computed(() => {
    // Se la fattura è un Debito Pregresso, IGNORA il controllo budget!
    if (!form.is_pregresso && budgetImpacts.value.some(i => !i.isOk)) return 'CRITICAL_BUDGET';
    if (!form.is_pregresso && bankForecast.value?.isRed) return 'WARNING_CASH';
    return 'SAFE';
});

const sforoBudgetTotaleCents = computed(() =>
    budgetImpacts.value.filter(i => !i.isOk).reduce((acc, i) => acc + (i.speso_cents - i.residuo_cents), 0)
);

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------
watch(() => form.fornitore_id, (v) => {
    const f = props.fornitori.find(x => x.id === v);
    if (!f) return;
    if (!form.data_scadenza) {
        const d = new Date(form.data_documento);
        d.setDate(d.getDate() + (f.giorni_scadenza || 30));
        form.data_scadenza = d.toISOString().substring(0, 10);
    }
    form.iban_fornitore     = f.iban_principale            || '';
    form.modalita_pagamento = f.modalita_pagamento_default || 'bonifico';
});

const gestioniFiltrate = computed(() => {
    if (!form.esercizio_id) return [];
    return props.gestioni.filter((g) => {
        if (g.esercizio_ids && g.esercizio_ids.length > 0) {
            return g.esercizio_ids.includes(form.esercizio_id as number);
        }
        return true;
    });
});

watch(() => form.esercizio_id, (v) => {
    form.gestione_id = null;
    if (!v || !props.gestioni.length) return;
    form.gestione_id = props.gestioni.find(g => g.tipo === 'ordinaria')?.id ?? props.gestioni[0].id;
}, { immediate: true });

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
const addRiga    = () => form.righe.push({ descrizione: '', conto_id: null, immobile_id: null, importo_imponibile: 0, aliquota_iva: 22 });
const removeRiga = (idx: number) => { if (form.righe.length > 1) form.righe.splice(idx, 1); };
const showSopravvenienzaModal = ref(false);

const handleSubmit = () => {
    // 1. Controllo Sforo Budget
    if (transactionStatus.value === 'CRITICAL_BUDGET') { 
        showOverrideModal.value = true; 
        return; 
    }

    // 2. Controllo Sopravvenienza (Scudo Legale)
    const hasSopravvenienza = form.coperture?.some(c => c.tipo_copertura === 'sopravvenienza');
    if (form.is_pregresso && hasSopravvenienza) {
        showSopravvenienzaModal.value = true;
        return;
    }

    // Se tutto ok, procedi.
    doSubmit();
};

const handleSopravvenienzaConfirm = (payload: any) => {
    form.dati_extra.log_legale_sopravvenienza = payload;
    doSubmit();
};

const confirmOverride = () => {
    if (overrideMotivazione.value.length < 10) return;
    form.dati_extra.override_budget = {
        motivazione: overrideMotivazione.value,
        importo_sforo: sforoBudgetTotaleCents.value,
        budget_residuo_al_momento: -sforoBudgetTotaleCents.value,
        timestamp: new Date().toISOString(),
    };
    showOverrideModal.value = false;
    overrideMotivazione.value = '';
    doSubmit();
};

const cancelOverride = () => { showOverrideModal.value = false; overrideMotivazione.value = ''; };

const doSubmit = () => {
    form.post(route(generateRoute('gestionale.fatture.store'), { condominio: props.condominio.id }), {
        forceFormData: true,
        preserveScroll: true, // Mantiene la posizione della pagina
        onSuccess: () => { 
            form.reset(); 
            overrideMotivazione.value = ''; 
            showSuccessModal.value = true; // Accende la modale di successo!
        },
    });
};

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------
const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Dashboard', href: route(generateRoute('gestionale.index'), { condominio: props.condominio.id }) },
    { title: 'Fatture',   href: route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }) },
    { title: 'Registrazione' },
]);

const pageGuides = [
    { title: 'Panel + Ledger',   description: 'I dati principali a sinistra, le voci a destra come un registro contabile. Tutto visibile in un\'unica schermata.', icon: ArrowRightLeft, colorVariant: 'blue' as const },
    { title: 'Controllo Budget', description: 'Il sistema verifica il residuo per ogni capitolo di spesa in tempo reale, riga per riga.', icon: Zap, colorVariant: 'amber' as const },
    { title: 'Audit Trail',      description: 'Ogni sforamento deve essere giustificato con motivazione legale prima della registrazione.', icon: ShieldAlert, colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Registrazione fattura" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                page-title="Registrazione fattura passiva"
                page-subtitle="Inserisci i dati nel pannello di sinistra e le voci di dettaglio nel registro a destra."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :back-url="route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id })"
                back-text="Indietro"
            />

            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100">
                <div v-if="!form.is_pregresso && transactionStatus !== 'SAFE'"
                    class="rounded-xl p-4 flex items-center gap-4 border"
                    :class="transactionStatus === 'CRITICAL_BUDGET' ? 'bg-rose-50 border-rose-200 text-rose-900' : 'bg-amber-50 border-amber-200 text-amber-900'">
                    <AlertOctagon v-if="transactionStatus === 'CRITICAL_BUDGET'" class="w-5 h-5 shrink-0" />
                    <TriangleAlert v-else class="w-5 h-5 shrink-0" />
                    <p class="text-sm font-medium">
                        {{ transactionStatus === 'CRITICAL_BUDGET'
                            ? 'Sforamento budget rilevato — sarà necessaria una motivazione al momento della registrazione.'
                            : 'Liquidità insufficiente sul conto selezionato.' }}
                    </p>
                </div>
            </Transition>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start relative z-10">

                <div class="lg:col-span-4 h-full flex flex-col bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 shrink-0">
                        <div class="space-y-3 mb-4">
                            <div class="flex bg-slate-100 dark:bg-slate-800 p-1 rounded-lg">
                                <button type="button" @click="form.tipo_documento = 'fattura'"
                                    class="flex-1 py-2 text-[11px] font-black uppercase tracking-wider rounded-md transition-all flex items-center justify-center gap-1.5"
                                    :class="form.tipo_documento === 'fattura' ? 'bg-white dark:bg-slate-700 text-primary shadow-sm' : 'text-slate-400'">
                                    <FileText class="w-3.5 h-3.5" /> Fattura
                                </button>
                                <button type="button" @click="form.tipo_documento = 'nota_credito'"
                                    class="flex-1 py-2 text-[11px] font-black uppercase tracking-wider rounded-md transition-all"
                                    :class="form.tipo_documento === 'nota_credito' ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-400'">
                                    Nota Credito
                                </button>
                            </div>
                            <Transition mode="out-in">
                                <div v-if="form.tipo_documento === 'fattura'" key="ft" class="flex items-start gap-2 px-2.5 py-2 bg-blue-50 dark:bg-blue-950/30 rounded-lg border border-blue-100 dark:border-blue-900/30">
                                    <FileText class="w-3.5 h-3.5 text-blue-500 shrink-0 mt-0.5" />
                                    <p class="text-[10px] text-blue-700 dark:text-blue-400 leading-relaxed">
                                        <strong>Fattura passiva</strong> — documento ricevuto dal fornitore per beni o servizi acquistati.
                                    </p>
                                </div>
                                <div v-else key="nc" class="flex items-start gap-2 px-2.5 py-2 bg-rose-50 dark:bg-rose-950/30 rounded-lg border border-rose-100 dark:border-rose-900/30">
                                    <AlertTriangle class="w-3.5 h-3.5 text-rose-500 shrink-0 mt-0.5" />
                                    <p class="text-[10px] text-rose-700 dark:text-rose-400 leading-relaxed">
                                        <strong>Nota di credito</strong> — documento emesso dal fornitore per rettificare una fattura.
                                    </p>
                                </div>
                            </Transition>
                        </div>
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Dati Principali</h3>
                    </div>

                    <div class="p-5 flex-1 overflow-y-auto space-y-5">
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fornitore</Label>
                            <v-select 
                                v-model="form.fornitore_id" 
                                :options="fornitori" 
                                label="ragione_sociale" 
                                :reduce="(f: Fornitore) => f.id" 
                                placeholder="Cerca fornitore o P.IVA..." 
                                class="style-chooser w-full"
                            >
                                <template #option="{ ragione_sociale, piva, codice_fiscale, soggetto_ritenuta }">
                                    <div class="flex items-center gap-3 py-1">
                                        <div class="w-8 h-8 rounded-md bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center shrink-0">
                                            <Briefcase class="w-4 h-4 text-slate-400" />
                                        </div>
                                        <div class="flex flex-col overflow-hidden">
                                            <span class="font-bold text-sm text-slate-800 dark:text-slate-200 truncate">{{ ragione_sociale }}</span>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <span v-if="piva" class="text-[10px] text-slate-500 font-medium">P.IVA: {{ piva }}</span>
                                                <span v-else-if="codice_fiscale" class="text-[10px] text-slate-500 font-medium">C.F.: {{ codice_fiscale }}</span>
                                                <span v-else class="text-[10px] text-slate-400 italic">Nessuna P.IVA / C.F.</span>
                                                <span v-if="soggetto_ritenuta" class="text-[8px] font-black uppercase tracking-wider text-amber-600 border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900/50 dark:text-amber-500 rounded px-1.5 py-0.5 leading-none">
                                                    Ritenuta
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <template #selected-option="{ ragione_sociale, soggetto_ritenuta }">
                                    <div class="flex items-center gap-2 w-full overflow-hidden pr-2">
                                        <Briefcase class="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                        <span class="font-semibold text-sm truncate text-slate-800 dark:text-slate-200">{{ ragione_sociale }}</span>
                                        <span v-if="soggetto_ritenuta" class="ml-auto text-[8px] font-black uppercase tracking-wider text-amber-600 border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900/50 dark:text-amber-500 rounded px-1.5 py-0.5 leading-none shrink-0">
                                            Ritenuta
                                        </span>
                                    </div>
                                </template>
                            </v-select>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Gestione</Label>
                            <v-select v-model="form.gestione_id" :options="gestioni" label="nome" :reduce="(g: Gestione) => g.id" placeholder="Seleziona..." class="style-chooser" />
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">N. Documento</Label>
                            <Input v-model="form.numero_documento" 
                                class="h-9 uppercase text-base tracking-widest"
                                :class="{ 'border-red-500 focus-visible:ring-red-500': form.errors.numero_documento }"
                                @input="form.clearErrors('numero_documento')"
                                placeholder="Es. 123/A" />
                            <p v-if="form.errors.numero_documento" class="text-[11px] text-red-600 font-medium">
                                {{ form.errors.numero_documento }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data *</Label>
                                <Input type="date" v-model="form.data_documento" class="h-9 text-sm" />
                            </div>
                            <div class="space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-primary">Scadenza *</Label>
                                <Input 
                                    type="date" 
                                    v-model="form.data_scadenza"
                                    class="h-9 text-sm border-primary/40 bg-primary/5 text-primary font-bold" 
                                />
                            </div>
                        </div>

                        <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700 flex items-start gap-3 transition-colors" :class="{'bg-amber-50/50 border-amber-200 dark:bg-amber-900/10 dark:border-amber-700/50': form.is_pregresso}">
                            <div class="flex items-center h-5">
                                <input type="checkbox" id="is_pregresso" v-model="form.is_pregresso" class="w-4 h-4 text-amber-500 rounded border-slate-300 focus:ring-amber-500 cursor-pointer" />
                            </div>
                            <div class="flex flex-col">
                                <label for="is_pregresso" class="text-[11px] font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 cursor-pointer">
                                    Debito Esercizio Precedente
                                </label>
                                <p v-if="form.is_pregresso" class="text-[10px] text-amber-600 dark:text-amber-400 mt-1 font-medium leading-tight">
                                    Questa spesa non intaccherà il budget corrente. Verrà registrata come debito pregresso nello Stato Patrimoniale.
                                </p>
                            </div>
                        </div>

                        <div v-if="form.is_pregresso" class="grid grid-cols-3 gap-3 p-4 bg-amber-50/30 dark:bg-amber-900/5 border border-amber-100 dark:border-amber-800/30 rounded-lg mt-3">
                            <div class="col-span-2 space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Imponibile Lordo</Label>
                                <MoneyInput
                                    id="importo_pregresso"
                                    v-model="form.imponibile_pregresso"
                                    :money-options="moneyOptions"
                                    :lazy="false"
                                    class="h-10 font-black text-base bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm rounded-md border w-full px-3 focus:border-amber-400 focus:ring-amber-400/20"
                                    placeholder="0,00"
                                />
                            </div>
                            <div class="col-span-1 space-y-1.5 relative">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">IVA</Label>
                                <div class="relative">
                                    <Input min="0" max="100" type="number" v-model="form.aliquota_iva_pregressa" 
                                        class="h-10 text-center font-black text-base pr-5 pl-1 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm focus:border-amber-400 focus:ring-amber-400/20" />
                                    <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none font-bold">%</span>
                                </div>
                            </div>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conto Addebito</Label>
                            <v-select v-model="form.conto_corrente_id" :options="bancheNormalizzate" label="nome" :reduce="(c: any) => c.id" placeholder="Seleziona banca..." class="style-chooser">
                                <template #option="{ nome, saldo_attuale_cents }">
                                    <div class="flex justify-between items-center py-0.5">
                                        <span class="font-bold text-sm">{{ nome }}</span>
                                        <span class="text-[10px]" :class="saldo_attuale_cents >= 0 ? 'text-emerald-600' : 'text-rose-500'">{{ euro(saldo_attuale_cents) }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome, saldo_attuale_cents }">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-sm">{{ nome }}</span>
                                        <span class="text-[10px] text-slate-400">{{ euro(saldo_attuale_cents) }}</span>
                                    </div>
                                </template>
                            </v-select>
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">IBAN Fornitore</Label>
                            <Input v-model="form.iban_fornitore" class="h-9 text-sm" placeholder="IT00 0000..." />
                        </div>

                        <div class="border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl p-4 text-center cursor-pointer hover:bg-slate-50 transition-colors" @click="fileInput?.click()">
                            <FileText class="w-5 h-5 text-slate-300 mx-auto mb-1" />
                            <p class="text-[11px] text-slate-400 font-medium">{{ form.file ? form.file.name : 'Allega documento (PDF, XML, P7M)' }}</p>
                            <input type="file" ref="fileInput" class="hidden" accept=".pdf,.xml,.p7m,.jpg,.jpeg,.png" @change="(e: any) => form.file = e.target.files[0]" />
                        </div>
                    </div>

                    <div class="p-5 bg-slate-900 dark:bg-slate-950 text-white border-t border-slate-700 shrink-0 space-y-4">
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">Imponibile</span>
                                <span>{{ euro(totali.imponibile, { fromCents: false }) }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">IVA</span>
                                <span>{{ euro(totali.iva, { fromCents: false }) }}</span>
                            </div>
                            
                            <div v-if="totali.ritenuta > 0" class="flex justify-between text-xs pt-1 border-t border-slate-800">
                                <span class="text-amber-400">Ritenuta d'Acconto</span>
                                <span class="text-amber-400">- {{ euro(totali.ritenuta, { fromCents: false }) }}</span>
                            </div>
                            <div v-else class="flex justify-between text-xs pt-1 border-t border-slate-800">
                                <span class="text-slate-500 italic">Nessuna Ritenuta</span>
                                <span class="text-slate-500">€ 0,00</span>
                            </div>
                            
                            <div class="flex justify-between items-baseline pt-3 border-t border-slate-700">
                                <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Netto da pagare</span>
                                <span class="font-black text-2xl" :class="totali.netto > 0 ? 'text-emerald-400' : 'text-white'">
                                    {{ euro(totali.netto, { fromCents: false }) }}
                                </span>
                            </div>
                        </div>
                        
                        <Button type="button" :disabled="form.processing" @click="handleSubmit" class="w-full h-12 font-black text-sm uppercase tracking-wider rounded-xl gap-2" :class="transactionStatus === 'CRITICAL_BUDGET' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'">
                            <ShieldAlert v-if="transactionStatus === 'CRITICAL_BUDGET'" class="w-5 h-5" />
                            <Save v-else class="w-5 h-5" />
                            {{ transactionStatus === 'CRITICAL_BUDGET' ? 'Autorizza e Registra' : 'Registra Documento' }}
                        </Button>
                    </div>
                </div>

                <div class="lg:col-span-8 flex flex-col gap-5 relative z-0">

                    <div v-if="form.is_pregresso">
                        <WidgetDoubleLock
                            :form="form"
                            :fornitore-id="form.fornitore_id"
                            :debiti-patrimoniali="debiti_patrimoniali as any"
                            :fatture-pregresse-registrate="fatture_pregresse_registrate as any"
                            :conti-spesa="conti as any"
                            :fondi-riserva="fondi_riserva as any"
                            :capienza-rata-zero="capienza_rata_zero"
                            :incassato-rata-zero="incassato_rata_zero" :totale-fattura-lordo="totaleDocLordoEuro"
                            :bank-forecast="bankForecast"
                        />
                    </div>

                    <div v-else class="flex flex-col gap-5">
                        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                            <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center justify-between rounded-t-xl">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <Receipt class="w-5 h-5 text-slate-400" />
                                        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">Registro voci di spesa</h3>
                                    </div>
                                    <p class="text-[11px] text-slate-500 mt-1">Aggiungi una o più righe per ripartire il documento sui capitoli corretti.</p>
                                </div>
                                <div class="flex items-center gap-4">
                                    <Badge variant="secondary" class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-transparent">
                                        {{ form.righe.length }} {{ form.righe.length === 1 ? 'Voce' : 'Voci' }}
                                    </Badge>
                                    <Button variant="outline" size="sm" type="button" @click="addRiga" 
                                        class="h-9 text-[11px] font-bold uppercase border-primary/20 text-primary hover:bg-primary/5 hover:text-primary transition-colors gap-1.5 shadow-sm">
                                        <Plus class="w-3.5 h-3.5" /> Aggiungi riga
                                    </Button>
                                </div>
                            </div>

                            <div class="divide-y divide-slate-100 dark:divide-slate-800/80">
                                <div v-for="(riga, idx) in form.righe" :key="idx" class="p-6 hover:bg-slate-50/30 group transition-colors flex flex-col gap-4">
                                    <div class="grid grid-cols-12 gap-4">
                                        <div class="col-span-12 md:col-span-8 relative">
                                            <Label class="text-[10px] font-bold uppercase text-slate-400 mb-1.5 block">Capitolo di spesa</Label>
                                            <v-select 
                                                v-model="riga.conto_id" 
                                                :options="conti" 
                                                label="nome"
                                                :reduce="(c: Conto) => c.id" 
                                                placeholder="Cerca capitolo..." 
                                                class="style-chooser w-full"
                                                append-to-body
                                            >
                                                <template #option="{ nome, parent_nome, residuo_budget, is_capiente }">
                                                    <div class="flex items-center justify-between py-1.5 border-b border-transparent hover:border-slate-100 dark:hover:border-slate-800">
                                                        <div class="flex flex-col">
                                                            <span v-if="parent_nome" class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wider font-semibold mb-0.5">
                                                                {{ parent_nome }}
                                                            </span>
                                                            <span class="font-medium text-sm text-slate-800 dark:text-slate-200" :class="{ 'text-rose-600 dark:text-rose-400': !is_capiente }">
                                                                {{ nome }}
                                                            </span>
                                                        </div>
                                                        <span class="text-[10px] px-2 py-1 rounded-md font-semibold whitespace-nowrap ml-4 border"
                                                            :class="is_capiente ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:border-emerald-900/50' : 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/30 dark:border-rose-900/50'">
                                                            {{ euro(residuo_budget) }}
                                                        </span>
                                                    </div>
                                                </template>
                                                <template #selected-option="{ nome, parent_nome, is_capiente }">
                                                    <div class="flex items-center gap-2 text-sm w-full overflow-hidden">
                                                        <span class="font-medium truncate" :class="{ 'text-rose-600 dark:text-rose-400': !is_capiente }">{{ nome }}</span>
                                                        <span class="text-xs text-slate-400 truncate" v-if="parent_nome">– {{ parent_nome }}</span>
                                                    </div>
                                                </template>
                                            </v-select>
                                        </div>

                                        <div class="col-span-12 md:col-span-4 relative">
                                            <Label class="text-[10px] font-bold uppercase text-slate-400 mb-1.5 block">Unità (Opzionale)</Label>
                                            <v-select 
                                                v-model="riga.immobile_id" 
                                                :options="immobili" 
                                                label="label" 
                                                :reduce="(i: Immobile) => i.id" 
                                                placeholder="Tutti (Spesa Comune)" 
                                                class="style-chooser text-xs"
                                                append-to-body
                                            >
                                                <template #option="{ label }">
                                                    <div class="flex items-center gap-1.5 py-0.5">
                                                        <User class="w-3 h-3 text-blue-400 shrink-0" />
                                                        <span class="text-xs">{{ label }}</span>
                                                    </div>
                                                </template>
                                            </v-select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-12 gap-4 items-start">
                                        <div class="col-span-12 md:col-span-4 lg:col-span-5">
                                            <Input v-model="riga.descrizione" 
                                                placeholder="Causale riga..." 
                                                class="h-10 text-sm bg-slate-50 dark:bg-slate-900/50"
                                                :class="{ 'border-red-500 focus-visible:ring-red-500': form.errors[`righe.${idx}.descrizione`] }" 
                                                @input="form.clearErrors(`righe.${idx}.descrizione`)"
                                            />
                                            <p v-if="form.errors[`righe.${idx}.descrizione`]" class="text-[11px] text-red-600 font-medium mt-1">
                                                {{ form.errors[`righe.${idx}.descrizione`] }}
                                            </p>
                                        </div>

                                        <div class="col-span-4 md:col-span-3 relative">
                                            <MoneyInput
                                                :id="'importo_' + idx"
                                                v-model="riga.importo_imponibile"
                                                :money-options="moneyOptions"
                                                :lazy="false"
                                                class="h-10 font-black text-base bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm rounded-md border w-full px-3"
                                                placeholder="0,00"
                                            />
                                            <div v-if="riga.conto_id && (() => {
                                                const c = conti.find(c => c.id === riga.conto_id);
                                                if (!c || c.residuo_budget === undefined) return false;
                                                return Math.round((Number(riga.importo_imponibile) || 0) * 100) > c.residuo_budget;
                                            })()" class="flex items-center gap-1 mt-1 text-rose-500 absolute -bottom-5 right-0">
                                                <TrendingDown class="w-3 h-3" />
                                                <span class="text-[9px] font-black uppercase">Sforo budget</span>
                                            </div>
                                        </div>

                                        <div class="col-span-3 md:col-span-2 lg:col-span-1 relative">
                                            <div class="relative">
                                                <Input min="0" max="100" v-model="riga.aliquota_iva" 
                                                    class="h-10 text-center font-black text-base pr-5 pl-1 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm" />
                                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none font-bold">%</span>
                                            </div>
                                        </div>

                                        <div class="col-span-5 md:col-span-3 flex items-center justify-end gap-3 h-10">
                                            <div class="text-right">
                                                <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block leading-none mb-1">Totale Riga</span>
                                                <span class="font-black text-base text-slate-800 dark:text-slate-200 block leading-none">
                                                    {{ euro(Number(riga.importo_imponibile) * (1 + (Number(riga.aliquota_iva) || 0) / 100), { fromCents: false }) }}
                                                </span>
                                            </div>
                                            <Button variant="ghost" size="icon" type="button" @click="removeRiga(idx)" 
                                                class="h-10 w-10 shrink-0 text-slate-300 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/30 opacity-0 group-hover:opacity-100 transition-all rounded-lg border border-transparent hover:border-rose-100 ml-1">
                                                <Trash2 class="w-4 h-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="py-5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 rounded-b-xl flex justify-end">
                                <div class="flex items-center gap-8 pr-[60px]"> 
                                    <div class="text-right">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest block mb-0.5">Imponibile</span>
                                        <span class="font-black text-slate-700 dark:text-slate-300 text-lg">{{ euro(totali.imponibile, { fromCents: false }) }}</span>
                                    </div>
                                    <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div> 
                                    <div class="text-right">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest block mb-0.5">IVA</span>
                                        <span class="font-black text-slate-700 dark:text-slate-300 text-lg">{{ euro(totali.iva, { fromCents: false }) }}</span>
                                    </div>
                                    <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div> 
                                    <div class="text-right">
                                        <span class="text-[10px] text-primary font-bold uppercase tracking-widest block mb-0.5">Totale</span>
                                        <span class="font-black text-primary text-xl">{{ euro(totali.imponibile + totali.iva, { fromCents: false }) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-900 dark:bg-slate-950 text-white rounded-xl border shadow-lg overflow-hidden transition-all duration-300"
                            :class="transactionStatus === 'CRITICAL_BUDGET' ? 'border-rose-500 shadow-rose-500/10' : transactionStatus === 'WARNING_CASH' ? 'border-amber-500/30' : 'border-slate-700'">

                            <div class="px-6 py-4 border-b border-slate-700/50 flex items-center justify-between bg-slate-800/40">
                                <div class="flex items-center gap-2">
                                    <Zap class="w-4 h-4 text-blue-400" :class="transactionStatus === 'CRITICAL_BUDGET' ? 'text-rose-400 animate-pulse' : ''" />
                                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Simulazione Impatto Finanziario</span>
                                </div>
                                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-black uppercase"
                                    :class="{
                                        'bg-rose-500/20 text-rose-400':    transactionStatus === 'CRITICAL_BUDGET',
                                        'bg-amber-500/20 text-amber-400':  transactionStatus === 'WARNING_CASH',
                                        'bg-emerald-500/20 text-emerald-400': transactionStatus === 'SAFE',
                                    }">
                                    <span class="w-1.5 h-1.5 rounded-full mr-1"
                                        :class="{
                                            'bg-rose-500 animate-pulse':  transactionStatus === 'CRITICAL_BUDGET',
                                            'bg-amber-500 animate-pulse': transactionStatus === 'WARNING_CASH',
                                            'bg-emerald-500':             transactionStatus === 'SAFE',
                                        }"></span>
                                    {{ transactionStatus === 'CRITICAL_BUDGET' ? 'Sforo Budget' : transactionStatus === 'WARNING_CASH' ? 'Attenzione Cassa' : 'Tutto OK' }}
                                </div>
                            </div>

                            <div class="grid grid-cols-2 divide-x divide-slate-700/50">
                                <div class="p-5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-4">Analisi Budget — Capitoli</p>
                                    <div v-if="budgetImpacts.length === 0" class="py-6 text-center text-slate-600 text-xs">Nessuna voce ancora</div>
                                    <div v-else class="space-y-3">
                                        <div v-for="impact in budgetImpacts" :key="impact.id" class="space-y-1.5 bg-slate-800/20 rounded-lg p-2.5 border border-slate-700/50">
                                            <div class="flex justify-between items-start">
                                                <span class="text-xs font-bold truncate max-w-[60%]">{{ impact.nome }}</span>
                                                <span class="text-xs font-black shrink-0" :class="impact.isOk ? 'text-emerald-400' : 'text-rose-400'">
                                                    {{ impact.isOk ? '+' : '' }}{{ euro(impact.delta_cents) }}
                                                </span>
                                            </div>
                                            
                                            <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                                <div class="h-full rounded-full transition-all duration-500"
                                                    :class="impact.isOk ? 'bg-emerald-500' : 'bg-rose-500'"
                                                    :style="{ width: Math.min((impact.speso_cents / Math.max(impact.residuo_cents, 1)) * 100, 100) + '%' }">
                                                </div>
                                            </div>
                                            
                                            <div class="flex justify-between text-[9px] text-slate-500 font-medium">
                                                <span>Usato: {{ euro(impact.speso_cents) }}</span>
                                                <span>Budget: {{ euro(impact.residuo_cents) }}</span>
                                            </div>
                                            
                                            <div v-if="impact.ultimi_movimenti && impact.ultimi_movimenti.length > 0" class="mt-2 pt-2 border-t border-slate-700/50">
                                                <button type="button" @click="toggleHistory(impact.id)" class="flex items-center gap-1.5 text-[9px] font-bold uppercase tracking-wider text-slate-400 hover:text-blue-400 transition-colors w-full">
                                                    <History class="w-3 h-3" />
                                                    <span>{{ impact.ultimi_movimenti.length }} Moviment{{ impact.ultimi_movimenti.length > 1 ? 'i' : 'o' }} recent{{ impact.ultimi_movimenti.length > 1 ? 'i' : 'e' }}</span>
                                                    <ChevronDown class="w-3 h-3 ml-auto transition-transform" :class="expandedHistory[impact.id] ? 'rotate-180' : ''" />
                                                </button>

                                                <div v-if="expandedHistory[impact.id]" class="mt-2 space-y-1.5">
                                                    <div v-for="(storico, sIdx) in impact.ultimi_movimenti" :key="sIdx" 
                                                        class="flex items-center justify-between bg-slate-900/50 p-1.5 rounded text-[10px] border border-slate-800">
                                                        <div class="flex flex-col truncate pr-2">
                                                            <span class="text-slate-300 font-semibold truncate">{{ storico.fornitore }}</span>
                                                            <div class="flex items-center gap-1.5">
                                                                <span class="text-slate-500">{{ storico.data }} · {{ storico.documento }}</span>
                                                                <span v-if="storico.is_pregresso" class="text-[8px] font-black uppercase tracking-wider bg-amber-500/20 text-amber-400 px-1.5 py-0.5 rounded">Pregresso</span>
                                                            </div>
                                                        </div>
                                                        <span class="font-bold text-slate-400 shrink-0">{{ euro(storico.importo) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-4">Previsione cassa</p>
                                    <div v-if="bankForecast" class="space-y-3">
                                        <div class="space-y-2">
                                            <div class="flex justify-between text-xs">
                                                <span class="text-slate-400">Saldo attuale</span>
                                                <span class="text-white">{{ euro(bankForecast.attuale_cents) }}</span>
                                            </div>
                                            <div class="flex justify-between text-xs">
                                                <span class="text-slate-400">Uscita prevista</span>
                                                <span class="text-rose-400">- {{ euro(totali.netto, { fromCents: false }) }}</span>
                                            </div>
                                        </div>
                                        <div class="pt-3 border-t border-slate-700 space-y-1">
                                            <p class="text-[9px] text-slate-500 uppercase font-bold">Saldo post-pagamento</p>
                                            <p class="font-black text-2xl" :class="bankForecast.isRed ? 'text-rose-500' : 'text-emerald-400'">
                                                {{ euro(bankForecast.post_cents) }}
                                            </p>
                                            <div class="h-1.5 bg-white/10 rounded-full overflow-hidden mt-2">
                                                <div class="h-full rounded-full transition-all"
                                                    :class="bankForecast.isRed ? 'bg-rose-500' : 'bg-emerald-500'"
                                                    :style="{ width: Math.min(Math.max((bankForecast.post_cents / Math.max(bankForecast.attuale_cents, 1)) * 100, 0), 100) + '%' }">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="py-6 text-center text-slate-600 text-xs">
                                        Seleziona un conto nel pannello sinistro
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showOverrideModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-200 dark:border-slate-800">
                    <div class="bg-rose-50 dark:bg-rose-950/30 p-7 border-b border-rose-100 dark:border-rose-900/30 flex items-start gap-4">
                        <div class="bg-rose-100 dark:bg-rose-900/50 p-2.5 rounded-xl shrink-0">
                            <ShieldAlert class="w-6 h-6 text-rose-600" />
                        </div>
                        <div>
                            <h3 class="font-black text-rose-900 dark:text-rose-100 text-lg">Sforamento budget</h3>
                            <p class="text-xs text-rose-700/70 mt-1">Eccesso: <span class="font-black">{{ euro(sforoBudgetTotaleCents) }}</span></p>
                        </div>
                    </div>
                    <div class="p-7 space-y-5">
                        <p class="text-xs text-slate-500 leading-relaxed italic border-l-4 border-rose-200 pl-4">
                            "L'amministratore non può ordinare lavori di manutenzione straordinaria, salvo carattere urgente..." — Art. 1135 c.c.
                        </p>
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2 block">Motivazione *</Label>
                            <textarea v-model="overrideMotivazione" rows="4" class="w-full border border-slate-200 dark:border-slate-700 rounded-xl p-4 text-sm bg-slate-50 dark:bg-slate-800 outline-none resize-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-400 transition-all" placeholder="Es: Intervento urgente per rottura colonna..." />
                            <div class="flex justify-between mt-1">
                                <p class="text-[10px] text-slate-400">Minimo 10 caratteri</p>
                                <p class="text-[10px]" :class="overrideMotivazione.length >= 10 ? 'text-emerald-500' : 'text-slate-400'">{{ overrideMotivazione.length }}</p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <Button variant="outline" class="flex-1 h-11 rounded-xl font-bold" @click="cancelOverride">Annulla</Button>
                            <Button class="flex-1 h-11 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black" :disabled="overrideMotivazione.length < 10" @click="confirmOverride">Conferma e Registra</Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <ModalSopravvenienza
            v-model:show="showSopravvenienzaModal"
            :condominio-id="props.condominio.id"
            :fornitore-nome="selectedFornitore?.ragione_sociale || 'Fornitore'"
            @confirm="handleSopravvenienzaConfirm"
        />

        <Teleport to="body">
            <div v-if="showSuccessModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all">
                <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden text-center p-8 border border-slate-200 dark:border-slate-800 transform scale-100">
                    
                    <div class="w-20 h-20 bg-emerald-50 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-emerald-100 dark:border-emerald-900/50">
                        <CheckCircle class="w-10 h-10 text-emerald-500" />
                    </div>
                    
                    <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-2">Operazione completata</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-8 leading-relaxed">
                        Il documento e le coperture contabili sono stati registrati e bilanciati correttamente.
                    </p>
                    
                    <div class="flex flex-col gap-3">
                        <Button @click="showSuccessModal = false" class="w-full h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black uppercase tracking-widest text-[11px] shadow-lg shadow-emerald-600/20 transition-all">
                            Registra un'altra fattura
                        </Button>
                        
                        <Button variant="ghost" @click="router.visit(route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }))" class="w-full h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                            Torna all'elenco fatture
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

    </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>

<style>
/* 1. Forza il dropdown sopra a tutto */
.vs__dropdown-menu {
    z-index: 9999 !important;
    position: absolute !important;
    background: white !important;
    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1) !important;
}

.dark .vs__dropdown-menu {
    background: #1e293b !important; /* slate-800 */
    border: 1px solid #334155;
}

/* 2. Assicurati che il contenitore della simulazione non crei un nuovo contesto di stacking */
.lg\:col-span-8 {
    position: relative;
    z-index: 1; /* Più basso del dropdown */
}

/* 3. Padding extra per le righe se necessario per far respirare il dropdown */
.style-chooser .vs__dropdown-toggle {
    border-radius: 0.75rem;
    min-height: 40px;
}
</style>