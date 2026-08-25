<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { ShieldAlert, TriangleAlert, Unlock, CheckCircle2, Wallet } from 'lucide-vue-next';
import vSelect from 'vue-select';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { useFondiRiserva, type FondoRiserva } from '@/composables/useFondiRiserva';

interface VoceInSforo {
    id: number;
    nome: string;
    sforoLordoCents: number;
    giaVersatoCents: number;
    residuoNettoStimatoCents: number;
}

const { euro } = useCurrencyFormatter();

const props = defineProps<{
    show: boolean;
    sforoTotale: number;
    vociInSforo: VoceInSforo[];
    hasSpesePrivate: boolean;
    fondiRiserva: FondoRiserva[];
    isProcessing: boolean;
}>();

// Voci con già-versato attivo: solo per queste la rata integrativa chiede il
// residuo netto invece dell'intero sforo lordo (CalcoloQuoteService::
// guardiaSovraFinanziamentoGiaVersato) — vale la pena spiegarlo solo qui.
const vociConGiaVersato = computed(() => props.vociInSforo.filter(v => v.giaVersatoCents > 0));

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
    (e: 'confirm', payload: { strategia: 'conguaglio_fine_anno' | 'rata_integrativa' | 'fondo_riserva', fondoId: number | null, motivazione: string }): void;
}>();

// ---------------------------------------------------------------------------
// Stato interno
// ---------------------------------------------------------------------------
const overrideStrategia = ref<'conguaglio_fine_anno' | 'rata_integrativa' | 'fondo_riserva'>('conguaglio_fine_anno');
const overrideFondoId   = ref<number | null>(null);
const overrideMotivazione = ref('');

// ---------------------------------------------------------------------------
// Fondi utilizzabili — logica condivisa via composable
// ---------------------------------------------------------------------------
const fondiRiservaRef = computed(() => props.fondiRiserva ?? []);
const { fondiUtilizzabili } = useFondiRiserva(fondiRiservaRef);

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------

// Reset dello stato quando la modale si apre
watch(() => props.show, (isOpen) => {
    if (isOpen) {
        overrideStrategia.value = 'conguaglio_fine_anno';
        overrideFondoId.value   = null;
        overrideMotivazione.value = '';
    }
});

// Se in background compaiono spese private, il fondo riserva non è più disponibile
watch(() => props.hasSpesePrivate, (newVal) => {
    if (newVal && overrideStrategia.value === 'fondo_riserva') {
        overrideStrategia.value = 'conguaglio_fine_anno';
        overrideFondoId.value   = null;
    }
});

// ---------------------------------------------------------------------------
// Computed
// ---------------------------------------------------------------------------
const isValid = computed(() => {
    if (overrideMotivazione.value.length < 10) return false;
    if (overrideStrategia.value === 'fondo_riserva' && !overrideFondoId.value) return false;
    return true;
});

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------
const handleCancel  = () => emit('update:show', false);

const handleConfirm = () => {
    if (!isValid.value) return;
    emit('confirm', {
        strategia:   overrideStrategia.value,
        fondoId:     overrideStrategia.value === 'fondo_riserva' ? overrideFondoId.value : null,
        motivazione: overrideMotivazione.value,
    });
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden border border-slate-200 dark:border-slate-800">

                <!-- Header -->
                <div class="bg-rose-50 dark:bg-rose-950/30 p-7 border-b border-rose-100 dark:border-rose-900/30 flex items-start gap-4">
                    <div class="bg-rose-100 dark:bg-rose-900/50 p-2.5 rounded-xl shrink-0">
                        <ShieldAlert class="w-6 h-6 text-rose-600" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="font-black text-rose-900 dark:text-rose-100 text-lg">Sforamento budget rilevato</h3>
                        <p class="text-xs text-rose-700/70 mt-1">
                            Eccesso complessivo: <span class="font-black">{{ euro(sforoTotale) }}</span>
                        </p>
                        <!-- Dettaglio per voce: una fattura può toccare più capitoli, mostriamo
                             ciascuno invece di nascondere il quadro dietro un unico numero. -->
                        <ul v-if="vociInSforo.length > 1" class="mt-2 space-y-0.5">
                            <li v-for="v in vociInSforo" :key="v.id"
                                class="text-[11px] text-rose-700/80 flex items-center justify-between gap-3">
                                <span class="truncate">{{ v.nome }}</span>
                                <span class="font-bold shrink-0">{{ euro(v.sforoLordoCents) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="p-7 space-y-6">

                    <!-- Strategia di rientro -->
                    <div class="space-y-3">
                        <h4 class="text-[10px] font-black uppercase text-slate-500 tracking-widest flex items-center gap-2">
                            <TriangleAlert class="w-3 h-3" /> Scegli la strategia di rientro *
                        </h4>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                            <!-- 1. Conguaglio -->
                            <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                                :class="overrideStrategia === 'conguaglio_fine_anno'
                                    ? 'bg-blue-50 border-blue-400 dark:bg-blue-900/20 dark:border-blue-600 shadow-md ring-1 ring-blue-400'
                                    : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm"
                                        :class="overrideStrategia === 'conguaglio_fine_anno' ? 'text-blue-800 dark:text-blue-300' : 'text-slate-700 dark:text-slate-300'">
                                        1. Attesa conguaglio
                                    </div>
                                    <input type="radio" v-model="overrideStrategia" value="conguaglio_fine_anno"
                                        class="w-4 h-4 mt-0.5 text-blue-600 border-slate-300 focus:ring-blue-600 shrink-0" />
                                </div>
                                <p class="text-[11px] leading-relaxed flex-1"
                                    :class="overrideStrategia === 'conguaglio_fine_anno' ? 'text-blue-700/80 dark:text-blue-400/80' : 'text-slate-500'">
                                    Registra lo sforo. Il sistema non creerà allarmi e chiederà i soldi mancanti ai condòmini automaticamente a chiusura esercizio.
                                </p>
                            </label>

                            <!-- 2. Rata integrativa -->
                            <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                                :class="overrideStrategia === 'rata_integrativa'
                                    ? 'bg-amber-50 border-amber-400 dark:bg-amber-900/20 dark:border-amber-600 shadow-md ring-1 ring-amber-400'
                                    : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm"
                                        :class="overrideStrategia === 'rata_integrativa' ? 'text-amber-800 dark:text-amber-300' : 'text-slate-700 dark:text-slate-300'">
                                        2. Rata Integrativa
                                    </div>
                                    <input type="radio" v-model="overrideStrategia" value="rata_integrativa"
                                        class="w-4 h-4 mt-0.5 text-amber-600 border-slate-300 focus:ring-amber-600 shrink-0" />
                                </div>
                                <p class="text-[11px] leading-relaxed flex-1"
                                    :class="overrideStrategia === 'rata_integrativa' ? 'text-amber-700/80 dark:text-amber-400/80' : 'text-slate-500'">
                                    L'allarme rimarrà acceso in dashboard per ricordarti di emettere in seguito un Piano Rate ordinario integrativo.
                                </p>
                            </label>

                            <!-- 3. Fondo riserva -->
                            <label class="flex flex-col p-5 rounded-xl border transition-all relative h-full"
                                :class="[
                                    hasSpesePrivate
                                        ? 'opacity-50 cursor-not-allowed bg-slate-50 border-slate-200 dark:bg-slate-900 dark:border-slate-800'
                                        : 'cursor-pointer',
                                    !hasSpesePrivate && overrideStrategia === 'fondo_riserva'
                                        ? 'bg-emerald-50 border-emerald-400 dark:bg-emerald-900/20 dark:border-emerald-600 shadow-md ring-1 ring-emerald-400'
                                        : '',
                                    !hasSpesePrivate && overrideStrategia !== 'fondo_riserva'
                                        ? 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'
                                        : '',
                                ]">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm"
                                        :class="overrideStrategia === 'fondo_riserva' && !hasSpesePrivate ? 'text-emerald-800 dark:text-emerald-300' : 'text-slate-700 dark:text-slate-300'">
                                        3. Fondo di riserva
                                    </div>
                                    <input type="radio" v-model="overrideStrategia" value="fondo_riserva"
                                        :disabled="hasSpesePrivate"
                                        class="w-4 h-4 mt-0.5 text-emerald-600 border-slate-300 focus:ring-emerald-600 shrink-0 disabled:opacity-50" />
                                </div>
                                <p class="text-[11px] leading-relaxed flex-1"
                                    :class="overrideStrategia === 'fondo_riserva' && !hasSpesePrivate ? 'text-emerald-700/80 dark:text-emerald-400/80' : 'text-slate-500'">
                                    <span v-if="hasSpesePrivate" class="font-bold text-rose-500 block mb-1">
                                        Bloccato: presenti spese private (ad personam). Non è possibile usare il fondo riserva del condominio.
                                    </span>
                                    <span v-else>
                                        Pianifica la copertura dal fondo. Il fondo si decurta solo alla conferma,
                                        con un giroconto che troverai proposto sulla fattura e nella pagina Giroconti.
                                    </span>
                                </p>
                            </label>
                        </div>
                    </div>

                    <!-- Già-versato: la rata integrativa chiederà il netto, non il lordo -->
                    <Transition
                        enter-active-class="transition-all duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-4"
                        enter-to-class="opacity-100 translate-y-0">
                        <div v-if="overrideStrategia === 'rata_integrativa' && vociConGiaVersato.length > 0"
                            class="bg-indigo-50 dark:bg-indigo-950/20 border border-indigo-200 dark:border-indigo-900/40 rounded-xl p-4 space-y-2.5">
                            <div class="flex items-start gap-2.5">
                                <Wallet class="w-4 h-4 text-indigo-600 shrink-0 mt-0.5" />
                                <p class="text-[11px] text-indigo-800 dark:text-indigo-300 leading-relaxed">
                                    <span v-if="vociConGiaVersato.length === 1">Questa voce ha</span>
                                    <span v-else>Queste voci hanno</span>
                                    già-versato registrato: il piano rate integrativo terrà conto di quanto già
                                    contribuito dai condòmini e chiederà solo il residuo netto — non l'intero sforo
                                    lordo mostrato sopra.
                                </p>
                            </div>
                            <div class="space-y-1.5 pl-6">
                                <div v-for="v in vociConGiaVersato" :key="v.id"
                                    class="flex items-center justify-between gap-3 text-[11px]">
                                    <span class="text-indigo-700/80 dark:text-indigo-400/80 truncate">{{ v.nome }}</span>
                                    <span class="shrink-0 tabular-nums">
                                        <span class="text-indigo-500/70 line-through mr-1.5">{{ euro(v.sforoLordoCents) }}</span>
                                        <span class="font-black text-indigo-800 dark:text-indigo-200">{{ euro(v.residuoNettoStimatoCents) }}</span>
                                    </span>
                                </div>
                            </div>
                            <p class="text-[10px] text-indigo-500/70 pl-6 leading-relaxed">
                                Stima indicativa (presume che questa fattura sia il costo totale reale della voce):
                                l'importo definitivo lo calcola il motore di riparto quando generi il piano rate.
                            </p>
                        </div>
                    </Transition>

                    <!-- Citazione legale -->
                    <p class="text-[11px] text-slate-500 leading-relaxed italic border-l-4 border-rose-200 pl-4">
                        "L'amministratore non può ordinare lavori di manutenzione straordinaria, salvo carattere urgente..." — Art. 1135 c.c.
                    </p>

                    <!-- Selezione fondo -->
                    <Transition
                        enter-active-class="transition-all duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-4"
                        enter-to-class="opacity-100 translate-y-0">
                        <div v-if="overrideStrategia === 'fondo_riserva'">
                            <Label class="text-[10px] font-black uppercase tracking-widest mb-2 block">
                                Seleziona il fondo da utilizzare *
                            </Label>
                            <v-select
                                v-model="overrideFondoId"
                                :options="fondiUtilizzabili"
                                label="nome"
                                :reduce="(f: FondoRiserva) => f.id"
                                placeholder="Cerca il fondo contabile..."
                                append-to-body>
                                <template #option="{ nome, saldo_attuale, is_override_assemblea }">
                                    <div class="flex justify-between items-center py-1">
                                        <div class="flex flex-col">
                                            <span class="font-bold text-sm">{{ nome }}</span>
                                            <div class="mt-1">
                                                <span v-if="is_override_assemblea"
                                                    class="inline-flex items-center gap-1 text-[9px] font-bold text-purple-600 bg-purple-50 px-1.5 py-0.5 rounded uppercase tracking-wide">
                                                    <Unlock class="w-3 h-3" /> Sbloccato (deroga)
                                                </span>
                                                <span v-else
                                                    class="inline-flex items-center gap-1 text-[9px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded uppercase tracking-wide">
                                                    <CheckCircle2 class="w-3 h-3" /> Libero
                                                </span>
                                            </div>
                                        </div>
                                        <span class="text-[10px] font-bold px-2 py-1 rounded-md border text-emerald-700 bg-emerald-50 border-emerald-200 shadow-sm ml-4">
                                            {{ euro(saldo_attuale) }}
                                        </span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome }">
                                    <div class="flex justify-between items-center w-full pr-2">
                                        <span class="font-bold text-sm">{{ nome }}</span>
                                    </div>
                                </template>
                            </v-select>
                        </div>
                    </Transition>

                    <!-- Motivazione -->
                    <div>
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2 block">
                            Motivazione dell'urgenza *
                        </Label>
                        <textarea
                            v-model="overrideMotivazione"
                            rows="3"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-xl p-4 text-sm bg-slate-50 dark:bg-slate-800 outline-none resize-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-400 transition-all"
                            placeholder="Es: Rottura improvvisa tubazione, necessaria riparazione immediata per evitare danni maggiori..." />
                        <div class="flex justify-between mt-1 text-[10px]">
                            <span class="text-slate-400">Minimo 10 caratteri</span>
                            <span :class="overrideMotivazione.length >= 10 ? 'text-emerald-500' : 'text-rose-500'">
                                {{ overrideMotivazione.length }}
                            </span>
                        </div>
                    </div>

                    <!-- Azioni -->
                    <div class="flex gap-3">
                        <Button variant="outline" class="flex-1 h-11 rounded-xl font-bold" @click="handleCancel">
                            Annulla
                        </Button>
                        <Button
                            class="flex-1 h-11 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black disabled:opacity-50"
                            :disabled="!isValid || isProcessing"
                            @click="handleConfirm">
                            {{ isProcessing ? 'Registrazione in corso...' : 'Conferma e registra' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>