<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Zap, Info, Wallet, Unlock, CheckCircle2, AlertTriangle, FileText } from 'lucide-vue-next';
import vSelect from 'vue-select';
import { useTabelle } from '@/composables/useTabelle';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { useFondiRiserva, type FondoRiserva } from '@/composables/useFondiRiserva';

const { euro } = useCurrencyFormatter();

const props = defineProps<{
    show: boolean;
    fornitoreNome: string;
    condominioId: number;
    fondiRiserva: FondoRiserva[];
    importoImprevisto: number; // in centesimi
    /** Coda 693 (c), decisione 533: la gestione della fattura, per dire in fondo alla finestra dove va la spesa. */
    gestione?: { nome: string; tipo: string } | null;
    /** Coda 693 (a): i dati confermati prima di un rifiuto del server, da cui la finestra riparte quando la pagina la riapre. */
    precedente?: Record<string, any> | null;
    /**
     * Coda 693, seconda passata: la fattura ha una riga addebitata a un'unità. Il fondo di riserva copre solo spese comuni (il server lo rifiuta,
     * `StoreFatturaRequest`): «Usa fondo riserva» si spegne prima, come nella finestra dello sforo (`ModalOverrideBudget`).
     */
    hasSpesePrivate?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
    (e: 'confirm', payload: any): void;
}>();

const { tabelle, isLoading: isLoadingTabelle, fetchTabelle } = useTabelle();
const fondiRiservaRef = computed(() => props.fondiRiserva ?? []);
const { fondiUtilizzabili } = useFondiRiserva(fondiRiservaRef);

// ---------------------------------------------------------------------------
// Stato interno
// ---------------------------------------------------------------------------
const data = ref({
    nome_voce: '',
    tabella_millesimale_id: null as number | null,
    percentuale_proprietario: 100,
    percentuale_inquilino: 0,
    percentuale_usufruttuario: 0,
    show_advanced_percentages: false,

    origine_decisionale: 'gestione_corrente' as 'gestione_corrente' | 'delibera_assembleare',
    data_assemblea: '',

    strategia_rientro: 'conguaglio_fine_anno' as 'conguaglio_fine_anno' | 'rata_integrativa' | 'fondo_riserva',
    fondo_patrimoniale_id: null as number | null,
    motivazione_urgenza: '',
});

// Opzioni compatte per la select Origine Incarico
const opzioniOrigine = [
    { id: 'gestione_corrente', label: "Intervento d'urgenza (art. 1135 c.c.)" },
    { id: 'delibera_assembleare', label: "Delibera assembleare" }
];

const onDropdownTabelleOpen = () => {
    if (tabelle.value.length === 0) fetchTabelle(props.condominioId);
};

const fondoSelezionato = computed(() => {
    if (!data.value.fondo_patrimoniale_id) return null;
    return props.fondiRiserva.find(f => f.id === data.value.fondo_patrimoniale_id) ?? null;
});

const percentualiValide = computed(() => {
    const p = Number(data.value.percentuale_proprietario);
    const i = Number(data.value.percentuale_inquilino);
    const u = Number(data.value.percentuale_usufruttuario);
    return (p + i + u) === 100 && [p, i, u].every(v => v >= 0 && v <= 100);
});

/** L'intervento d'urgenza (art. 1135 c.c.): la pagina lo registra come sforo da ratificare (`is_ordinario` nei dati emessi, il nome di sempre). */
const isUrgenza = computed(() => data.value.origine_decisionale === 'gestione_corrente');

/**
 * Coda 693 (c), decisione 533: in fondo alla finestra la gestione della fattura e che cosa succede, al posto di «Destinazione: Piano ORDINARIO/STRAORDINARIO».
 * La voce nasce sempre nella gestione della fattura, con l'urgenza come con la delibera (`FatturaPassivaService::creaContoDinamicoSopravvenienza`), e si
 * mette a rate dalla scheda «Spese fuori preventivo» del nuovo piano rate su qualunque gestione (decisione 510): «ordinario» per una spesa che si mette a
 * rate da un'altra parte era sbagliato. Un nome che comincia già con «Gestione» (quello che il programma dà alla prima, «Gestione ordinaria») si scrive com'è.
 */
const etichettaGestione = computed(() => {
    const nome = (props.gestione?.nome ?? '').trim();
    if (!nome) return 'La gestione della fattura';
    if (/^gestione\b/i.test(nome)) return nome;

    return `Gestione ${props.gestione?.tipo === 'straordinaria' ? 'straordinaria' : 'ordinaria'} «${nome}»`;
});

const cheCosaSuccede = computed(() => {
    switch (data.value.strategia_rientro) {
        case 'fondo_riserva':
            return fondoSelezionato.value ? `coperta dal fondo «${fondoSelezionato.value.nome}»` : 'coperta dal fondo che scegli qui sopra';
        case 'rata_integrativa':
            return 'si mette a rate con «Spese fuori preventivo» nel nuovo piano rate';
        default:
            return 'resta nel conguaglio del rendiconto';
    }
});

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------
watch(() => props.show, (isOpen) => {
    if (isOpen && props.precedente) {
        // Coda 693 (a): riaperta dopo un rifiuto del server, la finestra riparte da ciò che si era scritto, non da capo.
        const p = props.precedente;
        data.value = {
            nome_voce: String(p.nome_voce ?? `Imprevisto - ${props.fornitoreNome}`),
            tabella_millesimale_id: p.tabella_millesimale_id ?? null,
            percentuale_proprietario: Number(p.percentuale_proprietario ?? 100),
            percentuale_inquilino: Number(p.percentuale_inquilino ?? 0),
            percentuale_usufruttuario: Number(p.percentuale_usufruttuario ?? 0),
            show_advanced_percentages: Boolean(p.show_advanced_percentages),
            origine_decisionale: p.origine_decisionale === 'delibera_assembleare' ? 'delibera_assembleare' : 'gestione_corrente',
            data_assemblea: String(p.data_assemblea ?? ''),
            strategia_rientro: ['conguaglio_fine_anno', 'rata_integrativa', 'fondo_riserva'].includes(p.strategia_rientro) ? p.strategia_rientro : 'conguaglio_fine_anno',
            fondo_patrimoniale_id: p.fondo_patrimoniale_id ?? null,
            motivazione_urgenza: String(p.motivazione_urgenza ?? ''),
        };
        lasciaIlFondoSeNonSiPuo();
        return;
    }
    if (isOpen) {
        data.value = {
            nome_voce: `Imprevisto - ${props.fornitoreNome}`,
            tabella_millesimale_id: null,
            percentuale_proprietario: 100,
            percentuale_inquilino: 0,
            percentuale_usufruttuario: 0,
            show_advanced_percentages: false,
            origine_decisionale: 'gestione_corrente',
            data_assemblea: '',
            strategia_rientro: 'conguaglio_fine_anno',
            fondo_patrimoniale_id: null,
            motivazione_urgenza: '',
        };
    }
});

watch(() => data.value.strategia_rientro, (newVal) => {
    if (newVal !== 'fondo_riserva') {
        data.value.fondo_patrimoniale_id = null;
    }
});

/** Con una riga di un'unità il fondo non si usa: la strategia torna ad «Attesa conguaglio», come fa la finestra dello sforo. */
function lasciaIlFondoSeNonSiPuo() {
    if (props.hasSpesePrivate && data.value.strategia_rientro === 'fondo_riserva') {
        data.value.strategia_rientro = 'conguaglio_fine_anno';
        data.value.fondo_patrimoniale_id = null;
    }
}

watch(() => props.hasSpesePrivate, lasciaIlFondoSeNonSiPuo);

// ---------------------------------------------------------------------------
// Validazione & Submit
// ---------------------------------------------------------------------------
const isValid = computed(() => {
    if (data.value.nome_voce.length < 5) return false;
    if (!data.value.tabella_millesimale_id) return false;
    if (!percentualiValide.value) return false;

    // Coda 693 (a), decisione 533: il giustificativo dell'urgenza è obbligatorio con ogni strategia, anche con il fondo. Prima con il fondo si nascondeva, la
    // pagina mandava la motivazione vuota e il server rifiutava la fattura («La motivazione è obbligatoria quando si supera il budget.», prova E1).
    // Dieci caratteri, come la regola della motivazione dello sforo (`StoreFatturaRequest`).
    if (isUrgenza.value && data.value.motivazione_urgenza.trim().length < 10) return false;

    if (data.value.strategia_rientro === 'fondo_riserva') {
        if (props.hasSpesePrivate) return false;
        if (!data.value.fondo_patrimoniale_id) return false;
        if (fondoSelezionato.value && fondoSelezionato.value.saldo_attuale < props.importoImprevisto) return false;
    }

    return true;
});

const handleCancel = () => emit('update:show', false);

const handleConfirm = () => {
    if (!isValid.value) return;

    // La motivazione che parte è quella scritta nel giustificativo, e solo con l'urgenza: con la delibera il giustificativo non si vede, e un testo scritto
    // prima di cambiare l'origine non deve finire nella voce né nello sforo (Coda 693 (a)).
    const motivazione = isUrgenza.value ? data.value.motivazione_urgenza.trim() : '';

    emit('confirm', {
        ...data.value,
        motivazione_urgenza: motivazione,
        tipo_ripartizione: 'millesimale',
        is_ordinario: isUrgenza.value,
        richiede_copertura: true,
        motivazione_sforo: motivazione,
    });
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl flex flex-col max-h-[90vh] overflow-hidden border border-slate-200 dark:border-slate-800">

                <div class="bg-amber-50 dark:bg-amber-950/30 p-7 border-b border-amber-100 dark:border-amber-900/30 flex items-start gap-4 shrink-0">
                    <div class="bg-amber-100 dark:bg-amber-900/50 p-2.5 rounded-xl shrink-0">
                        <Zap class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div>
                        <h3 class="font-black text-amber-900 dark:text-amber-100 text-lg">Configura nuova voce di spesa</h3>
                        <p class="text-xs text-amber-700/70 dark:text-amber-300/80 mt-1">
                            Valore della parte comune da ripartire: <span class="font-black">{{ euro(importoImprevisto) }}</span>
                        </p>
                    </div>
                </div>

                <div class="p-7 space-y-8 overflow-y-auto flex-1 custom-scrollbar">

                    <div class="space-y-4">
                        <!-- Coda 693, seconda passata: le stesse parole, in testo normale e almeno a 12 px (gli standard delle pagine). -->
                        <h4 class="text-sm font-bold text-slate-600 dark:text-slate-300 flex items-center gap-2">
                            <Wallet class="w-4 h-4 text-slate-500 dark:text-slate-400" /> 1. Strategia di copertura per {{ euro(importoImprevisto) }} *
                        </h4>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                                :class="data.strategia_rientro === 'conguaglio_fine_anno'
                                    ? 'bg-blue-50 dark:bg-blue-950/40 border-blue-400 dark:border-blue-500 shadow-md ring-1 ring-blue-400'
                                    : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm" :class="data.strategia_rientro === 'conguaglio_fine_anno' ? 'text-blue-800 dark:text-blue-200' : 'text-slate-700 dark:text-slate-200'">Attesa conguaglio</div>
                                    <input type="radio" v-model="data.strategia_rientro" value="conguaglio_fine_anno" class="w-4 h-4 mt-0.5 text-blue-600 dark:text-blue-400 focus:ring-blue-600 shrink-0" />
                                </div>
                                <p class="text-xs leading-relaxed flex-1" :class="data.strategia_rientro === 'conguaglio_fine_anno' ? 'text-blue-700/80 dark:text-blue-200/80' : 'text-slate-500 dark:text-slate-400'">Registra la spesa senza una rata. Entra nel conguaglio per persona, calcolato nel rendiconto, se la voce ha una tabella (in una gestione straordinaria, quando l'opera è finita): è una proposta per l'assemblea, il programma non la chiede da solo.</p>
                            </label>

                            <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                                :class="data.strategia_rientro === 'rata_integrativa'
                                    ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-400 dark:border-amber-500 shadow-md ring-1 ring-amber-400'
                                    : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm" :class="data.strategia_rientro === 'rata_integrativa' ? 'text-amber-800 dark:text-amber-200' : 'text-slate-700 dark:text-slate-200'">
                                        {{ data.origine_decisionale === 'gestione_corrente' ? 'Rata integrativa' : 'Piano «Spese fuori preventivo»' }}
                                    </div>
                                    <input type="radio" v-model="data.strategia_rientro" value="rata_integrativa" class="w-4 h-4 mt-0.5 text-amber-600 dark:text-amber-400 focus:ring-amber-600 shrink-0" />
                                </div>
                                <p class="text-xs leading-relaxed flex-1" :class="data.strategia_rientro === 'rata_integrativa' ? 'text-amber-700/80 dark:text-amber-200/80' : 'text-slate-500 dark:text-slate-400'">
                                    Lascia un allarme in Dashboard per ricordarti di emettere un nuovo piano rate dedicato a questa voce.
                                </p>
                            </label>

                            <!-- Coda 693, seconda passata: con una riga addebitata a un'unità il fondo è spento, con la frase del perché. -->
                            <label class="flex flex-col p-5 rounded-xl border transition-all relative h-full"
                                :class="props.hasSpesePrivate
                                    ? 'cursor-not-allowed opacity-60 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60'
                                    : data.strategia_rientro === 'fondo_riserva'
                                        ? 'cursor-pointer bg-emerald-50 dark:bg-emerald-950/40 border-emerald-400 dark:border-emerald-500 shadow-md ring-1 ring-emerald-400'
                                        : 'cursor-pointer border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm" :class="data.strategia_rientro === 'fondo_riserva' ? 'text-emerald-800 dark:text-emerald-200' : 'text-slate-700 dark:text-slate-200'">Usa fondo riserva</div>
                                    <input type="radio" v-model="data.strategia_rientro" value="fondo_riserva" :disabled="props.hasSpesePrivate" class="w-4 h-4 mt-0.5 text-emerald-600 dark:text-emerald-400 focus:ring-emerald-600 shrink-0" />
                                </div>
                                <p class="text-xs leading-relaxed flex-1" :class="data.strategia_rientro === 'fondo_riserva' ? 'text-emerald-700/80 dark:text-emerald-200/80' : 'text-slate-500 dark:text-slate-400'">
                                    Neutralizza l'impatto sul bilancio attingendo direttamente da un portafoglio patrimoniale preesistente.
                                </p>
                                <p v-if="props.hasSpesePrivate" class="mt-2 text-xs font-semibold text-rose-700 dark:text-rose-300">
                                    Non disponibile: la fattura ha una riga addebitata a un'unità, e il fondo di riserva copre solo spese comuni.
                                </p>
                            </label>
                        </div>

                        <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 -translate-y-4" enter-to-class="opacity-100 translate-y-0">
                            <div v-if="data.strategia_rientro === 'fondo_riserva'" >
                                <Label class="text-xs font-bold text-slate-700 dark:text-slate-200 mb-2 block">Seleziona il fondo da utilizzare *</Label>
                                <v-select
                                    v-model="data.fondo_patrimoniale_id"
                                    :options="fondiUtilizzabili"
                                    label="nome"
                                    :reduce="(f: FondoRiserva) => f.id"
                                    placeholder="Cerca il fondo contabile sbloccato..."
                                    append-to-body>
                                    <template #option="{ nome, saldo_attuale, is_override_assemblea }">
                                        <div class="flex justify-between items-center py-1">
                                            <div class="flex flex-col">
                                                <span class="font-bold text-sm">{{ nome }}</span>
                                                <div class="mt-1">
                                                    <span v-if="is_override_assemblea" class="inline-flex items-center gap-1 text-xs font-semibold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-950/40 px-1.5 py-0.5 rounded-md"><Unlock class="w-3 h-3" /> Sbloccato (deroga)</span>
                                                    <span v-else class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 px-1.5 py-0.5 rounded-md"><CheckCircle2 class="w-3 h-3" /> Libero</span>
                                                </div>
                                            </div>
                                            <span class="text-xs font-bold px-2 py-1 rounded-md border text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800 shadow-sm ml-4">
                                                {{ euro(saldo_attuale) }}
                                            </span>
                                        </div>
                                    </template>
                                </v-select>

                                <div v-if="fondoSelezionato && fondoSelezionato.saldo_attuale < props.importoImprevisto" class="mt-2 flex items-center gap-1.5 text-xs font-bold text-rose-600 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/40 px-3 py-2 rounded-lg border border-rose-200 dark:border-rose-800">
                                    <AlertTriangle class="w-3.5 h-3.5" />
                                    <span>Il fondo selezionato non ha capienza sufficiente per coprire l'importo.</span>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <hr class="border-slate-100 dark:border-slate-800">

                    <div class="space-y-4">
                        <h4 class="text-sm font-bold text-slate-600 dark:text-slate-300 flex items-center gap-2">
                            <FileText class="w-4 h-4 text-slate-500 dark:text-slate-400" /> 2. Dati contabili e legali
                        </h4>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-4">
                                <div class="space-y-1.5">
                                    <Label class="text-xs font-bold text-slate-700 dark:text-slate-200">Nome voce di spesa *</Label>
                                    <Input v-model="data.nome_voce" class="h-10 text-sm font-semibold border-slate-300 dark:border-slate-700" />
                                </div>

                                <div class="space-y-1.5 relative">
                                    <Label class="text-xs font-bold text-slate-700 dark:text-slate-200">Tabella di riparto *</Label>
                                    <v-select
                                        :options="tabelle"
                                        label="nome"
                                        v-model="data.tabella_millesimale_id"
                                        :reduce="(t: any) => t.id"
                                        @open="onDropdownTabelleOpen"
                                        :loading="isLoadingTabelle"
                                        class="bg-white dark:bg-slate-800"
                                        placeholder="Seleziona la tabella..." />
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="space-y-1.5 relative">
                                    <Label class="text-xs font-bold text-slate-700 dark:text-slate-200">Origine dell'incarico *</Label>
                                    <v-select
                                        :options="opzioniOrigine"
                                        label="label"
                                        v-model="data.origine_decisionale"
                                        :reduce="(o: any) => o.id"
                                        :clearable="false"
                                        class="bg-white dark:bg-slate-800 font-semibold" />
                                </div>

                                <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2 h-0" enter-to-class="opacity-100 translate-y-0 h-auto">
                                    <div v-if="data.origine_decisionale === 'delibera_assembleare'" class="space-y-1.5 pt-1">
                                        <Label class="text-xs font-bold text-slate-700 dark:text-slate-200 flex justify-between">
                                            Data assemblea <span class="text-slate-500 dark:text-slate-400 font-normal">(raccomandata)</span>
                                        </Label>
                                        <Input type="date" v-model="data.data_assemblea" class="h-10 text-sm" />
                                    </div>
                                </Transition>
                            </div>
                        </div>

                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-100 dark:border-slate-800 mt-2">
                            <button v-if="!data.show_advanced_percentages" type="button" @click="data.show_advanced_percentages = true" class="text-xs font-bold text-blue-600 dark:text-blue-400 hover:underline">
                                Personalizza percentuali inquilino/usufruttuario (default: 100% proprietario)
                            </button>
                            <div v-else class="grid grid-cols-3 gap-4">
                                <div v-if="!percentualiValide" class="col-span-3 flex items-center gap-1.5 text-xs font-bold text-rose-600 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/40 px-2 py-1.5 rounded-md">
                                    <AlertTriangle class="w-3 h-3" /> La somma tra proprietario, inquilino e usufruttuario deve essere 100%
                                </div>
                                <div><Label class="text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">Proprietario %</Label><Input type="number" min="0" max="100" v-model="data.percentuale_proprietario" class="h-9 bg-white dark:bg-slate-900" /></div>
                                <div><Label class="text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">Inquilino %</Label><Input type="number" min="0" max="100" v-model="data.percentuale_inquilino" class="h-9 bg-white dark:bg-slate-900" /></div>
                                <div><Label class="text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">Usufruttuario %</Label><Input type="number" min="0" max="100" v-model="data.percentuale_usufruttuario" class="h-9 bg-white dark:bg-slate-900" /></div>
                            </div>
                        </div>

                        <!-- Coda 693 (a), decisione 533: con l'urgenza il giustificativo c'è con ogni strategia, anche con il fondo. -->
                        <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 h-0" enter-to-class="opacity-100 h-auto">
                            <div v-if="isUrgenza" class="mt-4">
                                <Label for="giustificativo-urgenza" class="text-xs font-bold text-slate-700 dark:text-slate-200 mb-2 block">Giustificativo dell'urgenza (art. 1135 c.c.) *</Label>
                                <textarea id="giustificativo-urgenza" v-model="data.motivazione_urgenza" rows="2"
                                    class="w-full border border-slate-200 dark:border-slate-700 rounded-xl p-3 text-sm bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-slate-100 outline-none resize-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-400 transition-all"
                                    placeholder="Es: Rottura improvvisa colonna di scarico, riparazione urgente per evitare danni a terzi..." />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    Serve anche quando la spesa la copre il fondo: è la ragione dell'urgenza, da riferire alla prossima assemblea.
                                </p>
                            </div>
                        </Transition>

                    </div>
                </div>

                <div class="p-6 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 shrink-0 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <!-- Coda 693 (c), decisione 533: la gestione della fattura e che cosa succede alla spesa, non «Destinazione: Piano ORDINARIO». -->
                    <div class="flex items-start gap-2 px-2 min-w-0">
                        <Info class="w-4 h-4 mt-0.5 shrink-0 text-slate-400 dark:text-slate-500" />
                        <span data-test="gestione-della-spesa" class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">{{ etichettaGestione }} · {{ cheCosaSuccede }}</span>
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto shrink-0">
                        <Button variant="outline" class="h-11 rounded-xl font-bold px-8" @click="handleCancel">Annulla</Button>
                        <Button
                            class="h-11 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-black disabled:opacity-50 px-8"
                            :disabled="!isValid"
                            @click="handleConfirm"
                        >
                            Conferma voce
                        </Button>
                    </div>
                </div>

            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
</style>