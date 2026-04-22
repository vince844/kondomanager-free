<script setup lang="ts">
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import vSelect from 'vue-select';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { 
    AlertTriangle, History, Scale, Info, 
    AlertOctagon, CheckCircle, Trash2, FileText, Calculator, Activity, Zap
} from 'lucide-vue-next';

const { euro } = useCurrencyFormatter();

// --- PROPS ---
const props = defineProps<{
    form: any; 
    fornitoreId: number | null;
    debitiPatrimoniali: any[];
    contiSpesa: any[];
    fondiRiserva: any[];
    capienzaRataZero: number; 
    incassatoRataZero: number;
    totaleFatturaLordo: number; 
    bankForecast: { attuale_cents: number, post_cents: number, isRed: boolean } | null; 
    fatturePregresseRegistrate: any[];
}>();

// --- COMPUTED ---
const debitiFiltrati = computed(() => {
    if (!props.fornitoreId) return [];
    return props.debitiPatrimoniali.filter(d => d.fornitore_id === props.fornitoreId);
});

const debitoSelezionato = computed(() => {
    return debitiFiltrati.value.find(d => d.id === props.form.saldo_patrimoniale_id);
});

const fatturePregresseFornitore = computed(() => {
    if (!props.fornitoreId || !props.fatturePregresseRegistrate) return [];
    return props.fatturePregresseRegistrate.filter((f: any) => f.fornitore_id === props.fornitoreId);
});

const fatturaCents = computed(() => Math.round(props.totaleFatturaLordo * 100));

const scopertoAttualeEuro = computed(() => {
    const disponibileCents = debitoSelezionato.value?.importo_disponibile || 0;
    
    // Rimuoviamo la logica delle coperture extra dal calcolo dello scoperto
    // perché ora se ne occupa la modale.
    const scopertoCents = Math.max(0, fatturaCents.value - disponibileCents);
    return scopertoCents / 100;
});

const uscitaNettaCents = computed(() => {
    if (!props.bankForecast) return fatturaCents.value;
    return props.bankForecast.attuale_cents - props.bankForecast.post_cents;
});

const bucoRataZeroCents = computed(() => {
    const debitoCents = debitoSelezionato.value?.importo_disponibile || 0;
    return Math.max(0, debitoCents - props.capienzaRataZero);
});

// --- L'INTELLIGENZA DEL SEMAFORO AGGIORNATA ---
const semaforoContabile = computed(() => {
    if (!props.form.saldo_patrimoniale_id) return 'WAITING';
    if (scopertoAttualeEuro.value > 0) return 'ORANGE'; 
    
    if (bucoRataZeroCents.value > 0) {
        if (props.bankForecast && !props.bankForecast.isRed) {
            return 'YELLOW';
        }
        return 'RED'; 
    }
    return 'GREEN'; 
});

// --- ASSISTENTE FISCALE ---
const fiscalAssistant = computed(() => {
    if (!props.form.saldo_patrimoniale_id) {
        return {
            title: 'In attesa di selezione',
            desc: 'Seleziona un debito ereditato dal menu a tendina per permettere al sistema di analizzare la copertura.',
            icon: Info,
            colorClass: 'bg-slate-50 border-slate-200 text-slate-700 dark:bg-slate-800/50 dark:border-slate-700',
            iconColor: 'text-slate-400'
        };
    }

    if (semaforoContabile.value === 'ORANGE') {
        const descrizioneDinamica = `La fattura è superiore al debito storico. Restano fuori esattamente ${euro(scopertoAttualeEuro.value * 100)} da giustificare.\n\n` +
                                   `Per poter salvare questa differenza, clicca su "Registra Documento". Si aprirà l'assistente per configurare la copertura (Rata Extra, Conguaglio o Fondo di Riserva).`;

        return {
            title: 'Quadratura incompleta (Azione Richiesta)',
            desc: descrizioneDinamica,
            icon: Scale,
            colorClass: 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800/50',
            iconColor: 'text-amber-600 dark:text-amber-400'
        };
    }

    if (semaforoContabile.value === 'YELLOW') {
        return {
            title: 'Deficit storico tamponato (Avviso)',
            desc: `Il bilancio quadra e il conto di addebito che hai selezionato ha fondi sufficienti per pagare questa fattura.\n\nTuttavia, ti ricordiamo che i condòmini non hanno versato abbastanza Rata 0 per coprire l'intero debito storico (mancano all'appello ${euro(bucoRataZeroCents.value)}). Assicurati che i soldi che stai per usare non siano destinati alla gestione ordinaria.`,
            icon: AlertTriangle,
            colorClass: 'bg-yellow-50 border-yellow-200 text-yellow-800 dark:bg-yellow-900/20 dark:border-yellow-800/50',
            iconColor: 'text-yellow-600 dark:text-yellow-400'
        };
    }

    if (semaforoContabile.value === 'RED') {
        return {
            title: 'Deficit copertura arretrati (Attenzione)',
            desc: `Il bilancio quadra, ma i condòmini non hanno versato abbastanza arretrati (Rata 0) per coprire interamente questo debito storico. Manca all'appello una provvista di ${euro(bucoRataZeroCents.value)}.\n\nPuoi registrare regolarmente la fattura, ma tieni presente che il suo pagamento assorbirà liquidità destinata alla gestione ordinaria. Sarà necessario un futuro riparto per recuperare questo deficit.`,
            icon: AlertOctagon,
            colorClass: 'bg-rose-50 border-rose-200 text-rose-800 dark:bg-rose-900/20 dark:border-rose-800/50',
            iconColor: 'text-rose-600 dark:text-rose-400'
        };
    }

    if (props.bankForecast?.isRed) {
        return {
            title: 'Allerta liquidità: Rischio scoperto bancario',
            desc: `Contabilmente l'operazione è corretta, ma il pagamento di questa fattura porterebbe il conto corrente selezionato in negativo.\nPuoi registrare tranquillamente il documento a bilancio ora, ma ti suggeriamo di attendere nuovi incassi o sollecitare i morosi prima di disporre il bonifico in banca.`,
            icon: AlertTriangle,
            colorClass: 'bg-yellow-50 border-yellow-200 text-yellow-800 dark:bg-yellow-900/20 dark:border-yellow-800/50',
            iconColor: 'text-yellow-600 dark:text-yellow-400'
        };
    }

    return {
        title: 'Copertura Verificata (Nessun impatto corrente)',
        desc: 'Operazione perfetta. Il debito è correttamente assorbito dai saldi dell\'esercizio precedente e il conto corrente ha liquidità sufficiente per il pagamento. Questa spesa non altererà il preventivo dell\'anno in corso.',
        icon: CheckCircle,
        colorClass: 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-900/20 dark:border-emerald-800/50',
        iconColor: 'text-emerald-600 dark:text-emerald-400'
    };
});

const rischioPrescrizione = computed(() => {
    if (!props.form.data_competenza_originaria) return false;
    return (new Date().getFullYear() - new Date(props.form.data_competenza_originaria).getFullYear()) >= 5;
});
</script>

<template>
    <div class="space-y-5">
        
        <div class="bg-blue-50 dark:bg-slate-900 rounded-xl border border-blue-200 dark:border-blue-800/50 shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-blue-200 dark:border-blue-800/50 bg-blue-100/50 dark:bg-amber-900/20">
                <div class="flex items-center gap-2">
                    <History class="w-5 h-5 text-blue-600 dark:text-blue-500" />
                    <h3 class="text-sm font-bold text-blue-900 dark:text-blue-100">Gestione debito pregresso</h3>
                </div>
                <p class="text-[11px] text-blue-700 dark:text-blue-400 mt-1">
                    Associa questa spesa a un debito ereditato dal bilancio precedente.
                </p>
            </div>

            <div class="p-6 space-y-6">
                
                <div class="flex flex-col gap-1.5 w-max">
                    <Label class="text-[11px] font-bold uppercase text-slate-500 block">Data di origine del debito</Label>
                    <Input type="date" v-model="form.data_competenza_originaria" class="h-9 w-48 text-sm bg-white" />
                    <p v-if="rischioPrescrizione" class="text-[11px] font-bold text-rose-600 flex items-center gap-1 mt-1">
                        <AlertTriangle class="w-3.5 h-3.5" /> Rischio prescrizione (> 5 anni)
                    </p>
                </div>

                <div class="space-y-1.5 relative z-50">
                    <Label class="text-[11px] font-bold uppercase text-slate-500">Seleziona fornitore da pagare</Label>
                    <v-select 
                        v-model="props.form.saldo_patrimoniale_id"
                        :options="debitiFiltrati" 
                        label="descrizione"
                        :reduce="(d: any) => d.id" 
                        placeholder="Nessun debito selezionato..." 
                        class="bg-white w-full"
                        append-to-body 
                    >
                        <template #option="{ descrizione, importo_disponibile, importo_iniziale }">
                            <div class="flex justify-between py-1">
                                <span class="font-medium text-sm">{{ descrizione }}</span>
                                <div class="flex flex-col text-right">
                                    <span class="text-xs font-bold text-amber-600">Disp: {{ euro(importo_disponibile) }}</span>
                                    <span v-if="importo_iniziale && importo_disponibile !== importo_iniziale" class="text-[9px] text-slate-400 line-through">Orig: {{ euro(importo_iniziale) }}</span>
                                </div>
                            </div>
                        </template>
                    </v-select>

                    <div v-if="fatturePregresseFornitore.length > 0" 
                        class="mt-4 p-3 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-xl">
                        <div class="flex items-center gap-2 mb-2 text-slate-500">
                            <History class="w-4 h-4" />
                            <span class="text-[10px] font-bold uppercase tracking-widest">Fatture pregresse già registrate</span>
                        </div>
                        <ul class="space-y-1.5">
                            <li v-for="fat in fatturePregresseFornitore" :key="fat.id" 
                                class="text-xs flex justify-between items-center bg-white dark:bg-slate-900 p-2 rounded border border-slate-100 dark:border-slate-800">
                                <div>
                                    <span class="font-semibold text-slate-700 dark:text-slate-300">Doc. {{ fat.numero_documento }}</span>
                                    <span class="text-slate-400 ml-2">{{ fat.data_documento }}</span>
                                </div>
                                <span class="font-bold text-slate-600 dark:text-slate-400">{{ euro(fat.importo_usato, { fromCents: false }) }}</span>
                            </li>
                        </ul>
                        <p class="text-[9px] text-amber-600 mt-2 flex items-center gap-1">
                            <AlertTriangle class="w-3 h-3" /> Verifica di non star inserendo un duplicato.
                        </p>
                    </div>
                </div>

                <div v-if="debitoSelezionato" class="space-y-6">
                    
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
    
                        <div class="p-4 bg-slate-900 text-[11px] rounded-xl shadow-inner border border-slate-800 text-slate-300 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center gap-2 text-[10px] text-blue-400 font-bold uppercase tracking-widest mb-3 border-b border-slate-800 pb-2">
                                    <Calculator class="w-3.5 h-3.5" /> Quadratura
                                </div>
                                <div class="flex justify-between mb-1.5">
                                    <span>[+] Fattura</span>
                                    <span class="text-white">{{ euro(fatturaCents) }}</span>
                                </div>
                                <div class="flex justify-between mb-1.5 text-emerald-400">
                                    <span>[-] Debito Storico</span>
                                    <span>{{ euro(debitoSelezionato.importo_disponibile) }}</span>
                                </div>
                                <div v-for="(cop, idx) in props.form.coperture" :key="idx" class="flex justify-between mb-1.5 text-blue-400">
                                    <span class="truncate pr-2">[-] Split extra</span>
                                    <span>{{ euro((cop.importo || 0) * 100) }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="border-t border-slate-700 my-2 border-dashed"></div>
                                <div class="flex justify-between font-bold text-xs">
                                    <span class="text-white">SCARTO</span>
                                    <span :class="scopertoAttualeEuro > 0 ? 'text-rose-500' : 'text-emerald-500'">
                                        {{ euro(scopertoAttualeEuro * 100) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 rounded-xl border flex flex-col justify-between" :class="bucoRataZeroCents > 0 ? 'bg-blue-100 border-blue-200' : 'bg-emerald-50/50 border-emerald-200'">
                            <div>
                                <div class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest mb-3 border-b pb-2"
                                    :class="bucoRataZeroCents > 0 ? 'text-blue-600 border-blue-200' : 'text-emerald-600 border-emerald-200'">
                                    <Activity class="w-3.5 h-3.5" /> Liquidità Arretrati
                                </div>
                                
                                <div class="flex justify-between text-[11px] mb-1">
                                    <span class="text-slate-600">Quote Rata 0 previste:</span>
                                    <span class="font-bold">{{ euro(capienzaRataZero) }}</span>
                                </div>
                                <div class="flex justify-between text-[10px] mb-2 text-emerald-600 font-medium">
                                    <span>↳ Cassa reale incassata:</span>
                                    <span class="font-bold">{{ euro(incassatoRataZero) }}</span>
                                </div>
                                
                                <div class="h-1 bg-slate-200 rounded-full overflow-hidden">
                                    <div class="h-full transition-all duration-500"
                                        :class="bucoRataZeroCents > 0 ? 'bg-amber-500' : 'bg-emerald-500'"
                                        :style="{ width: Math.min((debitoSelezionato.importo_disponibile / Math.max(capienzaRataZero, 1)) * 100, 100) + '%' }">
                                    </div>
                                </div>
                            </div>

                            <div v-if="bucoRataZeroCents > 0" class="mt-3 p-2 bg-amber-100/50 dark:bg-amber-900/30 rounded border border-amber-200/50 dark:border-amber-800/50">
                                <p class="text-[10px] font-black uppercase tracking-wider text-amber-700 dark:text-amber-400 mb-0.5">
                                    Info di Cassa
                                </p>
                                <p class="text-[10px] text-amber-600 dark:text-amber-300 leading-tight">
                                    La Rata 0 non copre interamente questo debito storico. Il pagamento di questa fattura assorbirà parzialmente liquidità della gestione ordinaria corrente.
                                </p>
                            </div>
                            <div v-else class="text-[10px] font-bold text-emerald-600 mt-3">
                                Rata 0 sufficiente.
                            </div>
                        </div>

                        <div class="p-4 rounded-xl border bg-slate-800 border-slate-700 text-white flex flex-col justify-between">
                            <div>
                                <div class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest mb-3 border-b border-slate-700 pb-2 text-blue-400">
                                    <Zap class="w-3.5 h-3.5" /> Impatto cassa
                                </div>
                                <div v-if="bankForecast">
                                    <div class="flex justify-between text-[11px] mb-1 text-slate-300">
                                        <span>Saldo attuale:</span>
                                        <span>{{ euro(bankForecast.attuale_cents) }}</span>
                                    </div>
                                    <div class="flex justify-between text-[11px] mb-2 text-rose-400">
                                        <span>Bonifico (Netto):</span>
                                        <span>- {{ euro(uscitaNettaCents) }}</span>
                                    </div>
                                </div>
                                <div v-else class="text-[10px] text-slate-400 italic">
                                    Seleziona un conto di addebito
                                </div>
                            </div>
                            <div v-if="bankForecast" class="border-t border-slate-700 pt-2 mt-2">
                                <div class="flex justify-between font-black text-sm" :class="bankForecast.isRed ? 'text-rose-500' : 'text-emerald-400'">
                                    <span>Post-pagam.</span>
                                    <span>{{ euro(bankForecast.post_cents) }}</span>
                                </div>
                            </div>
                        </div>
                        
                    </div>

                </div>
            </div>
        </div>

        <div class="p-5 rounded-xl border transition-colors duration-300" :class="fiscalAssistant.colorClass">
            <div class="flex items-start gap-4">
                <component :is="fiscalAssistant.icon" class="w-6 h-6 shrink-0 mt-0.5" :class="fiscalAssistant.iconColor" />
                <div>
                    <h4 class="font-bold text-base mb-1" :class="fiscalAssistant.iconColor">{{ fiscalAssistant.title }}</h4>
                    <p class="text-sm leading-relaxed opacity-90 whitespace-pre-line">{{ fiscalAssistant.desc }}</p>
                </div>
            </div>
        </div>
        
    </div>
</template>