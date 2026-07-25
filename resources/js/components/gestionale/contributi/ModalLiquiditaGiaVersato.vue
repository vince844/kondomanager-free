<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Wallet, HelpCircle, PiggyBank, ReceiptText, CheckCircle2 } from 'lucide-vue-next';
import vSelect from 'vue-select';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';

interface CassaOpzione {
    id: number;
    nome: string;
    tipo: string;
    saldo_cents: number;
    is_utilizzabile_per_imprevisti: boolean;
}

const { euro } = useCurrencyFormatter({ fromCents: true });

const props = defineProps<{
    show: boolean;
    totaleGiaVersato: number; // centesimi
    casse: CassaOpzione[];
    natura: 'fondo_vincolato' | 'avanzo';
    isProcessing: boolean;
}>();

// Un fondo deliberato (vincolo di destinazione ex art. 1135 c.c.) non può
// finire su una cassa liberamente prelevabile per imprevisti: diventerebbe
// disponibile per QUALUNQUE sforo futuro su un'altra voce, aggirando il
// vincolo. Le nascondiamo del tutto dalla scelta (non solo un avviso): niente
// deroga qui, a differenza del fondo di riserva sullo sforo — non c'è urgenza
// operativa che la giustifichi. Stessa regola verificata anche lato server.
const casseSelezionabili = computed(() =>
    props.natura === 'fondo_vincolato'
        ? props.casse.filter(c => !c.is_utilizzabile_per_imprevisti)
        : props.casse
);

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
    (e: 'confirm', payload: { liquiditaStato: 'registrata_in_cassa' | 'gia_speso_acconto', cassaId: number | null, notaAcconto: string }): void;
}>();

const etichettaTipo: Record<string, string> = { banca: 'Banca', cassa_contanti: 'Contanti', fondo: 'Fondo' };

// vue-select calcola la posizione del dropdown (append-to-body) assumendo che il
// toggle sia nel flusso normale del documento, e aggiunge window.scrollY alla sua
// posizione. Questa modale è `position: fixed`: le coordinate del toggle sono già
// relative al viewport e NON cambiano con lo scroll dello sfondo — sommarci scrollY
// spinge il menu in basso di tutto l'offset di scroll, fuori dallo schermo (verificato:
// con la pagina sotto scrollata a fondo modulo, il dropdown finiva a 823px di top su un
// viewport di 767px). Fix: posizione `fixed` con le coordinate pure del toggle.
const posizionaDropdownCassa = (dropdownList: HTMLElement, component: any, { width }: { width: string }) => {
    dropdownList.style.width = width;
    dropdownList.style.position = 'fixed';
    const rect = component.$refs.toggle.getBoundingClientRect();
    dropdownList.style.left = `${rect.left}px`;
    dropdownList.style.top = `${rect.bottom}px`;
};

const scenario = ref<'registrata_in_cassa' | 'gia_speso_acconto'>('registrata_in_cassa');
const cassaId = ref<number | null>(null);
const notaAcconto = ref('');

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        scenario.value = 'registrata_in_cassa';
        cassaId.value = null;
        notaAcconto.value = '';
    }
});

const isValid = computed(() => {
    if (scenario.value === 'registrata_in_cassa') return !!cassaId.value;
    return notaAcconto.value.trim().length >= 10;
});

const handleCancel = () => emit('update:show', false);

const handleConfirm = () => {
    if (!isValid.value) return;
    emit('confirm', {
        liquiditaStato: scenario.value,
        cassaId: scenario.value === 'registrata_in_cassa' ? cassaId.value : null,
        notaAcconto: scenario.value === 'gia_speso_acconto' ? notaAcconto.value.trim() : '',
    });
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden border border-slate-200 dark:border-slate-800">

                <!-- Header -->
                <div class="bg-indigo-50 dark:bg-indigo-950/30 p-7 border-b border-indigo-100 dark:border-indigo-900/30 flex items-start gap-4">
                    <div class="bg-indigo-100 dark:bg-indigo-900/50 p-2.5 rounded-xl shrink-0">
                        <Wallet class="w-6 h-6 text-indigo-600" />
                    </div>
                    <div>
                        <h3 class="font-black text-indigo-900 dark:text-indigo-100 text-lg">Dove sono questi soldi, oggi?</h3>
                        <p class="text-xs text-indigo-700/70 mt-1 leading-relaxed">
                            Hai dichiarato <span class="font-black">{{ euro(totaleGiaVersato) }}</span> di già versato.
                            Il riparto ora ne tiene conto — ma questi soldi esistono anche fuori dal riparto? Rispondi una
                            volta sola: non te lo richiederemo più per questa voce.
                        </p>
                    </div>
                </div>

                <div class="p-7 space-y-6">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <!-- Scenario A -->
                        <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                            :class="scenario === 'registrata_in_cassa'
                                ? 'bg-emerald-50 border-emerald-400 dark:bg-emerald-900/20 dark:border-emerald-600 shadow-md ring-1 ring-emerald-400'
                                : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
                            <div class="flex items-start justify-between w-full mb-3">
                                <div class="flex items-center gap-2">
                                    <PiggyBank class="w-4 h-4 shrink-0"
                                        :class="scenario === 'registrata_in_cassa' ? 'text-emerald-600' : 'text-slate-400'" />
                                    <span class="font-black text-sm"
                                        :class="scenario === 'registrata_in_cassa' ? 'text-emerald-800 dark:text-emerald-300' : 'text-slate-700 dark:text-slate-300'">
                                        Sono ancora fermi, mai spesi
                                    </span>
                                </div>
                                <input type="radio" v-model="scenario" value="registrata_in_cassa"
                                    class="w-4 h-4 mt-0.5 text-emerald-600 border-slate-300 focus:ring-emerald-600 shrink-0" />
                            </div>
                            <p class="text-[11px] leading-relaxed flex-1"
                                :class="scenario === 'registrata_in_cassa' ? 'text-emerald-700/80 dark:text-emerald-400/80' : 'text-slate-500'">
                                I soldi sono ancora su un conto o un fondo del condominio. Manca solo un passaggio:
                                registrarli come liquidità reale, esattamente come un saldo di apertura. Scegli sotto
                                su quale cassa si trovano — li accreditiamo subito.
                            </p>
                        </label>

                        <!-- Scenario B -->
                        <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                            :class="scenario === 'gia_speso_acconto'
                                ? 'bg-amber-50 border-amber-400 dark:bg-amber-900/20 dark:border-amber-600 shadow-md ring-1 ring-amber-400'
                                : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'">
                            <div class="flex items-start justify-between w-full mb-3">
                                <div class="flex items-center gap-2">
                                    <ReceiptText class="w-4 h-4 shrink-0"
                                        :class="scenario === 'gia_speso_acconto' ? 'text-amber-600' : 'text-slate-400'" />
                                    <span class="font-black text-sm"
                                        :class="scenario === 'gia_speso_acconto' ? 'text-amber-800 dark:text-amber-300' : 'text-slate-700 dark:text-slate-300'">
                                        Sono già stati spesi come acconto
                                    </span>
                                </div>
                                <input type="radio" v-model="scenario" value="gia_speso_acconto"
                                    class="w-4 h-4 mt-0.5 text-amber-600 border-slate-300 focus:ring-amber-600 shrink-0" />
                            </div>
                            <p class="text-[11px] leading-relaxed flex-1"
                                :class="scenario === 'gia_speso_acconto' ? 'text-amber-700/80 dark:text-amber-400/80' : 'text-slate-500'">
                                Sono già stati versati al fornitore come acconto, prima di Kondomanager. Non c'è
                                liquidità da registrare: il "già versato" resta corretto per il riparto, ma il debito
                                verso il fornitore che vedrai in fattura andrà verificato a mano — non è ancora scontato.
                            </p>
                        </label>
                    </div>

                    <!-- Selezione cassa -->
                    <Transition
                        enter-active-class="transition-all duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-4"
                        enter-to-class="opacity-100 translate-y-0">
                        <div v-if="scenario === 'registrata_in_cassa'">
                            <Label class="text-[10px] font-black uppercase tracking-widest mb-2 block">
                                Su quale cassa o fondo si trovano? *
                            </Label>
                            <p v-if="natura === 'fondo_vincolato'" class="text-[11px] text-slate-500 mb-2 leading-relaxed">
                                Fondo deliberato con vincolo di destinazione: non compaiono le casse liberamente
                                utilizzabili per altri imprevisti — scegli un fondo dedicato a quest'opera, o la banca.
                            </p>
                            <vSelect
                                v-model="cassaId"
                                :clearable="false"
                                :options="casseSelezionabili"
                                :reduce="(c: CassaOpzione) => c.id"
                                label="nome"
                                placeholder="Cerca la cassa o il fondo..."
                                append-to-body
                                :calculate-position="posizionaDropdownCassa">
                                <template #option="{ nome, tipo, saldo_cents }">
                                    <div class="flex items-center justify-between gap-3 py-0.5">
                                        <div class="flex items-center gap-2 overflow-hidden">
                                            <span class="truncate text-sm font-semibold">{{ nome }}</span>
                                            <span class="shrink-0 rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                {{ etichettaTipo[tipo] ?? tipo }}
                                            </span>
                                        </div>
                                        <span class="shrink-0 font-mono text-xs">{{ euro(saldo_cents) }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome, saldo_cents }">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">{{ nome }}</span>
                                        <span class="text-[10px] text-slate-400">{{ euro(saldo_cents) }}</span>
                                    </div>
                                </template>
                            </vSelect>
                            <p class="mt-2 flex items-start gap-1.5 text-[11px] text-slate-500 leading-relaxed">
                                <CheckCircle2 class="w-3.5 h-3.5 text-emerald-500 shrink-0 mt-0.5" />
                                Registriamo subito {{ euro(totaleGiaVersato) }} come accantonamento su questa cassa:
                                lo ritroverai nel saldo e nello stato patrimoniale.
                            </p>
                        </div>
                    </Transition>

                    <!-- Nota acconto -->
                    <Transition
                        enter-active-class="transition-all duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-4"
                        enter-to-class="opacity-100 translate-y-0">
                        <div v-if="scenario === 'gia_speso_acconto'">
                            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2 block">
                                Quando e a chi è stato versato questo acconto? *
                            </Label>
                            <textarea
                                v-model="notaAcconto"
                                rows="3"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-xl p-4 text-sm bg-slate-50 dark:bg-slate-800 outline-none resize-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-400 transition-all"
                                placeholder="Es: Acconto di 1.000€ versato al fornitore Rossi Srl a giugno 2025, a saldo del primo stato avanzamento lavori..." />
                            <div class="flex justify-between mt-1 text-[10px]">
                                <span class="text-slate-400">Minimo 10 caratteri</span>
                                <span :class="notaAcconto.trim().length >= 10 ? 'text-emerald-500' : 'text-rose-500'">
                                    {{ notaAcconto.trim().length }}
                                </span>
                            </div>
                            <p class="mt-2 flex items-start gap-1.5 text-[11px] text-amber-700 dark:text-amber-400 leading-relaxed">
                                <HelpCircle class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                                Apriamo un promemoria in Inbox con questa nota: quando registrerai la fattura reale del
                                fornitore, verifica a mano che il debito residuo tenga conto di questo acconto.
                            </p>
                        </div>
                    </Transition>

                    <!-- Azioni -->
                    <div class="flex gap-3">
                        <Button variant="outline" class="flex-1 h-11 rounded-xl font-bold" @click="handleCancel">
                            Annulla
                        </Button>
                        <Button
                            class="flex-1 h-11 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-black disabled:opacity-50"
                            :disabled="!isValid || isProcessing"
                            @click="handleConfirm">
                            {{ isProcessing ? 'Salvataggio in corso...' : 'Conferma e salva' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
