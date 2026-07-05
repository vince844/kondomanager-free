<script setup lang="ts">

import { ref, computed, watch } from 'vue';
import { useForm, Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { FileText, Plus, Trash2, AlertTriangle, User, ShieldAlert, Save, AlertOctagon, TriangleAlert, TrendingDown, Zap, ArrowRightLeft, Briefcase, History, ChevronDown, CheckCircle, Lock, Info } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import ModalOverrideBudget from '@/components/gestionale/movimenti/fatture/ModalOverrideBudget.vue';
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
    importo_disponibile: number;
    fatture_collegate?: any[];
}

interface FondoRiserva {
    id: number;
    nome: string;
    saldo_attuale: number;
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
    debiti_patrimoniali: any[];
    fatture_pregresse_registrate: any[];
    fondi_riserva: any[];
    capienza_rata_zero: number;
    incassato_rata_zero: number;
    fattura: any; // La fattura da modificare
}>();

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
const fileInput = ref<HTMLInputElement | null>(null);
const showOverrideModal = ref(false);
const showSuccessModal = ref(false);
const showModificaVietataModal = ref(false);
const modificaVietataMsg = ref('');

const form = useForm({
    _method: 'PUT',
    fornitore_id:       props.fattura.fornitore_id,
    esercizio_id:       props.fattura.esercizio_id,
    gestione_id:        props.fattura.gestione_id,
    tipo_documento:     props.fattura.tipo_documento,
    is_pregresso:       Boolean(props.fattura.is_pregresso),
    saldo_patrimoniale_id: null as number | null,
    imponibile_pregresso:       0,
    aliquota_iva_pregressa:     22,
    numero_documento:   props.fattura.numero_documento || '',
    data_documento:     props.fattura.data_documento ? props.fattura.data_documento.substring(0, 10) : '',
    data_scadenza:      props.fattura.data_scadenza ? props.fattura.data_scadenza.substring(0, 10) : '',
    conto_corrente_id:  props.fattura.conto_corrente_id,
    modalita_pagamento: props.fattura.modalita_pagamento,
    iban_fornitore:     props.fattura.iban_fornitore || '',
    dati_extra: {
        fiscal:     props.fattura.dati_extra?.fiscal || { cig: '', cup: '' },
        competenza: props.fattura.dati_extra?.competenza || { dal: '', al: '' },
        override_budget: null as any,
        log_legale_sopravvenienza: null as any
    },
    stato_approvazione: props.fattura.stato_approvazione,
    righe: props.fattura.righe ? props.fattura.righe.map((r: any) => ({
        descrizione: r.descrizione || '',
        conto_id: r.conto_id,
        immobile_id: r.immobile_id,
        importo_imponibile: Number(r.importo_imponibile) / 100,
        aliquota_iva: r.aliquota_iva,
        is_sopravvenienza: false // Edit non supporta sopravvenienze per fatture esistenti
    })) : [{
        descrizione: '',
        conto_id: null as number | null,
        immobile_id: null as number | null,
        importo_imponibile: 0,
        aliquota_iva: 22,
        is_sopravvenienza: false
    }],
    coperture: [] as any[],
    file: null as File | null,
});

// Since fornitore is read-only and not passed in props.fornitori
const selectedFornitore = computed(() => props.fattura.fornitore);

// ---------------------------------------------------------------------------
// Computed
// ---------------------------------------------------------------------------

const hasSpesePrivate = computed(() => {
    if (!form.righe || !Array.isArray(form.righe)) return false;
    return form.righe.some(riga => riga.immobile_id !== null);
});

const totali = computed(() => {
    let imponibile = 0, iva = 0;
    let imponibile_ordinario = 0, iva_ordinaria = 0;
    let imponibile_sopravvenienza = 0, iva_sopravvenienza = 0;

    if (form.is_pregresso) {
        imponibile = Number(form.imponibile_pregresso) || 0;
        iva = imponibile * (Number(form.aliquota_iva_pregressa) || 0) / 100;
        imponibile_ordinario = imponibile;
        iva_ordinaria = iva;
    } else {
        form.righe.forEach((r: any) => {
            const imp  = Number(r.importo_imponibile) || 0;
            const rIva = imp * (Number(r.aliquota_iva) || 0) / 100;

            imponibile += imp;
            iva        += rIva;

            if (r.is_sopravvenienza) {
                imponibile_sopravvenienza += imp;
                iva_sopravvenienza        += rIva;
            } else {
                imponibile_ordinario += imp;
                iva_ordinaria        += rIva;
            }
        });
    }

    let ritenuta = 0;
    if (selectedFornitore.value?.soggetto_ritenuta && form.tipo_documento !== 'nota_credito') {
        const base = imponibile * (Number(selectedFornitore.value.perc_imponibile_ritenuta) || 100) / 100;
        ritenuta   = base * (Number(selectedFornitore.value.perc_ritenuta) || 0) / 100;
    }

    return {
        imponibile:                  Math.round(imponibile * 100) / 100,
        iva:                         Math.round(iva * 100) / 100,
        imponibile_ordinario:        Math.round(imponibile_ordinario * 100) / 100,
        iva_ordinaria:               Math.round(iva_ordinaria * 100) / 100,
        imponibile_sopravvenienza:   Math.round(imponibile_sopravvenienza * 100) / 100,
        iva_sopravvenienza:          Math.round(iva_sopravvenienza * 100) / 100,
        ritenuta:                    Math.round(ritenuta * 100) / 100,
        netto:                       Math.round((imponibile + iva - ritenuta) * 100) / 100,
        ha_sopravvenienze:           imponibile_sopravvenienza > 0
    };
});

const totaleDocLordoEuro = computed(() => totali.value.imponibile + totali.value.iva);

// Storico capitoli espanso
const expandedHistory = ref<Record<number, boolean>>({});
const toggleHistory = (contoId: number) => {
    expandedHistory.value[contoId] = !expandedHistory.value[contoId];
};

// Calcoli Budget in centesimi
const budgetImpacts = computed(() => {
    // Le fatture pregresse non impattano il budget corrente
    if (form.is_pregresso) return [];

    const grouped = new Map<number, {
        id: number; nome: string;
        speso_cents: number; residuo_cents: number;
        ultimi_movimenti: any[]
    }>();

    form.righe.forEach((r: any) => {
        if (!r.conto_id) return;
        const c = props.conti.find(c => c.id === r.conto_id);
        if (!c) return;

        const residuoCents = c.residuo_budget || 0;
        const spesaCents   = Math.round((Number(r.importo_imponibile) || 0) * 100);
        const cur = grouped.get(r.conto_id) || {
            id:               c.id,
            nome:             c.nome,
            speso_cents:      0,
            residuo_cents:    residuoCents,
            ultimi_movimenti: c.ultimi_movimenti || []
        };
        cur.speso_cents += spesaCents;
        grouped.set(r.conto_id, cur);
    });

    return Array.from(grouped.values()).map(i => ({
        ...i,
        isOk:        i.speso_cents <= i.residuo_cents,
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
    const spesaCents   = Math.round(totali.value.netto * 100);
    const postCents    = attualeCents - spesaCents;

    return { attuale_cents: attualeCents, post_cents: postCents, isRed: postCents < 0 };
});

const transactionStatus = computed(() => {
    if (!form.is_pregresso && budgetImpacts.value.some(i => !i.isOk)) return 'CRITICAL_BUDGET';
    if (!form.is_pregresso && bankForecast.value?.isRed) return 'WARNING_CASH';
    return 'SAFE';
});

const isDataDocumentoVecchia = computed(() => {
    if (!form.data_documento) return false;
    const diffTime = Math.abs(new Date().getTime() - new Date(form.data_documento).getTime());
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays > 30;
});

const sforoBudgetTotaleCents = computed(() =>
    budgetImpacts.value.filter(i => !i.isOk).reduce((acc, i) => acc + (i.speso_cents - i.residuo_cents), 0)
);

const gestioniFiltrate = computed(() => {
    if (!form.esercizio_id) return [];
    return props.gestioni.filter(g => {
        if (g.esercizio_ids && g.esercizio_ids.length > 0) {
            return g.esercizio_ids.includes(form.esercizio_id as number);
        }
        return true;
    });
});

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------

/**
 * Watch unificato su [fornitore_id, data_documento].
 *
 * Ordine di operazioni:
 * 1. Aggiorna is_pregresso (dipende solo dalla data)
 * 2. Aggiorna campi derivati dal fornitore (dipende da entrambi)
 *
 * Avere un unico watch elimina il rischio di side-effect incrociati
 * che si verificavano con i due watch separati che reagivano entrambi
 * a data_documento in sequenza non garantita.
 */
watch(
    [() => form.fornitore_id, () => form.data_documento],
    ([newFornitoreId, newDataDoc], [oldFornitoreId, oldDataDoc]) => {

        // 1. Aggiorna is_pregresso
        if (newDataDoc && props.esercizio?.data_inizio) {
            form.is_pregresso = newDataDoc < props.esercizio.data_inizio.substring(0, 10);
        }

        // 2. Aggiorna campi derivati dal fornitore
        if (!newFornitoreId || !newDataDoc) return;
        const f = props.fornitori.find(x => x.id === newFornitoreId);
        if (!f) return;

        if (newFornitoreId !== oldFornitoreId || newDataDoc !== oldDataDoc) {
            const d = new Date(newDataDoc);
            d.setDate(d.getDate() + (f.giorni_scadenza || 30));
            form.data_scadenza = d.toISOString().substring(0, 10);
        }

        if (newFornitoreId !== oldFornitoreId) {
            form.iban_fornitore     = f.iban_principale || form.iban_fornitore;
            form.modalita_pagamento = f.modalita_pagamento_default || form.modalita_pagamento;
        }
    },
    { immediate: true }
);

watch(() => form.esercizio_id, (v) => {
    form.gestione_id = null;
    if (!v || !props.gestioni.length) return;
    form.gestione_id = props.gestioni.find(g => g.tipo === 'ordinaria')?.id ?? props.gestioni[0].id;
}, { immediate: true });

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
const addRiga = () => form.righe.push({
    descrizione:        '',
    conto_id:           null,
    immobile_id:        null,
    importo_imponibile: 0,
    aliquota_iva:       22,
    is_sopravvenienza:  false
});

const removeRiga = (idx: number) => {
    if (form.righe.length > 1) form.righe.splice(idx, 1);
};

const showSpesaImprevistaModal = ref(false);

const spesaImprevistaMode = ref<'corrente' | 'pregressa'>('corrente');

const totaleCopertoPregressoEuro = computed(() => {
    if (!form.is_pregresso) return 0;
    let sum = 0;
    
    // 1. Aggiungiamo la base del debito patrimoniale selezionato
    if (form.saldo_patrimoniale_id) {
        const debito = props.debiti_patrimoniali.find(d => d.id === form.saldo_patrimoniale_id);
        if (debito) sum += (debito.importo_disponibile / 100);
    }
    
    // 2. Aggiungiamo i fondi extra selezionati (ignorando i click manuali su sopravvenienza)
    if (form.coperture?.length) {
        sum += form.coperture
            .filter((c: any) => c.tipo_copertura !== 'sopravvenienza')
            .reduce((acc: number, c: any) => acc + (Number(c.importo) || 0), 0);
    }
    
    return sum;
});

const eccedenzaPregressaEuro = computed(() => {
    if (!form.is_pregresso) return 0;
    const eccedenza = totaleDocLordoEuro.value - totaleCopertoPregressoEuro.value;
    return eccedenza > 0.01 ? eccedenza : 0;
});

const handleSubmit = () => {
    // 1. Sforo budget CORRENTE
    if (!form.is_pregresso && transactionStatus.value === 'CRITICAL_BUDGET' && !form.dati_extra.override_budget) {
        showOverrideModal.value = true;
        return;
    }

    doSubmit();
};

/**
 * Chiamato da ModalOverrideBudget al confirm.
 */
const handleSpesaImprevistaConfirm = (payload: any) => {
    form.dati_extra.log_legale_sopravvenienza = payload;

    if (payload.is_ordinario) {
        form.dati_extra.override_budget = {
            motivazione:           payload.motivazione_sforo,
            importo_sforo:         Math.round((totali.value.imponibile_sopravvenienza + totali.value.iva_sopravvenienza) * 100),
            strategia_rientro:     payload.strategia_rientro,
            fondo_patrimoniale_id: payload.fondo_patrimoniale_id,
        };
    }

    showSpesaImprevistaModal.value = false;
    handleSubmit();
};

const handleOverrideConfirm = (payload: { strategia: string; fondoId: number | null; motivazione: string }) => {
    form.dati_extra.override_budget = {
        motivazione:                payload.motivazione,
        importo_sforo:              sforoBudgetTotaleCents.value,
        budget_residuo_al_momento:  -sforoBudgetTotaleCents.value,
        timestamp:                  new Date().toISOString(),
        strategia_rientro:          payload.strategia,
        fondo_patrimoniale_id:      payload.fondoId,
    };

    showOverrideModal.value = false;
    doSubmit();
};

const doSubmit = () => {
    form.transform((data) => {
        const payload = {
            ...data,
            dati_extra: JSON.parse(JSON.stringify(data.dati_extra)),
            coperture: data.coperture ? JSON.parse(JSON.stringify(data.coperture)) : []
        }; 
        
        payload.righe = payload.righe.map((r: any) => ({
            ...r,
            conto_id: r.conto_id ? Number(r.conto_id) : null,
            immobile_id: r.immobile_id ? Number(r.immobile_id) : null,
            importo_imponibile: Number(r.importo_imponibile) || 0,
            aliquota_iva: Number(r.aliquota_iva) || 22,
            is_sopravvenienza: Boolean(r.is_sopravvenienza)
        }));

        // --- INIZIO FIX PREGRESSO ---
        if (payload.is_pregresso) {
            // Puliamo eventuali "sopravvenienze" aggiunte per errore dall'utente nel Widget
            payload.coperture = payload.coperture.filter((c: any) => c.tipo_copertura !== 'sopravvenienza');
            
            // AUTO-INIEZIONE: Diciamo al backend che stiamo usando il Saldo Patrimoniale di base
            if (payload.saldo_patrimoniale_id) {
                const debito = props.debiti_patrimoniali.find(d => d.id === payload.saldo_patrimoniale_id);
                if (debito) {
                    const fatturaLordo = payload.imponibile_pregresso * (1 + payload.aliquota_iva_pregressa / 100);
                    const copertureExtra = payload.coperture.reduce((a:any, c:any) => a + Number(c.importo), 0);
                    
                    // Calcoliamo quanta parte del debito base stiamo effettivamente usando
                    const importoBase = Math.min(debito.importo_disponibile / 100, fatturaLordo - copertureExtra);
                    
                    if (importoBase > 0) {
                        payload.coperture.unshift({
                            tipo_copertura: 'rata_0',
                            importo: importoBase,
                            fonte_id: debito.id
                        });
                    }
                }
            }
        }
        // --- FINE FIX ---

        return payload;
    }).post(route(generateRoute('gestionale.fatture.update'), { condominio: props.condominio.id, fattura: props.fattura.id }), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showSuccessModal.value = true;
        },
        onError: (errors) => {
            if (errors.modifica_vietata) {
                modificaVietataMsg.value = errors.modifica_vietata;
                showModificaVietataModal.value = true;
            }
        },
    });
};

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------
const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Dashboard', href: route(generateRoute('gestionale.index'),         { condominio: props.condominio.id }) },
    { title: 'Fatture',   href: route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }) },
    { title: 'Modifica' },
]);

const pageGuides = [
    { title: 'Panel + Ledger',   description: 'I dati principali a sinistra, le voci a destra come un registro contabile. Tutto visibile in un\'unica schermata.', icon: ArrowRightLeft, colorVariant: 'blue' as const },
    { title: 'Controllo Budget', description: 'Il sistema verifica il residuo per ogni capitolo di spesa in tempo reale, riga per riga.',                          icon: Zap,            colorVariant: 'amber' as const },
    { title: 'Audit Trail',      description: 'Ogni sforamento deve essere giustificato con motivazione legale prima della registrazione.',                         icon: ShieldAlert,    colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Modifica fattura" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                page-title="Modifica fattura passiva"
                page-subtitle="Aggiorna i dati nel pannello di sinistra e le voci di dettaglio nel registro a destra."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :back-url="route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id })"
                back-text="Indietro"
            />

            <!-- Banner warning -->
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

                <!-- ── Pannello sinistro ── -->
                <div class="lg:col-span-4 h-full flex flex-col bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 shrink-0">
                        <div class="space-y-3 mb-4">
                            <!-- Toggle Fattura / Nota Credito -->
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

                        <!-- Fornitore (Read-Only in Edit) -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fornitore</Label>
                            <div class="flex items-center gap-3 py-2 px-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-md">
                                <div class="w-8 h-8 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 flex items-center justify-center shrink-0">
                                    <Briefcase class="w-4 h-4 text-slate-400" />
                                </div>
                                <div class="flex flex-col overflow-hidden">
                                    <span class="font-bold text-sm text-slate-800 dark:text-slate-200 truncate">{{ selectedFornitore?.ragione_sociale }}</span>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span v-if="selectedFornitore?.piva" class="text-[10px] text-slate-500 font-medium">P.IVA: {{ selectedFornitore.piva }}</span>
                                        <span v-else-if="selectedFornitore?.codice_fiscale" class="text-[10px] text-slate-500 font-medium">C.F.: {{ selectedFornitore.codice_fiscale }}</span>
                                        <span v-else class="text-[10px] text-slate-400 italic">Nessuna P.IVA / C.F.</span>
                                        <span v-if="selectedFornitore?.soggetto_ritenuta" class="text-[8px] font-black uppercase tracking-wider text-amber-600 border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-900/50 dark:text-amber-500 rounded px-1.5 py-0.5 leading-none">
                                            Ritenuta
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <!-- Gestione -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Gestione</Label>
                            <v-select
                                v-model="form.gestione_id"
                                :options="gestioniFiltrate"
                                :reduce="(g: Gestione) => g.id"
                                label="nome"
                                placeholder="Seleziona..."
                            />
                        </div>

                        <!-- N. Documento (Read-Only in Edit) -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">N. Documento</Label>
                            <div class="h-9 px-3 flex items-center bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-md">
                                <span class="uppercase text-base tracking-widest text-slate-700 dark:text-slate-300">{{ form.numero_documento }}</span>
                            </div>
                        </div>

                        <!-- Date -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5 col-span-2 md:col-span-1">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data *</Label>
                                <Input type="date" v-model="form.data_documento" class="h-9 text-sm" />
                            </div>
                            <div class="space-y-1.5 col-span-2 md:col-span-1">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-primary">Scadenza *</Label>
                                <Input
                                    type="date"
                                    v-model="form.data_scadenza"
                                    class="h-9 text-sm border-primary/40 bg-primary/5 text-primary font-bold" />
                            </div>
                        </div>
                        <div v-if="isDataDocumentoVecchia" class="flex items-start gap-2 text-[10.5px] font-medium text-amber-700 bg-amber-50 p-2 rounded-md border border-amber-200">
                            <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5 text-amber-500" />
                            <span><strong>Attenzione (Art. 1130 c.c.)</strong> Stai registrando un'operazione avvenuta oltre 30 giorni fa. Ricorda che la normativa prevede l'annotazione a registro entro i 30 giorni.</span>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <!-- Conto addebito -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conto Addebito</Label>
                            <v-select v-model="form.conto_corrente_id" :options="bancheNormalizzate" label="nome" :reduce="(c: any) => c.id" placeholder="Seleziona banca...">
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

                        <!-- IBAN -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">IBAN Fornitore</Label>
                            <Input v-model="form.iban_fornitore" class="h-9 text-sm" placeholder="IT00 0000..." />
                        </div>

                        <!-- Allegato -->
                        <div class="border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl p-4 text-center cursor-pointer hover:bg-slate-50 transition-colors" @click="fileInput?.click()">
                            <FileText class="w-5 h-5 text-slate-300 mx-auto mb-1" />
                            <p class="text-[11px] text-slate-400 font-medium">{{ form.file ? form.file.name : 'Allega documento (PDF, XML, P7M)' }}</p>
                            <input type="file" ref="fileInput" class="hidden" accept=".pdf,.xml,.p7m,.jpg,.jpeg,.png" @change="(e: any) => form.file = e.target.files[0]" />
                        </div>
                    </div>

                    <!-- Footer con totali e pulsante -->
                    <div class="p-5 bg-slate-900 dark:bg-slate-950 text-white border-t border-slate-700 shrink-0 space-y-4">
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">Imponibile lordo</span>
                                <span>{{ euro(totali.imponibile, { fromCents: false }) }}</span>
                            </div>

                            <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
                                <div v-if="totali.ha_sopravvenienze" class="flex justify-between text-[10px] pl-2 border-l-2 border-amber-500/50 ml-1 mt-1 mb-1">
                                    <span class="text-amber-400/80">Di cui imprevisto</span>
                                    <span class="text-amber-400/80">{{ euro(totali.imponibile_sopravvenienza, { fromCents: false }) }}</span>
                                </div>
                            </Transition>

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

                        <Button type="button" :disabled="form.processing" @click="handleSubmit"
                            class="w-full h-12 font-black text-sm uppercase tracking-wider rounded-xl gap-2"
                            :class="transactionStatus === 'CRITICAL_BUDGET' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'">
                            <ShieldAlert v-if="transactionStatus === 'CRITICAL_BUDGET'" class="w-5 h-5" />
                            <Save v-else class="w-5 h-5" />
                            {{ transactionStatus === 'CRITICAL_BUDGET' ? 'Autorizza e Registra' : 'Registra Documento' }}
                        </Button>
                    </div>
                </div>

                <!-- ── Pannello destro ── -->
                <div class="lg:col-span-8 flex flex-col gap-5 relative z-0">

                    <!-- Vista corrente -->
                    <div class="flex flex-col gap-5">

                        <!-- Registro voci -->
                        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                            <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center justify-between rounded-t-xl">
                                <div>
                                    <div class="flex items-center gap-2">
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

                                        <!-- Capitolo di spesa -->
                                        <div class="col-span-12 md:col-span-8 relative">
                                            <div class="flex items-center justify-between mb-1.5 min-h-[28px]">
                                                <Label class="text-[10px] font-bold uppercase text-slate-400">Capitolo di spesa</Label>

                                                <button
                                                    type="button"
                                                    @click="riga.is_sopravvenienza = !riga.is_sopravvenienza; riga.is_sopravvenienza && (riga.conto_id = null)"
                                                    class="flex items-center gap-1.5 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider transition-all border outline-none focus-visible:ring-2 focus-visible:ring-amber-500/50"
                                                    :class="riga.is_sopravvenienza
                                                        ? 'bg-amber-50 border-amber-200 text-amber-600 shadow-sm dark:bg-amber-900/30 dark:border-amber-700/50 dark:text-amber-400'
                                                        : 'bg-transparent border-slate-200 text-slate-400 hover:bg-slate-50 hover:text-slate-500 dark:border-slate-700 dark:hover:bg-slate-800'">
                                                    <Zap class="w-3 h-3" :class="riga.is_sopravvenienza ? 'text-amber-500' : 'text-slate-400'" />
                                                    <span>{{ riga.is_sopravvenienza ? 'Imprevista (Attiva)' : 'Fuori Preventivo' }}</span>
                                                </button>
                                            </div>

                                            <v-select
                                                v-model="riga.conto_id"
                                                :options="conti"
                                                :disabled="riga.is_sopravvenienza"
                                                label="nome"
                                                :reduce="(c: Conto) => c.id"
                                                placeholder="Cerca capitolo..."
                                                class="style-chooser w-full"
                                                append-to-body>
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

                                        <!-- Unità -->
                                        <div class="col-span-12 md:col-span-4 relative">
                                            <div class="flex items-center mb-1.5 min-h-[28px]">
                                                <Label class="text-[10px] font-bold uppercase text-slate-400">Unità (Opzionale)</Label>
                                            </div>
                                            <v-select
                                                v-model="riga.immobile_id"
                                                :options="immobili"
                                                label="label"
                                                :reduce="(i: Immobile) => i.id"
                                                placeholder="Tutti (Spesa Comune)"
                                                class="style-chooser text-xs"
                                                append-to-body>
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
                                        <!-- Causale -->
                                        <div class="col-span-12 md:col-span-4 lg:col-span-5">
                                            <Input v-model="riga.descrizione"
                                                placeholder="Causale riga..."
                                                class="h-10 text-sm bg-slate-50 dark:bg-slate-900/50"
                                                :class="{ 'border-red-500 focus-visible:ring-red-500': (form.errors as any)[`righe.${idx}.descrizione`] }"
                                                @input="form.clearErrors(`righe.${idx}.descrizione` as any)" />
                                            <p v-if="(form.errors as any)[`righe.${idx}.descrizione`]" class="text-[11px] text-red-600 font-medium mt-1">
                                                {{ (form.errors as any)[`righe.${idx}.descrizione`] }}
                                            </p>
                                        </div>

                                        <!-- Importo -->
                                        <div class="col-span-4 md:col-span-3 relative">
                                            <MoneyInput
                                                :id="'importo_' + idx"
                                                v-model="riga.importo_imponibile"
                                                :money-options="moneyOptions"
                                                :lazy="false"
                                                class="h-10 font-black text-base bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm rounded-md border w-full px-3"
                                                placeholder="0,00" />
                                            <div v-if="riga.conto_id && (() => {
                                                const c = conti.find(c => c.id === riga.conto_id);
                                                if (!c || c.residuo_budget === undefined) return false;
                                                return Math.round((Number(riga.importo_imponibile) || 0) * 100) > c.residuo_budget;
                                            })()" class="flex items-center gap-1 mt-1 text-rose-500 absolute -bottom-5 right-0">
                                                <TrendingDown class="w-3 h-3" />
                                                <span class="text-[9px] font-black uppercase">Sforo budget</span>
                                            </div>
                                        </div>

                                        <!-- Aliquota IVA -->
                                        <div class="col-span-3 md:col-span-2 lg:col-span-2 relative">
                                            <div class="relative">
                                                <Input min="0" max="100" v-model="riga.aliquota_iva"
                                                    class="h-10 text-center font-black text-base pr-5 pl-1 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm" />
                                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none font-bold">%</span>
                                            </div>
                                        </div>

                                        <!-- Totale riga + elimina -->
                                        <div class="col-span-5 md:col-span-3 lg:col-span-2 flex items-center justify-end gap-3 h-10">
                                            <div class="text-right">
                                                <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block leading-none mb-1">Totale Riga</span>
                                                <span class="font-black text-base text-slate-800 dark:text-slate-200 block leading-none">
                                                    {{ euro(Number(riga.importo_imponibile) * (1 + (Number(riga.aliquota_iva) || 0) / 100), { fromCents: false }) }}
                                                </span>
                                            </div>
                                            <Button variant="ghost" size="icon" type="button" @click="removeRiga(Number(idx))"
                                                class="h-10 w-10 shrink-0 text-slate-300 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/30 opacity-0 group-hover:opacity-100 transition-all rounded-lg border border-transparent hover:border-rose-100 ml-1">
                                                <Trash2 class="w-4 h-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer registro -->
                            <div class="py-5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 rounded-b-xl flex flex-col sm:flex-row items-end sm:items-center justify-between px-6">
                                <div>
                                    <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-x-4" enter-to-class="opacity-100 translate-x-0">
                                        <div v-if="totali.ha_sopravvenienze" class="flex items-center gap-2.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/50 px-3 py-2 rounded-lg shadow-sm">
                                            <div class="bg-amber-100 dark:bg-amber-800/50 p-1 rounded-md">
                                                <Zap class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                                            </div>
                                            <div class="text-[11px] text-amber-800 dark:text-amber-300 leading-tight">
                                                Di cui <strong class="font-black text-amber-900 dark:text-amber-100">{{ euro(totali.imponibile_sopravvenienza + totali.iva_sopravvenienza, { fromCents: false }) }}</strong><span class="opacity-80"> fuori preventivo</span>
                                            </div>
                                        </div>
                                    </Transition>
                                </div>

                                <div class="flex items-center gap-8 pr-2 mt-4 sm:mt-0">
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
                                        <span class="text-[10px] text-primary font-bold uppercase tracking-widest block mb-0.5">Totale Doc.</span>
                                        <span class="font-black text-primary text-xl">{{ euro(totali.imponibile + totali.iva, { fromCents: false }) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Simulazione impatto finanziario -->
                        <div class="bg-slate-900 dark:bg-slate-950 text-white rounded-xl border shadow-lg overflow-hidden transition-all duration-300"
                            :class="transactionStatus === 'CRITICAL_BUDGET' ? 'border-rose-500 shadow-rose-500/10' : transactionStatus === 'WARNING_CASH' ? 'border-amber-500/30' : 'border-slate-700'">

                            <div class="px-6 py-4 border-b border-slate-700/50 flex items-center justify-between bg-slate-800/40">
                                <div class="flex items-center gap-2">
                                    <Zap class="w-4 h-4 text-blue-400" :class="transactionStatus === 'CRITICAL_BUDGET' ? 'text-rose-400 animate-pulse' : ''" />
                                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Simulazione Impatto Finanziario</span>
                                </div>
                                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-black uppercase"
                                    :class="{
                                        'bg-rose-500/20 text-rose-400':      transactionStatus === 'CRITICAL_BUDGET',
                                        'bg-amber-500/20 text-amber-400':    transactionStatus === 'WARNING_CASH',
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
                                <!-- Analisi budget -->
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

                                <!-- Previsione cassa -->
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

        <!-- Modali -->
        <ModalOverrideBudget
            v-model:show="showOverrideModal"
            :sforo-totale="sforoBudgetTotaleCents"
            :has-spese-private="hasSpesePrivate"
            :fondi-riserva="fondi_riserva"
            :is-processing="form.processing"
            @confirm="handleOverrideConfirm" 
        />

        <ModalSpesaImprevista
            v-model:show="showSpesaImprevistaModal"
            :mode="spesaImprevistaMode"
            :condominio-id="props.condominio.id"
            :fornitore-nome="selectedFornitore?.ragione_sociale || 'Fornitore'"
            :fondi-riserva="fondi_riserva"
            :importo-imprevisto="spesaImprevistaMode === 'corrente' 
                ? Math.round((totali.imponibile_sopravvenienza + totali.iva_sopravvenienza) * 100) 
                : Math.round(eccedenzaPregressaEuro * 100)"
            @confirm="handleSpesaImprevistaConfirm" 
        />

        <!-- Modale di modifica non consentita (stornata, esercizio chiuso, pregressa, ecc.) — non bypassabile -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showModificaVietataModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <div class="bg-gradient-to-br from-slate-50 to-slate-100/50 dark:from-slate-800 dark:to-slate-700/30 px-8 pt-8 pb-6 text-center border-b border-slate-200 dark:border-slate-700">
                            <div class="w-16 h-16 bg-white dark:bg-slate-700 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg border border-slate-200 dark:border-slate-600">
                                <Lock class="w-8 h-8 text-slate-500" />
                            </div>
                            <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Modifica non consentita</h3>
                        </div>

                        <div class="p-8 space-y-5">
                            <div class="flex items-start gap-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800/50 rounded-xl p-4">
                                <Info class="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                                <p class="text-[11px] text-blue-700 dark:text-blue-400 leading-relaxed">{{ modificaVietataMsg }}</p>
                            </div>

                            <Button @click="() => { showModificaVietataModal = false; router.visit(route(generateRoute('gestionale.fatture.show'), { condominio: props.condominio.id, fattura: props.fattura.id })); }"
                                class="w-full h-12 rounded-xl bg-slate-700 hover:bg-slate-800 dark:bg-slate-600 dark:hover:bg-slate-500 text-white font-black uppercase tracking-widest text-[11px]">
                                Ho capito — Torna al dettaglio
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Modale di successo -->
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
                        <!--
                            Il reset avviene qui, al click esplicito dell'utente,
                            non in onSuccess: il comportamento è più leggibile e prevedibile.
                        -->
                        <Button
                            @click="() => { form.reset(); showSuccessModal = false; }"
                            class="w-full h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black uppercase tracking-widest text-[11px] shadow-lg shadow-emerald-600/20 transition-all">
                            Registra un'altra fattura
                        </Button>

                        <Button
                            variant="ghost"
                            @click="router.visit(route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }))"
                            class="w-full h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
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
.vs__dropdown-menu {
    z-index: 9999 !important;
    position: absolute !important;
    background: white !important;
    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1) !important;
}

.dark .vs__dropdown-menu {
    background: #1e293b !important;
    border: 1px solid #334155;
}

.lg\:col-span-8 {
    position: relative;
    z-index: 1;
}

.style-chooser .vs__dropdown-toggle {
    border-radius: 0.75rem;
    min-height: 40px;
}
</style>