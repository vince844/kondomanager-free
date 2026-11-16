<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il riquadro «Sei mesi per agire» della dashboard del condominio (art. 1129 c. 9 c.c.): una riga per esercizio, in ordine di data, con la data, i giorni, le
 * persone e la cifra «di questo esercizio»; aperta, la tabella delle persone con le colonne fisse da `lg` («Persona», «Non versato», «A che punto è»,
 * «Prossima azione», «Registra che hai agito»): la prossima azione che il programma suggerisce (o le azioni possibili, con «Scelgo io la prossima azione»,
 * una scelta del condominio). In fondo alla riga le azioni in blocco, la dispensa, la frase sulle lettere e «Tutte le rate di questo esercizio →». Sotto, le
 * rate senza la data dei sei mesi, che si aprono come la riga di un esercizio (le persone, perché la data manca, la prossima azione del caso), e il rendiconto
 * da portare in assemblea entro 180 giorni (art. 1130 n. 10 c.c.).
 *
 * Tutto arriva dal server già deciso (`SeiMesiPerAgire`): il riquadro non ricalcola niente. Nessun passo parte da solo: ogni pulsante apre una finestra o una
 * pagina dove l'amministratore guarda e decide. Niente scorrimento dentro il riquadro: fino a 8 persone tutte, oltre le 5 con la cifra più alta e la riga di fondo.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
    ArrowRight, CalendarCheck, CalendarPlus, CheckCircle2, ChevronDown, ChevronRight, Hourglass, Info, MoreHorizontal,
} from 'lucide-vue-next';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import AccordoRientroDialog from '@/components/gestionale/accordi/AccordoRientroDialog.vue';
import FascicoloLegaleDialog from '@/components/gestionale/fascicolo/FascicoloLegaleDialog.vue';
import PassoMenu from './PassoMenu.vue';
import { usePermission } from '@/composables/permissions';
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import {
    COLONNE_PERSONE, COLONNE_SENZA_DATA, FRASE_LETTERE, FRASE_NON_VERSATO, FRASE_PIEDE, FRASE_PIEDE_SCELGO_IO, FRASE_RENDICONTO, FRASE_SENZA_DATA, FRASE_SUGGERIMENTO,
    FRASE_VUOTO, PASSI_CON_UNA_PORTA, TITOLO, annoDi, classiDelColore, testiCartellini,
    testoChiuse, testoDelGesto, testoGiorni, testoInBlocco, testoOltre, testoPersoneCifra, testoRigaSenzaAperte, testoSenzaData,
    type MotivoSenzaData, type Passo, type PersonaDellaRiga, type PersonaSenzaData, type RendicontoInAssemblea, type RichiestaDiRisposta, type RigaSeiMesi, type SeiMesi,
} from '@/lib/gestionale/debiti/seiMesiPerAgire';
import type { TipoLettera } from '@/lib/gestionale/lettere/lettere';

const props = defineProps<{ seiMesi: SeiMesi & { testo_norma?: string }; condominioId: number; puoRispondere: boolean }>();
const emit = defineEmits<{ (e: 'rispondi', r: RichiestaDiRisposta): void }>();

const { generateRoute } = usePermission();
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.${nome}`), { condominio: props.condominioId, ...altro });

// Le righe aperte: la prima con la data passata o entro 60 giorni, e quella dell'ancora nell'indirizzo (il compito dell'Inbox porta qui).
const aperte = ref<Set<string>>(new Set(props.seiMesi.righe.filter((r) => r.aperta).map((r) => r.chiave)));
const chiuseMostrate = ref<Set<string>>(new Set());
const registroMostrato = ref<Set<string>>(new Set());
const insiemi = { aperte, chiuse: chiuseMostrate, registro: registroMostrato };
const alterna = (nome: keyof typeof insiemi, chiave: string) => {
    const insieme = insiemi[nome];
    const nuovo = new Set(insieme.value);
    if (nuovo.has(chiave)) {
        nuovo.delete(chiave);
    } else {
        nuovo.add(chiave);
    }
    insieme.value = nuovo;
};
function apriDallAncora() {
    const ancora = typeof window !== 'undefined' ? window.location.hash.replace('#', '') : '';
    if (ancora && props.seiMesi.righe.some((r) => r.chiave === ancora)) {
        aperte.value = new Set([...aperte.value, ancora]);
    }
}
onMounted(() => {
    apriDallAncora();
    window.addEventListener('hashchange', apriDallAncora);
});
onUnmounted(() => window.removeEventListener('hashchange', apriDallAncora));

const vicine = computed(() => props.seiMesi.righe.filter((r) => !r.piu_avanti));
const piuAvanti = computed(() => props.seiMesi.righe.filter((r) => r.piu_avanti));
const vuoto = computed(() => props.seiMesi.righe.length === 0 && !props.seiMesi.senza_data && !props.seiMesi.troppe);

// La finestra delle lettere: per una persona o per le persone della riga, con il tipo proposto.
const lettera = ref<{ aperta: boolean; persona: { id: number; nome: string } | null; ids: number[]; tipo: TipoLettera | null }>({ aperta: false, persona: null, ids: [], tipo: null });
const accordo = ref<{ aperta: boolean; persona: { id: number; nome: string } | null }>({ aperta: false, persona: null });
const fascicolo = ref<{ aperta: boolean; persona: { id: number; nome: string } | null }>({ aperta: false, persona: null });

function scegli(riga: RigaSeiMesi, p: PersonaDellaRiga, passo: Passo) {
    switch (passo) {
        case 'diffida':
        case 'sollecito':
            lettera.value = { aperta: true, persona: { id: p.anagrafica_id, nome: p.nome }, ids: [], tipo: passo };
            break;
        case 'spedizione':
            router.visit(rotta('anagrafiche.estratto-conto', { anagrafica: p.anagrafica_id, scheda: 'corrispondenza' }));
            break;
        case 'accordo':
            accordo.value = { aperta: true, persona: { id: p.anagrafica_id, nome: p.nome } };
            break;
        case 'fascicolo':
            fascicolo.value = { aperta: true, persona: { id: p.anagrafica_id, nome: p.nome } };
            break;
        case 'compensa':
            router.visit(rotta('movimenti-rate.create', { prefill_anagrafica_id: p.anagrafica_id, ...(p.passo.rata_compensa ? { prefill_rata_id: p.passo.rata_compensa } : {}) }));
            break;
        case 'agito':
            agito(riga, p);
            break;
        default:
            break;
    }
}
function inBlocco(riga: RigaSeiMesi, b: { passo: Passo; persone: number[] }) {
    if (b.passo === 'spedizione') {
        router.visit(rotta('rate-scadute.index', { da_spedire: 1, sei_mesi_del: riga.data }));

        return;
    }
    lettera.value = { aperta: true, persona: null, ids: b.persone, tipo: b.passo as TipoLettera };
}
function scriviATutte(riga: RigaSeiMesi) {
    lettera.value = { aperta: true, persona: null, ids: (riga.tutte.length ? riga.tutte : riga.persone).map((p) => p.anagrafica_id), tipo: null };
}
const spedizioni = (riga: RigaSeiMesi) => riga.in_blocco.find((b) => b.passo === 'spedizione') ?? null;
const proposti = (riga: RigaSeiMesi) => riga.in_blocco.filter((b) => b.passo !== 'spedizione');

const richiesta = (riga: RigaSeiMesi, tipo: RichiestaDiRisposta['tipo']): RichiestaDiRisposta => ({ tipo, argomento: 'sei_mesi', esercizio: riga.esercizio, data: riga.data, persone: riga.tutte });
function agito(riga: RigaSeiMesi, p: PersonaDellaRiga | { anagrafica_id: number; nome: string; cifra: number }) {
    emit('rispondi', { ...richiesta(riga, 'agito'), persona: { anagrafica_id: p.anagrafica_id, nome: p.nome, cifra: p.cifra, situazione: '' } });
}
function riapriPersona(riga: RigaSeiMesi, c: { anagrafica_id: number; nome: string; cifra: number }) {
    emit('rispondi', { ...richiesta(riga, 'riapri'), persona: { anagrafica_id: c.anagrafica_id, nome: c.nome, cifra: c.cifra, situazione: '' } });
}
const rispostaRendiconto = (r: RendicontoInAssemblea, tipo: RichiestaDiRisposta['tipo']): RichiestaDiRisposta => ({ tipo, argomento: 'rendiconto', esercizio: r.esercizio, data: r.data });

const indirizzoDispense = computed(() => rotta('dispense.index'));
const indirizzoElenco = (riga: RigaSeiMesi) => rotta('rate-scadute.index', { sei_mesi_del: riga.data });
// Troppe rate per calcolare qui i sei mesi: l'elenco delle rate scadute in ordine di data dei sei mesi, come la lista dello studio per lo stesso caso.
const indirizzoTutteLeRate = computed(() => rotta('rate-scadute.index', { ordine: 'sei_mesi' }));
const indirizzoAgenda = computed(() => route(generateRoute('eventi.index')));
// Chi non chiude i compiti non registra che ha agito: la voce non c'è nemmeno nel menu dei passi (il server direbbe di no).
const passiDisponibili = computed<Passo[]>(() => (props.puoRispondere ? PASSI_CON_UNA_PORTA : PASSI_CON_UNA_PORTA.filter((p) => p !== 'agito')));
const indirizzoEstratto = (id: number) => rotta('anagrafiche.estratto-conto', { anagrafica: id });
const fraseDelPiede = computed(() => (props.seiMesi.passo_proposto ? FRASE_PIEDE : FRASE_PIEDE_SCELGO_IO));

// Le colonne fisse della tabella delle persone, da lg: la persona, la cifra, a che punto è, la prossima azione e «Registra che hai agito», queste due larghe
// abbastanza per il pulsante più lungo, così i pulsanti sono uguali in ogni riga. Sotto lg una riga sotto l'altra.
const GRIGLIA = 'lg:grid-cols-[minmax(0,1.1fr)_7rem_minmax(0,1.7fr)_15rem_11rem]';
const GRIGLIA_SENZA_DATA = 'lg:grid-cols-[minmax(0,1.1fr)_7rem_minmax(0,1.9fr)_15rem]';
/** La spiegazione sotto la frase di «A che punto è»: chi torna, le note, il termine. */
const spiegazione = (p: PersonaDellaRiga) => [p.torna, ...(p.passo.note ?? []), p.passo.termine].filter((x): x is string => !!x);

// Le rate senza la data dei sei mesi: la riga si apre con la freccia, come quella di un esercizio.
const senzaDataAperta = ref(false);
const personeSenzaData = computed<PersonaSenzaData[]>(() => props.seiMesi.senza_data?.righe ?? []);
/** Dove porta la prossima azione di un perché: i saldi con la finestra sulla persona, l'esercizio nuovo, il piano rate, l'estratto conto. */
function indirizzoAzione(p: PersonaSenzaData, m: MotivoSenzaData): string {
    switch (m.azione.tipo) {
        case 'origine':
            return rotta('saldi.index', { origine_di: p.anagrafica_id });
        case 'esercizio':
            return rotta('esercizi.create');
        case 'piano':
            return m.azione.piano?.esercizio_id
                ? rotta('esercizi.piani-rate.show', { esercizio: m.azione.piano.esercizio_id, pianoRate: m.azione.piano.id })
                : indirizzoEstratto(p.anagrafica_id);
        default:
            return indirizzoEstratto(p.anagrafica_id);
    }
}
const ricarica = () => router.reload({ only: ['seiMesi', 'compitiSeiMesi', 'widgets'] });
</script>

<template>
    <section id="sei-mesi-per-agire" class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden scroll-mt-20" data-testid="sei-mesi-per-agire">
        <!-- La testata -->
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/50 sm:px-5">
            <div class="flex items-center gap-1.5">
                <Hourglass class="w-4 h-4 text-slate-400" />
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">{{ TITOLO }}</h3>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger><Info class="w-3.5 h-3.5 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                        <TooltipContent side="bottom" class="max-w-sm">
                            <p class="text-xs italic">{{ seiMesi.testo_norma }}</p>
                            <p class="mt-1 text-xs">{{ FRASE_SUGGERIMENTO }}</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
            <Link :href="indirizzoDispense" class="text-xs font-medium text-slate-500 hover:text-primary hover:underline">Dispense dell'assemblea</Link>
        </div>

        <div class="space-y-3 p-3 sm:p-4">
            <p v-if="seiMesi.troppe" class="rounded-lg border border-slate-200 bg-slate-50/50 p-3 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-300">
                {{ seiMesi.troppe.frase }} <Link :href="indirizzoTutteLeRate" class="font-medium text-primary hover:underline">Apri l'elenco per data dei sei mesi →</Link>
            </p>

            <div v-if="vuoto" class="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">
                <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-500" /> {{ FRASE_VUOTO }}
            </div>

            <!-- Le righe dei sei mesi: prima le vicine e le passate, poi «Più avanti». -->
            <template v-for="(gruppo, g) in [vicine, piuAvanti]" :key="g">
                <p v-if="g === 1 && gruppo.length" class="pt-1 text-xs font-bold uppercase tracking-widest text-slate-400">Più avanti</p>
                <div v-for="riga in gruppo" :id="riga.chiave" :key="riga.chiave" :data-testid="riga.chiave"
                    class="rounded-lg border border-slate-200 scroll-mt-20 dark:border-slate-700"
                    :class="riga.risposta?.stato === 'non_serve' ? 'opacity-70' : ''">
                    <!-- La riga chiusa: la data e i giorni; l'esercizio, le persone e la cifra, i cartellini; la risposta, il menu e la freccia. -->
                    <div class="flex flex-col gap-2 p-3 lg:flex-row lg:items-center lg:gap-4" :class="classiDelColore(riga.colore).sfondo" data-testid="testa-riga">
                        <div class="lg:w-36 shrink-0">
                            <p class="text-base font-black text-slate-800 dark:text-slate-100 tabular-nums">{{ giorno(riga.data) }}</p>
                            <p class="text-xs font-semibold" :class="classiDelColore(riga.colore).testo" data-testid="giorni">{{ testoGiorni(riga.giorni) }}</p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">{{ riga.esercizio.nome }}</p>
                            <p class="text-xs text-slate-600 dark:text-slate-300">{{ riga.quante > 0 ? testoPersoneCifra(riga.quante, riga.cifra) : testoRigaSenzaAperte(riga.chiuse) }}</p>
                            <div v-if="testiCartellini(riga.cartellini).length" class="mt-1 flex flex-wrap gap-1">
                                <Badge v-for="c in testiCartellini(riga.cartellini)" :key="c" variant="outline"
                                    class="bg-white text-xs font-medium text-slate-600 dark:bg-slate-900 dark:text-slate-300">{{ c }}</Badge>
                            </div>
                            <p v-if="riga.compito_tornato" class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ riga.compito_tornato }}</p>
                        </div>
                        <div class="flex items-center justify-between gap-2 lg:justify-end">
                            <p v-if="riga.risposta?.frase" class="text-xs text-slate-600 dark:text-slate-300 lg:max-w-[16rem] lg:text-right">{{ riga.risposta.frase }}</p>
                            <div class="flex items-center gap-1">
                                <DropdownMenu v-if="puoRispondere">
                                    <DropdownMenuTrigger as-child>
                                        <button type="button" aria-label="Le risposte" data-testid="menu-risposte"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 shadow-sm hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:hover:text-slate-200">
                                            <MoreHorizontal class="h-4 w-4" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-56">
                                        <DropdownMenuItem class="text-xs" @select="emit('rispondi', richiesta(riga, 'fatto'))">Fatto, con una nota</DropdownMenuItem>
                                        <DropdownMenuItem class="text-xs" @select="emit('rispondi', richiesta(riga, 'non_serve'))">Non serve, con il motivo</DropdownMenuItem>
                                        <DropdownMenuItem class="text-xs" @select="emit('rispondi', richiesta(riga, 'ricordamelo'))">Ricordamelo il…</DropdownMenuItem>
                                        <template v-if="riga.risposta">
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem v-if="riga.risposta.stato" class="text-xs" @select="emit('rispondi', richiesta(riga, 'riapri'))">Riapri</DropdownMenuItem>
                                            <DropdownMenuItem v-if="riga.risposta.registro.length" class="text-xs" @select="alterna('registro', riga.chiave)">Il registro</DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <button type="button" :aria-label="aperte.has(riga.chiave) ? 'Chiudi la riga' : 'Apri la riga'" data-testid="apri-riga"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800"
                                    @click="alterna('aperte', riga.chiave)">
                                    <ChevronDown v-if="aperte.has(riga.chiave)" class="h-4 w-4" />
                                    <ChevronRight v-else class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Il registro delle risposte della riga. -->
                    <ul v-if="registroMostrato.has(riga.chiave) && riga.risposta" class="space-y-1 border-t border-slate-100 px-3 py-2 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-300">
                        <li v-for="(r, i) in riga.risposta.registro" :key="i">{{ r.quando }} · {{ r.utente }} · {{ testoDelGesto(String(r.tipo)) }}<template v-if="r.nota">: «{{ r.nota }}»</template></li>
                    </ul>

                    <!-- La riga aperta: le persone. -->
                    <div v-if="aperte.has(riga.chiave)" class="border-t border-slate-100 dark:border-slate-800" data-testid="persone">
                        <div class="hidden gap-3 whitespace-nowrap px-3 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-400 lg:grid" :class="GRIGLIA"
                            data-testid="intestazione-persone">
                            <span data-testid="colonna">{{ COLONNE_PERSONE[0] }}</span>
                            <span class="flex items-center justify-end gap-1">
                                <span data-testid="colonna">{{ COLONNE_PERSONE[1] }}</span>
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" role="img" :aria-label="FRASE_NON_VERSATO" class="inline-flex cursor-help rounded text-slate-300 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                                <Info class="w-3.5 h-3.5" />
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent side="top" class="max-w-xs normal-case tracking-normal"><p class="text-xs font-normal">{{ FRASE_NON_VERSATO }}</p></TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </span>
                            <span data-testid="colonna">{{ COLONNE_PERSONE[2] }}</span>
                            <span data-testid="colonna">{{ COLONNE_PERSONE[3] }}</span>
                            <span data-testid="colonna">{{ puoRispondere ? COLONNE_PERSONE[4] : '' }}</span>
                        </div>
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            <li v-for="p in riga.persone" :key="p.anagrafica_id" class="grid grid-cols-1 gap-2 px-3 py-2.5 lg:items-center lg:gap-3" :class="GRIGLIA"
                                :data-testid="`persona-${p.anagrafica_id}`">
                                <div class="flex items-start justify-between gap-2 lg:block min-w-0">
                                    <div class="min-w-0">
                                        <Link :href="indirizzoEstratto(p.anagrafica_id)" class="block truncate text-sm font-medium text-slate-900 hover:underline dark:text-slate-100">{{ p.nome }}</Link>
                                        <p v-if="p.unita.length" class="truncate text-xs text-slate-500 dark:text-slate-400">{{ p.unita.join(', ') }}</p>
                                        <p v-if="p.credito > 0" class="text-xs text-sky-600 dark:text-sky-400">credito {{ euroTesto(p.credito) }}</p>
                                    </div>
                                    <span class="text-sm font-bold tabular-nums text-slate-900 dark:text-white lg:hidden">{{ euroTesto(p.cifra) }}</span>
                                </div>
                                <span class="hidden text-right text-sm font-bold tabular-nums text-slate-900 dark:text-white lg:block" data-testid="non-versato">{{ euroTesto(p.cifra) }}</span>
                                <div class="min-w-0 space-y-0.5" data-testid="a-che-punto">
                                    <p class="text-[13px] leading-snug text-slate-800 dark:text-slate-200">{{ p.passo.frase }}</p>
                                    <div v-if="spiegazione(p).length" class="line-clamp-2 text-xs leading-snug text-slate-500 dark:text-slate-400" :title="spiegazione(p).join(' ')"
                                        data-testid="spiegazione">
                                        <span v-for="(x, i) in spiegazione(p)" :key="i" :class="{ 'text-amber-700 dark:text-amber-400': x === p.torna }">{{ x }} </span>
                                    </div>
                                </div>
                                <!-- Sotto lg i due pulsanti vanno a capo se non stanno; da lg sono le due colonne della griglia. -->
                                <div class="flex flex-wrap items-center gap-2 lg:contents" data-testid="pulsanti-persona">
                                    <div class="w-full sm:w-60 lg:w-auto" data-testid="prossima-azione">
                                        <PassoMenu :passo="p.passo" :proposto="seiMesi.passo_proposto" :disponibili="passiDisponibili" @scegli="(x) => scegli(riga, p, x)" />
                                    </div>
                                    <div class="w-full sm:w-44 lg:w-auto">
                                        <Button v-if="puoRispondere" size="sm" variant="outline" class="h-8 w-full px-3 text-xs" data-testid="registra-agito" @click="agito(riga, p)">
                                            Registra che hai agito
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        </ul>

                        <!-- Oltre 8 persone: le 5 con la cifra più alta, e quante mancano. -->
                        <div v-if="riga.oltre" class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-300">
                            <span>{{ testoOltre(riga.oltre) }}</span>
                        </div>

                        <!-- Le persone chiuse, ripiegate. -->
                        <div v-if="riga.chiuse.length" class="border-t border-slate-100 px-3 py-2 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-300">
                            <button type="button" class="hover:underline" data-testid="mostra-chiuse" @click="alterna('chiuse', riga.chiave)">
                                {{ testoChiuse(riga.chiuse) }} · {{ chiuseMostrate.has(riga.chiave) ? 'Nascondi' : 'Mostra' }}
                            </button>
                            <ul v-if="chiuseMostrate.has(riga.chiave)" class="mt-1.5 space-y-1">
                                <li v-for="c in riga.chiuse" :key="c.anagrafica_id" class="flex flex-wrap items-center gap-x-2">
                                    <span class="font-medium text-slate-800 dark:text-slate-100">{{ c.nome }}</span>
                                    <span class="tabular-nums">{{ euroTesto(c.cifra) }}</span>
                                    <span class="text-slate-500 dark:text-slate-400">{{ c.frase }}</span>
                                    <button v-if="puoRispondere && c.motivo === 'agito'" type="button" class="font-medium text-primary hover:underline" @click="riapriPersona(riga, c)">Riapri</button>
                                </li>
                            </ul>
                        </div>

                        <!-- I passi in blocco: un passo che almeno due persone della riga hanno; con «Scelgo io il passo», le lettere a tutte le persone della riga. -->
                        <div v-if="proposti(riga).length || spedizioni(riga) || (!seiMesi.passo_proposto && riga.quante > 1)"
                            class="flex flex-wrap gap-2 border-t border-slate-100 px-3 py-2 dark:border-slate-800" data-testid="in-blocco">
                            <template v-if="seiMesi.passo_proposto">
                                <Button v-for="b in proposti(riga)" :key="b.passo" size="sm" variant="outline" class="h-8 px-3 text-xs" @click="inBlocco(riga, b)">
                                    {{ testoInBlocco(b.passo, b.persone.length) }}
                                </Button>
                            </template>
                            <Button v-else-if="riga.quante > 1" size="sm" variant="outline" class="h-8 px-3 text-xs" @click="scriviATutte(riga)">
                                Scrivi alle {{ riga.quante }} persone della riga
                            </Button>
                            <Button v-if="spedizioni(riga)" size="sm" variant="outline" class="h-8 px-3 text-xs" @click="inBlocco(riga, spedizioni(riga)!)">
                                {{ testoInBlocco('spedizione', spedizioni(riga)!.lettere.length || spedizioni(riga)!.persone.length) }}
                            </Button>
                        </div>

                        <!-- Il fondo della riga. -->
                        <div class="flex flex-col gap-1.5 border-t border-slate-100 bg-slate-50/50 px-3 py-2 text-xs dark:border-slate-800 dark:bg-slate-800/30 lg:flex-row lg:items-center lg:justify-between lg:gap-4">
                            <Link :href="indirizzoDispense" class="font-medium text-slate-600 hover:text-primary hover:underline dark:text-slate-300">Registra la dispensa dell'assemblea</Link>
                            <span class="text-slate-500 dark:text-slate-400 lg:text-center">{{ FRASE_LETTERE }}</span>
                            <Link :href="indirizzoElenco(riga)" class="shrink-0 font-medium text-primary hover:underline">Tutte le rate di questo esercizio →</Link>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Le rate senza la data dei sei mesi: chiusa, quante e la frase; aperta con la freccia, le persone, perché la data manca e la prossima azione del caso. -->
            <div v-if="seiMesi.senza_data" class="rounded-lg border border-slate-200 dark:border-slate-700" data-testid="senza-data">
                <div class="flex items-start justify-between gap-2 bg-slate-50/60 p-3 text-xs text-slate-600 dark:bg-slate-800/30 dark:text-slate-300">
                    <div class="min-w-0 space-y-0.5">
                        <p class="font-medium text-slate-700 dark:text-slate-200">{{ testoSenzaData(seiMesi.senza_data) }}</p>
                        <p v-if="personeSenzaData.length" class="text-slate-500 dark:text-slate-400">{{ FRASE_SENZA_DATA }}</p>
                        <template v-else>
                            <p v-for="perche in seiMesi.senza_data.perche" :key="perche" class="text-slate-500 dark:text-slate-400">{{ perche }}</p>
                        </template>
                    </div>
                    <button v-if="personeSenzaData.length" type="button" :aria-label="senzaDataAperta ? 'Chiudi la riga' : 'Apri la riga'" data-testid="apri-senza-data"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800"
                        @click="senzaDataAperta = !senzaDataAperta">
                        <ChevronDown v-if="senzaDataAperta" class="h-4 w-4" />
                        <ChevronRight v-else class="h-4 w-4" />
                    </button>
                </div>
                <div v-if="senzaDataAperta && personeSenzaData.length" class="border-t border-slate-100 dark:border-slate-800" data-testid="persone-senza-data">
                    <div class="hidden gap-3 whitespace-nowrap px-3 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-400 lg:grid" :class="GRIGLIA_SENZA_DATA"
                        data-testid="intestazione-senza-data">
                        <span data-testid="colonna">{{ COLONNE_SENZA_DATA[0] }}</span>
                        <span class="text-right" data-testid="colonna">{{ COLONNE_SENZA_DATA[1] }}</span>
                        <span data-testid="colonna">{{ COLONNE_SENZA_DATA[2] }}</span>
                        <span data-testid="colonna">{{ COLONNE_SENZA_DATA[3] }}</span>
                    </div>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="p in personeSenzaData" :key="p.anagrafica_id" class="grid grid-cols-1 gap-2 px-3 py-2.5 lg:items-center lg:gap-3" :class="GRIGLIA_SENZA_DATA"
                            :data-testid="`senza-data-${p.anagrafica_id}`">
                            <div class="flex items-start justify-between gap-2 lg:block min-w-0">
                                <div class="min-w-0">
                                    <Link :href="indirizzoEstratto(p.anagrafica_id)" class="block truncate text-sm font-medium text-slate-900 hover:underline dark:text-slate-100">{{ p.nome }}</Link>
                                    <p v-if="p.unita.length" class="truncate text-xs text-slate-500 dark:text-slate-400">{{ p.unita.join(', ') }}</p>
                                </div>
                                <span class="text-sm font-bold tabular-nums text-slate-900 dark:text-white lg:hidden">{{ euroTesto(p.cifra) }}</span>
                            </div>
                            <span class="hidden text-right text-sm font-bold tabular-nums text-slate-900 dark:text-white lg:block">{{ euroTesto(p.cifra) }}</span>
                            <div class="min-w-0 space-y-1.5">
                                <div v-for="m in p.motivi" :key="m.caso" class="space-y-0.5">
                                    <p class="text-[13px] leading-snug text-slate-800 dark:text-slate-200">{{ m.perche }}</p>
                                    <p v-if="m.spiegazione" class="text-xs leading-snug text-slate-500 dark:text-slate-400">{{ m.spiegazione }}</p>
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5 sm:w-60 lg:w-auto">
                                <Link v-for="m in p.motivi" :key="m.caso" :href="indirizzoAzione(p, m)" data-testid="azione-senza-data"
                                    class="inline-flex h-8 w-full items-center justify-center gap-1 rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                    {{ m.azione.etichetta }} <ArrowRight class="h-3.5 w-3.5" />
                                </Link>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Il rendiconto da portare in assemblea. -->
            <template v-if="seiMesi.rendiconti.length">
                <div class="flex items-center gap-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Rendiconto in assemblea</p>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger><Info class="w-3.5 h-3.5 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                            <TooltipContent side="bottom" class="max-w-sm"><p class="text-xs">{{ FRASE_RENDICONTO }}</p></TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
                <div v-for="r in seiMesi.rendiconti" :id="`rendiconto-${r.esercizio.id}`" :key="r.esercizio.id" :data-testid="`rendiconto-${r.esercizio.id}`"
                    class="flex flex-col gap-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700 lg:flex-row lg:items-center lg:gap-4 scroll-mt-20"
                    :class="[classiDelColore(r.colore).sfondo, r.risposta?.stato === 'non_serve' ? 'opacity-70' : '']">
                    <div class="lg:w-36 shrink-0">
                        <p class="text-base font-black text-slate-800 dark:text-slate-100 tabular-nums">{{ giorno(r.data) }}</p>
                        <p class="text-xs font-semibold" :class="classiDelColore(r.colore).testo">{{ testoGiorni(r.giorni) }}</p>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-slate-900 dark:text-white">Rendiconto {{ annoDi(r.esercizio.nome) }}</p>
                        <p class="text-xs text-slate-600 dark:text-slate-300">da convocare in assemblea entro il {{ giorno(r.data) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ r.frase }}</p>
                        <p v-if="r.risposta?.frase" class="text-xs text-slate-600 dark:text-slate-300">{{ r.risposta.frase }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <CheckCircle2 v-if="r.stato === 'approvata'" class="h-5 w-5 text-emerald-500" />
                        <a v-else-if="r.passo" :href="r.url" class="inline-flex h-8 items-center gap-1 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground shadow-sm hover:bg-primary/90">
                            {{ r.passo === 'apri' ? 'Apri il rendiconto' : 'Registra l\'approvazione' }} <ArrowRight class="h-3.5 w-3.5" />
                        </a>
                        <Link v-if="r.agenda" :href="indirizzoAgenda" data-testid="in-agenda" title="Apri l'agenda"
                            class="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300 dark:hover:bg-emerald-900/40">
                            <CalendarCheck class="h-3.5 w-3.5" /> In agenda il {{ giorno(r.agenda.giorno) }}
                        </Link>
                        <Button v-else-if="puoRispondere && r.stato !== 'approvata'" size="sm" variant="outline" class="h-8 gap-1 px-3 text-xs" @click="emit('rispondi', rispostaRendiconto(r, 'in_agenda'))">
                            <CalendarPlus class="h-3.5 w-3.5" /> Mettilo in agenda
                        </Button>
                        <DropdownMenu v-if="puoRispondere && r.stato === 'bozza'">
                            <DropdownMenuTrigger as-child>
                                <button type="button" aria-label="Le risposte" data-testid="menu-risposte"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 shadow-sm hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:hover:text-slate-200">
                                    <MoreHorizontal class="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuItem class="text-xs" @select="emit('rispondi', rispostaRendiconto(r, 'fatto'))">Fatto, con una nota</DropdownMenuItem>
                                <DropdownMenuItem class="text-xs" @select="emit('rispondi', rispostaRendiconto(r, 'non_serve'))">Non serve, con il motivo</DropdownMenuItem>
                                <DropdownMenuItem class="text-xs" @select="emit('rispondi', rispostaRendiconto(r, 'ricordamelo'))">Ricordamelo il…</DropdownMenuItem>
                                <DropdownMenuItem v-if="r.risposta?.stato" class="text-xs" @select="emit('rispondi', rispostaRendiconto(r, 'riapri'))">Riapri</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </template>
        </div>

        <p class="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-400 dark:border-slate-800 sm:px-5">{{ fraseDelPiede }}</p>

        <LettereDialog
            v-model:open="lettera.aperta"
            :condominio-id="condominioId"
            :persona="lettera.persona"
            :selezione="lettera.persona ? null : { tutte: false, ids: lettera.ids }"
            :tipo-iniziale="lettera.tipo"
            :oggi="seiMesi.oggi"
            @fatto="ricarica"
        />
        <AccordoRientroDialog v-model:open="accordo.aperta" :condominio-id="condominioId" :persona="accordo.persona" :oggi="seiMesi.oggi" @fatto="ricarica" />
        <FascicoloLegaleDialog v-model:open="fascicolo.aperta" :condominio-id="condominioId" :persona="fascicolo.persona" @fatto="ricarica" />
    </section>
</template>
