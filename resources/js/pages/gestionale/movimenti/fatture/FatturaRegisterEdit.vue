<script setup lang="ts">

import { ref, computed, watch } from 'vue';
import { useForm, Head, router, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { FileText, Plus, Trash2, AlertTriangle, User, ShieldAlert, Save, AlertOctagon, TriangleAlert, TrendingDown, Zap, ArrowRightLeft, Briefcase, History, ChevronDown, CheckCircle, Lock, Info } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import MoneyInput from '@/components/MoneyInput.vue';
import { centsToEuro } from '@/lib/gestionale/money';
import { lordoRigaCents } from '@/lib/gestionale/fatture/budget';
import { calcolaTotali, risolviRegimeRitenuta } from '@/lib/gestionale/fatture/totali';
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
    regime_forfetario?: boolean;
    ultima_aliquota_iva?: number | null;
    tipo_ritenuta?: string | null;
    natura_percipiente?: string | null;
}

const MOTIVI_ESCLUSIONE_RITENUTA = [
    { value: 'bonifico_parlante', label: "Bonifico parlante (ritenuta 11% già operata dalla banca)" },
    { value: 'forfetario', label: 'Fornitore in regime forfetario' },
    { value: 'fuori_campo', label: 'Fuori dal campo di applicazione della ritenuta' },
    { value: 'posa_accessoria', label: 'Posa in opera accessoria alla fornitura' },
    { value: 'override_manuale', label: 'Altro motivo (specificare)' },
];

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
const showSuccessModal = ref(false);
const showModificaVietataModal = ref(false);
const showPresaAttoSforoModal = ref(false);
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
    // FIX (revisione avversariale): un booleano esplicito qui, sempre, faceva
    // scattare required_if:applica_ritenuta,false in UpdateFatturaRequest per
    // OGNI fattura di un fornitore non soggetto a ritenuta (il caso comune) —
    // il salvataggio veniva rifiutato con un errore che non aveva nulla a che
    // fare con la ritenuta. Il booleano esplicito ha senso solo quando la
    // ritenuta è davvero rilevante per questo fornitore (riflette lo stato
    // reale della fattura); altrimenti null, come in creazione — stesso
    // percorso null → stringa vuota → null già validato lì.
    applica_ritenuta: (props.fattura.fornitore?.soggetto_ritenuta && !props.fattura.fornitore?.regime_forfetario)
        ? (Number(props.fattura.importo_ritenuta) || 0) !== 0
        : null,
    dati_extra: {
        fiscal: {
            cig: '', cup: '',
            ...(props.fattura.dati_extra?.fiscal || {}),
            motivo_esclusione_ritenuta: props.fattura.dati_extra?.fiscal?.motivo_esclusione_ritenuta || '',
            motivo_esclusione_ritenuta_note: props.fattura.dati_extra?.fiscal?.motivo_esclusione_ritenuta_note || '',
            conferma_codice_tributo_mancante: props.fattura.dati_extra?.fiscal?.conferma_codice_tributo_mancante || false,
        },
        competenza: props.fattura.dati_extra?.competenza || { dal: '', al: '' },
        override_budget: null as any,
        log_legale_sopravvenienza: null as any
    },
    stato_approvazione: props.fattura.stato_approvazione,
    righe: props.fattura.righe ? props.fattura.righe.map((r: any) => ({
        descrizione: r.descrizione || '',
        conto_id: r.conto_id,
        immobile_id: r.immobile_id,
        // Valore ASSOLUTO, e non è una difesa: su una nota di credito il database
        // tiene le righe già negative (`FatturaPassivaService:696`), ma
        // `aggiornaFattura()` vuole in ingresso la cifra digitata e il segno lo mette
        // lui (`:682` e `:696`). Rimandandogli il negativo che ha scritto, `-1000 ×
        // 100 × (-1)` torna positivo e la nota di credito diventa un costo.
        // Il form lavora in valore assoluto: il segno appartiene al tipo di documento.
        importo_imponibile: Math.abs(centsToEuro(r.importo_imponibile)),
        aliquota_iva: r.aliquota_iva,
        is_sopravvenienza: false, // Edit non supporta sopravvenienze per fatture esistenti
        concorre_base_ritenuta: r.concorre_base_ritenuta ?? true,
    })) : [{
        descrizione: '',
        conto_id: null as number | null,
        immobile_id: null as number | null,
        importo_imponibile: 0,
        aliquota_iva: 22,
        is_sopravvenienza: false,
        concorre_base_ritenuta: true,
    }],
    coperture: [] as any[],
    file: null as File | null,
});

// Since fornitore is read-only and not passed in props.fornitori
const selectedFornitore = computed(() => props.fattura.fornitore);

/** Il forfetario esclude la ritenuta per legge, a prescindere da soggetto_ritenuta. */
const fornitoreRitenutaAttiva = computed(() =>
    !!selectedFornitore.value?.soggetto_ritenuta && !selectedFornitore.value?.regime_forfetario
);

const applicaRitenutaEffective = computed<boolean>({
    get: () => form.applica_ritenuta ?? (form.tipo_documento !== 'nota_credito'),
    set: (val: boolean) => { form.applica_ritenuta = val; },
});

/**
 * Design §2.4 M2: senza natura del percipiente (né un codice tributo legacy
 * come override) il codice tributo 1019/1020 è indeterminabile. v1.10: warning
 * bloccante con conferma esplicita — v1.11: blocco duro (design doc).
 */
const codiceTributoIndeterminabile = computed(() =>
    fornitoreRitenutaAttiva.value
    && applicaRitenutaEffective.value
    && !!selectedFornitore.value?.tipo_ritenuta
    && !selectedFornitore.value?.natura_percipiente
    && !selectedFornitore.value?.codice_tributo
);

/** ultima_aliquota_iva vive solo nella collection props.fornitori, non su fattura.fornitore. */
const fornitoreConStorico = computed(() => props.fornitori.find(f => f.id === form.fornitore_id));

// ---------------------------------------------------------------------------
// Computed
// ---------------------------------------------------------------------------

/**
 * Anteprima dei totali, in centesimi interi. Stesso modulo condiviso della pagina di
 * registrazione: qui il rischio è anche più concreto, perché la fattura mostrata è già a
 * database e l'amministratore confronta a occhio il netto del form con quello dell'elenco.
 */
const totali = computed(() => calcolaTotali({
    is_pregresso:           form.is_pregresso,
    imponibile_pregresso:   form.imponibile_pregresso,
    aliquota_iva_pregressa: form.aliquota_iva_pregressa,
    righe:                  form.righe,
    ritenuta:               risolviRegimeRitenuta(selectedFornitore.value, applicaRitenutaEffective.value),
}));

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
        const spesaCents   = lordoRigaCents(r.importo_imponibile, r.aliquota_iva);
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

/**
 * Sforo della SINGOLA riga, per il badge sotto il campo importo.
 *
 * Attenzione: `budgetImpacts` aggrega invece per capitolo, quindi due righe sullo stesso
 * conto possono sforare insieme senza che nessuna delle due sfori da sola. Il badge di riga
 * e il pannello laterale rispondono deliberatamente a domande diverse.
 */
const rigaInSforo = (riga: { conto_id: number | null; importo_imponibile: unknown; aliquota_iva: unknown }): boolean => {
    if (!riga.conto_id) return false;
    const c = props.conti.find(c => c.id === riga.conto_id);
    if (!c || c.residuo_budget === undefined) return false;

    return lordoRigaCents(riga.importo_imponibile, riga.aliquota_iva) > c.residuo_budget;
};

const bancheNormalizzate = computed(() =>
    props.banche.map(b => ({ ...b, saldo_attuale_cents: b.saldo_attuale || 0 }))
);

const bankForecast = computed(() => {
    if (!form.conto_corrente_id) return null;
    const b = bancheNormalizzate.value.find(b => b.id === form.conto_corrente_id);
    if (!b) return null;

    const attualeCents = b.saldo_attuale_cents;
    const spesaCents   = totali.value.netto_cents;
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
 * NB: in modifica `is_pregresso` NON si ricalcola mai — viene dalla fattura ed
 * è immutabile (le pregresse non sono modificabili, e UpdateFatturaRequest
 * ignora comunque il campo). Il vecchio ricalcolo dalla data poteva accenderlo
 * a runtime retrodatando il documento: la vista passava al pregresso con i
 * totali a zero mentre le righe mostravano gli importi veri.
 */
watch(
    [() => form.fornitore_id, () => form.data_documento],
    ([newFornitoreId, newDataDoc], [oldFornitoreId, oldDataDoc]) => {

        // Aggiorna campi derivati dal fornitore
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
    aliquota_iva:       fornitoreConStorico.value?.ultima_aliquota_iva ?? 22,
    is_sopravvenienza:  false,
    concorre_base_ritenuta: true,
});

const removeRiga = (idx: number) => {
    if (form.righe.length > 1) form.righe.splice(idx, 1);
};

/**
 * In modifica lo sforo non può essere motivato (UpdateFatturaRequest scarta
 * qualunque override), ma non deve nemmeno passare con un click distratto:
 * sopra budget il salvataggio richiede una presa d'atto esplicita. Nessun
 * flag persistente: il dialogo si ripresenta a ogni submit oltre budget,
 * così una modifica ulteriore delle righe non eredita una conferma vecchia.
 */
const handleSubmit = () => {
    if (!form.is_pregresso && transactionStatus.value === 'CRITICAL_BUDGET') {
        showPresaAttoSforoModal.value = true;
        return;
    }
    doSubmit();
};

const confermaPresaAttoSforo = () => {
    showPresaAttoSforoModal.value = false;
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
            // NB: `|| 22` trattava lo ZERO come valore assente e lo sostituiva con
            // l'aliquota ordinaria. Le spese senza IVA sono normalissime in condominio
            // — commissioni bancarie, professionisti in regime forfetario — e venivano
            // salvate con il 22% pur essendo state digitate a 0: l'anteprima mostrava
            // l'importo giusto (usa `|| 0`), il documento salvato no.
            aliquota_iva: Number.isFinite(Number(r.aliquota_iva)) ? Number(r.aliquota_iva) : 22,
            is_sopravvenienza: Boolean(r.is_sopravvenienza),
            concorre_base_ritenuta: r.concorre_base_ritenuta !== false,
        }));

        // Il motivo di esclusione va inviato solo quando la ritenuta è
        // effettivamente esclusa: una stringa vuota fallirebbe Rule::in lato
        // backend, quindi normalizziamo a null quando non applicabile.
        if (payload.dati_extra?.fiscal) {
            const fiscal = payload.dati_extra.fiscal;
            if (applicaRitenutaEffective.value) {
                fiscal.motivo_esclusione_ritenuta = null;
                fiscal.motivo_esclusione_ritenuta_note = null;
            } else {
                fiscal.motivo_esclusione_ritenuta = fiscal.motivo_esclusione_ritenuta || null;
                fiscal.motivo_esclusione_ritenuta_note = fiscal.motivo_esclusione_ritenuta_note || null;
            }
        }

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
    { title: 'Audit Trail',      description: 'In modifica lo sforo non può essere motivato: per registrare una motivazione occorre stornare e ri-registrare.',      icon: ShieldAlert,    colorVariant: 'emerald' as const },
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
                    <p v-if="transactionStatus === 'CRITICAL_BUDGET'" class="text-sm font-medium">
                        Questa modifica porta il capitolo oltre il budget di {{ euro(sforoBudgetTotaleCents) }}.
                        In modifica lo sforo <strong>non può essere motivato</strong>: per registrare una motivazione
                        e la relativa copertura occorre stornare la fattura e registrarla di nuovo.
                    </p>
                    <p v-else class="text-sm font-medium">
                        Liquidità insufficiente sul conto selezionato.
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

                        <!-- Ritenuta d'acconto: toggle per documento -->
                        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="-translate-y-1 opacity-0" enter-to-class="translate-y-0 opacity-100">
                            <div v-if="fornitoreRitenutaAttiva" class="p-3 bg-amber-50/50 dark:bg-amber-900/10 rounded-lg border border-amber-100 dark:border-amber-900/30 space-y-2.5">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <input type="checkbox"
                                        :checked="applicaRitenutaEffective"
                                        @change="applicaRitenutaEffective = ($event.target as HTMLInputElement).checked"
                                        class="w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500 cursor-pointer" />
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-400">
                                        Applica ritenuta d'acconto su questo documento
                                    </span>
                                </label>

                                <div v-if="!applicaRitenutaEffective" class="space-y-2 pt-1">
                                    <v-select
                                        v-model="form.dati_extra.fiscal.motivo_esclusione_ritenuta"
                                        :options="MOTIVI_ESCLUSIONE_RITENUTA"
                                        :reduce="(o: any) => o.value"
                                        label="label"
                                        placeholder="Motivo dell'esclusione..."
                                        class="text-xs"
                                    />
                                    <p v-if="(form.errors as any)['dati_extra.fiscal.motivo_esclusione_ritenuta']" class="text-[11px] text-red-600 font-medium">
                                        {{ (form.errors as any)['dati_extra.fiscal.motivo_esclusione_ritenuta'] }}
                                    </p>
                                    <Input
                                        v-if="form.dati_extra.fiscal.motivo_esclusione_ritenuta === 'override_manuale'"
                                        v-model="form.dati_extra.fiscal.motivo_esclusione_ritenuta_note"
                                        placeholder="Specifica il motivo..."
                                        class="h-9 text-xs bg-white" />
                                </div>
                            </div>
                        </Transition>

                        <!-- Codice tributo indeterminabile: warning bloccante con override (design §2.4 M2) -->
                        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="-translate-y-1 opacity-0" enter-to-class="translate-y-0 opacity-100">
                            <div v-if="codiceTributoIndeterminabile" class="p-3 bg-rose-50 dark:bg-rose-900/10 rounded-lg border border-rose-200 dark:border-rose-900/30 space-y-2">
                                <p class="text-[11px] text-rose-700 dark:text-rose-400 leading-relaxed">
                                    <strong>Codice tributo indeterminabile.</strong> Manca la natura del percipiente sull'anagrafica di {{ selectedFornitore?.ragione_sociale }}: il sistema non può decidere se il codice è 1019 o 1020.
                                    <Link :href="route(generateRoute('fornitori.edit'), { fornitore: form.fornitore_id })" target="_blank" class="underline font-semibold">Completa l'anagrafica</Link>
                                    oppure conferma per procedere comunque.
                                </p>
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <input type="checkbox" v-model="form.dati_extra.fiscal.conferma_codice_tributo_mancante"
                                        class="w-4 h-4 text-rose-600 rounded border-slate-300 focus:ring-rose-500 cursor-pointer" />
                                    <span class="text-[11px] font-semibold text-rose-800 dark:text-rose-300">
                                        Confermo di voler procedere: correggerò il codice tributo manualmente prima dell'F24
                                    </span>
                                </label>
                            </div>
                        </Transition>

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
                                <span>{{ euro(totali.imponibile_cents) }}</span>
                            </div>

                            <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
                                <div v-if="totali.ha_sopravvenienze" class="flex justify-between text-[10px] pl-2 border-l-2 border-amber-500/50 ml-1 mt-1 mb-1">
                                    <span class="text-amber-400/80">Di cui imprevisto</span>
                                    <span class="text-amber-400/80">{{ euro(totali.imponibile_sopravvenienza_cents) }}</span>
                                </div>
                            </Transition>

                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">IVA</span>
                                <span>{{ euro(totali.iva_cents) }}</span>
                            </div>

                            <div v-if="totali.ritenuta_cents > 0" class="flex justify-between text-xs pt-1 border-t border-slate-800">
                                <span class="text-amber-400">Ritenuta d'Acconto</span>
                                <span class="text-amber-400">- {{ euro(totali.ritenuta_cents) }}</span>
                            </div>
                            <div v-else class="flex justify-between text-xs pt-1 border-t border-slate-800">
                                <span class="text-slate-500 italic">Nessuna Ritenuta</span>
                                <span class="text-slate-500">€ 0,00</span>
                            </div>

                            <div class="flex justify-between items-baseline pt-3 border-t border-slate-700">
                                <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Netto da pagare</span>
                                <span class="font-black text-2xl" :class="totali.netto_cents > 0 ? 'text-emerald-400' : 'text-white'">
                                    {{ euro(totali.netto_cents) }}
                                </span>
                            </div>
                        </div>

                        <Button type="button" :disabled="form.processing" @click="handleSubmit"
                            class="w-full h-12 font-black text-sm uppercase tracking-wider rounded-xl gap-2"
                            :class="transactionStatus === 'CRITICAL_BUDGET' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'">
                            <AlertOctagon v-if="transactionStatus === 'CRITICAL_BUDGET'" class="w-5 h-5" />
                            <Save v-else class="w-5 h-5" />
                            Salva Modifiche
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
                                            <!-- Nessun toggle "Fuori Preventivo" qui: in modifica le sopravvenienze non sono
                                                 accettate dal server (UpdateFatturaRequest le respinge con un 422 che questo
                                                 form non mostra). Il flusso corretto è storno + nuova registrazione. -->
                                            <div class="flex items-center justify-between mb-1.5 min-h-[28px]">
                                                <Label class="text-[10px] font-bold uppercase text-slate-400">Capitolo di spesa</Label>
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
                                        <div class="col-span-12 md:col-span-4 lg:col-span-4">
                                            <Input v-model="riga.descrizione"
                                                placeholder="Causale riga..."
                                                class="h-10 text-sm"
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
                                                placeholder="0,00" />
                                            <div v-if="rigaInSforo(riga)" class="flex items-center gap-1 mt-1 text-rose-500 absolute -bottom-5 right-0">
                                                <TrendingDown class="w-3 h-3" />
                                                <span class="text-[9px] font-black uppercase">Sforo budget</span>
                                            </div>
                                        </div>

                                        <!-- Aliquota IVA -->
                                        <div class="col-span-3 md:col-span-2 lg:col-span-2 relative">
                                            <div class="relative">
                                                <Input min="0" max="100" v-model="riga.aliquota_iva"
                                                    class="h-10 text-center pr-5 pl-1" />
                                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none font-bold">%</span>
                                            </div>
                                        </div>

                                        <!-- Totale riga + elimina -->
                                        <div class="col-span-5 md:col-span-3 lg:col-span-3 flex items-center justify-end gap-2 h-10">
                                            <div class="text-right min-w-0 flex-1">
                                                <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block leading-none mb-1 whitespace-nowrap">Totale Riga</span>
                                                <span class="font-black text-base text-slate-800 dark:text-slate-200 block leading-none whitespace-nowrap tabular-nums">
                                                    {{ euro(lordoRigaCents(riga.importo_imponibile, riga.aliquota_iva)) }}
                                                </span>
                                            </div>
                                            <Button variant="ghost" size="icon" type="button" @click="removeRiga(Number(idx))"
                                                class="h-10 w-10 shrink-0 text-slate-300 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/30 opacity-0 group-hover:opacity-100 transition-all rounded-lg border border-transparent hover:border-rose-100 ml-1">
                                                <Trash2 class="w-4 h-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    <label v-if="fornitoreRitenutaAttiva && applicaRitenutaEffective" class="flex items-center gap-1.5 cursor-pointer select-none w-fit">
                                        <input type="checkbox" v-model="riga.concorre_base_ritenuta"
                                            class="w-3.5 h-3.5 text-amber-600 rounded border-slate-300 focus:ring-amber-500 cursor-pointer" />
                                        <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400">
                                            Concorre alla base ritenuta
                                        </span>
                                    </label>
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
                                                Di cui <strong class="font-black text-amber-900 dark:text-amber-100">{{ euro(totali.imponibile_sopravvenienza_cents + totali.iva_sopravvenienza_cents) }}</strong><span class="opacity-80"> fuori preventivo</span>
                                            </div>
                                        </div>
                                    </Transition>
                                </div>

                                <div class="flex items-center gap-8 pr-2 mt-4 sm:mt-0">
                                    <div class="text-right">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest block mb-0.5">Imponibile</span>
                                        <span class="font-black text-slate-700 dark:text-slate-300 text-lg">{{ euro(totali.imponibile_cents) }}</span>
                                    </div>
                                    <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                                    <div class="text-right">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest block mb-0.5">IVA</span>
                                        <span class="font-black text-slate-700 dark:text-slate-300 text-lg">{{ euro(totali.iva_cents) }}</span>
                                    </div>
                                    <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                                    <div class="text-right">
                                        <span class="text-[10px] text-primary font-bold uppercase tracking-widest block mb-0.5">Totale Doc.</span>
                                        <span class="font-black text-primary text-xl">{{ euro(totali.totale_documento_cents) }}</span>
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
                                                <span class="text-rose-400">- {{ euro(totali.netto_cents) }}</span>
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

        <!-- Modale di presa d'atto sforo — non chiede una motivazione (che non verrebbe
             registrata): dichiara cosa NON accadrà e chiede una conferma consapevole -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
                <div v-if="showPresaAttoSforoModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                        <div class="bg-gradient-to-br from-rose-50 to-rose-100/50 dark:from-rose-950/40 dark:to-rose-900/20 px-8 pt-8 pb-6 text-center border-b border-rose-200 dark:border-rose-900/50">
                            <div class="w-16 h-16 bg-white dark:bg-slate-800 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg border border-rose-200 dark:border-rose-900/50">
                                <AlertOctagon class="w-8 h-8 text-rose-500" />
                            </div>
                            <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Salvataggio oltre budget</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                Questa modifica porta il capitolo di spesa oltre il budget di
                                <span class="font-black text-rose-600 dark:text-rose-400">{{ euro(sforoBudgetTotaleCents) }}</span>.
                            </p>
                        </div>

                        <div class="p-8 space-y-5">
                            <div class="flex items-start gap-3 bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-800/50 rounded-xl p-4">
                                <TriangleAlert class="w-4 h-4 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                                <p class="text-[11px] text-rose-700 dark:text-rose-400 leading-relaxed">
                                    In modifica lo sforo <strong>non può essere motivato</strong>: salvando non verrà
                                    registrata alcuna motivazione né copertura, la fattura resterà "approvata" e
                                    <strong>non comparirà nel cruscotto sforamenti</strong>. Per motivare lo sforo e
                                    pianificare una copertura occorre stornare la fattura e registrarla di nuovo.
                                </p>
                            </div>

                            <div class="flex flex-col gap-3">
                                <Button @click="confermaPresaAttoSforo" :disabled="form.processing"
                                    class="w-full h-12 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black uppercase tracking-widest text-[11px] shadow-lg shadow-rose-600/20">
                                    Prendo atto e salvo comunque
                                </Button>
                                <Button variant="ghost" @click="showPresaAttoSforoModal = false"
                                    class="w-full h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
                                    Annulla — torno a correggere
                                </Button>
                            </div>
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
</style>

<!-- `scoped` e' deliberato: `.style-chooser` e' usata anche da altre pagine, e un
     blocco non incapsulato verrebbe iniettato globalmente non appena questo
     componente viene importato, andando a toccarle. -->
<style scoped>
/* Le etichette dei capitoli qui sono lunghe (fornitore + descrizione dell'intervento)
   e sfondavano il bordo del campo, finendo sotto le icone di cancellazione e apertura.
   `min-width: 0` e' la chiave: senza, un figlio flex non puo' rimpicciolirsi sotto la
   propria larghezza naturale e `text-overflow: ellipsis` non ha alcun effetto.
   Nessuna metrica viene toccata: altezza, raggio e spaziature restano quelle di
   vue-select, identiche ai menu della colonna di sinistra. */
:deep(.style-chooser .vs__selected-options) {
    min-width: 0;
    overflow: hidden;
    flex-wrap: nowrap;
}

:deep(.style-chooser .vs__selected) {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Le icone non devono mai finire coperte dall'etichetta. */
:deep(.style-chooser .vs__actions) {
    flex: 0 0 auto;
}
</style>