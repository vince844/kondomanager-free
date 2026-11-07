<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
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
        /** Gli incassi attesi da rate già scadute (al netto del credito della persona nella stessa gestione). */
        incassiScadutiCents: number;
        /** Gli incassi attesi da rate che scadono entro 30 giorni. */
        incassiInScadenzaCents: number;
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
        /** Le prime cinque persone per scaduto, con i giorni di ritardo della rata più vecchia e il loro estratto conto. */
        morositaImpattanti: Array<{
            anagraficaId: number;
            immobile: string;
            condomino: string;
            importoCents: number;
            /** I giorni della rata più vecchia che il programma sa contare; nullo con il solo pregresso di anzianità non nota. */
            giorniRitardo: number | null;
            /** Il ritardo in parole, scritto dal server: «in ritardo di N giorni», o con il pregresso di anzianità non nota. */
            ritardoTesto?: string;
            anzianitaNonNota?: boolean;
            /** Il credito della persona (lo stesso che «Rate scadute» toglie): l'importo resta lordo, il credito si mostra accanto. */
            creditoCents?: number;
            route: string;
            routeParams: Record<string, number>;
        }>;
        /** Quante altre persone hanno rate scadute, oltre alle cinque. */
        morositaAltri: number;
        /** Lo scaduto di tutte le persone, per giorni di ritardo. */
        scadutoPerFasce: { '1_30': number; '31_90': number; oltre_90: number };
        /** L'elenco delle rate scadute del condominio, per «Vedi tutte». */
        elencoRateScadute?: { route: string; routeParams: Record<string, number> } | null;
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
                // Un titolo sulla liquidità del condominio: niente parole di effetto legale accanto all'elenco dei condòmini.
                title: 'Liquidità insufficiente',
                desc: 'La liquidità non copre le uscite previste nei prossimi 30 giorni.'
            };
        case 'giallo':
            return {
                bg: 'bg-amber-50/50 dark:bg-amber-900/10',
                border: 'border-amber-200 dark:border-amber-900/30',
                text: 'text-amber-700 dark:text-amber-400',
                icon: 'text-amber-500',
                badgeBg: 'bg-amber-100 text-amber-800',
                title: 'Esposizione rischio',
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
                title: 'Finanziariamente sano',
                desc: 'Il saldo di c/c copre abbondantemente le uscite del mese.'
            };
    }
});

const elencoRateScadute = computed(() => {
    const e = props.treasury.elencoRateScadute;
    if (!e) return null;
    try {
        return route(e.route, e.routeParams);
    } catch {
        return null;
    }
});

const estrattoConto = (m: { route: string; routeParams: Record<string, number> }) => {
    try {
        return route(m.route, m.routeParams);
    } catch {
        return null;
    }
};

/** Il ritardo di una persona: le parole del server; una risposta di prima, senza, i giorni. */
const ritardo = (m: { giorniRitardo: number | null; ritardoTesto?: string }) =>
    m.ritardoTesto ?? (m.giorniRitardo === null ? '' : `in ritardo di ${m.giorniRitardo} ${m.giorniRitardo === 1 ? 'giorno' : 'giorni'}`);

const FASCE: Array<{ chiave: '1_30' | '31_90' | 'oltre_90'; etichetta: string }> = [
    { chiave: '1_30', etichetta: '1–30 giorni' },
    { chiave: '31_90', etichetta: '31–90 giorni' },
    { chiave: 'oltre_90', etichetta: 'oltre 90 giorni' },
];

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
                            <div class="flex items-center gap-1.5 px-2 py-1 rounded-md text-[10px] font-bold border cursor-help transition-colors"
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
                            <span class="font-black text-rose-600 whitespace-nowrap shrink-0">-{{ euro(treasury.uscitePredittiveCents) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs border-t border-slate-200 dark:border-slate-700/50 pt-1 mt-1">
                            <span class="flex flex-col min-w-0 pr-2">
                                <span class="text-slate-500 font-medium">Rate scadute</span>
                                <span class="text-[9px] text-slate-400 leading-tight">al netto del credito di ognuno nella stessa gestione</span>
                            </span>
                            <span class="font-black text-emerald-600 whitespace-nowrap shrink-0">+{{ euro(treasury.incassiScadutiCents) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-500 font-medium">Rate in scadenza</span>
                            <span class="font-black text-emerald-600 whitespace-nowrap shrink-0">+{{ euro(treasury.incassiInScadenzaCents) }}</span>
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

            <!-- Le persone con rate scadute: le prime cinque, poi quante altre e lo scaduto per fasce -->
            <div v-if="treasury.morositaImpattanti.length > 0" class="mb-3">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Rate scadute dei condòmini</p>
                <p class="text-[9px] text-slate-400 mb-2">Lo scaduto di ognuno, senza togliere il suo credito: il credito è scritto sotto il nome.</p>
                <div class="space-y-1">
                    <component :is="estrattoConto(m) ? Link : 'div'" v-for="m in treasury.morositaImpattanti" :key="m.anagraficaId"
                        :href="estrattoConto(m) ?? undefined"
                        class="flex justify-between items-center px-2 py-1 rounded text-[10px] bg-slate-50/50 dark:bg-slate-800/30 border border-slate-100 dark:border-slate-800 hover:border-slate-300">
                        <span class="flex flex-col min-w-0 mr-2">
                            <span class="text-slate-600 dark:text-slate-400 truncate">{{ m.condomino }} — {{ m.immobile }}</span>
                            <span class="text-[9px] text-slate-400">{{ ritardo(m) }}</span>
                            <span v-if="(m.creditoCents ?? 0) > 0" class="text-[9px] text-sky-600 dark:text-sky-400">credito {{ euro(m.creditoCents ?? 0) }}</span>
                        </span>
                        <span class="font-bold text-slate-700 dark:text-slate-300 shrink-0">{{ euro(m.importoCents) }}</span>
                    </component>
                    <p v-if="treasury.morositaAltri > 0" class="text-[9px] text-slate-400 text-center pt-1">e altri {{ treasury.morositaAltri }}: lo scaduto di tutti è nelle fasce qui sotto</p>
                </div>
                <!-- Sempre, anche con il semaforo verde: l'elenco di tutte le persone con rate scadute. -->
                <component :is="elencoRateScadute ? Link : 'span'" v-if="treasury.elencoRateScadute" :href="elencoRateScadute ?? undefined" data-testid="vedi-tutte"
                    class="block text-[10px] font-semibold text-primary text-right mt-1 hover:underline">Vedi tutte le rate scadute</component>
                <div class="mt-2 grid grid-cols-3 gap-1">
                    <div v-for="f in FASCE" :key="f.chiave" class="text-[9px] text-slate-500 text-center">
                        {{ f.etichetta }} <span class="font-bold text-slate-700 dark:text-slate-300">{{ euro(treasury.scadutoPerFasce[f.chiave]) }}</span>
                    </div>
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
                        <span class="text-[10px] font-bold text-slate-700 dark:text-slate-300">Fatture pregresse non pagate</span>
                        <span class="text-[9px] text-slate-500">Debiti verso i fornitori, scaduti da prima</span>
                    </div>
                </div>
                <span class="text-xs font-black text-slate-700">{{ euro(treasury.debitiPregressiScadutiCents) }}</span>
            </div>

            <!-- Fondo riserva presente ma senza somme accantonate — §6.4 -->
            <div v-if="treasury.hasFondoRiserva && treasury.liquiditaVincolataCents === 0" 
                 class="flex items-center gap-2 p-2.5 rounded-lg bg-indigo-50/50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-800/30">
                <Lock class="w-3.5 h-3.5 text-indigo-400 shrink-0" />
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-indigo-700 dark:text-indigo-300">Fondi vincolati</span>
                    <span class="text-[9px] text-indigo-500">Il fondo riserva non ha somme accantonate</span>
                </div>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger><Info class="w-3 h-3 text-indigo-400/60 cursor-help shrink-0" /></TooltipTrigger>
                        <TooltipContent><p class="text-xs max-w-[200px]">Il condominio ha un fondo riserva, ma senza somme accantonate: il disponibile coincide con la liquidità. Le somme versate nel fondo con un giroconto vengono tolte dal disponibile.</p></TooltipContent>
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
