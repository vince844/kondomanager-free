<script setup lang="ts">
import { computed } from 'vue';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { ShieldAlert, TrendingDown, AlertTriangle, WalletCards, Activity, Lock, Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import TreasuryActionRow from './TreasuryActionRow.vue';

const props = defineProps<{
    treasury: {
        liquiditaTotaleCents: number;
        liquiditaVincolataCents: number;
        liquiditaDisponibileCents: number;
        uscitePredittiveCents: number;
        incassiAttesiCents: number;
        debitiPregressiScadutiCents: number;
        scenarioPessimisticoCents: number;
        scenarioOttimisticoCents: number;
        giornoScopertoPrevisto: string | null;
        scopertoMaxCents: number;
        livello: 'verde' | 'giallo' | 'rosso';
        fattureInScadenza: Array<{
            fornitore: string;
            numero: string;
            dataScadenza: string | null;
            importoCents: number;
        }>;
        morositaImpattanti: Array<{
            immobile: string;
            condomino: string;
            importoCents: number;
        }>;
        azioniSuggerite: Array<{
            tipo: string;
            label: string;
            descrizione: string;
            impattoCents: number;
            route: string;
            routeParams?: any;
        }>;
        fattureSenzaScadenza?: Array<{
            fornitore: string;
            numero: string;
            importoCents: number;
        }>;
        hasFondoRiserva?: boolean;
    };
}>();

const { euro } = useCurrencyFormatter();

const widgetTheme = computed(() => {
    switch (props.treasury.livello) {
        case 'rosso':
            return {
                bg: 'bg-red-50/50 dark:bg-red-900/10',
                border: 'border-red-200 dark:border-red-900/30',
                text: 'text-red-700 dark:text-red-400',
                icon: 'text-red-600',
                badgeBg: 'bg-red-100 text-red-800',
                title: 'Rischio Ingiunzione',
                desc: 'La liquidità non copre le uscite previste nei prossimi 30 giorni.'
            };
        case 'giallo':
            return {
                bg: 'bg-amber-50/50 dark:bg-amber-900/10',
                border: 'border-amber-200 dark:border-amber-900/30',
                text: 'text-amber-700 dark:text-amber-400',
                icon: 'text-amber-500',
                badgeBg: 'bg-amber-100 text-amber-800',
                title: 'Esposizione Rischio',
                desc: 'In assenza di nuovi incassi, il conto andrà in rosso.'
            };
        case 'verde':
        default:
            return {
                bg: 'bg-emerald-50/50 dark:bg-emerald-900/10',
                border: 'border-emerald-200 dark:border-emerald-900/30',
                text: 'text-emerald-700 dark:text-emerald-400',
                icon: 'text-emerald-500',
                badgeBg: 'bg-emerald-100 text-emerald-800',
                title: 'Finanziariamente Sano',
                desc: 'Il saldo di c/c copre abbondantemente le uscite del mese.'
            };
    }
});

const formattatoreGiorno = computed(() => {
    if (!props.treasury.giornoScopertoPrevisto) return null;
    const date = new Date(props.treasury.giornoScopertoPrevisto);
    return new Intl.DateTimeFormat('it-IT', { day: 'numeric', month: 'short' }).format(date);
});
</script>

<template>
    <div class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md group">
        <!-- Background Icon -->
        <div class="absolute -right-6 -top-6 text-slate-50 dark:text-slate-800/50 pointer-events-none transition-colors group-hover:text-slate-100 dark:group-hover:text-slate-800">
            <Activity class="h-32 w-32 opacity-50" />
        </div>
        
        <div class="p-5 relative z-10">
            <!-- Header Widget -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-1.5">
                    <WalletCards class="w-4 h-4 text-slate-400" />
                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Tesoreria a 30gg</h3>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger><AlertTriangle class="w-3.5 h-3.5 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                            <TooltipContent side="right"><p class="text-xs max-w-[200px]">Simulazione del flusso di cassa aggregato del condominio per i prossimi 30 giorni.</p></TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
                
                <!-- Badge Stato Semaforico -->
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger>
                            <div class="flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-bold border cursor-help transition-colors"
                                :class="widgetTheme.badgeBg + ' ' + widgetTheme.border">
                                <span class="flex h-1.5 w-1.5 rounded-full" 
                                    :class="{'bg-red-500 animate-pulse': treasury.livello === 'rosso', 'bg-amber-500 animate-pulse': treasury.livello === 'giallo', 'bg-emerald-500': treasury.livello === 'verde'}"></span>
                                <span>{{ widgetTheme.title }}</span>
                            </div>
                        </TooltipTrigger>
                        <TooltipContent><p class="text-xs">{{ widgetTheme.desc }}</p></TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>

            <!-- Dashboard Numerica -->
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div class="bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800 flex flex-col justify-center">
                    <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1.5">Disp. C/C (Oggi)</p>
                    <p class="text-base font-black leading-none"
                       :class="treasury.liquiditaDisponibileCents < 0 ? 'text-red-600' : 'text-slate-700 dark:text-slate-200'">
                        {{ euro(treasury.liquiditaDisponibileCents) }}
                    </p>
                </div>
                
                <div class="bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800 flex flex-col justify-center">
                    <div class="flex justify-between items-center mb-1.5">
                        <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest">Flusso a 30gg</p>
                    </div>
                    <div class="space-y-1">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-500 font-medium">Uscite</span>
                            <span class="font-black text-rose-600">-{{ euro(treasury.uscitePredittiveCents) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs border-t border-slate-200 dark:border-slate-700/50 pt-1 mt-1">
                            <span class="text-slate-500 font-medium">Rate in Arr.</span>
                            <span class="font-black text-emerald-600">+{{ euro(treasury.incassiAttesiCents) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Avviso Giorno Scoperto (Rosso/Giallo) -->
            <div v-if="treasury.giornoScopertoPrevisto && treasury.livello !== 'verde'" class="mb-4 rounded-lg p-3" :class="widgetTheme.bg + ' ' + widgetTheme.border">
                <div class="flex items-center gap-2 mb-1.5">
                    <ShieldAlert class="w-4 h-4" :class="widgetTheme.icon" />
                    <span class="text-[10px] font-black uppercase tracking-widest" :class="widgetTheme.text">Attenzione Scoperto</span>
                </div>
                <p class="text-xs font-medium" :class="widgetTheme.text">
                    Possibile scoperto di <strong>{{ euro(Math.abs(treasury.scopertoMaxCents)) }}</strong> entro il <strong>{{ formattatoreGiorno }}</strong>. 
                    <span v-if="treasury.livello === 'giallo'">Gli incassi previsti eviteranno lo sforo solo se pagati puntualmente.</span>
                </p>
            </div>
            
            <div v-else-if="treasury.livello === 'verde'" class="mb-4 rounded-lg p-3 bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-900/30">
                <p class="text-[10px] font-medium text-emerald-700 dark:text-emerald-400 leading-tight">
                    Nessuna emergenza di cassa rilevata. I fondi coprono tutte le uscite approvate per il mese in corso.
                </p>
            </div>

            <!-- Sezione "Perché" (causa) — §7.1 -->
            <div v-if="treasury.fattureInScadenza.length > 0 && treasury.livello !== 'verde'" class="mb-3">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Fatture in scadenza</p>
                <div class="space-y-1 max-h-[80px] overflow-y-auto custom-scrollbar">
                    <div v-for="(fat, idx) in treasury.fattureInScadenza.slice(0, 5)" :key="idx"
                         class="flex justify-between items-center px-2 py-1 rounded text-[10px] bg-slate-50/50 dark:bg-slate-800/30 border border-slate-100 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 truncate mr-2">{{ fat.fornitore }} — {{ fat.numero }}</span>
                        <span class="font-bold text-slate-700 dark:text-slate-300 shrink-0">{{ euro(fat.importoCents) }}</span>
                    </div>
                    <p v-if="treasury.fattureInScadenza.length > 5" class="text-[9px] text-slate-400 text-center pt-1">
                        + altre {{ treasury.fattureInScadenza.length - 5 }} fatture
                    </p>
                </div>
            </div>

            <!-- Anomalia: fatture senza scadenza — §6.2 -->
            <div v-if="treasury.fattureSenzaScadenza && treasury.fattureSenzaScadenza.length > 0" 
                 class="flex items-center gap-2 p-2.5 rounded-lg bg-orange-50/80 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800/30 mb-3">
                <AlertTriangle class="w-3.5 h-3.5 text-orange-500 shrink-0" />
                <div class="flex-1 min-w-0">
                    <span class="text-[10px] font-bold text-orange-700 dark:text-orange-400">
                        {{ treasury.fattureSenzaScadenza.length }} fattur{{ treasury.fattureSenzaScadenza.length === 1 ? 'a' : 'e' }} senza data di scadenza
                    </span>
                    <p class="text-[9px] text-orange-600/70 dark:text-orange-500/70 leading-tight">
                        Uscite non conteggiabili nella previsione. Compilare la scadenza per una previsione accurata.
                    </p>
                </div>
            </div>

            <!-- Pregresso Alert Isolato -->
            <div v-if="treasury.debitiPregressiScadutiCents > 0" class="flex items-center justify-between p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 mb-2">
                <div class="flex items-center gap-2">
                    <TrendingDown class="w-3.5 h-3.5 text-slate-400" />
                    <div class="flex flex-col">
                        <span class="text-[10px] font-bold text-slate-700 dark:text-slate-300">Pregressi non pagati</span>
                        <span class="text-[9px] text-slate-500">Debiti storici accumulati</span>
                    </div>
                </div>
                <span class="text-xs font-black text-slate-700">{{ euro(treasury.debitiPregressiScadutiCents) }}</span>
            </div>

            <!-- Avviso fondi vincolati MVP — §6.4 -->
            <div v-if="treasury.hasFondoRiserva && treasury.liquiditaVincolataCents === 0" 
                 class="flex items-center gap-2 p-2.5 rounded-lg bg-indigo-50/50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-800/30">
                <Lock class="w-3.5 h-3.5 text-indigo-400 shrink-0" />
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-indigo-700 dark:text-indigo-300">Fondi vincolati</span>
                    <span class="text-[9px] text-indigo-500">Il calcolo non distingue ancora i fondi vincolati dal disponibile</span>
                </div>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger><Info class="w-3 h-3 text-indigo-400/60 cursor-help shrink-0" /></TooltipTrigger>
                        <TooltipContent><p class="text-xs max-w-[200px]">Il condominio ha un fondo riserva, ma la separazione precisa sarà disponibile con la funzione Voci Accantonamento.</p></TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>

            <!-- Fondi Vincolati Calcolati (post-MVP, quando disponibili) -->
            <div v-else-if="treasury.liquiditaVincolataCents > 0" class="flex items-center justify-between p-2.5 rounded-lg bg-indigo-50/50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-800/30">
                <div class="flex items-center gap-2">
                    <Lock class="w-3.5 h-3.5 text-indigo-400" />
                    <div class="flex flex-col">
                        <span class="text-[10px] font-bold text-indigo-700 dark:text-indigo-300">Fondi Vincolati</span>
                        <span class="text-[9px] text-indigo-500">Riserve non disponibili per l'ordinaria</span>
                    </div>
                </div>
                <span class="text-xs font-black text-indigo-700">{{ euro(treasury.liquiditaVincolataCents) }}</span>
            </div>
        </div>

        <!-- Azioni (CTA) — §7.1: usa TreasuryActionRow -->
        <div class="mt-auto border-t border-slate-100 dark:border-slate-800 px-4 py-3 bg-slate-50/50 dark:bg-slate-800/50 flex flex-col gap-2">
            <template v-if="treasury.azioniSuggerite && treasury.azioniSuggerite.length > 0">
                <TreasuryActionRow
                    v-for="(azione, index) in treasury.azioniSuggerite" 
                    :key="index"
                    :azione="azione"
                    :is-primary="index === 0"
                    :livello="treasury.livello"
                />
            </template>
            <span v-else class="text-[10px] text-slate-400 font-medium text-center py-1">Nessuna azione richiesta</span>
        </div>
    </div>
</template>
