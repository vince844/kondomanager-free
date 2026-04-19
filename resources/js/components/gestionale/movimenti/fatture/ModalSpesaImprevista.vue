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
    { id: 'gestione_corrente', label: "Intervento d'Urgenza (Art. 1135 c.c.)" },
    { id: 'delibera_assembleare', label: "Delibera Assembleare" }
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

const destinazioneContabile = computed(() => {
    if (data.value.origine_decisionale === 'gestione_corrente') {
        return {
            tipo: 'ORDINARIO',
            isOrdinario: true,
            descrizione: 'Spesa urgente (Art. 1135 c.c.). Finirà nella gestione ordinaria.',
        };
    }
    return {
        tipo: 'STRAORDINARIO',
        isOrdinario: false,
        descrizione: 'Lavori deliberati. Finiranno in un Piano Rate Straordinario dedicato.',
    };
});

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------
watch(() => props.show, (isOpen) => {
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

// ---------------------------------------------------------------------------
// Validazione & Submit
// ---------------------------------------------------------------------------
const isValid = computed(() => {
    if (data.value.nome_voce.length < 5) return false;
    if (!data.value.tabella_millesimale_id) return false;
    if (!percentualiValide.value) return false;

    // Motivazione obbligatoria per le urgenze non coperte da fondo
    if (data.value.origine_decisionale === 'gestione_corrente' && data.value.strategia_rientro !== 'fondo_riserva') {
        if (data.value.motivazione_urgenza.length < 10) return false;
    }

    if (data.value.strategia_rientro === 'fondo_riserva') {
        if (!data.value.fondo_patrimoniale_id) return false;
        if (fondoSelezionato.value && fondoSelezionato.value.saldo_attuale < props.importoImprevisto) return false;
    }

    return true;
});

const handleCancel = () => emit('update:show', false);

const handleConfirm = () => {
    if (!isValid.value) return;

    emit('confirm', {
        ...data.value,
        tipo_ripartizione: 'millesimale',
        is_ordinario: destinazioneContabile.value.isOrdinario,
        richiede_copertura: true,
        motivazione_sforo: data.value.motivazione_urgenza,
    });
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl flex flex-col max-h-[90vh] overflow-hidden border border-slate-200 dark:border-slate-800">

                <div class="bg-amber-50 dark:bg-amber-950/30 p-7 border-b border-amber-100 dark:border-amber-900/30 flex items-start gap-4 shrink-0">
                    <div class="bg-amber-100 dark:bg-amber-900/50 p-2.5 rounded-xl shrink-0">
                        <Zap class="w-6 h-6 text-amber-600" />
                    </div>
                    <div>
                        <h3 class="font-black text-amber-900 dark:text-amber-100 text-lg">Configura Nuova Voce di Spesa</h3>
                        <p class="text-xs text-amber-700/70 mt-1">
                            Valore della parte comune da ripartire: <span class="font-black">{{ euro(importoImprevisto) }}</span>
                        </p>
                    </div>
                </div>

                <div class="p-7 space-y-8 overflow-y-auto flex-1 custom-scrollbar">

                    <div class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase text-slate-500 tracking-widest flex items-center gap-2">
                            <Wallet class="w-3 h-3" /> 1. Strategia di Copertura per {{ euro(importoImprevisto) }} *
                        </h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                                :class="data.strategia_rientro === 'conguaglio_fine_anno' ? 'bg-blue-50 border-blue-400 shadow-md ring-1 ring-blue-400' : 'border-slate-200 hover:bg-slate-50'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm" :class="data.strategia_rientro === 'conguaglio_fine_anno' ? 'text-blue-800' : 'text-slate-700'">Attesa conguaglio</div>
                                    <input type="radio" v-model="data.strategia_rientro" value="conguaglio_fine_anno" class="w-4 h-4 mt-0.5 text-blue-600 focus:ring-blue-600 shrink-0" />
                                </div>
                                <p class="text-[11px] leading-relaxed flex-1" :class="data.strategia_rientro === 'conguaglio_fine_anno' ? 'text-blue-700/80' : 'text-slate-500'">Registra lo sforo in passivo. Nessun allarme generato, si regolerà tutto a chiusura esercizio.</p>
                            </label>

                            <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                                :class="data.strategia_rientro === 'rata_integrativa' ? 'bg-amber-50 border-amber-400 shadow-md ring-1 ring-amber-400' : 'border-slate-200 hover:bg-slate-50'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm" :class="data.strategia_rientro === 'rata_integrativa' ? 'text-amber-800' : 'text-slate-700'">
                                        {{ data.origine_decisionale === 'gestione_corrente' ? 'Rata Integrativa' : 'Piano Straordinario' }}
                                    </div>
                                    <input type="radio" v-model="data.strategia_rientro" value="rata_integrativa" class="w-4 h-4 mt-0.5 text-amber-600 focus:ring-amber-600 shrink-0" />
                                </div>
                                <p class="text-[11px] leading-relaxed flex-1" :class="data.strategia_rientro === 'rata_integrativa' ? 'text-amber-700/80' : 'text-slate-500'">
                                    Lascia un allarme in Dashboard per ricordarti di emettere un nuovo piano rate dedicato a questa voce.
                                </p>
                            </label>

                            <label class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all relative h-full"
                                :class="data.strategia_rientro === 'fondo_riserva' ? 'bg-emerald-50 border-emerald-400 shadow-md ring-1 ring-emerald-400' : 'border-slate-200 hover:bg-slate-50'">
                                <div class="flex items-start justify-between w-full mb-3">
                                    <div class="font-black text-sm" :class="data.strategia_rientro === 'fondo_riserva' ? 'text-emerald-800' : 'text-slate-700'">Usa Fondo Riserva</div>
                                    <input type="radio" v-model="data.strategia_rientro" value="fondo_riserva" class="w-4 h-4 mt-0.5 text-emerald-600 focus:ring-emerald-600 shrink-0" />
                                </div>
                                <p class="text-[11px] leading-relaxed flex-1" :class="data.strategia_rientro === 'fondo_riserva' ? 'text-emerald-700/80' : 'text-slate-500'">
                                    Neutralizza l'impatto sul bilancio attingendo direttamente da un portafoglio patrimoniale preesistente.
                                </p>
                            </label>
                        </div>

                        <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 -translate-y-4" enter-to-class="opacity-100 translate-y-0">
                            <div v-if="data.strategia_rientro === 'fondo_riserva'" >
                                <Label class="text-[10px] font-black uppercase tracking-widest mb-2 block">Seleziona il fondo da utilizzare *</Label>
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
                                                    <span v-if="is_override_assemblea" class="inline-flex items-center gap-1 text-[9px] font-bold text-purple-600 bg-purple-50 px-1.5 py-0.5 rounded uppercase tracking-wide"><Unlock class="w-3 h-3" /> Sbloccato (deroga)</span>
                                                    <span v-else class="inline-flex items-center gap-1 text-[9px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded uppercase tracking-wide"><CheckCircle2 class="w-3 h-3" /> Libero</span>
                                                </div>
                                            </div>
                                            <span class="text-[10px] font-bold px-2 py-1 rounded-md border text-emerald-700 bg-emerald-50 border-emerald-200 shadow-sm ml-4">
                                                {{ euro(saldo_attuale) }}
                                            </span>
                                        </div>
                                    </template>
                                </v-select>
                                
                                <div v-if="fondoSelezionato && fondoSelezionato.saldo_attuale < props.importoImprevisto" class="mt-2 flex items-center gap-1.5 text-[10px] font-bold text-rose-600 bg-rose-50 px-3 py-2 rounded-lg border border-rose-200">
                                    <AlertTriangle class="w-3.5 h-3.5" />
                                    <span>Il fondo selezionato non ha capienza sufficiente per coprire l'importo.</span>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <hr class="border-slate-100 dark:border-slate-800">

                    <div class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase text-slate-500 tracking-widest flex items-center gap-2">
                            <FileText class="w-3 h-3" /> 2. Dati Contabili e Legali
                        </h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-4">
                                <div class="space-y-1.5">
                                    <Label class="text-[11px] font-bold text-slate-700">Nome voce di spesa *</Label>
                                    <Input v-model="data.nome_voce" class="h-10 text-sm font-semibold border-slate-300" />
                                </div>

                                <div class="space-y-1.5 relative">
                                    <Label class="text-[11px] font-bold text-slate-700">Tabella di riparto *</Label>
                                    <v-select
                                        :options="tabelle"
                                        label="nome"
                                        v-model="data.tabella_millesimale_id"
                                        :reduce="(t: any) => t.id"
                                        @open="onDropdownTabelleOpen"
                                        :loading="isLoadingTabelle"
                                        class="bg-white"
                                        placeholder="Seleziona la tabella..." />
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="space-y-1.5 relative">
                                    <Label class="text-[11px] font-bold text-slate-700">Origine dell'Incarico *</Label>
                                    <v-select
                                        :options="opzioniOrigine"
                                        label="label"
                                        v-model="data.origine_decisionale"
                                        :reduce="(o: any) => o.id"
                                        :clearable="false"
                                        class="bg-white font-semibold" />
                                </div>

                                <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2 h-0" enter-to-class="opacity-100 translate-y-0 h-auto">
                                    <div v-if="data.origine_decisionale === 'delibera_assembleare'" class="space-y-1.5 pt-1">
                                        <Label class="text-[11px] font-bold text-slate-700 flex justify-between">
                                            Data Assemblea <span class="text-slate-400 font-normal">(Raccomandata)</span>
                                        </Label>
                                        <Input type="date" v-model="data.data_assemblea" class="h-10 text-sm" />
                                    </div>
                                </Transition>
                            </div>
                        </div>

                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 mt-2">
                            <button v-if="!data.show_advanced_percentages" type="button" @click="data.show_advanced_percentages = true" class="text-[10px] font-bold text-blue-600 hover:underline">
                                Personalizza percentuali Inquilino/Usufruttuario (Default: 100% Proprietario)
                            </button>
                            <div v-else class="grid grid-cols-3 gap-4">
                                <div v-if="!percentualiValide" class="col-span-3 flex items-center gap-1.5 text-[10px] font-bold text-rose-600 bg-rose-50 px-2 py-1.5 rounded">
                                    <AlertTriangle class="w-3 h-3" /> La somma tra Proprietario, Inquilino e Usufruttuario deve essere 100%
                                </div>
                                <div><Label class="text-[10px] font-bold text-slate-500 mb-1.5">Proprietario %</Label><Input type="number" min="0" max="100" v-model="data.percentuale_proprietario" class="h-9 bg-white" /></div>
                                <div><Label class="text-[10px] font-bold text-slate-500 mb-1.5">Inquilino %</Label><Input type="number" min="0" max="100" v-model="data.percentuale_inquilino" class="h-9 bg-white" /></div>
                                <div><Label class="text-[10px] font-bold text-slate-500 mb-1.5">Usufruttuario %</Label><Input type="number" min="0" max="100" v-model="data.percentuale_usufruttuario" class="h-9 bg-white" /></div>
                            </div>
                        </div>

                        <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 h-0" enter-to-class="opacity-100 h-auto">
                            <div v-if="data.origine_decisionale === 'gestione_corrente' && data.strategia_rientro !== 'fondo_riserva'" class="mt-4">
                                <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2 block">Giustificativo dell'Urgenza (Art. 1135 c.c.) *</Label>
                                <textarea v-model="data.motivazione_urgenza" rows="2" 
                                    class="w-full border border-slate-200 rounded-xl p-3 text-sm bg-slate-50 outline-none resize-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-400 transition-all" 
                                    placeholder="Es: Rottura improvvisa colonna di scarico, riparazione urgente per evitare danni a terzi..." />
                            </div>
                        </Transition>

                    </div>
                </div>

                <div class="p-6 border-t border-slate-100 bg-slate-50 dark:bg-slate-900 shrink-0 flex items-center justify-between gap-4">
                    <div class="items-center gap-2 px-2 hidden sm:flex">
                        <Info class="w-4 h-4 text-slate-400" />
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Destinazione: Piano {{ destinazioneContabile.tipo }}</span>
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <Button variant="outline" class="h-11 rounded-xl font-bold px-8" @click="handleCancel">Annulla</Button>
                        <Button 
                            class="h-11 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-black disabled:opacity-50 px-8" 
                            :disabled="!isValid" 
                            @click="handleConfirm"
                        >
                            Conferma Voce
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