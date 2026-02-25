<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    FileText, Plus, Trash2, AlertTriangle, User,
    ShieldAlert, Save, CheckCircle2, AlertOctagon,
    TriangleAlert, Receipt, Wallet, TrendingDown,
    Building2, Euro, Zap, ArrowRightLeft
} from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';

// ---------------------------------------------------------------------------
// Interfaces & Props
// ---------------------------------------------------------------------------
const { euro } = useCurrencyFormatter();

interface Fornitore {
    id: number; ragione_sociale: string; piva: string; soggetto_ritenuta: boolean;
    perc_ritenuta?: number; perc_imponibile_ritenuta?: number; giorni_scadenza?: number;
    iban_principale?: string; modalita_pagamento_default?: string; codice_tributo?: string;
}
interface Condominio { id: number; nome: string; }
interface Esercizio  { id: number; nome: string; stato: string; }
interface Gestione   { id: number; nome: string; tipo: string; esercizio_ids?: number[]; }
interface Conto      { id: number; nome: string; residuo_budget?: number; is_capiente?: boolean; }
interface Banca      { id: number; nome: string; saldo_attuale?: number; }
interface Immobile   { id: number; label: string; }

const props = defineProps<{
    condominio: Condominio; condomini: Condominio[];
    esercizio: Esercizio; esercizi: Esercizio[];
    gestioni: Gestione[]; fornitori: Fornitore[];
    conti: Conto[]; banche: Banca[]; immobili: Immobile[];
}>();

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
const fileInput = ref<HTMLInputElement | null>(null);
const showOverrideModal   = ref(false);
const overrideMotivazione = ref('');

const form = useForm({
    fornitore_id:       null as number | null,
    esercizio_id:       props.esercizio?.id || null,
    gestione_id:        null as number | null,
    tipo_documento:     'fattura',
    numero_documento:   '',
    data_documento:     new Date().toISOString().substring(0, 10),
    data_scadenza:      '',
    conto_corrente_id:  null as number | null,
    modalita_pagamento: 'bonifico',
    iban_fornitore:     '',
    dati_extra: { fiscal: { cig: '', cup: '' }, competenza: { dal: '', al: '' }, override_budget: null as any },
    stato_approvazione: 'approvata',
    righe: [{ descrizione: '', conto_id: null as number | null, immobile_id: null as number | null, importo_imponibile: 0, aliquota_iva: 22 }],
    file: null as File | null,
});

// ---------------------------------------------------------------------------
// Computed
// ---------------------------------------------------------------------------
const selectedFornitore = computed(() => props.fornitori.find(f => f.id === form.fornitore_id));

// NOTA: I totali calcolati dal form sono in EURO (perché l'utente digita in Euro)
const totali = computed(() => {
    let imponibile = 0, iva = 0;
    form.righe.forEach(r => {
        imponibile += Number(r.importo_imponibile) || 0;
        iva        += (Number(r.importo_imponibile) * (Number(r.aliquota_iva) || 0) / 100);
    });
    let ritenuta = 0;
    if (selectedFornitore.value?.soggetto_ritenuta && form.tipo_documento !== 'nota_credito') {
        const base = imponibile * (Number(selectedFornitore.value.perc_imponibile_ritenuta) || 100) / 100;
        ritenuta   = base * (Number(selectedFornitore.value.perc_ritenuta) || 0) / 100;
    }
    
    // Arrotondamento per evitare errori di floating point js
    return { 
        imponibile: Math.round(imponibile * 100) / 100, 
        iva: Math.round(iva * 100) / 100, 
        ritenuta: Math.round(ritenuta * 100) / 100, 
        netto: Math.round((imponibile + iva - ritenuta) * 100) / 100 
    };
});

// Calcoli Budget INTERAMENTE IN CENTESIMI
const budgetImpacts = computed(() => {
    const grouped = new Map<number, { nome: string; speso_cents: number; residuo_cents: number }>();
    form.righe.forEach(r => {
        if (!r.conto_id) return;
        const c = props.conti.find(c => c.id === r.conto_id);
        if (!c) return;
        
        // residuo_budget dal backend è in centesimi
        const residuoCents = c.residuo_budget || 0;
        
        // La riga form è in euro, convertiamola in centesimi per il confronto
        const spesaCents = Math.round((Number(r.importo_imponibile) || 0) * 100);

        const cur = grouped.get(r.conto_id) || { nome: c.nome, speso_cents: 0, residuo_cents: residuoCents };
        cur.speso_cents += spesaCents;
        grouped.set(r.conto_id, cur);
    });
    return Array.from(grouped.values()).map(i => ({ 
        ...i, 
        isOk: i.speso_cents <= i.residuo_cents, 
        delta_cents: i.residuo_cents - i.speso_cents 
    }));
});

// Le banche mantengono il loro valore in centesimi per uniformità col DB
const bancheNormalizzate = computed(() =>
    props.banche.map(b => ({ ...b, saldo_attuale_cents: b.saldo_attuale || 0 }))
);

// Calcolo Cassa INTERAMENTE IN CENTESIMI
const bankForecast = computed(() => {
    if (!form.conto_corrente_id) return null;
    const b = bancheNormalizzate.value.find(b => b.id === form.conto_corrente_id);
    if (!b) return null;
    
    const attualeCents = b.saldo_attuale_cents;
    // totali.netto è in euro, lo portiamo in centesimi per il calcolo
    const spesaCents = Math.round(totali.value.netto * 100); 
    
    const postCents = attualeCents - spesaCents;
    
    return { 
        attuale_cents: attualeCents, 
        post_cents: postCents, 
        isRed: postCents < 0 
    };
});

const transactionStatus = computed(() => {
    if (budgetImpacts.value.some(i => !i.isOk)) return 'CRITICAL_BUDGET';
    if (bankForecast.value?.isRed)              return 'WARNING_CASH';
    return 'SAFE';
});

// Restituisce il valore in centesimi per consistenza
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
        // Se la gestione ha degli esercizi collegati, controlliamo se contiene quello selezionato
        if (g.esercizio_ids && g.esercizio_ids.length > 0) {
            return g.esercizio_ids.includes(form.esercizio_id as number);
        }
        
        // Fallback: se una gestione per qualche motivo non ha ancora esercizi collegati
        // (magari appena creata e non associata), la mostriamo per sicurezza.
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

const handleSubmit = () => {
    if (transactionStatus.value === 'CRITICAL_BUDGET') { showOverrideModal.value = true; return; }
    doSubmit();
};

const confirmOverride = () => {
    if (overrideMotivazione.value.length < 10) return;
    form.dati_extra.override_budget = {
        motivazione: overrideMotivazione.value,
        importo_sforo: sforoBudgetTotaleCents.value, // Passato in centesimi!
        budget_residuo_al_momento: -sforoBudgetTotaleCents.value,
        timestamp: new Date().toISOString(),
    };
    showOverrideModal.value   = false;
    overrideMotivazione.value = '';
    doSubmit();
};

const cancelOverride = () => { showOverrideModal.value = false; overrideMotivazione.value = ''; };

const doSubmit = () => {
    form.post(route('admin.gestionale.fatture.store', { condominio: props.condominio.id }), {
        forceFormData: true,
        onSuccess: () => { form.reset(); overrideMotivazione.value = ''; },
    });
};

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------
const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Dashboard', href: route('admin.gestionale.index', { condominio: props.condominio.id }) },
    { title: 'Fatture',   href: route('admin.gestionale.fatture.index', { condominio: props.condominio.id }) },
    { title: 'Registrazione' },
]);

const pageGuides = [
    { title: 'Panel + Ledger',   description: 'I dati principali a sinistra, le voci a destra come un registro contabile. Tutto visibile in un\'unica schermata.', icon: ArrowRightLeft, colorVariant: 'blue' as const },
    { title: 'Controllo Budget', description: 'Il sistema verifica il residuo per ogni capitolo di spesa in tempo reale, riga per riga.', icon: Zap, colorVariant: 'amber' as const },
    { title: 'Audit Trail',      description: 'Ogni sforamento deve essere giustificato con motivazione legale prima della registrazione.', icon: ShieldAlert, colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Registrazione Fattura" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                page-title="Registrazione Fattura Passiva"
                page-subtitle="Inserisci i dati nel pannello di sinistra e le voci di dettaglio nel registro a destra."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            />

            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100">
                <div v-if="transactionStatus !== 'SAFE'"
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

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

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
                            <Transition
                                enter-active-class="transition duration-200 ease-out"
                                enter-from-class="opacity-0 -translate-y-1"
                                enter-to-class="opacity-100 translate-y-0"
                                mode="out-in">
                                <div v-if="form.tipo_documento === 'fattura'" key="ft"
                                    class="flex items-start gap-2 px-2.5 py-2 bg-blue-50 dark:bg-blue-950/30 rounded-lg border border-blue-100 dark:border-blue-900/30">
                                    <FileText class="w-3.5 h-3.5 text-blue-500 shrink-0 mt-0.5" />
                                    <p class="text-[10px] text-blue-700 dark:text-blue-400 leading-relaxed">
                                        <strong>Fattura passiva</strong> — documento ricevuto dal fornitore per beni o servizi acquistati. Genera un debito verso il fornitore e un'uscita di cassa al pagamento.
                                    </p>
                                </div>
                                <div v-else key="nc"
                                    class="flex items-start gap-2 px-2.5 py-2 bg-rose-50 dark:bg-rose-950/30 rounded-lg border border-rose-100 dark:border-rose-900/30">
                                    <AlertTriangle class="w-3.5 h-3.5 text-rose-500 shrink-0 mt-0.5" />
                                    <p class="text-[10px] text-rose-700 dark:text-rose-400 leading-relaxed">
                                        <strong>Nota di credito</strong> — documento emesso dal fornitore per rettificare una fattura (rimborso, reso, sconto). Gli importi vengono registrati in <em>negativo</em> e la ritenuta non si applica.
                                    </p>
                                </div>
                            </Transition>
                        </div>
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Dati Principali</h3>
                    </div>

                    <div class="p-5 flex-1 overflow-y-auto space-y-5">

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fornitore *</Label>
                            <v-select v-model="form.fornitore_id" :options="fornitori"
                                label="ragione_sociale" :reduce="(f: Fornitore) => f.id"
                                placeholder="Cerca fornitore..." class="style-chooser">
                                <template #option="{ ragione_sociale, piva, soggetto_ritenuta }">
                                    <div class="py-1">
                                        <span class="font-bold text-sm block">{{ ragione_sociale }}</span>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-[10px] text-slate-400">{{ piva }}</span>
                                            <Badge v-if="soggetto_ritenuta" variant="outline" class="text-[9px] text-amber-600 border-amber-200 bg-amber-50 h-4">Ritenuta</Badge>
                                        </div>
                                    </div>
                                </template>
                            </v-select>
                            <div v-if="selectedFornitore && selectedFornitore.soggetto_ritenuta"
                                class="flex items-center gap-2 p-2.5 bg-amber-50 border border-amber-100 rounded-lg mt-2">
                                <AlertTriangle class="w-3.5 h-3.5 text-amber-500 shrink-0" />
                                <span class="text-[10px] text-amber-700 font-bold">
                                    Ritenuta {{ selectedFornitore.perc_ritenuta }}% — {{ selectedFornitore.codice_tributo }}
                                </span>
                            </div>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Gestione</Label>
                            <v-select v-model="form.gestione_id" :options="gestioni"
                                label="nome" :reduce="(g: Gestione) => g.id" placeholder="Seleziona..." class="style-chooser" />
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">N. Documento (Fornitore)</Label>
                            <Input v-model="form.numero_documento"
                                class="h-11 uppercase text-base tracking-widest"
                                placeholder="Es. 123/A" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data *</Label>
                                <Input type="date" v-model="form.data_documento" class="h-11 text-sm" />
                            </div>
                            <div class="space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-primary">Scadenza *</Label>
                                <Input type="date" v-model="form.data_scadenza"
                                    class="h-11 text-sm border-primary/40 bg-primary/5 text-primary font-bold" />
                            </div>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conto Addebito</Label>
                            <v-select v-model="form.conto_corrente_id" :options="bancheNormalizzate"
                                label="nome" :reduce="(c: any) => c.id" placeholder="Seleziona banca..." class="style-chooser">
                                <template #option="{ nome, saldo_attuale_cents }">
                                    <div class="flex justify-between items-center py-0.5">
                                        <span class="font-bold text-sm">{{ nome }}</span>
                                        <span class="text-[10px]"
                                            :class="saldo_attuale_cents >= 0 ? 'text-emerald-600' : 'text-rose-500'">
                                            {{ euro(saldo_attuale_cents) }} 
                                        </span>
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
                            <Input v-model="form.iban_fornitore" class="h-11 text-sm" placeholder="IT00 0000..." />
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Modalità Pagamento</Label>
                            <select v-model="form.modalita_pagamento"
                                class="w-full h-11 border border-slate-200 dark:border-slate-700 rounded-lg text-sm px-3 bg-white dark:bg-slate-900">
                                <option value="bonifico">Bonifico</option>
                                <option value="mav">MAV</option>
                                <option value="contanti">Contanti</option>
                                <option value="ri.ba">Ri.Ba.</option>
                            </select>
                        </div>

                        <div class="border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl p-4 text-center cursor-pointer hover:bg-slate-50 transition-colors"
                            @click="fileInput?.click()">
                            <FileText class="w-5 h-5 text-slate-300 mx-auto mb-1" />
                            <p class="text-[11px] text-slate-400 font-medium">
                                {{ form.file ? form.file.name : 'Allega documento (PDF, XML, P7M)' }}
                            </p>
                            <input type="file" ref="fileInput" class="hidden"
                                accept=".pdf,.xml,.p7m,.jpg,.jpeg,.png"
                                @change="(e: any) => form.file = e.target.files[0]" />
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
                            <div v-if="totali.ritenuta > 0" class="flex justify-between text-xs">
                                <span class="text-amber-400">Ritenuta</span>
                                <span class="text-amber-400">- {{ euro(totali.ritenuta, { fromCents: false }) }}</span>
                            </div>
                            <div class="flex justify-between items-baseline pt-3 border-t border-slate-700">
                                <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Netto</span>
                                <span class="font-black text-2xl">{{ euro(totali.netto, { fromCents: false }) }}</span>
                            </div>
                        </div>

                        <div v-if="bankForecast" class="flex justify-between items-center text-xs p-2.5 rounded-lg"
                            :class="bankForecast.isRed ? 'bg-rose-500/20 text-rose-300' : 'bg-emerald-500/10 text-emerald-400'">
                            <span>Saldo post-pag.</span>
                            <span class="font-black">{{ euro(bankForecast.post_cents) }}</span>
                        </div>

                        <Button type="button" :disabled="form.processing" @click="handleSubmit"
                            class="w-full h-12 font-black text-sm uppercase tracking-wider rounded-xl gap-2"
                            :class="transactionStatus === 'CRITICAL_BUDGET'
                                ? 'bg-rose-600 hover:bg-rose-700'
                                : 'bg-emerald-600 hover:bg-emerald-700'">
                            <ShieldAlert v-if="transactionStatus === 'CRITICAL_BUDGET'" class="w-5 h-5" />
                            <Save v-else class="w-5 h-5" />
                            {{ transactionStatus === 'CRITICAL_BUDGET' ? 'Autorizza e Registra' : 'Registra Documento' }}
                        </Button>
                    </div>
                </div>

                <div class="lg:col-span-8 flex flex-col gap-5">

                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <Receipt class="w-4 h-4 text-slate-400" />
                                <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500">Registro Voci di Spesa</h3>
                                <Badge variant="secondary" class="text-[10px]">{{ form.righe.length }}</Badge>
                            </div>
                            <Button variant="outline" size="sm" type="button" @click="addRiga"
                                class="h-8 text-[11px] font-black uppercase border-primary/20 text-primary hover:bg-primary/5 gap-1.5">
                                <Plus class="w-3.5 h-3.5" /> Nuova Voce
                            </Button>
                        </div>

                        <div class="grid grid-cols-12 gap-2 px-6 py-2.5 border-b border-slate-100 dark:border-slate-800 bg-slate-50/80 text-[10px] font-black uppercase tracking-wider text-slate-400">
                            <div class="col-span-4">Capitolo / Causale</div>
                            <div class="col-span-2">Unità</div>
                            <div class="col-span-3 text-right">Imponibile (€)</div>
                            <div class="col-span-1 text-center">IVA%</div>
                            <div class="col-span-1 text-right">Totale</div>
                            <div class="col-span-1"></div>
                        </div>

                        <div class="divide-y divide-slate-50 dark:divide-slate-800/50">
                            <div v-for="(riga, idx) in form.righe" :key="idx"
                                class="grid grid-cols-12 gap-2 px-6 py-4 items-start hover:bg-slate-50/20 group transition-colors">

                                <div class="col-span-4 space-y-2">
                                    <v-select v-model="riga.conto_id" :options="conti" label="nome"
                                        :reduce="(c: Conto) => c.id" placeholder="Capitolo..." class="style-chooser text-sm">
                                        <template #option="{ nome, residuo_budget, is_capiente }">
                                            <div class="flex items-center justify-between py-0.5">
                                                <span class="font-bold text-sm" :class="!is_capiente ? 'text-rose-600' : ''">{{ nome }}</span>
                                                <span class="text-[10px] px-1.5 py-0.5 rounded"
                                                    :class="is_capiente ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'">
                                                    {{ euro(residuo_budget) }}
                                                </span>
                                            </div>
                                        </template>
                                    </v-select>
                                    <Input v-model="riga.descrizione" placeholder="Causale..." class="h-9 text-xs italic bg-white/50" />
                                </div>

                                <div class="col-span-2">
                                    <v-select v-model="riga.immobile_id" :options="immobili" label="label"
                                        :reduce="(i: Immobile) => i.id" placeholder="Comune" class="style-chooser text-xs">
                                        <template #option="{ label }">
                                            <div class="flex items-center gap-1.5 py-0.5">
                                                <User class="w-3 h-3 text-blue-400 shrink-0" />
                                                <span class="text-xs">{{ label }}</span>
                                            </div>
                                        </template>
                                    </v-select>
                                </div>

                                <div class="col-span-3">
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold">€</span>
                                        <Input min="0" v-model="riga.importo_imponibile"
                                            class="h-11 pl-7 text-right font-black text-base pr-3" />
                                    </div>
                                    <div v-if="riga.conto_id && (() => {
                                        const c = conti.find(c => c.id === riga.conto_id);
                                        if (!c || c.residuo_budget === undefined) return false;
                                        // Confrontiamo centesimi con centesimi
                                        return Math.round((Number(riga.importo_imponibile) || 0) * 100) > c.residuo_budget;
                                    })()" class="flex items-center gap-1 mt-1.5 text-rose-500">
                                        <TrendingDown class="w-3 h-3" />
                                        <span class="text-[9px] font-black uppercase">Sforo budget</span>
                                    </div>
                                </div>

                                <div class="col-span-1">
                                    <div class="relative">
                                        <Input min="0" max="100" v-model="riga.aliquota_iva"
                                            class="h-11 text-center font-black text-base pr-1 pl-1" />
                                        <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-300 text-xs pointer-events-none">%</span>
                                    </div>
                                </div>

                                <div class="col-span-1 text-right pt-3">
                                    <span class="font-black text-sm text-slate-800 dark:text-slate-200">
                                        {{ euro(Number(riga.importo_imponibile) * (1 + (Number(riga.aliquota_iva) || 0) / 100), { fromCents: false }) }}
                                    </span>
                                </div>

                                <div class="col-span-1 flex justify-end pt-1">
                                    <Button variant="ghost" size="icon" type="button" @click="removeRiga(idx)"
                                        class="h-10 w-10 text-slate-200 hover:text-rose-500 hover:bg-rose-50 opacity-0 group-hover:opacity-100 transition-all">
                                        <Trash2 class="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                            <div class="grid grid-cols-12 gap-2 text-xs">
                                <div class="col-span-6 text-right text-slate-400 font-bold uppercase tracking-wider pt-1.5">Subtotali</div>
                                <div class="col-span-3 text-right">
                                    <span class="font-black text-slate-700 dark:text-slate-300 block text-base">{{ euro(totali.imponibile, { fromCents: false }) }}</span>
                                    <span class="text-[9px] text-slate-400">Imponibile</span>
                                </div>
                                <div class="col-span-1 text-right">
                                    <span class="font-black text-slate-700 dark:text-slate-300 block text-base">{{ euro(totali.iva, { fromCents: false }) }}</span>
                                    <span class="text-[9px] text-slate-400">IVA</span>
                                </div>
                                <div class="col-span-2"></div>
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
                                    <div v-for="impact in budgetImpacts" :key="impact.nome" class="space-y-1.5">
                                        <div class="flex justify-between items-start">
                                            <span class="text-xs font-bold truncate max-w-[60%]">{{ impact.nome }}</span>
                                            <span class="text-xs font-black shrink-0"
                                                :class="impact.isOk ? 'text-emerald-400' : 'text-rose-400'">
                                                {{ impact.isOk ? '+' : '' }}{{ euro(impact.delta_cents) }}
                                            </span>
                                        </div>
                                        <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-500"
                                                :class="impact.isOk ? 'bg-emerald-500' : 'bg-rose-500'"
                                                :style="{ width: Math.min((impact.speso_cents / Math.max(impact.residuo_cents, 1)) * 100, 100) + '%' }">
                                            </div>
                                        </div>
                                        <div class="flex justify-between text-[9px] text-slate-600">
                                            <span>Usato: {{ euro(impact.speso_cents) }}</span>
                                            <span>Budget: {{ euro(impact.residuo_cents) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="p-5">
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-4">Previsione Cassa</p>
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

        <Teleport to="body">
            <div v-if="showOverrideModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-200 dark:border-slate-800">
                    <div class="bg-rose-50 dark:bg-rose-950/30 p-7 border-b border-rose-100 dark:border-rose-900/30 flex items-start gap-4">
                        <div class="bg-rose-100 dark:bg-rose-900/50 p-2.5 rounded-xl shrink-0">
                            <ShieldAlert class="w-6 h-6 text-rose-600" />
                        </div>
                        <div>
                            <h3 class="font-black text-rose-900 dark:text-rose-100 text-lg">Sforamento Budget</h3>
                            <p class="text-xs text-rose-700/70 mt-1">Eccesso: <span class="font-black">{{ euro(sforoBudgetTotaleCents) }}</span></p>
                        </div>
                    </div>
                    <div class="p-7 space-y-5">
                        <p class="text-xs text-slate-500 leading-relaxed italic border-l-4 border-rose-200 pl-4">
                            "L'amministratore non può ordinare lavori di manutenzione straordinaria, salvo carattere urgente..." — Art. 1135 c.c.
                        </p>
                        <div>
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2 block">Motivazione *</Label>
                            <textarea v-model="overrideMotivazione" rows="4"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-xl p-4 text-sm bg-slate-50 dark:bg-slate-800 outline-none resize-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-400 transition-all"
                                placeholder="Es: Intervento urgente per rottura colonna..." />
                            <div class="flex justify-between mt-1">
                                <p class="text-[10px] text-slate-400">Minimo 10 caratteri</p>
                                <p class="text-[10px]" :class="overrideMotivazione.length >= 10 ? 'text-emerald-500' : 'text-slate-400'">{{ overrideMotivazione.length }}</p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <Button variant="outline" class="flex-1 h-11 rounded-xl font-bold" @click="cancelOverride">Annulla</Button>
                            <Button class="flex-1 h-11 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black"
                                :disabled="overrideMotivazione.length < 10" @click="confirmOverride">
                                Conferma e Registra
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

    </GestionaleLayout>
</template>

<style scoped>
:deep(.style-chooser .vs__dropdown-toggle) {
    border-color: #e2e8f0; border-radius: 0.5rem; padding: 6px 0; background: white; min-height: 44px;
}
:deep(.dark .style-chooser .vs__dropdown-toggle) { border-color: #334155; background: #0f172a; }
:deep(.style-chooser .vs__selected) { font-size: 0.875rem; font-weight: 600; color: #1e293b; }
:deep(.dark .style-chooser .vs__selected) { color: #f1f5f9; }
:deep(.style-chooser .vs__search::placeholder) { color: #94a3b8; font-size: 0.875rem; }
:deep(.style-chooser .vs__dropdown-menu) { border-radius: 0.75rem; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.08); border: 1px solid #e2e8f0; padding: 6px; }
:deep(.style-chooser .vs__dropdown-option) { border-radius: 0.4rem; padding: 6px 10px; }
:deep(.style-chooser .vs__dropdown-option--highlight) { background: #f1f5f9; color: #1e293b; }
</style>