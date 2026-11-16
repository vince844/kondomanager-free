<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La card «Saldo previsto» della dashboard del condominio: nella colonna di destra della fila della tesoreria, sopra «Crediti da compensare».
 * Il saldo giorno per giorno dei prossimi 30 giorni, a gradini, con la zona sotto zero rossa e un punto sul primo giorno sotto zero, lo stesso della scritta in
 * testa «sotto zero dal …». Il grafico (Chart.js) si carica a parte, solo qui; per chi non lo vede c'è la descrizione e la tabella dei giorni in una finestra.
 *
 * In alto i tre scenari affiancati, ognuno largo quanto il suo testo e su una riga: «Senza le rate» (all'apertura), «Con le rate in scadenza», «Se pagano
 * tutti», con la riga di che cosa conta lo scenario. Lo scenario cambia la linea, la scritta in testa con il punto e la cifra «Fra 30 giorni» della
 * tesoreria (la pagina lo passa); il semaforo no. Sotto il grafico «N azioni consigliate», che apre il cassetto con la situazione, il grafico con tre linee, la simulazione e le azioni;
 * senza azioni il pulsante non c'è; con «Scelgo io» nel condominio dice «Simula il saldo».
 */
import { computed, defineAsyncComponent, ref } from 'vue';
import { ChartLine, Info, PanelRightOpen, Sparkles } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AzioniConsigliate from './AzioniConsigliate.vue';
import {
    SCENARI, SCENARIO_DI_SERIE, descrizioneDelGrafico, righeDellaTabella, scenarioDi, testoInTesta, type SaldoPrevisto, type Scenario,
} from '@/lib/gestionale/tesoreria/saldoPrevisto';
import { azioniConsigliate, testoDelPulsante, type DatiDelleAzioni, type Recupero } from '@/lib/gestionale/tesoreria/azioniConsigliate';

const GraficoSaldoPrevisto = defineAsyncComponent(() => import('./GraficoSaldoPrevisto.vue'));

const props = withDefaults(defineProps<{
    saldoPrevisto: SaldoPrevisto;
    /** Le persone del recupero, dalla tesoreria: senza (una tesoreria di prima) niente pulsante. */
    recupero?: Recupero | null;
    /** La scelta del condominio «Il programma suggerisce la prossima azione»: spenta, il cassetto non ha le azioni. */
    suggerisce?: boolean;
    condominioId?: number;
    oggi?: string;
    /** I fatti delle altre azioni consigliate, dalla tesoreria: senza, solo il recupero e le fatture. */
    datiDelleAzioni?: DatiDelleAzioni | null;
}>(), { recupero: null, suggerisce: true, condominioId: 0, oggi: '', datiDelleAzioni: null });

/** Lo scenario scelto: lo legge anche la tesoreria accanto, per «Fra 30 giorni». */
const scenario = defineModel<Scenario>('scenario', { default: SCENARIO_DI_SERIE });

const inTesta = computed(() => testoInTesta(props.saldoPrevisto, scenario.value));
const sottoZero = computed(() => inTesta.value !== 'mai sotto zero');
const descrizione = computed(() => descrizioneDelGrafico(props.saldoPrevisto, scenario.value));
const righe = computed(() => righeDellaTabella(props.saldoPrevisto, scenario.value));
const giorniAperti = ref(false);

const azioni = computed(() => (props.recupero ? azioniConsigliate(props.saldoPrevisto, props.recupero, props.datiDelleAzioni) : []));
const pulsante = computed(() => (props.recupero ? testoDelPulsante(azioni.value.length, props.suggerisce) : null));
const cassettoAperto = ref(false);
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden" data-testid="saldo-previsto">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/50">
            <div class="flex items-center gap-1.5">
                <ChartLine class="w-4 h-4 text-slate-400" />
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Saldo previsto</h3>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger aria-label="Che cosa dice il grafico"><Info class="w-3.5 h-3.5 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                        <TooltipContent side="bottom" class="max-w-xs">
                            <p class="text-xs">Ogni gradino è un giorno: il saldo a fine giornata. «Senza le rate» conta solo le fatture e i movimenti già registrati; «Con le rate
                                in scadenza» anche le rate che scadono nei 30 giorni, al loro giorno; «Se pagano tutti» anche quelle già scadute, da oggi. Per esempio: con
                                € 1.462,03 oggi e € 3.830,80 di fatture il 20/11, senza le rate il saldo va sotto zero il 20/11; se il 30/11 scadono € 2.400,00 di rate, con le
                                rate in scadenza torna sopra zero il 30/11.</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
            <span class="text-xs font-semibold" :class="sottoZero ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'" data-testid="in-testa">{{ inTesta }}</span>
        </div>
        <div class="space-y-2 p-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">Il saldo a fine giornata, giorno per giorno, nei prossimi 30 giorni.</p>

            <!--
                I tre scenari: un gruppo di pulsanti, non un menu. Il semaforo della tesoreria non segue la scelta. Ogni pulsante è largo quanto il suo testo, su una
                riga sola: tre colonne uguali mandavano «Con le rate in scadenza» su due righe. A 1.440 e a 1.280 pixel la card è larga 398–403 pixel e i tre testi a
                12 pixel ne chiedono al più 332 con il margine; dove la card è più stretta (al telefono) il terzo pulsante va intero sulla riga sotto.
            -->
            <div role="group" aria-label="Lo scenario del grafico" data-testid="scenari"
                class="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-xs dark:bg-slate-800">
                <button v-for="s in SCENARI" :key="s.chiave" type="button" data-testid="scenario" :aria-pressed="scenario === s.chiave ? 'true' : 'false'"
                    class="flex-auto whitespace-nowrap rounded-md px-2 py-1.5 text-center leading-tight transition-colors"
                    :class="scenario === s.chiave
                        ? 'bg-white font-semibold text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
                    @click="scenario = s.chiave">
                    {{ s.etichetta }}
                </button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="frase-scenario">{{ scenarioDi(scenario).frase }}</p>

            <div class="h-48 w-full" role="img" :aria-label="descrizione" data-testid="grafico-saldo">
                <GraficoSaldoPrevisto :saldo-previsto="saldoPrevisto" :scenario="scenario" />
            </div>
            <div class="flex justify-end">
                <Button variant="ghost" size="sm" class="h-8 px-2 text-xs text-primary" data-testid="vedi-i-giorni" @click="giorniAperti = true">Vedi i giorni</Button>
            </div>
            <button v-if="pulsante" type="button" data-testid="apri-azioni"
                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 text-sm font-bold text-indigo-700 transition-colors hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300 dark:hover:bg-indigo-950/70"
                @click="cassettoAperto = true">
                <Sparkles class="h-4 w-4" />
                {{ pulsante }}
                <PanelRightOpen class="h-4 w-4" />
            </button>
        </div>

        <Dialog v-model:open="giorniAperti">
            <DialogContent class="sm:max-w-lg max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Saldo previsto, giorno per giorno</DialogTitle>
                    <DialogDescription>{{ descrizione }}</DialogDescription>
                </DialogHeader>
                <table class="w-full table-fixed text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs font-medium text-slate-500 dark:border-slate-700 dark:text-slate-400">
                            <th class="w-24 py-1.5 font-medium whitespace-nowrap">Giorno</th>
                            <th class="w-28 py-1.5 pr-3 text-right font-medium whitespace-nowrap">Saldo</th>
                            <th class="py-1.5 font-medium whitespace-nowrap">Che cosa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in righe" :key="r.giorno" class="border-b border-slate-100 align-top dark:border-slate-800">
                            <td class="py-1.5 tabular-nums">{{ r.giorno }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums whitespace-nowrap" :class="r.sottoZero ? 'text-red-600 dark:text-red-400' : ''">{{ r.saldo }}</td>
                            <td class="py-1.5 text-xs text-slate-600 dark:text-slate-300">{{ r.cheCosa }}</td>
                        </tr>
                    </tbody>
                </table>
            </DialogContent>
        </Dialog>

        <AzioniConsigliate
            v-if="recupero"
            v-model:open="cassettoAperto"
            :saldo-previsto="saldoPrevisto"
            :recupero="recupero"
            :suggerisce="suggerisce"
            :condominio-id="condominioId"
            :oggi="oggi"
            :dati-delle-azioni="datiDelleAzioni"
        />
    </section>
</template>
