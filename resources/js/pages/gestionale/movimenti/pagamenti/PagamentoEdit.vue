<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Head, Link, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import MoneyInput from '@/components/MoneyInput.vue';
import {
    Banknote, CreditCard, Send, ShieldCheck, ShieldAlert, AlertTriangle,
    CheckCircle, Briefcase, FileText, Wallet,
    AlertOctagon, TriangleAlert, Lock,
    Save, BadgeAlert,
    Bug, Scale, Info, FileX, Ban
} from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { centsToEuro } from '@/lib/gestionale/money';
import { usePermission } from '@/composables/permissions';
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

interface PagamentoFornitore {
    id: number;
    fornitore: { id: number, ragione_sociale: string };
    conto_corrente: { id: number, nome: string, iban?: string };
    data_pagamento: string;
    metodo_pagamento: string;
    iban_beneficiario?: string; // non c'è nella resource, va preso dal conto/fornitore se serviva, lo lasciamo
    importo_commissione: number;
    importo_lordo: number;
    importo_ritenuta: number;
    importo_netto: number;
    bonifico_parlante: boolean;
    tipo_detrazione?: string;
    note_override?: string;
}

const props = defineProps<{
    condominio: Condominio;
    condomini: Condominio[];
    esercizio: Esercizio;
    fornitori: Fornitore[];
    banche: Banca[];
    pagamento: PagamentoFornitore;
}>();

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
const form = useForm({
    fornitore_id:                   props.pagamento.fornitore?.id,
    esercizio_id:                   props.esercizio?.id || null,
    conto_corrente_id:              props.pagamento.conto_corrente?.id,
    data_pagamento:                 props.pagamento.data_pagamento ? new Date(props.pagamento.data_pagamento).toISOString().substring(0, 10) : new Date().toISOString().substring(0, 10),
    metodo_pagamento:               props.pagamento.metodo_pagamento || 'bonifico',
    iban_beneficiario:              props.pagamento.iban_beneficiario || '',
    // La prop arriva dalla Resource ed è già in centesimi, come importo_lordo/ritenuta/netto
    // qui sotto: la casella però si digita in euro, quindi qui si DIVIDE. Moltiplicare
    // sarebbe la seconda conversione della stessa cifra — il bug del ×100 della beta.32.
    importo_commissioni_cents:      centsToEuro(props.pagamento.importo_commissione),
    importo_lordo_cents:            props.pagamento.importo_lordo,
    importo_ritenuta_cents:         props.pagamento.importo_ritenuta,
    importo_netto_cents:            props.pagamento.importo_netto,
    bonifico_parlante:              props.pagamento.bonifico_parlante || false,
    tipo_detrazione:                props.pagamento.tipo_detrazione || null,
    beneficiari_detrazione:         [] as any[],
    allow_overdraft:                false,
    allow_overpayment:              false,
    iban_confermato_manualmente:    false,
    conferma_duplicato_verificato:  false,
    note_override:                  props.pagamento.note_override || null,
    causale_bonifico:               (props.pagamento as any).causale_bonifico || '',
    riferimento_bancario:           (props.pagamento as any).riferimento_bancario || '',
});

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

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
const showModificaVietataModal = ref(false);
const modificaVietataMsg = ref('');

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

const selectedFornitore = computed(() => props.fornitori?.find(f => f.id === form.fornitore_id));

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
// Computed — Totali
// ---------------------------------------------------------------------------
const commissioniCents = computed(() =>
    Math.round((Number(form.importo_commissioni_cents) || 0) * 100)
);

const uscitaCassaTotale = computed(() =>
    (props.pagamento.importo_netto || 0) / 100 + commissioniCents.value / 100
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

const isDataPagamentoVecchia = computed(() => {
    if (!form.data_pagamento) return false;
    const diffTime = Math.abs(new Date().getTime() - new Date(form.data_pagamento).getTime());
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays > 30;
});

// ---------------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------------
const handleSubmit = () => {

    form.transform((data) => {
        const payload = JSON.parse(JSON.stringify(data));
        payload.importo_commissioni_cents = Math.round((Number(data.importo_commissioni_cents) || 0) * 100);
        // Non passiamo fornitore_id per evitare manipolazioni (lato backend è ignorato comunque)
        delete payload.fornitore_id;
        return payload;
    }).put(route(generateRoute('gestionale.pagamenti-fornitori.update'), { condominio: props.condominio.id, pagamento: props.pagamento.id }), {
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
            if (errors.modifica_vietata) {
                modificaVietataMsg.value = errors.modifica_vietata;
                showModificaVietataModal.value = true;
                return;
            }
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
    form.note_override = overdraftNote.value;
    showInsufficientFundsModal.value = false;
    handleSubmit();
};

// Override overpayment: l'admin conferma l'eccedenza con nota obbligatoria
const confirmOverpayment = () => {
    form.allow_overpayment = true;
    form.note_override = overpaymentNote.value;
    showOverpaymentModal.value = false;
    handleSubmit();
};

// ---------------------------------------------------------------------------
// Computed
// ---------------------------------------------------------------------------


const transactionStatus = computed(() => {
    if (!form.conto_corrente_id) return 'SAFE';
    const b = bancheNormalizzate.value.find(b => b.id === form.conto_corrente_id);
    if (!b) return 'SAFE';

    const postCents = b.saldo_attuale_cents - form.importo_lordo_cents;
    if (postCents < 0) return 'WARNING_CASH';
    return 'SAFE';
});

const ibanDiscrepanza = computed(() => {
    if (!form.iban_beneficiario) return false;
    if (!selectedFornitore.value?.iban_principale) return false;
    const input = form.iban_beneficiario.replace(/\s+/g, '').toUpperCase();
    const target = selectedFornitore.value.iban_principale.replace(/\s+/g, '').toUpperCase();
    return input !== target;
});

// Richiede IBAN per bonifico
const richiedeIban = computed(() => form.metodo_pagamento === 'bonifico');

// Il Bonifico Parlante richiede un bonifico tracciabile (art. 16-bis TUIR):
// se si cambia metodo, la dichiarazione fiscale non è più valida.
watch(() => form.metodo_pagamento, (metodo) => {
    if (metodo !== 'bonifico') {
        form.bonifico_parlante = false;
        form.tipo_detrazione = null;
    }
});

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------
const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Dashboard', href: route(generateRoute('gestionale.index'), { condominio: props.condominio.id }) },
    { title: 'Pagamenti', href: route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id }) },
    { title: 'Modifica Pagamento' },
]);

const pageGuides: never[] = [];
</script>

<template>
    <Head title="Modifica Pagamento Fornitore" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                page-title="Modifica pagamento fornitore"
                page-subtitle="Aggiorna i dati del pagamento. Non è possibile modificare le allocazioni alle fatture o il fornitore."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :back-url="route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id })"
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

            <div class="relative z-10">

                <div class="flex flex-col bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 shrink-0">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Disposizione Pagamento</h3>
                    </div>

                    <div class="p-5 flex-1 overflow-y-auto space-y-5">

                        <!-- Fornitore + Conto Addebito -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fornitore *</Label>
                                <v-select
                                    v-model="form.fornitore_id"
                                    :options="fornitori"
                                    label="ragione_sociale"
                                    :reduce="(f: Fornitore) => f.id"
                                    placeholder="Cerca fornitore..."
                                    class="w-full"
                                    :disabled="true">
                                    <template #option="{ ragione_sociale, piva, codice_fiscale, soggetto_ritenuta }">
                                        <div class="flex items-center gap-3 py-1 opacity-60">
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
                        </div>

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

                        <!-- Data Pagamento + Commissioni Bancarie -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data Pagamento *</Label>
                                <Input type="date" v-model="form.data_pagamento" class="h-9 text-sm" />
                            </div>

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
                        </div>
                        <div v-if="isDataPagamentoVecchia" class="flex items-start gap-2 text-[10.5px] font-medium text-amber-700 bg-amber-50 p-2 rounded-md border border-amber-200">
                            <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5 text-amber-500" />
                            <span><strong>Attenzione (Art. 1130 c.c.)</strong> Stai modificando con una data avvenuta oltre 30 giorni fa. Ricorda che la normativa prevede l'annotazione a registro entro i 30 giorni.</span>
                        </div>

                        <!-- Fiscal Sentinel (Bonifico Parlante) — richiede un bonifico tracciabile (art. 16-bis TUIR) -->
                        <div v-if="richiedeIban" class="space-y-1.5">
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

                        <hr class="border-slate-100 dark:border-slate-800">

                        <!-- Riferimenti Bancari e Note -->
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="space-y-1.5">
                                    <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Causale Bonifico</Label>
                                    <Input v-model="form.causale_bonifico" class="h-9 text-sm" placeholder="Causale..." />
                                </div>
                                <div class="space-y-1.5">
                                    <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Rif. (CRO/TRN)</Label>
                                    <Input v-model="form.riferimento_bancario" class="h-9 text-sm font-mono" placeholder="CRO / TRN" />
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Note interne (opzionali)</Label>
                                <textarea
                                    v-model="form.note_override"
                                    rows="2"
                                    placeholder="Note interne visualizzate nel gestionale..."
                                    class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary resize-none dark:bg-slate-900 dark:border-slate-700 dark:text-slate-200"
                                />
                            </div>
                        </div>

                    </div>

                    <!-- Footer — Riepilogo Uscita Cassa -->
                    <div class="p-5 bg-slate-900 dark:bg-slate-950 text-white border-t border-slate-700 shrink-0 space-y-4">
                        <div class="space-y-2">
                            <div v-if="Number(form.importo_commissioni_cents) > 0" class="flex justify-between text-xs">
                                <span class="text-slate-400">Commissioni bancarie</span>
                                <span>{{ euro(form.importo_commissioni_cents, { fromCents: false }) }}</span>
                            </div>
                                            <div class="flex justify-between items-baseline pt-3 border-t border-slate-700">
                                <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Importo Pagamento</span>
                                <span class="font-black text-2xl" :class="uscitaCassaTotale > 0 ? 'text-emerald-400' : 'text-white'">
                                    {{ euro(props.pagamento.importo_netto) }}
                                </span>
                            </div>

                            <div class="mt-4 pt-4 border-t border-slate-700 flex items-center justify-end gap-3">
                                <Link :href="route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id })"
                                    class="inline-flex items-center justify-center h-9 px-5 rounded-md border border-slate-700 text-slate-300 text-[10px] font-bold uppercase tracking-widest hover:bg-slate-800 transition-all">
                                    Annulla
                                </Link>
                                <Button type="button" @click="handleSubmit" :disabled="form.processing"
                                    class="h-9 px-6 rounded-md font-black uppercase tracking-wider text-[10px] gap-2 transition-all shadow-md bg-emerald-600 hover:bg-emerald-700 text-white shadow-emerald-600/20">
                                    <Save class="w-4 h-4" />
                                    Salva Modifiche
                                </Button>
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
                            @click="() => { showSuccessModal = false; }"
                            class="w-full h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black uppercase tracking-widest text-[11px] shadow-lg shadow-emerald-600/20 transition-all">
                            Chiudi
                        </Button>
                        <Button
                            variant="ghost"
                            @click="router.visit(route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id }))"
                            class="w-full h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                            Torna all'elenco pagamenti
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

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

        <!-- ── 3. MODIFICA NON CONSENTITA (storno, esercizio scrittura, ecc.) — non bypassabile ── -->
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

                            <Button @click="() => { showModificaVietataModal = false; router.visit(route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id })); }"
                                class="w-full h-12 rounded-xl bg-slate-700 hover:bg-slate-800 dark:bg-slate-600 dark:hover:bg-slate-500 text-white font-black uppercase tracking-widest text-[11px]">
                                Ho capito — Torna all'elenco
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── 4. ESERCIZIO CHIUSO — non bypassabile ── -->
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

        <!-- ── 5. LIMITE CONTANTI ANTIRICICLAGGIO — non bypassabile ── -->
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

        <!-- ── 6. FATTURA NON APPROVATA — non bypassabile ── -->
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

        <!-- ── 7. ALLOCAZIONI INCONSISTENTI — non bypassabile ── -->
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

        <!-- ── 8. ERRORE TECNICO GENERICO — fallback ── -->
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
