<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La «Tesoreria a 30 giorni» della dashboard del condominio: le quattro cifre su due righe («Disponibile oggi»
 * con da dove viene, «Entrate attese», «Uscite previste», «Fra 30 giorni»), lo stato con la sua scritta, e due cassetti come «Sei mesi per agire»: le fatture in
 * scadenza con «Paga» e le persone con rate scadute con la loro prossima azione. Il saldo previsto giorno per giorno è nella card accanto («Saldo previsto»).
 *
 * Tutto arriva dal server già deciso (`TreasuryGuardianService`): le cifre, la regola delle 8 (fino a 8 righe tutte, oltre le prime 5 e quante mancano), la
 * prossima azione di ogni persona. Nessuna azione parte da sola: ogni pulsante apre una pagina o la finestra delle lettere, dove l'amministratore decide.
 *
 * «Fra 30 giorni» segue lo scenario scelto nella card «Saldo previsto», che la pagina passa; il semaforo (lo stato e l'avviso) no: resta quello
 * del server, così non diventa verde perché si è scelto lo scenario migliore.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ChevronDown, ChevronRight, Info, Lock, TrendingDown, WalletCards } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import DaDoveViene from './DaDoveViene.vue';
import { usePermission } from '@/composables/permissions';
import { SCENARIO_DI_SERIE, euroSegno, finaleDelloScenario, giornoIntero, scenarioDi, type SaldoPrevisto, type Scenario } from '@/lib/gestionale/tesoreria/saldoPrevisto';
import {
    FRASE_PERSONE, azioniDellaPersona, fasceDellaBarra, statoDellaTesoreria, testoCassetto, testoMancanoFatture, testoMancanoPersone, testoScadenzaFattura,
    type Disponibile, type Livello, type PassoDellaRiga,
} from '@/lib/gestionale/tesoreria/tesoreria';
import type { TipoLettera } from '@/lib/gestionale/lettere/lettere';

interface FatturaInScadenza {
    id?: number;
    fornitore: string;
    numero: string;
    dataScadenza: string | null;
    scaduta?: boolean;
    importoCents: number;
}

interface PersonaConRateScadute {
    anagraficaId: number;
    immobile: string;
    condomino: string;
    importoCents: number;
    /** I giorni della rata più vecchia che il programma sa contare; nullo con il solo pregresso di anzianità non nota. */
    giorniRitardo: number | null;
    /** Il ritardo in parole, scritto dal server: «in ritardo di N giorni», o con il pregresso di anzianità non nota. */
    ritardoTesto?: string;
    /** Il credito della persona (lo stesso che «Rate scadute» toglie): l'importo resta lordo, il credito si mostra accanto. */
    creditoCents?: number;
    /** La prossima azione della regola dei sei mesi, vista tesoreria; null quando il server non l'ha. */
    passo?: PassoDellaRiga | null;
    route: string;
    routeParams: Record<string, number>;
}

const props = defineProps<{
    treasury: {
        condominioId?: number;
        liquiditaTotaleCents: number;
        liquiditaVincolataCents: number;
        liquiditaDisponibileCents: number;
        uscitePredittiveCents: number;
        incassiAttesiCents: number;
        incassiScadutiCents: number;
        incassiInScadenzaCents: number;
        debitiPregressiScadutiCents: number;
        scenarioPessimisticoCents: number;
        scenarioOttimisticoCents: number;
        giornoScopertoPrevisto: string | null;
        scopertoMaxCents: number;
        livello: Livello;
        fattureInScadenza: FatturaInScadenza[];
        fattureQuante?: number;
        fattureMancano?: { quante: number; cifra: number } | null;
        morositaImpattanti: PersonaConRateScadute[];
        morositaAltri: number;
        morositaAltriCents?: number;
        morositaPersone?: number;
        scadutoInTutto?: number;
        scadutoPerFasce: { '1_30': number; '31_90': number; oltre_90: number };
        elencoRateScadute?: { route: string; routeParams: Record<string, number> } | null;
        azioniSuggerite: Array<{ tipo: string; label: string; descrizione: string; impattoCents: number; route: string; routeParams?: Record<string, unknown> }>;
        fattureSenzaScadenza?: Array<{ fornitore: string; numero: string; importoCents: number }>;
        hasFondoRiserva?: boolean;
        disponibile?: Disponibile | null;
        saldoPrevisto?: SaldoPrevisto | null;
        suggerisceAzione?: boolean;
        oggi?: string | null;
    };
    /** Per ogni persona, la riga dei sei mesi dove è la sua prossima azione (l'ancora del riquadro): «La prossima azione è nei sei mesi ↓». */
    righeSeiMesi?: Record<number, string>;
    /** Lo scenario scelto nella card «Saldo previsto»: «Fra 30 giorni» lo segue. */
    scenario?: Scenario;
}>();

const t = computed(() => props.treasury);
const condominioId = computed(() => Number(t.value.condominioId ?? t.value.elencoRateScadute?.routeParams?.condominio ?? 0));
const { generateRoute } = usePermission();
/** Un indirizzo del gestionale del condominio; null se la rotta non c'è (il pulsante diventa testo, non porta in un vicolo cieco). */
const rotta = (nome: string, altro: Record<string, unknown> = {}) => {
    try {
        return route(generateRoute(`gestionale.${nome}`), { condominio: condominioId.value, ...altro });
    } catch {
        return null;
    }
};
const daServer = (r: { route: string; routeParams?: Record<string, unknown> } | null | undefined) => {
    if (!r) return null;
    try {
        return route(r.route, r.routeParams ?? {});
    } catch {
        return null;
    }
};

const stato = computed(() => statoDellaTesoreria(t.value.livello));
const TONI_STATO: Record<Livello, { badge: string; pallino: string; avviso: string }> = {
    rosso: { badge: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200', pallino: 'bg-red-500',
        avviso: 'border-red-200 bg-red-50/60 text-red-800 dark:border-red-900/40 dark:bg-red-900/10 dark:text-red-200' },
    giallo: { badge: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200', pallino: 'bg-amber-500',
        avviso: 'border-amber-200 bg-amber-50/60 text-amber-800 dark:border-amber-900/40 dark:bg-amber-900/10 dark:text-amber-200' },
    verde: { badge: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200', pallino: 'bg-emerald-500',
        avviso: 'border-emerald-100 bg-emerald-50/60 text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-900/10 dark:text-emerald-200' },
};
const toni = computed(() => TONI_STATO[t.value.livello] ?? TONI_STATO.verde);

// Le quattro cifre: «Fra 30 giorni» è l'ultimo giorno del saldo previsto nello scenario scelto nella card accanto (all'apertura «Senza le rate»).
const scenario = computed<Scenario>(() => props.scenario ?? SCENARIO_DI_SERIE);
const fra30 = computed(() => (t.value.saldoPrevisto ? finaleDelloScenario(t.value.saldoPrevisto, scenario.value) : t.value.scenarioPessimisticoCents));
const fra30ConEntrate = computed(() => t.value.saldoPrevisto?.finaleConEntrateCents ?? t.value.scenarioOttimisticoCents);
const colore = (cifra: number, positivo = 'text-slate-800 dark:text-slate-100') => (cifra < 0 ? 'text-red-600 dark:text-red-400' : cifra === 0 ? 'text-slate-400' : positivo);
const soloContiCorrenti = computed(() => !!t.value.disponibile?.solo_conti_correnti);

// L'avviso sotto le cifre: da quando il saldo va sotto zero, lo stesso giorno della card «Saldo previsto».
const sottoZeroDal = computed(() => t.value.saldoPrevisto?.primoSottoZero ?? t.value.giornoScopertoPrevisto);
const minimo = computed(() => Math.min(t.value.saldoPrevisto?.minimoCents ?? t.value.scopertoMaxCents, 0));
const emissione = computed(() => t.value.azioniSuggerite.find((a) => a.tipo === 'emissione_rate') ?? null);
// I movimenti già registrati con una data nei prossimi 30 giorni: sono nel saldo previsto e in «Fra 30 giorni», non nelle uscite né nelle entrate attese.
const registratiNelFlusso = computed(() => {
    const f = t.value.disponibile?.futuri;
    if (!f || f.nel_flusso_quanti <= 0) return null;

    return `Nel saldo previsto ci ${f.nel_flusso_quanti === 1 ? 'è anche 1 movimento già registrato' : `sono anche ${f.nel_flusso_quanti} movimenti già registrati`} con una data nei prossimi 30 giorni: ${euroSegno(f.nel_flusso_cifra)}.`;
});
const pregresse = computed(() => t.value.azioniSuggerite.find((a) => a.tipo === 'gestione_pregressi') ?? null);

// I cassetti: le fatture si aprono da sole quando il semaforo non è verde, perché sono il perché; le persone si aprono con la freccia.
const mostraFatture = computed(() => t.value.livello !== 'verde' && t.value.fattureInScadenza.length > 0);
const fattureAperte = ref(t.value.livello !== 'verde');
const personeAperte = ref(false);
const quanteFatture = computed(() => t.value.fattureQuante ?? t.value.fattureInScadenza.length);
const cifraFatture = computed(() => t.value.fattureInScadenza.reduce((s, f) => s + f.importoCents, 0) + (t.value.fattureMancano?.cifra ?? 0));
const fondoFatture = computed(() => testoMancanoFatture(t.value.fattureMancano ?? null, t.value.fattureInScadenza.length));
const fattureDaPagare = computed(() => rotta('fatture.index', { stato_pagamento: 'aperta' }));

const quantePersone = computed(() => t.value.morositaPersone ?? t.value.morositaImpattanti.length + t.value.morositaAltri);
const scadutoInTutto = computed(() => t.value.scadutoInTutto ?? Object.values(t.value.scadutoPerFasce).reduce((s, x) => s + x, 0));
const fondoPersone = computed(() => testoMancanoPersone(t.value.morositaAltri, t.value.morositaAltriCents ?? 0, t.value.morositaImpattanti.length));
const fasce = computed(() => fasceDellaBarra(t.value.scadutoPerFasce));
const elencoRateScadute = computed(() => daServer(t.value.elencoRateScadute) ?? rotta('rate-scadute.index'));

const suggerisce = computed(() => t.value.suggerisceAzione !== false);
const azioni = (m: PersonaConRateScadute) => azioniDellaPersona(m, suggerisce.value);
const estrattoConto = (m: PersonaConRateScadute) => daServer(m);
const incasso = (m: PersonaConRateScadute) => {
    const compensa = azioni(m).incasso === 'compensa' && m.passo?.rata_compensa;

    return rotta('movimenti-rate.create', { prefill_anagrafica_id: m.anagraficaId, ...(compensa ? { prefill_rata_id: m.passo!.rata_compensa } : {}) });
};
const noteDi = (m: PersonaConRateScadute) => m.passo?.note ?? [];
const neiSeiMesi = (m: PersonaConRateScadute) => `#${props.righeSeiMesi?.[m.anagraficaId] ?? 'sei-mesi-per-agire'}`;
const ritardo = (m: PersonaConRateScadute) =>
    m.ritardoTesto ?? (m.giorniRitardo === null ? '' : `in ritardo di ${m.giorniRitardo} ${m.giorniRitardo === 1 ? 'giorno' : 'giorni'}`);

// La finestra delle lettere, per una persona: con il sollecito che la regola suggerisce, o senza un tipo con «Scelgo io la prossima azione».
const lettera = ref<{ aperta: boolean; persona: { id: number; nome: string } | null; tipo: TipoLettera | null }>({ aperta: false, persona: null, tipo: null });
function scrivi(m: PersonaConRateScadute, tipo: TipoLettera | null) {
    lettera.value = { aperta: true, persona: { id: m.anagraficaId, nome: m.condomino }, tipo };
}
const ricarica = () => router.reload({ only: ['widgets', 'seiMesi', 'compitiSeiMesi'] });

// Il condominio ha un fondo, ma nessun fondo ha denaro: lo si dice, perché il disponibile non ne toglie niente.
const fondiVuoti = computed(() => !!t.value.hasFondoRiserva && (t.value.disponibile
    ? t.value.disponibile.righe.filter((r) => r.tipo === 'fondo').every((r) => (r.saldo ?? 0) === 0)
    : t.value.liquiditaVincolataCents === 0));
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden" data-testid="tesoreria">
        <!-- La testata: il titolo con «Da dove viene» accanto, lo stato a destra. -->
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/50 sm:px-5">
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                <WalletCards class="w-4 h-4 text-slate-400" />
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Tesoreria a 30 giorni</h3>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger aria-label="Che cosa fa la tesoreria"><Info class="w-3.5 h-3.5 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                        <TooltipContent side="bottom" class="max-w-xs">
                            <p class="text-xs">Il denaro di oggi e le uscite e le entrate dei prossimi 30 giorni, giorno per giorno. Per esempio: con € 4.480,38 oggi e una fattura
                                di € 5.000,00 che scade il 18/11, il saldo previsto va sotto zero il 18/11.</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <Popover v-if="t.disponibile">
                    <PopoverTrigger as-child>
                        <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" data-testid="apri-da-dove-viene">
                            <Info class="w-3.5 h-3.5" /> Da dove viene
                        </button>
                    </PopoverTrigger>
                    <PopoverContent align="start" class="w-80 max-w-[calc(100vw-2rem)] bg-white dark:bg-slate-900">
                        <DaDoveViene :disponibile="t.disponibile" :condominio-id="condominioId" />
                    </PopoverContent>
                </Popover>
            </div>
            <Badge variant="outline" :class="toni.badge" class="gap-1.5" data-testid="stato-tesoreria">
                <span class="inline-block h-1.5 w-1.5 rounded-full" :class="toni.pallino" aria-hidden="true"></span>{{ stato.etichetta }}
            </Badge>
        </div>

        <div class="space-y-4 p-4 sm:p-5">
            <p class="text-xs text-slate-500 dark:text-slate-400">Il disponibile di oggi, le uscite previste e le entrate attese dei prossimi 30 giorni. Il saldo giorno per giorno è nel grafico «Saldo previsto».</p>

            <!-- Le quattro cifre, su due righe. -->
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/30" data-testid="cifra-tesoreria">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Disponibile oggi</p>
                    <p class="mt-1 text-base font-bold leading-tight tabular-nums sm:text-lg" :class="colore(t.liquiditaDisponibileCents)" data-testid="valore">{{ euroSegno(t.liquiditaDisponibileCents) }}</p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">si può usare<template v-if="soloContiCorrenti"> · sul conto corrente</template></p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/30" data-testid="cifra-tesoreria">
                    <p class="flex items-center gap-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                        Entrate attese
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger aria-label="Che cosa sono le entrate attese"><Info class="w-3 h-3 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                                <TooltipContent class="max-w-xs">
                                    <p class="text-xs">Le rate dei condòmini già scadute ({{ euroSegno(t.incassiScadutiCents) }}) e quelle che scadono nei 30 giorni
                                        ({{ euroSegno(t.incassiInScadenzaCents) }}), al netto del credito di ognuno nella stessa gestione. Non sono nel saldo previsto: se arrivano,
                                        fra 30 giorni il saldo è {{ euroSegno(fra30ConEntrate) }}.</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </p>
                    <p class="mt-1 text-base font-bold leading-tight tabular-nums sm:text-lg" :class="colore(t.incassiAttesiCents, 'text-emerald-700 dark:text-emerald-400')" data-testid="valore">
                        {{ t.incassiAttesiCents > 0 ? '+' : '' }}{{ euroSegno(t.incassiAttesiCents) }}
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">rate dei condòmini, se arrivano</p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/30" data-testid="cifra-tesoreria">
                    <p class="flex items-center gap-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                        Uscite previste
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger aria-label="Che cosa sono le uscite previste"><Info class="w-3 h-3 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                                <TooltipContent class="max-w-xs">
                                    <p class="text-xs">Le fatture da pagare che scadono nei 30 giorni, comprese quelle già scadute, che contano da oggi. Le fatture pregresse scadute
                                        stanno a parte, qui sotto.</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </p>
                    <p class="mt-1 text-base font-bold leading-tight tabular-nums sm:text-lg" :class="t.uscitePredittiveCents > 0 ? 'text-rose-700 dark:text-rose-400' : 'text-slate-400'" data-testid="valore">
                        {{ euroSegno(-t.uscitePredittiveCents) }}
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">fatture dei fornitori</p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/30" data-testid="cifra-tesoreria">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Fra 30 giorni</p>
                    <p class="mt-1 text-base font-bold leading-tight tabular-nums sm:text-lg" :class="colore(fra30)" data-testid="valore">{{ euroSegno(fra30) }}</p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400" data-testid="scenario-fra-30">«{{ scenarioDi(scenario).etichetta }}», come il grafico</p>
                </div>
            </div>

            <!-- Lo stato in una frase: da quando il saldo va sotto zero, e che cosa fare se non ci sono entrate attese. -->
            <div class="rounded-lg border p-3 text-xs" :class="toni.avviso" data-testid="avviso-tesoreria">
                <p v-if="sottoZeroDal && t.livello !== 'verde'">
                    <strong class="font-semibold">Sotto zero dal {{ giornoIntero(sottoZeroDal) }}</strong>: fino a {{ euroSegno(minimo) }} senza le entrate attese.
                    {{ t.livello === 'giallo' ? 'Con le entrate attese, se arrivano in tempo, il saldo resta sopra zero.' : 'Anche con le entrate attese il saldo non basta.' }}
                </p>
                <p v-else>{{ stato.frase }}</p>
                <p v-if="registratiNelFlusso" class="mt-1" data-testid="registrati-nel-flusso">{{ registratiNelFlusso }}</p>
                <p v-if="t.livello !== 'verde' && emissione && daServer(emissione)" class="mt-1">
                    Non ci sono entrate attese che coprano le uscite: le rate si emettono dai piani rate.
                    <Link :href="daServer(emissione)!" class="font-medium underline underline-offset-2">Apri i piani rate →</Link>
                </p>
            </div>

            <!-- Il cassetto delle fatture: con il semaforo non verde, aperto di serie. -->
            <div v-if="mostraFatture" class="rounded-lg border border-slate-200 dark:border-slate-700" data-testid="cassetto-fatture">
                <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-semibold text-slate-800 hover:bg-slate-50 dark:text-slate-100 dark:hover:bg-slate-800/50"
                    :aria-expanded="fattureAperte ? 'true' : 'false'" @click="fattureAperte = !fattureAperte">
                    <ChevronDown v-if="fattureAperte" class="h-4 w-4 shrink-0 text-slate-400" /><ChevronRight v-else class="h-4 w-4 shrink-0 text-slate-400" />
                    <span class="min-w-0">{{ testoCassetto('fatture', quanteFatture, cifraFatture) }}</span>
                </button>
                <div v-if="fattureAperte" class="space-y-1.5 border-t border-slate-100 px-3 py-3 dark:border-slate-800">
                    <div class="hidden gap-3 px-2 text-xs font-medium text-slate-400 whitespace-nowrap sm:grid sm:grid-cols-[minmax(0,1fr)_9.5rem_7rem_6rem]">
                        <span>Fornitore e numero</span><span>Scadenza</span><span class="text-right">Da pagare</span><span>Prossima azione</span>
                    </div>
                    <div v-for="f in t.fattureInScadenza" :key="f.id ?? f.numero" data-testid="riga-fattura"
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-lg border border-slate-100 bg-slate-50/50 px-2 py-2 text-sm dark:border-slate-800 dark:bg-slate-800/30 sm:grid-cols-[minmax(0,1fr)_9.5rem_7rem_6rem]">
                        <span class="min-w-0 truncate text-slate-700 dark:text-slate-200">{{ f.fornitore }} — n. {{ f.numero }}</span>
                        <span class="text-xs sm:order-none order-3" :class="f.scaduta ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'" data-testid="scadenza">{{ testoScadenzaFattura(f) }}</span>
                        <span class="text-right font-semibold tabular-nums whitespace-nowrap text-slate-800 dark:text-slate-100 order-2 sm:order-none">{{ euroSegno(f.importoCents) }}</span>
                        <Link v-if="f.id && rotta('pagamenti-fornitori.create', { fattura_id: f.id })" :href="rotta('pagamenti-fornitori.create', { fattura_id: f.id })!"
                            class="order-4 sm:order-none" data-testid="azione-paga">
                            <Button size="sm" variant="outline" class="h-8 w-full px-2 text-xs" tabindex="-1">Paga</Button>
                        </Link>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 px-2 pt-1 text-xs" data-testid="fondo-fatture">
                        <span v-if="fondoFatture" class="text-slate-500 dark:text-slate-400">{{ fondoFatture }}</span>
                        <Link v-if="fattureDaPagare" :href="fattureDaPagare" class="ml-auto font-medium text-primary hover:underline">Vedi le fatture da pagare →</Link>
                    </div>
                </div>
            </div>

            <!-- Il cassetto delle persone con rate scadute: sempre, quando ce ne sono. -->
            <div v-if="quantePersone > 0" class="rounded-lg border border-slate-200 dark:border-slate-700" data-testid="cassetto-persone">
                <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-semibold text-slate-800 hover:bg-slate-50 dark:text-slate-100 dark:hover:bg-slate-800/50"
                    :aria-expanded="personeAperte ? 'true' : 'false'" @click="personeAperte = !personeAperte">
                    <ChevronDown v-if="personeAperte" class="h-4 w-4 shrink-0 text-slate-400" /><ChevronRight v-else class="h-4 w-4 shrink-0 text-slate-400" />
                    <span class="min-w-0">{{ testoCassetto('persone', quantePersone, scadutoInTutto) }}</span>
                </button>
                <div v-if="personeAperte" class="space-y-2 border-t border-slate-100 px-3 py-3 dark:border-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_PERSONE }}</p>
                    <div class="hidden gap-3 px-2 text-xs font-medium text-slate-400 whitespace-nowrap sm:grid sm:grid-cols-[minmax(0,1fr)_7rem_11rem]">
                        <span>Persona e a che punto è</span><span class="text-right">Scaduto</span><span>Prossima azione</span>
                    </div>
                    <div v-for="m in t.morositaImpattanti" :key="m.anagraficaId" data-testid="riga-persona"
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3 gap-y-2 rounded-lg border border-slate-100 bg-slate-50/50 px-2 py-2 dark:border-slate-800 dark:bg-slate-800/30 sm:grid-cols-[minmax(0,1fr)_7rem_11rem]">
                        <div class="min-w-0 space-y-0.5">
                            <component :is="estrattoConto(m) ? Link : 'span'" :href="estrattoConto(m) ?? undefined" data-testid="nome-persona"
                                class="block truncate text-sm font-medium text-slate-800 hover:underline dark:text-slate-100">{{ m.condomino }} — {{ m.immobile }}</component>
                            <p v-if="ritardo(m)" class="text-xs text-slate-500 dark:text-slate-400">{{ ritardo(m) }}</p>
                            <p v-if="m.passo?.frase" class="text-xs text-slate-600 dark:text-slate-300">{{ m.passo.frase }}</p>
                            <p v-for="(n, i) in noteDi(m)" :key="i" class="text-xs text-slate-500 dark:text-slate-400">{{ n }}</p>
                            <p v-if="(m.creditoCents ?? 0) > 0" class="text-xs text-sky-700 dark:text-sky-300">credito {{ euroSegno(m.creditoCents ?? 0) }}</p>
                            <a v-if="azioni(m).neiSeiMesi" :href="neiSeiMesi(m)" class="inline-block text-xs font-medium text-primary hover:underline" data-testid="nei-sei-mesi">La prossima azione è nei sei mesi ↓</a>
                        </div>
                        <span class="text-right text-sm font-bold tabular-nums whitespace-nowrap text-slate-800 dark:text-slate-100">{{ euroSegno(m.importoCents) }}</span>
                        <div class="col-span-2 grid grid-cols-2 gap-1.5 sm:col-span-1 sm:grid-cols-1">
                            <Link v-if="incasso(m)" :href="incasso(m)!" data-testid="azione-incasso" class="w-full">
                                <Button size="sm" variant="outline" class="h-8 w-full px-2 text-xs" tabindex="-1">{{ azioni(m).incasso === 'compensa' ? 'Compensa' : 'Registra l\'incasso' }}</Button>
                            </Link>
                            <Button v-if="azioni(m).lettera === 'sollecito'" size="sm" variant="outline" class="h-8 w-full px-2 text-xs" data-testid="azione-sollecito" @click="scrivi(m, 'sollecito')">Manda un sollecito</Button>
                            <Button v-else-if="azioni(m).lettera === 'scrivi'" size="sm" variant="outline" class="h-8 w-full px-2 text-xs" data-testid="azione-scrivi" @click="scrivi(m, null)">Scrivi una lettera</Button>
                        </div>
                    </div>

                    <!-- Il fondo della lista: lo scaduto in tutto, la barra delle fasce, le tre fasce, la riga di fondo. -->
                    <div class="space-y-2 border-t border-slate-100 px-2 pt-2 dark:border-slate-800">
                        <div class="flex items-baseline justify-between gap-3 text-sm" data-testid="scaduto-in-tutto">
                            <span class="font-medium text-slate-700 dark:text-slate-200">Scaduto in tutto · {{ quantePersone === 1 ? '1 persona' : `${quantePersone} persone` }}</span>
                            <span class="font-bold tabular-nums text-slate-800 dark:text-slate-100">{{ euroSegno(scadutoInTutto) }}</span>
                        </div>
                        <div class="flex h-2 w-full overflow-hidden rounded bg-slate-100 dark:bg-slate-800" data-testid="barra-fasce" aria-hidden="true">
                            <div v-for="f in fasce" :key="f.chiave" :style="{ width: `${f.percentuale}%` }"
                                :class="f.chiave === 'oltre_90' ? 'bg-red-500' : f.chiave === '31_90' ? 'bg-amber-400' : 'bg-slate-400'"></div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div v-for="f in fasce" :key="f.chiave" data-testid="fascia" class="text-xs"
                                :class="f.spenta ? 'text-slate-400' : f.rossa ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-300'">
                                {{ f.etichetta }} <span class="block font-semibold tabular-nums">{{ euroSegno(f.cifra) }}</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs" data-testid="fondo-persone">
                            <span v-if="fondoPersone" class="text-slate-500 dark:text-slate-400">{{ fondoPersone }}</span>
                            <Link v-if="elencoRateScadute" :href="elencoRateScadute" class="ml-auto font-medium text-primary hover:underline">Vedi tutte le rate scadute →</Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Le fatture senza scadenza: non entrano nella previsione, e la frase lo dice. -->
            <div v-if="t.fattureSenzaScadenza && t.fattureSenzaScadenza.length > 0"
                class="flex items-start gap-2 rounded-lg border border-orange-200 bg-orange-50/80 p-3 dark:border-orange-800/30 dark:bg-orange-900/10">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-orange-500" />
                <div class="min-w-0 text-xs">
                    <p class="font-semibold text-orange-700 dark:text-orange-300">
                        {{ t.fattureSenzaScadenza.length }} fattur{{ t.fattureSenzaScadenza.length === 1 ? 'a' : 'e' }} senza data di scadenza
                    </p>
                    <p class="text-orange-700/80 dark:text-orange-300/80">Non entrano nelle uscite previste: scrivi la scadenza nella fattura per una previsione giusta.</p>
                </div>
            </div>

            <!-- Le fatture pregresse non pagate: debiti verso i fornitori scaduti da prima, a parte. -->
            <div v-if="t.debitiPregressiScadutiCents > 0"
                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                <div class="flex min-w-0 items-start gap-2">
                    <TrendingDown class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <div class="min-w-0 text-xs">
                        <p class="font-semibold text-slate-700 dark:text-slate-200">Fatture pregresse non pagate</p>
                        <p class="text-slate-500 dark:text-slate-400">Debiti verso i fornitori, scaduti da prima: non sono nelle uscite previste.</p>
                        <Link v-if="daServer(pregresse)" :href="daServer(pregresse)!" class="font-medium text-primary hover:underline">Apri le fatture pregresse →</Link>
                    </div>
                </div>
                <span class="text-sm font-bold tabular-nums text-slate-800 dark:text-slate-100">{{ euroSegno(t.debitiPregressiScadutiCents) }}</span>
            </div>

            <!-- Il fondo di riserva senza somme accantonate (§6.4). -->
            <div v-if="fondiVuoti"
                class="flex items-start gap-2 rounded-lg border border-indigo-100 bg-indigo-50/50 p-3 text-xs dark:border-indigo-800/30 dark:bg-indigo-900/10">
                <Lock class="mt-0.5 h-4 w-4 shrink-0 text-indigo-400" />
                <p class="text-indigo-700 dark:text-indigo-300">
                    <span class="font-semibold">Il fondo riserva non ha somme accantonate</span>: il disponibile non ne toglie niente. Le somme versate nel fondo con un giroconto si
                    vedono in «Da dove viene».
                </p>
            </div>
        </div>

        <LettereDialog
            v-model:open="lettera.aperta"
            :condominio-id="condominioId"
            :persona="lettera.persona"
            :selezione="null"
            :tipo-iniziale="lettera.tipo"
            :oggi="t.oggi ?? ''"
            @fatto="ricarica"
        />
    </section>
</template>
