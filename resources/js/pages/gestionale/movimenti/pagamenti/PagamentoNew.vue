<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import MoneyInput from '@/components/MoneyInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
    Banknote, CreditCard, Send, ShieldCheck, ShieldAlert, AlertTriangle,
    CheckCircle, Briefcase, FileText, Zap, ArrowRightLeft, Wallet,
    AlertOctagon, TriangleAlert, Lock, ChevronDown,
    Sparkles, Receipt, Save, Clock, BadgeAlert, Search, X, Check, Stamp,
    Bug, Scale, Info, FileX, Ban
} from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';
import axios from 'axios';

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
    iban_principale?: string;
    modalita_pagamento_default?: string;
}

interface Condominio {
    id: number;
    nome: string;
}

interface Esercizio {
    id: number;
    nome: string;
    stato: string;
}

interface Banca {
    id: number;
    cassa_id: number;
    nome: string;
    tipo: string;
    iban?: string;
    saldo_attuale: number;
}

interface Gestione {
    id: number;
    nome: string;
    tipo: string;
    esercizio_ids?: number[];
}

interface Pendenza {
    id: number;
    tipo_documento: string;
    numero_documento: string;
    data_documento: string;
    data_scadenza?: string;
    data_scadenza_fmt?: string;
    importo_lordo: number;
    netto_a_pagare: number;
    residuo: number;
    stato_pagamento: string;
    is_scaduta: boolean;
    is_nota_credito: boolean;
    gestione_id?: number;
    descrizione_righe?: string;
    stato_approvazione: string;
    // UI state
    selezionata?: boolean;
    importo_allocato?: number;
    tipo_allocazione?: 'pagamento' | 'compensazione';
}

const props = defineProps<{
    condominio: Condominio;
    condomini: Condominio[];
    esercizio: Esercizio;
    esercizi: Esercizio[];
    fornitori: Fornitore[];
    banche: Banca[];
    gestioni: Gestione[];
    preselected_fornitore_id?: number | null;
    preselected_fattura_id?: number | null;
}>();

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
const form = useForm({
    fornitore_id:                   props.preselected_fornitore_id || null,
    esercizio_id:                   props.esercizio?.id || null,
    conto_corrente_id:              null as number | null,
    data_pagamento:                 new Date().toISOString().substring(0, 10),
    metodo_pagamento:               'bonifico',
    iban_beneficiario:              '',
    importo_commissioni_cents:      0,
    bonifico_parlante:              false,
    tipo_detrazione:                null as string | null,
    beneficiari_detrazione:         [] as any[],
    allow_overdraft:                false,
    allow_overpayment:              false,
    iban_confermato_manualmente:    false,
    conferma_duplicato_verificato:  false,
    nota_override:                  null as string | null,
    allocazioni:                    [] as { fattura_id: number; tipo: string; importo_allocato_cents: number }[],
});

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

const pendenze = ref<Pendenza[]>([]);
const loadingPendenze = ref(false);
const hasNetting = ref(false);
const totaleNC = ref(0);
const totaleFT = ref(0);

// ── Modali successo / IBAN / duplicato (originali) ──
const showSuccessModal = ref(false);
const showIbanConfirmModal = ref(false);
const showDuplicateConfirmModal = ref(false);
const ibanDiscrepanzaMsg = ref('');
const duplicatoMsg = ref('');

// ── Modali errori di dominio — bypassabili ──
const showInsufficientFundsModal = ref(false);
const insufficientFundsMsg = ref('');
const insufficientFundsData = ref({ saldo_cents: 0, necessario_cents: 0, scopertura_cents: 0 });
const overdraftNote = ref('');

const showOverpaymentModal = ref(false);
const overpaymentMsg = ref('');
const overpaymentData = ref({ allocato_cents: 0, residuo_cents: 0, num_fattura: '' });
const overpaymentNote = ref('');

// ── Modali errori di dominio — non bypassabili ──
const showFiscalYearClosedModal = ref(false);
const fiscalYearClosedMsg = ref('');

const showIllegalCashModal = ref(false);
const illegalCashMsg = ref('');

const showFatturaNonApprovataModal = ref(false);
const fatturaNonApprovataMsg = ref('');

const showAllocazioniInconsistentiModal = ref(false);
const allocazioniInconsistentiMsg = ref('');

const showGenericErrorModal = ref(false);
const genericErrorMsg = ref('');

// --- Ratifica Sforo (inline in PagamentoNew) ---
const showApprovaSforoModal = ref(false);
const sforoTarget = ref<Pendenza | null>(null);
const noteApprovazioneInline = ref('');

const fiscalSentinelExpanded = ref(false);
const selectedFornitore = computed(() => props.fornitori.find(f => f.id === form.fornitore_id));

// Metodi pagamento disponibili (porta aperta per futuri)
const metodiPagamento = [
    { value: 'bonifico', label: 'Bonifico', icon: Send },
    { value: 'contanti', label: 'Contanti', icon: Banknote },
    { value: 'assegno', label: 'Assegno', icon: CreditCard },
];

const tipiDetrazione = [
    { value: 'ristrutturazione', label: 'Ristrutturazione edilizia (50%)' },
    { value: 'ecobonus',         label: 'Ecobonus / Riqualificazione energetica' },
    { value: 'sismabonus',       label: 'Sismabonus' },
    { value: 'superbonus',       label: 'Superbonus' },
    { value: 'altro',            label: 'Altra detrazione' },
];

// ---------------------------------------------------------------------------
// Computed — Totali & Allocazioni
// ---------------------------------------------------------------------------
const totaleAllocatoPagamento = computed(() =>
    pendenze.value
        .filter(p => p.selezionata && !p.is_nota_credito)
        .reduce((sum, p) => sum + (p.importo_allocato || 0), 0)
);

const totaleAllocatoCompensazione = computed(() =>
    pendenze.value
        .filter(p => p.selezionata && p.is_nota_credito)
        .reduce((sum, p) => sum + Math.abs(p.importo_allocato || 0), 0)
);

const bonificoEffettivo = computed(() =>
    Math.max(0, totaleAllocatoPagamento.value - totaleAllocatoCompensazione.value)
);

const commissioniCents = computed(() =>
    Math.round((Number(form.importo_commissioni_cents) || 0) * 100)
);

const uscitaCassaTotale = computed(() =>
    bonificoEffettivo.value + (Number(form.importo_commissioni_cents) || 0)
);

const bancheNormalizzate = computed(() =>
    props.banche.map(b => ({ ...b, saldo_attuale_cents: b.saldo_attuale || 0 }))
);

const bankForecast = computed(() => {
    if (!form.conto_corrente_id) return null;
    const b = bancheNormalizzate.value.find(b => b.id === form.conto_corrente_id);
    if (!b) return null;

    const attualeCents = b.saldo_attuale_cents;
    const spesaCents   = Math.round(uscitaCassaTotale.value * 100);
    const postCents    = attualeCents - spesaCents;

    return { attuale_cents: attualeCents, post_cents: postCents, isRed: postCents < 0 };
});

const transactionStatus = computed(() => {
    if (bankForecast.value?.isRed) return 'WARNING_CASH';
    if (pendenze.value.some(p => p.selezionata)) return 'SAFE';
    return 'IDLE';
});

// IBAN sentinella
const ibanDiscrepanza = computed(() => {
    if (!form.iban_beneficiario || !selectedFornitore.value?.iban_principale) return false;
    const inputClean = form.iban_beneficiario.replace(/\s/g, '').toUpperCase();
    const anagraficaClean = (selectedFornitore.value.iban_principale || '').replace(/\s/g, '').toUpperCase();
    return inputClean !== anagraficaClean && inputClean.length >= 15;
});

const fatturePendentiSoloFT = computed(() =>
    pendenze.value.filter(p => !p.is_nota_credito)
);

const noteCreditoCompensabili = computed(() =>
    pendenze.value.filter(p => p.is_nota_credito)
);

const documentiSelezionati = computed(() =>
    pendenze.value.filter(p => p.selezionata).length
);

// ---------------------------------------------------------------------------
// Fetch Pendenze (AJAX)
// ---------------------------------------------------------------------------
const fetchPendenze = async (fornitoreId: number) => {
    loadingPendenze.value = true;
    try {
        const response = await axios.get(
            route(generateRoute('gestionale.pagamenti-fornitori.pendenze'), {
                condominio: props.condominio.id,
            }),
            { params: { fornitore_id: fornitoreId } }
        );

        const data = response.data;
        pendenze.value = (data.pendenze || []).map((p: any) => ({
            ...p,
            selezionata: false,
            importo_allocato: 0,
            tipo_allocazione: p.is_nota_credito ? 'compensazione' : 'pagamento',
        }));
        hasNetting.value = data.has_netting;
        totaleNC.value = data.totale_nc;
        totaleFT.value = data.totale_ft;
    } catch (e) {
        console.error('Errore nel caricamento pendenze:', e);
        pendenze.value = [];
    } finally {
        loadingPendenze.value = false;
    }
};

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
const togglePendenza = (p: Pendenza) => {
    if (p.stato_approvazione !== 'approvata') return;
    p.selezionata = !p.selezionata;
    if (p.selezionata) {
        p.importo_allocato = p.is_nota_credito ? Math.abs(p.residuo) / 100 : p.residuo / 100;
    } else {
        p.importo_allocato = 0;
    }
    syncAllocazioni();
};

const saldaTutto = (p: Pendenza) => {
    if (p.stato_approvazione !== 'approvata') return;
    p.selezionata = true;
    p.importo_allocato = p.is_nota_credito ? Math.abs(p.residuo) / 100 : p.residuo / 100;
    syncAllocazioni();
};

const onAllocazioneChange = (p: Pendenza, val: any) => {
    const num = Number(val) || 0;
    const maxEuro = p.residuo / 100;
    p.importo_allocato = Math.min(num, maxEuro);
    p.selezionata = p.importo_allocato > 0;
    syncAllocazioni();
};

// Netting 1-Click: auto-compensa NC sulle FT
const applyNetting = () => {
    const ncs = pendenze.value.filter(p => p.is_nota_credito && p.stato_approvazione === 'approvata');
    const fts = pendenze.value.filter(p => !p.is_nota_credito && p.stato_approvazione === 'approvata').sort((a, b) => {
        // Priorità: fatture scadute prima
        if (a.is_scaduta && !b.is_scaduta) return -1;
        if (!a.is_scaduta && b.is_scaduta) return 1;
        return (a.data_scadenza || '').localeCompare(b.data_scadenza || '');
    });

    let creditoDisponibile = 0;

    // 1. Attiva tutte le NC
    ncs.forEach(nc => {
        nc.selezionata = true;
        nc.importo_allocato = Math.abs(nc.residuo) / 100;
        creditoDisponibile += nc.importo_allocato;
    });

    // 2. Distribuisci il credito sulle FT più vecchie, poi il resto va in pagamento cash
    fts.forEach(ft => {
        const residuoEuro = ft.residuo / 100;
        ft.selezionata = true;
        ft.importo_allocato = residuoEuro;
    });

    syncAllocazioni();
};

const selezionaTutte = () => {
    pendenze.value.filter(p => !p.is_nota_credito && p.stato_approvazione === 'approvata').forEach(p => saldaTutto(p));
};

// Apre il modale di ratifica sforo per una fattura specifica
const apriModaleApprovazioneSforo = (p: Pendenza) => {
    sforoTarget.value = p;
    noteApprovazioneInline.value = '';
    showApprovaSforoModal.value = true;
};

// Invia la ratifica al backend e ricarica le pendenze al successo
const executeApprovaSforoInline = () => {
    if (!sforoTarget.value) return;
    router.post(
        route(generateRoute('gestionale.fatture.approva-sforo'), {
            condominio: props.condominio.id,
            fattura: sforoTarget.value.id,
        }),
        { note: noteApprovazioneInline.value || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                showApprovaSforoModal.value = false;
                sforoTarget.value = null;
                // Ricarica le pendenze: la fattura ora appare sbloccata
                if (form.fornitore_id) fetchPendenze(form.fornitore_id);
            },
        }
    );
};

const deselezionaTutte = () => {
    pendenze.value.forEach(p => {
        p.selezionata = false;
        p.importo_allocato = 0;
    });
    syncAllocazioni();
};

const syncAllocazioni = () => {
    form.allocazioni = pendenze.value
        .filter(p => p.selezionata && (p.importo_allocato || 0) > 0)
        .map(p => ({
            fattura_id: p.id,
            tipo: p.is_nota_credito ? 'compensazione' : 'pagamento',
            importo_allocato_cents: Math.round((p.importo_allocato || 0) * 100),
        }));
};

// ---------------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------------
const handleSubmit = () => {
    syncAllocazioni();

    form.transform((data) => {
        const payload = JSON.parse(JSON.stringify(data));
        payload.importo_commissioni_cents = Math.round((Number(data.importo_commissioni_cents) || 0) * 100);
        return payload;
    }).post(route(generateRoute('gestionale.pagamenti-fornitori.store'), { condominio: props.condominio.id }), {
        preserveScroll: true,
        onSuccess: () => {
            showSuccessModal.value = true;
        },
        onError: (errors) => {
            // ── Bypassabili: IBAN ──
            if (errors.iban_discrepanza) {
                ibanDiscrepanzaMsg.value = errors.iban_discrepanza;
                showIbanConfirmModal.value = true;
                return;
            }
            // ── Bypassabili: Duplicato ──
            if (errors.possibile_duplicato) {
                duplicatoMsg.value = errors.possibile_duplicato;
                showDuplicateConfirmModal.value = true;
                return;
            }
            // ── Bypassabili: Saldo insufficiente ──
            if (errors.insufficient_funds) {
                insufficientFundsMsg.value = errors.insufficient_funds;
                // I dati strutturati viaggiano come JSON in errors (canale garantito da Inertia).
                // Il middleware condivide solo flash.message, non chiavi flash custom.
                if (errors.insufficient_funds_data) {
                    try {
                        insufficientFundsData.value = JSON.parse(errors.insufficient_funds_data);
                    } catch { /* usa i valori di default */ }
                }
                overdraftNote.value = '';
                showInsufficientFundsModal.value = true;
                return;
            }
            // ── Bypassabili: Overpayment ──
            if (errors.overpayment) {
                overpaymentMsg.value = errors.overpayment;
                if (errors.overpayment_data) {
                    try {
                        overpaymentData.value = JSON.parse(errors.overpayment_data);
                    } catch { /* usa i valori di default */ }
                }
                overpaymentNote.value = '';
                showOverpaymentModal.value = true;
                return;
            }
            // ── Non bypassabili ──
            if (errors.fiscal_year_closed) {
                fiscalYearClosedMsg.value = errors.fiscal_year_closed;
                showFiscalYearClosedModal.value = true;
                return;
            }
            if (errors.illegal_cash) {
                illegalCashMsg.value = errors.illegal_cash;
                showIllegalCashModal.value = true;
                return;
            }
            if (errors.fattura_non_approvata) {
                fatturaNonApprovataMsg.value = errors.fattura_non_approvata;
                showFatturaNonApprovataModal.value = true;
                return;
            }
            if (errors.allocazioni_inconsistenti) {
                allocazioniInconsistentiMsg.value = errors.allocazioni_inconsistenti;
                showAllocazioniInconsistentiModal.value = true;
                return;
            }
            // ── Fallback tecnico ──
            if (errors.error) {
                genericErrorMsg.value = errors.error;
                showGenericErrorModal.value = true;
            }
        },
    });
};

const confirmIban = () => {
    form.iban_confermato_manualmente = true;
    showIbanConfirmModal.value = false;
    handleSubmit();
};

const confirmDuplicate = () => {
    form.conferma_duplicato_verificato = true;
    showDuplicateConfirmModal.value = false;
    handleSubmit();
};

// Override saldo insufficiente: l'admin assume responsabilità con nota obbligatoria
const confirmOverdraft = () => {
    form.allow_overdraft = true;
    form.nota_override = overdraftNote.value;
    showInsufficientFundsModal.value = false;
    handleSubmit();
};

// Override overpayment: l'admin conferma l'eccedenza con nota obbligatoria
const confirmOverpayment = () => {
    form.allow_overpayment = true;
    form.nota_override = overpaymentNote.value;
    showOverpaymentModal.value = false;
    handleSubmit();
};

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------
watch(() => form.fornitore_id, async (newVal) => {
    if (newVal) {
        await fetchPendenze(newVal);
        const f = props.fornitori.find(x => x.id === newVal);
        if (f) {
            form.iban_beneficiario = f.iban_principale || '';
            form.iban_confermato_manualmente = false;
            form.conferma_duplicato_verificato = false;
        }
        
        if (props.preselected_fattura_id) {
            const p = pendenze.value.find((x: any) => x.id === props.preselected_fattura_id);
            if (p && !p.selezionata) {
                saldaTutto(p);
            }
        }
    } else {
        pendenze.value = [];
    }
}, { immediate: true });

// Richiede IBAN per bonifico
const richiedeIban = computed(() => form.metodo_pagamento === 'bonifico');

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------
const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Dashboard', href: route(generateRoute('gestionale.index'), { condominio: props.condominio.id }) },
    { title: 'Fatture',   href: route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }) },
    { title: 'Registra Pagamento' },
]);

const pageGuides = [
    { title: 'Ledger Esecutivo',     description: 'Seleziona il fornitore, poi scegli le fatture da pagare nel registro a destra. Il sistema calcola tutto automaticamente.', icon: ArrowRightLeft, colorVariant: 'blue' as const },
    { title: 'Smart Netting',        description: 'Se il fornitore ha Note di Credito aperte, un click compensa automaticamente riducendo l\'uscita di cassa.',               icon: Sparkles,       colorVariant: 'amber' as const },
    { title: 'Sentinella Anti-Frode', description: 'Ogni IBAN viene verificato contro l\'anagrafica. In caso di discrepanza, è richiesta conferma manuale.',                  icon: ShieldCheck,    colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Registra Pagamento Fornitore" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                page-title="Registra pagamento fornitore"
                page-subtitle="Seleziona il fornitore nel pannello a sinistra, poi scegli le fatture da pagare nel registro a destra."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :back-url="route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id })"
                back-text="Indietro"
            />

            <!-- Banner warning cash -->
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100">
                <div v-if="transactionStatus === 'WARNING_CASH'"
                    class="rounded-xl p-4 flex items-center gap-4 border bg-amber-50 border-amber-200 text-amber-900">
                    <TriangleAlert class="w-5 h-5 shrink-0" />
                    <p class="text-sm font-medium">
                        Liquidità insufficiente sul conto selezionato. Il pagamento può comunque essere registrato.
                    </p>
                </div>
            </Transition>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start relative z-10">

                <!-- ── COLONNA SINISTRA — Setup & Sentinelle ── -->
                <div class="lg:col-span-4 h-full flex flex-col bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 shrink-0">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Disposizione Pagamento</h3>
                    </div>

                    <div class="p-5 flex-1 overflow-y-auto space-y-5">

                        <!-- Fornitore -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fornitore *</Label>
                            <v-select
                                v-model="form.fornitore_id"
                                :options="fornitori"
                                label="ragione_sociale"
                                :reduce="(f: Fornitore) => f.id"
                                placeholder="Cerca fornitore..."
                                class="w-full">
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
                                                <span v-if="soggetto_ritenuta" class="text-[8px] font-black uppercase tracking-wider text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 leading-none">
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
                                        <span v-if="soggetto_ritenuta" class="ml-auto text-[8px] font-black uppercase tracking-wider text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 leading-none shrink-0">
                                            Ritenuta
                                        </span>
                                    </div>
                                </template>
                            </v-select>
                        </div>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <!-- Conto Addebito -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conto di Addebito *</Label>
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

                        <!-- Treasury Guardian Light -->
                        <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
                            <div v-if="bankForecast" class="rounded-xl border p-4 space-y-3 transition-colors"
                                :class="bankForecast.isRed ? 'bg-rose-50/50 border-rose-200 dark:bg-rose-950/20 dark:border-rose-800/50' : 'bg-slate-50 border-slate-200 dark:bg-slate-800/30 dark:border-slate-700'">
                                <div class="flex items-center gap-2">
                                    <Wallet class="w-4 h-4" :class="bankForecast.isRed ? 'text-rose-500' : 'text-blue-500'" />
                                    <span class="text-[10px] font-black uppercase tracking-widest" :class="bankForecast.isRed ? 'text-rose-500' : 'text-slate-400'">Treasury Guardian</span>
                                </div>
                                <div class="space-y-2">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-slate-500 flex items-center gap-1.5">💰 Saldo Attuale</span>
                                        <span class="font-bold text-slate-700 dark:text-slate-300">{{ euro(bankForecast.attuale_cents) }}</span>
                                    </div>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-slate-500 flex items-center gap-1.5">📉 Dopo questo pagamento</span>
                                        <span class="font-bold" :class="bankForecast.isRed ? 'text-rose-600' : 'text-emerald-600'">{{ euro(bankForecast.post_cents) }}</span>
                                    </div>
                                </div>
                                <div class="h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500"
                                        :class="bankForecast.isRed ? 'bg-rose-500' : 'bg-emerald-500'"
                                        :style="{ width: Math.min(Math.max((bankForecast.post_cents / Math.max(bankForecast.attuale_cents, 1)) * 100, 0), 100) + '%' }">
                                    </div>
                                </div>
                                <p v-if="bankForecast.isRed" class="text-[10px] text-rose-600 font-semibold leading-tight flex items-start gap-1.5">
                                    <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                                    Attenzione: il saldo post-pagamento risulta negativo. Valuta se procedere.
                                </p>
                            </div>
                        </Transition>

                        <hr class="border-slate-100 dark:border-slate-800">

                        <!-- Metodo Pagamento -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Metodo Pagamento</Label>
                            <div class="flex bg-slate-100 dark:bg-slate-800 p-1 rounded-lg gap-1">
                                <button v-for="m in metodiPagamento" :key="m.value"
                                    type="button"
                                    @click="form.metodo_pagamento = m.value"
                                    class="flex-1 py-2 text-[10px] font-black uppercase tracking-wider rounded-md transition-all flex items-center justify-center gap-1.5"
                                    :class="form.metodo_pagamento === m.value ? 'bg-white dark:bg-slate-700 text-primary shadow-sm' : 'text-slate-400 hover:text-slate-500'">
                                    <component :is="m.icon" class="w-3.5 h-3.5" />
                                    {{ m.label }}
                                </button>
                            </div>
                        </div>

                        <!-- IBAN + Sentinella Anti-Frode -->
                        <div v-if="richiedeIban" class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">IBAN Beneficiario</Label>
                            <div class="relative">
                                <Input v-model="form.iban_beneficiario"
                                    class="h-9 text-sm font-mono tracking-wide pr-10"
                                    :class="ibanDiscrepanza ? 'border-rose-400 focus-visible:ring-rose-400 bg-rose-50/50' : ''"
                                    placeholder="IT00 X000 0000 0000 0000 0000 000" />
                                <div v-if="ibanDiscrepanza" class="absolute right-2 top-1/2 -translate-y-1/2">
                                    <ShieldAlert class="w-4 h-4 text-rose-500 animate-pulse" />
                                </div>
                                <div v-else-if="form.iban_beneficiario && !ibanDiscrepanza" class="absolute right-2 top-1/2 -translate-y-1/2">
                                    <ShieldCheck class="w-4 h-4 text-emerald-500" />
                                </div>
                            </div>
                            <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0">
                                <div v-if="ibanDiscrepanza" class="flex items-start gap-2 px-3 py-2.5 bg-rose-50 dark:bg-rose-950/30 rounded-lg border border-rose-200 dark:border-rose-800/50">
                                    <ShieldAlert class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" />
                                    <div>
                                        <p class="text-[10px] text-rose-700 dark:text-rose-400 font-bold uppercase tracking-wide">Sentinella Anti-Frode</p>
                                        <p class="text-[10px] text-rose-600 dark:text-rose-400 mt-0.5 leading-relaxed">
                                            L'IBAN inserito differisce dall'anagrafica del fornitore
                                            (<span class="font-mono font-bold">{{ selectedFornitore?.iban_principale }}</span>).
                                            Al momento dell'invio ti verrà richiesta una conferma esplicita.
                                        </p>
                                    </div>
                                </div>
                            </Transition>
                        </div>

                        <!-- Data Pagamento -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data Pagamento *</Label>
                            <Input type="date" v-model="form.data_pagamento" class="h-9 text-sm" />
                        </div>

                        <!-- Commissioni Bancarie -->
                        <div class="space-y-1.5">
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Commissioni Bancarie</Label>
                            <MoneyInput
                                id="commissioni"
                                v-model="form.importo_commissioni_cents"
                                :money-options="moneyOptions"
                                :lazy="false"
                                class="h-9 text-sm bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 shadow-sm rounded-md border w-full px-3"
                                placeholder="0,00" />
                        </div>

                        <!-- Fiscal Sentinel (Bonifico Parlante) -->
                        <div class="space-y-1.5">
                            <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700 transition-colors"
                                :class="{ 'bg-indigo-50/50 border-indigo-200 dark:bg-indigo-900/10 dark:border-indigo-700/50': form.bonifico_parlante }">
                                <div class="flex items-center justify-between cursor-pointer" @click="form.bonifico_parlante = !form.bonifico_parlante">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" v-model="form.bonifico_parlante"
                                            class="w-4 h-4 text-indigo-500 rounded border-slate-300 focus:ring-indigo-500 cursor-pointer" />
                                        <div>
                                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300">Bonifico Parlante</span>
                                            <p class="text-[9px] text-slate-400 mt-0.5">Detrazioni fiscali condominiali (Art. 16-bis TUIR)</p>
                                        </div>
                                    </div>
                                    <Badge v-if="form.bonifico_parlante" class="bg-indigo-100 text-indigo-700 border-indigo-200 text-[8px] font-black uppercase tracking-widest">
                                        Fiscale
                                    </Badge>
                                </div>

                                <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-[300px]">
                                    <div v-if="form.bonifico_parlante" class="mt-4 space-y-3 pt-3 border-t border-slate-200 dark:border-slate-700">
                                        <div class="space-y-1.5">
                                            <Label class="text-[10px] font-bold uppercase tracking-wider text-indigo-600">Tipo Detrazione *</Label>
                                            <v-select
                                                v-model="form.tipo_detrazione"
                                                :options="tipiDetrazione"
                                                :reduce="(t: any) => t.value"
                                                label="label"
                                                placeholder="Seleziona tipo..."
                                                class="w-full text-sm"
                                            />
                                        </div>
                                        <div class="flex items-start gap-2 px-2.5 py-2 bg-indigo-50 dark:bg-indigo-950/30 rounded-lg border border-indigo-100">
                                            <FileText class="w-3.5 h-3.5 text-indigo-500 shrink-0 mt-0.5" />
                                            <p class="text-[10px] text-indigo-700 dark:text-indigo-400 leading-relaxed">
                                                La causale del bonifico verrà generata automaticamente con i riferimenti normativi richiesti dalla banca.
                                            </p>
                                        </div>
                                    </div>
                                </Transition>
                            </div>
                        </div>
                    </div>

                    <!-- Footer — Riepilogo Uscita Cassa -->
                    <div class="p-5 bg-slate-900 dark:bg-slate-950 text-white border-t border-slate-700 shrink-0 space-y-4">
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">Totale Pagamenti</span>
                                <span>{{ euro(totaleAllocatoPagamento, { fromCents: false }) }}</span>
                            </div>

                            <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
                                <div v-if="totaleAllocatoCompensazione > 0" class="flex justify-between text-xs pl-2 border-l-2 border-blue-500/50 ml-1">
                                    <span class="text-blue-400/80">Compensato con NC</span>
                                    <span class="text-blue-400/80">- {{ euro(totaleAllocatoCompensazione, { fromCents: false }) }}</span>
                                </div>
                            </Transition>

                            <div v-if="Number(form.importo_commissioni_cents) > 0" class="flex justify-between text-xs">
                                <span class="text-slate-400">Commissioni bancarie</span>
                                <span>{{ euro(form.importo_commissioni_cents, { fromCents: false }) }}</span>
                            </div>

                            <div class="flex justify-between items-baseline pt-3 border-t border-slate-700">
                                <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Uscita di Cassa</span>
                                <span class="font-black text-2xl" :class="uscitaCassaTotale > 0 ? 'text-emerald-400' : 'text-white'">
                                    {{ euro(uscitaCassaTotale, { fromCents: false }) }}
                                </span>
                            </div>
                        </div>

                        <Button type="button" :disabled="form.processing || form.allocazioni.length === 0" @click="handleSubmit"
                            class="w-full h-12 font-black text-sm uppercase tracking-wider rounded-xl gap-2"
                            :class="transactionStatus === 'WARNING_CASH' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700'">
                            <Save class="w-5 h-5" />
                            Registra Pagamento
                        </Button>
                    </div>
                </div>

                <!-- ── COLONNA DESTRA — Ledger Esecutivo ── -->
                <div class="lg:col-span-8 flex flex-col gap-5 relative z-0">

                    <!-- Smart Router Netting Banner -->
                    <Transition enter-active-class="transition-all duration-500 ease-out" enter-from-class="opacity-0 -translate-y-3 scale-[0.98]" enter-to-class="opacity-100 translate-y-0 scale-100">
                        <div v-if="hasNetting && form.fornitore_id"
                            class="bg-gradient-to-r from-amber-50 to-amber-50/60 dark:from-amber-950/30 dark:to-amber-950/10 rounded-xl border border-amber-200 dark:border-amber-800/50 p-4 flex items-center justify-between shadow-sm">
                            <div class="flex items-center gap-3">
                                <div class="p-2.5 bg-amber-100 dark:bg-amber-800/40 rounded-xl border border-amber-200/50">
                                    <Sparkles class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-amber-900 dark:text-amber-200">Smart Router — Netting 1-Click</p>
                                    <p class="text-[11px] text-amber-700/80 dark:text-amber-400/80 mt-0.5">
                                        Questo fornitore ha <strong>{{ euro(totaleNC) }}</strong> in Note di Credito compensabili
                                        contro <strong>{{ euro(totaleFT) }}</strong> di fatture aperte.
                                    </p>
                                </div>
                            </div>
                            <Button variant="outline" size="sm" type="button" @click="applyNetting"
                                class="h-9 px-4 text-[11px] font-black uppercase tracking-wider border-amber-300 bg-white dark:bg-amber-800/30 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-800/50 shadow-sm transition-all gap-1.5">
                                <Zap class="w-3.5 h-3.5" /> Compensa Automaticamente
                            </Button>
                        </div>
                    </Transition>

                    <!-- Tabella Documenti Pendenze -->
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center justify-between rounded-t-xl">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">Documenti pendenti</h3>
                                    <Badge v-if="pendenze.length" variant="secondary" class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-transparent">
                                        {{ pendenze.length }} {{ pendenze.length === 1 ? 'Documento' : 'Documenti' }}
                                    </Badge>
                                </div>
                                <p class="text-[11px] text-slate-500 mt-1">
                                    {{ form.fornitore_id ? 'Seleziona le fatture da pagare e le note di credito da compensare.' : 'Seleziona un fornitore per visualizzare i documenti.' }}
                                </p>
                            </div>
                            <div v-if="pendenze.length" class="flex items-center gap-2">
                                <Badge v-if="documentiSelezionati > 0" class="bg-emerald-50 text-emerald-700 border-emerald-200 text-[10px] font-bold">
                                    {{ documentiSelezionati }} selezionat{{ documentiSelezionati === 1 ? 'o' : 'i' }}
                                </Badge>
                                <Button variant="outline" size="sm" type="button" @click="selezionaTutte"
                                    class="h-8 text-[10px] font-bold uppercase border-slate-200 text-slate-600 hover:bg-slate-50 gap-1">
                                    <Check class="w-3 h-3" /> Tutte
                                </Button>
                                <Button variant="ghost" size="sm" type="button" @click="deselezionaTutte"
                                    class="h-8 text-[10px] font-bold uppercase text-slate-400 hover:text-rose-500 hover:bg-rose-50 gap-1">
                                    <X class="w-3 h-3" /> Reset
                                </Button>
                            </div>
                        </div>

                        <!-- Loading -->
                        <div v-if="loadingPendenze" class="p-12 flex flex-col items-center justify-center gap-3">
                            <div class="w-8 h-8 rounded-full border-2 border-primary border-t-transparent animate-spin"></div>
                            <p class="text-xs text-slate-400 font-medium">Caricamento documenti...</p>
                        </div>

                        <!-- Empty state -->
                        <div v-else-if="!form.fornitore_id" class="py-16 flex flex-col items-center justify-center text-slate-400 space-y-4 bg-slate-50/30">
                            <div class="p-5 bg-white rounded-2xl shadow-sm border border-slate-100">
                                <Search class="w-10 h-10 opacity-20" />
                            </div>
                            <div class="text-center">
                                <p class="font-medium text-sm text-slate-500">Nessun fornitore selezionato</p>
                                <p class="text-xs text-slate-400 mt-1">Seleziona un fornitore nel pannello a sinistra per iniziare</p>
                            </div>
                        </div>

                        <div v-else-if="pendenze.length === 0 && !loadingPendenze" class="py-16 flex flex-col items-center justify-center text-slate-400 space-y-4 bg-slate-50/30">
                            <div class="p-5 bg-white rounded-2xl shadow-sm border border-slate-100">
                                <CheckCircle class="w-10 h-10 text-emerald-300" />
                            </div>
                            <div class="text-center">
                                <p class="font-medium text-sm text-slate-500">Nessun documento pendente</p>
                                <p class="text-xs text-slate-400 mt-1">Tutte le fatture di questo fornitore sono state saldate</p>
                            </div>
                        </div>

                        <!-- Tabella pendenze -->
                        <div v-else class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            <div v-for="p in pendenze" :key="p.id"
                                class="px-6 py-4 flex items-center gap-4 group transition-all duration-200"
                                :class="[
                                    p.stato_approvazione !== 'approvata' ? 'opacity-60 cursor-not-allowed bg-slate-50/50' : 'cursor-pointer',
                                    p.selezionata ? 'bg-emerald-50/40 dark:bg-emerald-950/10' : (p.stato_approvazione === 'approvata' ? 'hover:bg-slate-50/50' : ''),
                                    p.is_nota_credito ? 'border-l-[3px] border-l-blue-400' : '',
                                    p.is_scaduta && !p.is_nota_credito ? 'border-l-[3px] border-l-rose-400' : ''
                                ]"
                                @click="p.stato_approvazione === 'approvata' && togglePendenza(p)">

                                <!-- Checkbox -->
                                <div class="shrink-0">
                                    <div class="w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all"
                                        :class="[
                                            p.selezionata ? 'bg-emerald-500 border-emerald-500 text-white shadow-sm shadow-emerald-500/30' : 'border-slate-300 dark:border-slate-600',
                                            p.stato_approvazione !== 'approvata' ? 'opacity-50' : ''
                                        ]">
                                        <Check v-if="p.selezionata" class="w-3 h-3" />
                                    </div>
                                </div>

                                <!-- Info documento -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <Badge :class="p.is_nota_credito
                                            ? 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/30 dark:border-blue-800/50 dark:text-blue-400'
                                            : 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-400'"
                                            class="text-[9px] font-black uppercase tracking-widest px-1.5 py-0.5">
                                            {{ p.is_nota_credito ? 'NC' : 'FT' }}
                                        </Badge>
                                        <span class="font-bold text-sm text-slate-800 dark:text-slate-200">{{ p.numero_documento }}</span>
                                        <span v-if="p.is_scaduta && !p.is_nota_credito"
                                            class="text-[8px] font-black uppercase tracking-wider text-rose-600 bg-rose-50 border border-rose-200 rounded px-1.5 py-0.5 leading-none flex items-center gap-1">
                                            <Clock class="w-2.5 h-2.5" /> Scaduta
                                        </span>
                                        <span v-if="p.stato_pagamento === 'parziale'"
                                            class="text-[8px] font-black uppercase tracking-wider text-amber-600 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5 leading-none">
                                            Parziale
                                        </span>
                                        <!-- Badge sforo con Tooltip shadcn (sfondo nero, freccia) -->
                                        <TooltipProvider v-if="p.stato_approvazione === 'sforo_motivato'" :delay-duration="200">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <span
                                                        class="text-[8px] font-black uppercase tracking-wider text-orange-700 bg-orange-100 border border-orange-300 rounded px-1.5 py-0.5 leading-none cursor-help"
                                                    >
                                                        ⚠ Ratifica richiesta
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent side="top" class="max-w-xs text-center">
                                                    <p class="font-bold mb-1">Spesa urgente in attesa di ratifica</p>
                                                    <p class="text-[11px] leading-relaxed font-normal opacity-90">Art. 1135 c.c. — L'assemblea deve deliberare la spesa prima del pagamento. Usa "Approva sforo" per registrare la ratifica.</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                        <span
                                            v-else-if="p.stato_approvazione !== 'approvata'"
                                            class="text-[8px] font-black uppercase tracking-wider text-slate-500 bg-slate-200 border border-slate-300 rounded px-1.5 py-0.5 leading-none"
                                        >
                                            Da approvare
                                        </span>
                                        <!-- Bottone inline ratifica: visibile solo per sforo_motivato -->
                                        <button
                                            v-if="p.stato_approvazione === 'sforo_motivato'"
                                            type="button"
                                            @click.stop="apriModaleApprovazioneSforo(p)"
                                            class="inline-flex items-center gap-1 text-[8px] font-black uppercase tracking-wider text-white bg-orange-500 hover:bg-orange-600 border border-orange-600 rounded px-2 py-0.5 leading-none transition-colors shadow-sm"
                                        >
                                            <Stamp class="w-2.5 h-2.5" />
                                            Approva sforo
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-3 text-[11px] text-slate-500">
                                        <span>{{ p.data_documento }}</span>
                                        <span v-if="p.data_scadenza_fmt" class="flex items-center gap-1">
                                            <Clock class="w-3 h-3" /> Scad. {{ p.data_scadenza_fmt }}
                                        </span>
                                    </div>
                                    <p v-if="p.descrizione_righe" class="text-[10px] text-slate-400 mt-1 truncate max-w-[400px]">{{ p.descrizione_righe }}</p>
                                </div>

                                <!-- Residuo -->
                                <div class="text-right shrink-0 w-28">
                                    <span class="text-[9px] font-bold uppercase tracking-wider block mb-0.5"
                                        :class="p.is_nota_credito ? 'text-blue-500' : 'text-slate-400'">
                                        {{ p.is_nota_credito ? 'Credito' : 'Residuo' }}
                                    </span>
                                    <span class="font-black text-sm block"
                                        :class="p.is_nota_credito ? 'text-blue-600 dark:text-blue-400' : 'text-slate-800 dark:text-slate-200'">
                                        {{ p.is_nota_credito ? '' : '' }}{{ euro(p.residuo) }}
                                    </span>
                                </div>

                                <!-- Input allocazione -->
                                <div class="shrink-0 w-32" @click.stop>
                                    <MoneyInput
                                        :id="'alloc_' + p.id"
                                        :modelValue="p.importo_allocato"
                                        @update:modelValue="p.stato_approvazione === 'approvata' && onAllocazioneChange(p, $event)"
                                        :disabled="p.stato_approvazione !== 'approvata'"
                                        :money-options="moneyOptions"
                                        :lazy="false"
                                        class="h-9 font-bold text-sm text-right transition-all rounded-md border w-full px-2 outline-none focus:ring-2 focus:ring-primary/20 disabled:opacity-50 disabled:bg-slate-100"
                                        :class="[
                                            (p.importo_allocato || 0) > 0
                                                ? 'border-emerald-500 bg-white ring-1 ring-emerald-500/20 text-emerald-700'
                                                : 'border-slate-200 bg-transparent hover:border-slate-300 text-slate-800',
                                        ]"
                                        placeholder="0,00"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Footer registro -->
                        <div v-if="pendenze.length > 0"
                            class="py-5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 rounded-b-xl flex flex-col sm:flex-row items-end sm:items-center justify-between px-6">
                            <div>
                                <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-x-4" enter-to-class="opacity-100 translate-x-0">
                                    <div v-if="totaleAllocatoCompensazione > 0" class="flex items-center gap-2.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800/50 px-3 py-2 rounded-lg shadow-sm">
                                        <div class="bg-blue-100 dark:bg-blue-800/50 p-1 rounded-md">
                                            <ArrowRightLeft class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" />
                                        </div>
                                        <div class="text-[11px] text-blue-800 dark:text-blue-300 leading-tight">
                                            Netting: <strong class="font-black text-blue-900 dark:text-blue-100">{{ euro(totaleAllocatoCompensazione, { fromCents: false }) }}</strong> <span class="opacity-80">compensato con NC</span>
                                        </div>
                                    </div>
                                </Transition>
                            </div>

                            <div class="flex items-center gap-8 pr-2 mt-4 sm:mt-0">
                                <div class="text-right">
                                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest block mb-0.5">Fatture</span>
                                    <span class="font-black text-slate-700 dark:text-slate-300 text-lg">{{ euro(totaleAllocatoPagamento, { fromCents: false }) }}</span>
                                </div>
                                <div v-if="totaleAllocatoCompensazione > 0" class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                                <div v-if="totaleAllocatoCompensazione > 0" class="text-right">
                                    <span class="text-[10px] text-blue-500 font-bold uppercase tracking-widest block mb-0.5">NC Comp.</span>
                                    <span class="font-black text-blue-600 dark:text-blue-400 text-lg">- {{ euro(totaleAllocatoCompensazione, { fromCents: false }) }}</span>
                                </div>
                                <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                                <div class="text-right">
                                    <span class="text-[10px] text-primary font-bold uppercase tracking-widest block mb-0.5">Bonifico</span>
                                    <span class="font-black text-primary text-xl">{{ euro(bonificoEffettivo, { fromCents: false }) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ledger Oscuro — Simulazione Impatto -->
                    <div v-if="form.allocazioni.length > 0"
                        class="bg-slate-900 dark:bg-slate-950 text-white rounded-xl border shadow-lg overflow-hidden transition-all duration-300"
                        :class="transactionStatus === 'WARNING_CASH' ? 'border-amber-500/30' : 'border-slate-700'">

                        <div class="px-6 py-4 border-b border-slate-700/50 flex items-center justify-between bg-slate-800/40">
                            <div class="flex items-center gap-2">
                                <Receipt class="w-4 h-4 text-emerald-400" />
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Riepilogo Operazione</span>
                            </div>
                            <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-black uppercase"
                                :class="{
                                    'bg-amber-500/20 text-amber-400': transactionStatus === 'WARNING_CASH',
                                    'bg-emerald-500/20 text-emerald-400': transactionStatus === 'SAFE',
                                }">
                                <span class="w-1.5 h-1.5 rounded-full mr-1"
                                    :class="{
                                        'bg-amber-500 animate-pulse': transactionStatus === 'WARNING_CASH',
                                        'bg-emerald-500': transactionStatus === 'SAFE',
                                    }"></span>
                                {{ transactionStatus === 'WARNING_CASH' ? 'Attenzione Cassa' : 'Tutto OK' }}
                            </div>
                        </div>

                        <div class="grid grid-cols-2 divide-x divide-slate-700/50">
                            <!-- Dettaglio allocazioni -->
                            <div class="p-5">
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-4">Dettaglio Allocazioni</p>
                                <div class="space-y-2">
                                    <div v-for="p in pendenze.filter(x => x.selezionata)" :key="p.id"
                                        class="flex justify-between items-start text-xs border-b border-slate-800 pb-2 last:border-0 last:pb-0">
                                        <div class="flex-1 mr-4">
                                            <div class="flex items-center gap-2">
                                                <span class="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                                    :class="p.is_nota_credito ? 'bg-blue-500/20 text-blue-400' : 'bg-slate-700 text-slate-400'">
                                                    {{ p.is_nota_credito ? 'NC' : 'FT' }}
                                                </span>
                                                <span class="font-medium text-slate-200">{{ p.numero_documento }}</span>
                                            </div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <span class="font-bold" :class="p.is_nota_credito ? 'text-blue-400' : 'text-white'">
                                                {{ p.is_nota_credito ? '- ' : '' }}{{ euro((p.importo_allocato || 0) * 100) }}
                                            </span>
                                            <div class="text-[9px] text-slate-500 font-medium mt-0.5">
                                                {{ p.is_nota_credito ? 'Compensazione' : 'Pagamento' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Previsione cassa -->
                            <div class="p-5">
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-4">Previsione Cassa</p>
                                <div v-if="bankForecast" class="space-y-3">
                                    <div class="space-y-2">
                                        <div class="flex justify-between text-xs">
                                            <span class="text-slate-400">Saldo attuale</span>
                                            <span class="text-white font-bold">{{ euro(bankForecast.attuale_cents) }}</span>
                                        </div>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-slate-400">Uscita prevista</span>
                                            <span class="text-rose-400 font-bold">- {{ euro(uscitaCassaTotale, { fromCents: false }) }}</span>
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

        <!-- ── MODALI ── -->

        <!-- Modale conferma IBAN discrepanza -->
        <Teleport to="body">
            <div v-if="showIbanConfirmModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all">
                <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-md overflow-hidden text-center p-8 border border-slate-200 dark:border-slate-800">
                    <div class="w-16 h-16 bg-rose-50 dark:bg-rose-900/30 rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-rose-100 dark:border-rose-900/50">
                        <ShieldAlert class="w-8 h-8 text-rose-500" />
                    </div>
                    <h3 class="font-black text-slate-800 dark:text-slate-100 text-lg mb-2">Sentinella Anti-Frode IBAN</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">
                        {{ ibanDiscrepanzaMsg }}
                    </p>
                    <p class="text-xs text-slate-400 mb-6">
                        Confermando, dichiari di aver verificato che l'IBAN è corretto e che il pagamento deve procedere con questo IBAN.
                    </p>
                    <div class="flex gap-3">
                        <Button variant="ghost" @click="showIbanConfirmModal = false"
                            class="flex-1 h-11 rounded-xl font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100">
                            Annulla
                        </Button>
                        <Button @click="confirmIban"
                            class="flex-1 h-11 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black uppercase tracking-widest text-[11px]">
                            <ShieldCheck class="w-4 h-4 mr-2" /> Confermo l'IBAN
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modale conferma duplicato -->
        <Teleport to="body">
            <div v-if="showDuplicateConfirmModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all">
                <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-md overflow-hidden text-center p-8 border border-slate-200 dark:border-slate-800">
                    <div class="w-16 h-16 bg-amber-50 dark:bg-amber-900/30 rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-amber-100 dark:border-amber-900/50">
                        <BadgeAlert class="w-8 h-8 text-amber-500" />
                    </div>
                    <h3 class="font-black text-slate-800 dark:text-slate-100 text-lg mb-2">Possibile pagamento duplicato</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">
                        {{ duplicatoMsg }}
                    </p>
                    <div class="flex gap-3">
                        <Button variant="ghost" @click="showDuplicateConfirmModal = false"
                            class="flex-1 h-11 rounded-xl font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100">
                            Annulla
                        </Button>
                        <Button @click="confirmDuplicate"
                            class="flex-1 h-11 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-black uppercase tracking-widest text-[11px]">
                            Procedi comunque
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modale successo -->
        <Teleport to="body">
            <div v-if="showSuccessModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all">
                <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden text-center p-8 border border-slate-200 dark:border-slate-800">
                    <div class="w-20 h-20 bg-emerald-50 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-emerald-100 dark:border-emerald-900/50">
                        <CheckCircle class="w-10 h-10 text-emerald-500" />
                    </div>
                    <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-2">Pagamento registrato</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-8 leading-relaxed">
                        Il pagamento e le scritture contabili sono stati registrati e bilanciati correttamente.
                    </p>
                    <div class="flex flex-col gap-3">
                        <Button
                            @click="() => { form.reset(); pendenze = []; showSuccessModal = false; }"
                            class="w-full h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black uppercase tracking-widest text-[11px] shadow-lg shadow-emerald-600/20 transition-all">
                            Registra un altro pagamento
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

        <!-- Modale Ratifica Assembleare Sforo (inline da PagamentoNew) — stesso stile ConfirmDialog -->
        <ConfirmDialog
            v-model="showApprovaSforoModal"
            title="Ratifica Assembleare — Sforo Motivato"
            confirm-text="Conferma Ratifica"
            variant="default"
            :disabled="noteApprovazioneInline.trim().length < 10"
            @confirm="executeApprovaSforoInline"
        >
            <div class="space-y-4">

                <!-- Contesto legale -->
                <div class="bg-orange-50 border border-orange-200 text-orange-800 p-3 rounded-lg flex gap-3 items-start">
                    <ShieldCheck class="w-5 h-5 shrink-0 mt-0.5 text-orange-600" />
                    <div>
                        <p class="font-bold text-orange-900">Ratifica assembleare obbligatoria (Art. 1135 c.c.)</p>
                        <p class="text-xs mt-1 leading-relaxed">
                            Questa fattura è stata registrata con sforo motivato: la spesa supera il budget approvato dall'assemblea.
                            La ratifica è obbligatoria per legge prima del pagamento.
                            Confermando dichiari che l'assemblea ha deliberato l'approvazione di questa spesa.
                        </p>
                    </div>
                </div>

                <!-- Fattura in oggetto -->
                <div v-if="sforoTarget" class="bg-slate-50 rounded-lg p-3 border border-slate-200">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Fattura in oggetto</p>
                    <p class="text-sm font-bold text-slate-800">{{ sforoTarget.numero_documento }}</p>
                    <p class="text-xs text-slate-500 mt-0.5">{{ sforoTarget.data_documento }}</p>
                </div>

                <!-- Note -->
                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500 flex justify-between">
                        <span>Riferimento verbale / Note <span class="text-rose-500">*</span></span>
                        <span class="font-normal text-slate-400 normal-case tracking-normal ml-1" :class="{'text-rose-500 font-bold': noteApprovazioneInline.trim().length < 10}">
                            {{ noteApprovazioneInline.trim().length < 10 ? `(minimo 10 caratteri, attuali: ${noteApprovazioneInline.trim().length})` : '(obbligatorio)' }}
                        </span>
                    </label>
                    <textarea
                        v-model="noteApprovazioneInline"
                        rows="3"
                        placeholder="Es: Delibera assembleare del 15/05/2025 – Verbale n. 3/2025 – Ratifica spesa urgente manutenzione ascensore..."
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-orange-400/30 focus:border-orange-400 resize-none"
                    />
                    <p class="text-[10px] text-slate-400 leading-relaxed">
                        Il sistema registrerà automaticamente data e autore dell'approvazione nell'audit trail della fattura.
                    </p>
                </div>
            </div>
        </ConfirmDialog>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- MODALI ERRORI DI DOMINIO                                        -->
        <!-- ════════════════════════════════════════════════════════════════ -->

        <!-- ── 1. SALDO INSUFFICIENTE — bypassabile con nota obbligatoria ── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showInsufficientFundsModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <!-- Header -->
                        <div class="bg-gradient-to-br from-rose-50 to-rose-100/50 dark:from-rose-950/40 dark:to-rose-900/20 px-8 pt-8 pb-6 text-center border-b border-rose-100 dark:border-rose-900/30">
                            <div class="w-16 h-16 bg-white dark:bg-rose-900/50 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-rose-200/50 dark:shadow-rose-900/30 border border-rose-100 dark:border-rose-800">
                                <Wallet class="w-8 h-8 text-rose-500" />
                            </div>
                            <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Saldo Conto Insufficiente</h3>
                            <span class="inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-rose-600 dark:text-rose-400 bg-rose-100 dark:bg-rose-900/50 px-2.5 py-1 rounded-full border border-rose-200 dark:border-rose-800">
                                <Scale class="w-3 h-3" /> Art. 1129 c.c.
                            </span>
                        </div>

                        <div class="p-8 space-y-5">
                            <!-- Dati strutturati saldo -->
                            <div class="grid grid-cols-3 gap-3">
                                <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl p-3 text-center border border-slate-200 dark:border-slate-700">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Saldo Attuale</p>
                                    <p class="font-black text-lg text-slate-700 dark:text-slate-300">{{ euro(insufficientFundsData.saldo_cents) }}</p>
                                </div>
                                <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl p-3 text-center border border-slate-200 dark:border-slate-700">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Necessario</p>
                                    <p class="font-black text-lg text-slate-700 dark:text-slate-300">{{ euro(insufficientFundsData.necessario_cents) }}</p>
                                </div>
                                <div class="bg-rose-50 dark:bg-rose-950/30 rounded-xl p-3 text-center border border-rose-200 dark:border-rose-800/50">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-rose-400 mb-1">Scopertura</p>
                                    <p class="font-black text-lg text-rose-600 dark:text-rose-400">{{ euro(insufficientFundsData.scopertura_cents) }}</p>
                                </div>
                            </div>

                            <!-- Contesto legale -->
                            <div class="flex items-start gap-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/50 rounded-xl p-4">
                                <Info class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-xs font-bold text-amber-800 dark:text-amber-300 mb-1">Responsabilità dell'amministratore (art. 1129 c.c.)</p>
                                    <p class="text-[11px] text-amber-700 dark:text-amber-400 leading-relaxed">
                                        Procedere con saldo insufficiente significa che il pagamento avverrà in scoperto di conto corrente.
                                        L'amministratore assume personalmente la responsabilità di questa decisione. La motivazione sarà
                                        registrata nell'audit trail permanente del pagamento.
                                    </p>
                                </div>
                            </div>

                            <!-- Nota override obbligatoria -->
                            <div class="space-y-2">
                                <label class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 flex items-center gap-2">
                                    <FileText class="w-3.5 h-3.5" />
                                    Motivazione (obbligatoria per procedere)
                                </label>
                                <textarea
                                    v-model="overdraftNote"
                                    rows="3"
                                    placeholder="Es: Incasso rate previsto entro 48h — bonifico urgente per lavori in corso — accordo verbale con fornitore..."
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3 text-sm text-slate-700 dark:text-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-400 resize-none transition-all"
                                />
                                <p class="text-[10px] text-slate-400 flex items-center gap-1.5">
                                    <Lock class="w-3 h-3" />
                                    Salvata permanentemente nel record del pagamento e nel log di sistema.
                                </p>
                            </div>

                            <!-- Azioni -->
                            <div class="flex gap-3 pt-2">
                                <Button variant="ghost" @click="showInsufficientFundsModal = false"
                                    class="flex-1 h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800">
                                    Annulla
                                </Button>
                                <Button @click="confirmOverdraft"
                                    :disabled="overdraftNote.trim().length < 10"
                                    class="flex-1 h-12 rounded-xl bg-rose-600 hover:bg-rose-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-black uppercase tracking-widest text-[11px] transition-all">
                                    <Lock class="w-4 h-4 mr-2" />
                                    Procedo — Assumo Responsabilità
                                </Button>
                            </div>
                            <p v-if="overdraftNote.trim().length > 0 && overdraftNote.trim().length < 10" class="text-[10px] text-rose-500 text-center">
                                La motivazione deve essere almeno 10 caratteri.
                            </p>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── 2. OVERPAYMENT — bypassabile con nota obbligatoria ── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showOverpaymentModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <div class="bg-gradient-to-br from-orange-50 to-orange-100/50 dark:from-orange-950/40 dark:to-orange-900/20 px-8 pt-8 pb-6 text-center border-b border-orange-100 dark:border-orange-900/30">
                            <div class="w-16 h-16 bg-white dark:bg-orange-900/50 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-orange-200/50 border border-orange-100 dark:border-orange-800">
                                <AlertOctagon class="w-8 h-8 text-orange-500" />
                            </div>
                            <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Importo Eccede il Residuo Fattura</h3>
                            <span class="inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-orange-600 bg-orange-100 dark:bg-orange-900/50 px-2.5 py-1 rounded-full border border-orange-200 dark:border-orange-800">
                                <Scale class="w-3 h-3" /> Partita Doppia
                            </span>
                        </div>

                        <div class="p-8 space-y-5">
                            <div class="grid grid-cols-3 gap-3">
                                <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl p-3 text-center border border-slate-200 dark:border-slate-700">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Fattura</p>
                                    <p class="font-bold text-sm text-slate-700 dark:text-slate-300 truncate">{{ overpaymentData.num_fattura }}</p>
                                </div>
                                <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl p-3 text-center border border-slate-200 dark:border-slate-700">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Residuo</p>
                                    <p class="font-black text-lg text-slate-700 dark:text-slate-300">{{ euro(overpaymentData.residuo_cents) }}</p>
                                </div>
                                <div class="bg-orange-50 dark:bg-orange-950/30 rounded-xl p-3 text-center border border-orange-200 dark:border-orange-800/50">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-orange-400 mb-1">Allocato</p>
                                    <p class="font-black text-lg text-orange-600 dark:text-orange-400">{{ euro(overpaymentData.allocato_cents) }}</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/50 rounded-xl p-4">
                                <Info class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-xs font-bold text-amber-800 dark:text-amber-300 mb-1">Implicazione contabile — Partita Doppia</p>
                                    <p class="text-[11px] text-amber-700 dark:text-amber-400 leading-relaxed">
                                        Un pagamento eccedente il residuo crea un credito vs. fornitore non controllato nel piano dei conti.
                                        Questo è ammissibile solo se concordato con il fornitore (es. anticipo su lavori futuri).
                                        Documenta il motivo per giustificare la scrittura contabile.
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 flex items-center gap-2">
                                    <FileText class="w-3.5 h-3.5" />
                                    Motivazione (obbligatoria per procedere)
                                </label>
                                <textarea
                                    v-model="overpaymentNote"
                                    rows="3"
                                    placeholder="Es: Acconto su lavori straordinari approvati — accordo con fornitore del [data]..."
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3 text-sm text-slate-700 dark:text-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 focus:border-orange-400 resize-none transition-all"
                                />
                            </div>

                            <div class="flex gap-3 pt-2">
                                <Button variant="ghost" @click="showOverpaymentModal = false"
                                    class="flex-1 h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800">
                                    Annulla e Correggi
                                </Button>
                                <Button @click="confirmOverpayment"
                                    :disabled="overpaymentNote.trim().length < 10"
                                    class="flex-1 h-12 rounded-xl bg-orange-600 hover:bg-orange-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-black uppercase tracking-widest text-[11px] transition-all">
                                    Confermo — Procedo
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── 3. ESERCIZIO CHIUSO — non bypassabile ── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showFiscalYearClosedModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <div class="bg-gradient-to-br from-slate-50 to-slate-100/50 dark:from-slate-800 dark:to-slate-700/30 px-8 pt-8 pb-6 text-center border-b border-slate-200 dark:border-slate-700">
                            <div class="w-16 h-16 bg-white dark:bg-slate-700 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg border border-slate-200 dark:border-slate-600">
                                <Lock class="w-8 h-8 text-slate-500" />
                            </div>
                            <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Esercizio Contabile Chiuso</h3>
                            <span class="inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-slate-600 dark:text-slate-300 bg-slate-200 dark:bg-slate-700 px-2.5 py-1 rounded-full">
                                <Scale class="w-3 h-3" /> Art. 1130-bis c.c.
                            </span>
                        </div>

                        <div class="p-8 space-y-5">
                            <div class="flex items-start gap-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800/50 rounded-xl p-4">
                                <Info class="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-xs font-bold text-blue-800 dark:text-blue-300 mb-1">Perché non posso procedere?</p>
                                    <p class="text-[11px] text-blue-700 dark:text-blue-400 leading-relaxed">
                                        Le scritture contabili devono essere attribuite all'esercizio corretto (art. 1130-bis c.c.).
                                        Un esercizio chiuso non può ricevere nuove scritture — questo è un requisito contabile fondamentale,
                                        non una limitazione del software.
                                    </p>
                                </div>
                            </div>

                            <div class="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800/50 rounded-xl p-4 space-y-2">
                                <p class="text-xs font-bold text-emerald-800 dark:text-emerald-300">✅ Come risolvere</p>
                                <ol class="text-[11px] text-emerald-700 dark:text-emerald-400 space-y-1.5 list-decimal list-inside leading-relaxed">
                                    <li>Torna al form e seleziona un <strong>esercizio aperto</strong> nel campo "Esercizio"</li>
                                    <li>Se non esiste un esercizio aperto, crea un nuovo esercizio dalla gestione condominio</li>
                                    <li>Una fattura di competenza 2025 può essere pagata in un esercizio 2026 aperto</li>
                                </ol>
                            </div>

                            <Button @click="showFiscalYearClosedModal = false"
                                class="w-full h-12 rounded-xl bg-slate-700 hover:bg-slate-800 dark:bg-slate-600 dark:hover:bg-slate-500 text-white font-black uppercase tracking-widest text-[11px]">
                                Ho capito — Torno al Form
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── 4. LIMITE CONTANTI ANTIRICICLAGGIO — non bypassabile ── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showIllegalCashModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-red-200 dark:border-red-900/50">
                        <div class="bg-gradient-to-br from-red-600 to-red-700 px-8 pt-8 pb-6 text-center">
                            <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/30">
                                <Ban class="w-8 h-8 text-white" />
                            </div>
                            <h3 class="font-black text-white text-xl mb-1">Pagamento Non Consentito</h3>
                            <span class="inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-red-100 bg-white/20 px-2.5 py-1 rounded-full">
                                D.Lgs. 231/2007 — Antiriciclaggio
                            </span>
                        </div>

                        <div class="p-8 space-y-5">
                            <div class="flex items-start gap-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800/50 rounded-xl p-4">
                                <AlertOctagon class="w-5 h-5 text-red-600 dark:text-red-400 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-xs font-bold text-red-800 dark:text-red-300 mb-1">Normativa antiriciclaggio obbligatoria</p>
                                    <p class="text-[11px] text-red-700 dark:text-red-400 leading-relaxed">
                                        Il <strong>D.Lgs. 231/2007</strong> vieta i pagamenti in contanti di importo pari o superiore a
                                        <strong>5.000€</strong> (soglia vigente dal 01/01/2023). La violazione comporta sanzioni
                                        amministrative da <strong>1.000€ a 50.000€</strong>. Questo blocco non può essere aggirato.
                                    </p>
                                </div>
                            </div>

                            <div class="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800/50 rounded-xl p-4 space-y-2">
                                <p class="text-xs font-bold text-emerald-800 dark:text-emerald-300">✅ Metodi di pagamento alternativi consentiti</p>
                                <ul class="text-[11px] text-emerald-700 dark:text-emerald-400 space-y-1 leading-relaxed">
                                    <li>🏦 <strong>Bonifico bancario</strong> — metodo preferito, tracciabile</li>
                                    <li>📋 <strong>Assegno non trasferibile</strong> — sempre intestato al beneficiario</li>
                                    <li>💳 <strong>Carta di credito/bancomat</strong> — con ricevuta elettronica</li>
                                </ul>
                            </div>

                            <Button @click="showIllegalCashModal = false"
                                class="w-full h-12 rounded-xl bg-red-600 hover:bg-red-700 text-white font-black uppercase tracking-widest text-[11px]">
                                Cambio Metodo di Pagamento
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── 5. FATTURA NON APPROVATA — non bypassabile ── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showFatturaNonApprovataModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 dark:from-amber-950/40 dark:to-yellow-950/20 px-8 pt-8 pb-6 text-center border-b border-amber-100 dark:border-amber-900/30">
                            <div class="w-16 h-16 bg-white dark:bg-amber-900/50 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg border border-amber-100 dark:border-amber-800">
                                <FileX class="w-8 h-8 text-amber-500" />
                            </div>
                            <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Fattura Non Ancora Approvata</h3>
                            <span class="inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-amber-600 bg-amber-100 dark:bg-amber-900/50 px-2.5 py-1 rounded-full border border-amber-200 dark:border-amber-800">
                                <Scale class="w-3 h-3" /> Art. 1135 c.c.
                            </span>
                        </div>

                        <div class="p-8 space-y-5">
                            <div class="flex items-start gap-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/50 rounded-xl p-4">
                                <ShieldCheck class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-xs font-bold text-amber-800 dark:text-amber-300 mb-1">Delibera assembleare richiesta (art. 1135 c.c.)</p>
                                    <p class="text-[11px] text-amber-700 dark:text-amber-400 leading-relaxed">
                                        L'assemblea condominiale deve deliberare e approvare le spese <strong>prima</strong> che l'amministratore
                                        possa procedere al pagamento. Pagare fatture non approvate espone l'amministratore a
                                        responsabilità personale verso i condòmini.
                                    </p>
                                </div>
                            </div>

                            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800/50 rounded-xl p-4 space-y-2">
                                <p class="text-xs font-bold text-blue-800 dark:text-blue-300">✅ Come sbloccare il pagamento</p>
                                <ol class="text-[11px] text-blue-700 dark:text-blue-400 space-y-1.5 list-decimal list-inside leading-relaxed">
                                    <li>Vai alla <strong>fattura passiva</strong> e approvala tramite il workflow di approvazione</li>
                                    <li>Per spese urgenti non differibili: registra come <strong>"sforo motivato"</strong> (art. 1135 c.2 c.c.)
                                        e poi fai ratificare in assemblea</li>
                                    <li>Torna qui e ripeti la registrazione del pagamento</li>
                                </ol>
                            </div>

                            <Button @click="showFatturaNonApprovataModal = false"
                                class="w-full h-12 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-black uppercase tracking-widest text-[11px]">
                                Ho capito — Vado ad Approvare
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── 6. ALLOCAZIONI INCONSISTENTI — non bypassabile ── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showAllocazioniInconsistentiModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <div class="bg-gradient-to-br from-violet-50 to-violet-100/50 dark:from-violet-950/40 dark:to-violet-900/20 px-8 pt-8 pb-6 text-center border-b border-violet-100 dark:border-violet-900/30">
                            <div class="w-16 h-16 bg-white dark:bg-violet-900/50 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg border border-violet-100 dark:border-violet-800">
                                <AlertTriangle class="w-8 h-8 text-violet-500" />
                            </div>
                            <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Errore nelle Allocazioni</h3>
                            <span class="inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-violet-600 bg-violet-100 dark:bg-violet-900/50 px-2.5 py-1 rounded-full border border-violet-200 dark:border-violet-800">
                                Partita Doppia
                            </span>
                        </div>

                        <div class="p-8 space-y-5">
                            <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">{{ allocazioniInconsistentiMsg }}</p>

                            <div class="flex items-start gap-3 bg-violet-50 dark:bg-violet-950/20 border border-violet-200 dark:border-violet-800/50 rounded-xl p-4">
                                <Info class="w-4 h-4 text-violet-600 dark:text-violet-400 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-xs font-bold text-violet-800 dark:text-violet-300 mb-1">Principio contabile violato</p>
                                    <p class="text-[11px] text-violet-700 dark:text-violet-400 leading-relaxed">
                                        Un singolo pagamento deve chiudere debiti verso un <strong>unico fornitore</strong> per un
                                        <strong>unico condominio</strong>. Questo è un requisito fondamentale della partita doppia
                                        che garantisce la tracciabilità contabile.
                                    </p>
                                </div>
                            </div>

                            <div class="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800/50 rounded-xl p-4">
                                <p class="text-xs font-bold text-emerald-800 dark:text-emerald-300 mb-2">✅ Come correggere</p>
                                <p class="text-[11px] text-emerald-700 dark:text-emerald-400 leading-relaxed">
                                    Deseleziona tutte le fatture con il pulsante "Reset" e riseleziona solo quelle dello stesso fornitore.
                                    Il sistema garantisce automaticamente la coerenza delle selezioni.
                                </p>
                            </div>

                            <Button @click="showAllocazioniInconsistentiModal = false"
                                class="w-full h-12 rounded-xl bg-violet-600 hover:bg-violet-700 text-white font-black uppercase tracking-widest text-[11px]">
                                Capito — Correggo la Selezione
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── 7. ERRORE TECNICO GENERICO — fallback ── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showGenericErrorModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 px-8 pt-8 pb-6 text-center">
                            <div class="w-16 h-16 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/20">
                                <Bug class="w-8 h-8 text-white/80" />
                            </div>
                            <h3 class="font-black text-white text-xl mb-1">Errore Tecnico</h3>
                            <p class="text-slate-400 text-sm">Il pagamento non è stato registrato</p>
                        </div>

                        <div class="p-8 space-y-5">
                            <div class="flex items-start gap-3 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800/50 rounded-xl p-4">
                                <CheckCircle class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-xs font-bold text-emerald-800 dark:text-emerald-300 mb-1">Nessun dato è andato perso</p>
                                    <p class="text-[11px] text-emerald-700 dark:text-emerald-400 leading-relaxed">
                                        Il sistema utilizza transazioni atomiche: o tutto viene registrato, o niente. Non ci sono
                                        dati parziali o scritture contabili danneggiate.
                                    </p>
                                </div>
                            </div>

                            <!-- Dettaglio tecnico collassabile -->
                            <details class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                                <summary class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-500 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    Dettaglio tecnico (per il supporto)
                                </summary>
                                <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700">
                                    <p class="text-[10px] font-mono text-slate-600 dark:text-slate-400 break-all leading-relaxed">{{ genericErrorMsg }}</p>
                                </div>
                            </details>

                            <div class="flex gap-3">
                                <Button variant="ghost" @click="showGenericErrorModal = false"
                                    class="flex-1 h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100">
                                    Chiudi
                                </Button>
                                <Button @click="() => { showGenericErrorModal = false; handleSubmit(); }"
                                    class="flex-1 h-12 rounded-xl bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white font-black uppercase tracking-widest text-[11px]">
                                    Riprova
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
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
</style>
