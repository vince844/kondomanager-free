<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { computed, ref, watch, onMounted } from 'vue';
import { Head, useForm, Link, router, usePage } from '@inertiajs/vue3';
import ScopertoWarning, { type ScopertoCents } from '@/components/gestionale/pianiRate/ScopertoWarning.vue';
import DestinatariWarning, { type DestinatarioCambiato } from '@/components/gestionale/pianiRate/DestinatariWarning.vue';
import AChiVaIlPregresso, { aChiVa, elencoDeiNomi, giornoInParole, sceltaInVigore, toccaUnPassaggio, type ModoDelPregresso, type RipartoPrevisto } from '@/components/gestionale/pianiRate/AChiVaIlPregresso.vue';
import axios from 'axios';
import vSelect from 'vue-select';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import PianoRateGuide from '@/components/guides/PianoRateGuide.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import MoneyInput from '@/components/MoneyInput.vue'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Plus, LoaderCircle, List, AlertTriangle, CheckCircle, Wallet, Ban, Info, Trash2, Users, CalendarDays, TrendingDown, BookOpen, ArrowRightLeft, ReceiptText, CalendarRange, Search } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Gestione } from '@/types/gestionale/gestioni';
import { partenzaCalendario } from '@/lib/gestionale/pianiRate/calendario';
import { centesimiDaImporto, fraseDaRipartire, totaliDaImportiInEuro } from '@/lib/gestionale/pianiRate/totaliPerTipo';
import { avvisoFatturaNelCarrello, notaCollegataNelCarrello, senzaCompetenzaScelte } from '@/lib/gestionale/pianiRate/avvisoCarrello';
import { motivoPianoAZero } from '@/lib/gestionale/pianiRate/pianoAZero';
import Alert from '@/components/Alert.vue';
import type { Flash } from '@/types/flash';
import {
  ETICHETTE_PRESET, descriviTratti, periodoGestione, presetDaiTratti, trattiDelPreset, verificaTratti,
  type PresetCompetenza, type Tratto,
} from '@/lib/gestionale/pianiRate/competenzaCapitolo';
import type { BreadcrumbItem } from '@/types';
import ScegliLaDelibera from '@/components/assemblee/ScegliLaDelibera.vue';
import LaDeliberaOLUrgenza from '@/components/gestionale/pianiRate/LaDeliberaOLUrgenza.vue';
import { campiDellUrgenza } from '@/lib/gestionale/pianiRate/urgenza';
import { oggiLocale } from '@/lib/giorno';
import { gestisceLeAssemblee } from '@/lib/assemblee/permessi';
import { nomeDelPianoPreparato } from '@/lib/assemblee/pianoStraordinario';
import {
  cifraFuoriDaiLimiti, cifraIniziale, righeDelRipianamento, ripianamentoAltrove, ripianamentoDaInviare, ripianamentoVisibile, tabellaIniziale,
  type RipianamentoDaInviare, type RipianamentoProposto,
} from '@/lib/gestionale/pianiRate/ripianamento';
import type { DeliberaDelMenu } from '@/types/assemblee';

interface Capitolo {
  id: number;
  nome: string;
  /** Spesa o entrata: le entrate si sottraggono alle spese, come le distribuisce il motore. */
  tipo: string;
  is_radice?: boolean;
  parent_id?: number | null;
  /** La competenza già dichiarata sulla voce in un piano di questa gestione (decisione 20): si propone, non si impone. */
  competenza_dichiarata?: { piano_rate_id: number; piano: string; tratti: Tratto[] } | null;
  disabled: boolean;
  is_sforo: boolean;
  da_sposta_spesa?: boolean;
  /** Una voce di spesa scritta con il meno: il server la manda spenta, e `note` dice la strada (correggerla, o il piano che la addebita già). */
  spesa_negativa?: boolean;
  importo_totale: number;
  residuo: number;
  note?: string;
}

interface CapitoloDettaglio {
  id: number;
  nome: string;
  tipo: string;
  importo_totale: number;
  residuo: number;
  importo_da_usare: string | number | undefined;
  note: string;
}

interface SaldoDettaglio {
  id: number;
  tipo: 'nominale' | 'solidale';
  anagrafica_id?: number;
  soggetto_nome: string;
  immobile_nome: string;
  ruolo: string;
  importo: number;
  is_debito: boolean;
  immobile_id?: number;
  ripartizione_mode: ModoDelPregresso;
  ripartizioni_custom: { anagrafica_id: number; importo: string | number }[];
  /**
   * Issue 58 (Coda 174): il giorno dei titolari scelto nella finestra per un saldo dell'unità che tocca un passaggio; `null` è la
   * regola (chi era titolare il primo giorno del suo esercizio), e non si manda.
   */
  alla_data: string | null;
  /**
   * Chi pagherebbe cosa in automatico, calcolato dal server con le stesse funzioni del
   * generatore. `null` sui saldi nominali, che un destinatario ce l'hanno già. Con un passaggio
   * nell'arco della solidarietà porta anche i passaggi e le scelte della finestra (issue 58).
   */
  riparto_previsto: RipartoPrevisto | null;
}

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  gestioni: Gestione[]
  saldoInfo: {
    saldo: number
    applicabile: boolean
    motivo: string
    gestione_utilizzatrice?: any
    is_primo_anno?: boolean
    has_movimenti?: boolean
  }
  hasPianoEsistente?: boolean
  anagraficheDisponibili: { id: number, nome: string }[]
  /** «Crea il piano straordinario» dal seguito delle delibere: la delibera già scelta, risolta dal server nel condominio (decisione 622 (1)). */
  deliberaProposta?: DeliberaDelMenu | null
  /** «Metti a rate questa spesa» dalla fattura: «Torna alla fattura» invece di «Torna all'elenco» (il server dice dove). */
  ritorno?: { url: string; testo: string } | null
  /** Coda 743: «Prepara il piano di ripianamento» (il compito «Deficit da recuperare»): il debito ereditato da mettere a rate, in centesimi. */
  ripianamento?: RipianamentoProposto | null
  /** «Rendi incassabili questi saldi» dalla pagina dei saldi: il piano di soli saldi già compilato (`PianoRateController::pianoDeiSaldi`), il totale in centesimi. */
  precompilato?: { origine: 'saldi'; gestione_id: number; nome: string; numero_rate: number; metodo_distribuzione: string; totale: number } | null
}>()

const euroDeiSaldi = (cents: number) => `€ ${(cents / 100).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const permessi = usePermission()
const { generateRoute, generatePath } = permessi
// Con «Gestisce le assemblee» la data viene dalla delibera scelta; senza, si scrive a mano come prima (il server la accetta).
const conLaDelibera = computed(() => gestisceLeAssemblee(permessi))

// --- BREADCRUMBS & GUIDES ---
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Piani Rate', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: 'Nuovo Piano Rate', href: '#' },
]);

/** Il pannello laterale della guida: chiuso finché non lo si chiede. */
const mostraGuida = ref(false);

const pageGuides = computed(() => [
  {
    title: 'Informazioni Base',
    description: "Definisci il nome del piano e la gestione contabile di competenza.",
    icon: List,
    colorVariant: 'blue' as const
  },
  {
    title: 'Saldi Pregressi',
    description: "Scegli se creare una Rata 0 per i debiti precedenti o spalmarli sul nuovo piano.",
    icon: Wallet,
    colorVariant: 'amber' as const
  },
  {
    title: 'Scadenze',
    description: "Imposta numero di rate, giorni di scadenza e genera il piano automaticamente.",
    icon: CalendarDays,
    colorVariant: 'emerald' as const
  }
]);

const moneyOptions = ref({
  prefix: '',              
  suffix: '',              
  thousands: '.',          
  decimal: ',',          
  precision: 2,            
  allowBlank: true, 
  masked: true,
  disableNegative: true
})

// --- STATO REATTIVO ---
const showRecurrence = ref(false)
const capitoliDisponibili = ref<Capitolo[]>([]);
const isLoadingCapitoli = ref(false);
const capitoliDettaglio = ref<CapitoloDettaglio[]>([]);
const saldiDettaglio = ref<SaldoDettaglio[]>([]);
const isLoadingSaldi = ref(false);
const isModaleSaldiAperta = ref(false);
const { euro } = useCurrencyFormatter({ fromCents: false });
// 1. Variabile per mostrare l'avviso di "Automazione"
const showBannerPreselezione = ref(false);
// Salva gli ID fatture dal deep-link PRIMA che Inertia sovrascriva l'URL
const preselectedFattureIds = ref<string[]>([]);
// «Gestisci sforo» dalla dashboard punta una voce: si seleziona da sola appena i capitoli sono caricati, con
// l'importo dello sforo come importo da richiedere. Prima arrivava solo la gestione, e l'amministratore doveva
// ritrovare la voce nel menu fra quelle esaurite (verifica a video del 20/09/2026).
const preselectedContoId = ref<number | null>(null);
// Gestione puntata dal deep-link Dashboard: il lock del tipo e l'etichetta
// "Integrativa" derivati da showBannerPreselezione restano validi SOLO
// finché l'utente non cambia gestione a mano (stesso principio già usato
// sopra per preselectedFattureIds: il contesto Dashboard non si ri-applica
// a una gestione diversa da quella per cui è stato calcolato).
const preselectedGestioneId = ref<number | null>(null);

const frequencies = [
  { label: 'Mensile', value: 'MONTHLY' },
  { label: 'Settimanale', value: 'WEEKLY' },
  { label: 'Annuale', value: 'YEARLY' }
]

const weekdays = [
  { label: 'Lunedì', value: 'MO' },
  { label: 'Martedì', value: 'TU' },
  { label: 'Mercoledì', value: 'WE' },
  { label: 'Giovedì', value: 'TH' },
  { label: 'Venerdì', value: 'FR' },
  { label: 'Sabato', value: 'SA' },
  { label: 'Domenica', value: 'SU' }
]

// Legge il flash dalla sessione Inertia
const scopertiWarning = computed<ScopertoCents[] | null>(
    () => usePage<{ flash: { scoperti_warning?: ScopertoCents[] } }>().props.flash?.scoperti_warning ?? null
)
// B2, cancello (2): la risoluzione per periodo ha cambiato dei destinatari (decisione 14).
const destinatariWarning = computed<DestinatarioCambiato[] | null>(
    () => usePage<{ flash: { destinatari_warning?: DestinatarioCambiato[] } }>().props.flash?.destinatari_warning ?? null
)

const form = useForm({
  tipo: 'ordinario',
  origine: '',
  gestione_id: null as number | null,
  nome: '',
  descrizione: '',
  metodo_distribuzione: 'rata_zero',
  saldi_config: [] as any[],
  numero_rate: 12,
  giorno_scadenza: 5,
  // Vuota = «parte dall'inizio della gestione», che è il comportamento di sempre.
  data_prima_scadenza: null as string | null,
  note: '',
  genera_subito: true,
  recurrence_enabled: false,
  recurrence_frequency: 'MONTHLY',
  recurrence_interval: 1,
  recurrence_by_day: [] as string[],
  capitoli_ids: [] as number[],
  capitoli_config: [] as any[],
  // B2, S6 (decisione 20): i tratti di competenza per voce, solo per le voci che escono dalla base.
  competenze_capitoli: [] as { conto_id: number; tratti: Tratto[] }[],
  fatture_config: [] as any[], 
  tipo_autorizzazione: '',
  motivazione_autorizzazione: '',
  // B2 (decisione 12): la data della delibera nasce con il piano, senza default. Dalla 1.12, a chi gestisce le assemblee la pagina la manda con la delibera
  // scelta («Scegli la delibera»): «Deliberata il» viene dalla riunione. Chi non le gestisce la scrive a mano, come prima.
  data_delibera_assemblea: '' as string,
  delibera_id: null as number | null,
  // Coda 688: «Lavori ordinati il …», con «Urgenza» su una gestione straordinaria, sulle due schede. Di serie oggi; si cambia.
  giorno_dei_lavori: oggiLocale() as string | null,
  accetta_scoperti: false as boolean,
  nota_scoperti: '' as string,
  accetta_destinatari: false as boolean,
  nota_destinatari: '' as string,
  // Coda 743: il debito ereditato da ripianare, che si scrive con il piano.
  ripianamento: null as RipianamentoDaInviare | null,
})

// Coda 743 (decisione 655): il debito ereditato da ripianare, dal compito «Deficit da recuperare» o dal seguito della delibera. Si vede sulla scheda «Spese
// fuori preventivo» e sulla gestione della pregressa; la cifra e la tabella le decide l'amministratore; si scrive solo quando si salva il piano.
const ripianamentoAttivo = ref(true);
const ripianamentoImporto = ref<string>(props.ripianamento ? cifraIniziale(props.ripianamento) : '');
const ripianamentoTabella = ref<number | null>(props.ripianamento ? tabellaIniziale(props.ripianamento) : null);
const ripianamentoSiVede = computed(() => ripianamentoVisibile(props.ripianamento, form.tipo, form.gestione_id));
// In un esercizio che non è quello della pregressa il ripianamento non si prepara: la pagina si ferma e dice dove.
const ripianamentoFermo = computed(() => ripianamentoAltrove(props.ripianamento, form.tipo));
const ripianamentoNelPiano = computed(() => ripianamentoSiVede.value && ripianamentoAttivo.value);
const avvisoDellaCifra = computed(() => props.ripianamento && ripianamentoNelPiano.value ? cifraFuoriDaiLimiti(props.ripianamento, ripianamentoImporto.value) : null);


/**
 * La data da cui partirebbe il piano, calcolata con la stessa regola del server —
 * `partenzaCalendario()`, gemella di `PianoRate::dataPartenzaCalendario()`. Serve solo a
 * suggerirla: se l'interfaccia dicesse una data e il server ne usasse un'altra, nessuna delle
 * due sarebbe sbagliata da sola.
 */
/**
 * La natura della gestione scelta decide se la delibera è obbligatoria (decisione 11: `gestioni.tipo`, non il
 * tipo del piano): su una gestione straordinaria il giorno della delibera è la competenza di tutto il piano e
 * il motore senza si ferma; con «Urgenza» decide il giorno dei lavori, o il periodo dichiarato sulla fattura.
 */
const gestioneScelta = computed(() => props.gestioni?.find(g => g.id === form.gestione_id) as any);
const gestioneStraordinaria = computed(() => gestioneScelta.value?.tipo === 'straordinaria');
const deliberaObbligatoria = computed(() => gestioneStraordinaria.value && form.tipo_autorizzazione !== 'urgenza');
const mostraDataDelibera = computed(() => gestioneStraordinaria.value || form.tipo === 'straordinario');

// Le fatture nel carrello senza competenza dichiarata: con «Urgenza» prendono il giorno dei lavori del piano (Coda 688; prima il riparto si fermava).
// Il riquadro «La delibera, o l'urgenza» dice quante sono.
// Le pregresse a parte: non si dichiarano sulla fattura, si stornano e si registrano di nuovo (R20 della Fase 1-bis).
const scelteSenzaCompetenza = computed(() => senzaCompetenzaScelte(fattureStraordinarie.value));

// Che cosa dice il carrello sotto ogni fattura: dipende dalla gestione e dall'autorizzazione (R3 della Fase 1-bis).
const contestoCarrello = computed(() => ({ gestioneStraordinaria: !!gestioneStraordinaria.value, urgenza: form.tipo_autorizzazione === 'urgenza',
  giornoDeiLavori: gestioneStraordinaria.value && form.tipo_autorizzazione === 'urgenza' ? form.giorno_dei_lavori : null }));
const avvisoDi = (fat: any) => avvisoFatturaNelCarrello(fat, contestoCarrello.value);

// Il messaggio del server — una generazione fermata («Urgenza» con una pregressa senza periodo), una gestione senza piano
// dei conti — e gli errori sulle fatture del carrello (una stornata nel frattempo). La pagina non li mostrava: «Salva»
// non faceva niente di visibile, e il motivo stava solo nel log (verifica a video della 1.11.0-beta.35). Si mostrano
// accanto al pulsante, dove è chi ha appena premuto. Anche gli errori dell'importo di una voce nella configurazione (`capitoli_config…`):
// il campo non permette il segno, ma una richiesta costruita non deve restare muta.
const flashMessage = computed(() => usePage<{ flash: { message?: Flash } }>().props.flash?.message ?? null);
// Coda 686 (b): anche gli errori dei campi che la pagina non mostra accanto al campo (il giorno del mese, la ricorrenza, i saldi, …) e quelli di un campo
// oggi nascosto (lo scudo legale a carrello vuoto, la data con «Urgenza») stanno qui: «Salva» non resta mai muto. I messaggi sono in parole semplici
// (`ParoleDelPiano`), quindi si leggono così come arrivano.
const scudoVisibile = computed(() => form.tipo === 'straordinario' && (fattureStraordinarie.value.length > 0 || ripianamentoSiVede.value) && !gestioneStraordinaria.value);
const dataDeliberaVisibile = computed(() => mostraDataDelibera.value && !(form.tipo_autorizzazione === 'urgenza' && gestioneStraordinaria.value));
const haUnPostoAccanto = (campo: string): boolean => {
  if (['nome', 'gestione_id', 'numero_rate', 'data_prima_scadenza'].includes(campo)) return true;
  if (campo.startsWith('ripianamento')) return ripianamentoSiVede.value;
  if (campo === 'metodo_distribuzione') return mostraDistribuzioneSaldo.value;
  if (campo === 'data_delibera_assemblea' || campo === 'delibera_id') return dataDeliberaVisibile.value;
  if (campo === 'tipo_autorizzazione' || campo === 'motivazione_autorizzazione') return scudoVisibile.value || gestioneStraordinaria.value;
  // Coda 688: il giorno dei lavori urgenti ha il suo posto nel riquadro «La delibera, o l'urgenza», sulle due schede.
  if (campo === 'giorno_dei_lavori') return gestioneStraordinaria.value && form.tipo_autorizzazione === 'urgenza';
  // La competenza di una voce ha il suo errore sotto la riga della voce; i suoi tratti no.
  if (/^competenze_capitoli\.\d+$/.test(campo)) return form.tipo === 'ordinario';
  // La presa d'atto ha la sua finestra.
  return ['accetta_scoperti', 'nota_scoperti', 'accetta_destinatari', 'nota_destinatari'].includes(campo);
};
const erroriCarrello = computed(() => Object.entries(form.errors as Record<string, string>)
  .filter(([campo]) => campo.startsWith('fatture_config') || campo.startsWith('capitoli_config') || !haUnPostoAccanto(campo))
  .map(([, messaggio]) => messaggio)
  .filter((messaggio, i, tutti) => tutti.indexOf(messaggio) === i));

// Coda 688: sulla gestione straordinaria la scelta fra la delibera e l'urgenza è una sola, nel riquadro «La delibera, o l'urgenza», uguale sulle due schede;
// di serie la delibera. Sulla gestione ordinaria resta lo «Scudo Legale» delle fatture fuori preventivo, com'era.
watch(gestioneStraordinaria, (straordinaria) => {
  if (straordinaria && form.tipo_autorizzazione === '') form.tipo_autorizzazione = 'delibera';
}, { immediate: true });
const schedaDelPiano = computed<'voci' | 'fatture'>(() => form.tipo === 'straordinario' ? 'fatture' : 'voci');

// Con «Urgenza» il campo della delibera sparisce: il valore battuto prima non deve restare nel form e viaggiare
// nascosto (verifica S6, R2). Il server non lo scriverebbe comunque; qui si dice la verità a video.
watch(() => form.tipo_autorizzazione, (v) => {
  if (v === 'urgenza') {
    form.data_delibera_assemblea = '';
    delibera.value = null;
  }
});

/**
 * «Scegli la delibera» al posto della data scritta a mano (scene 1 e 3 della bozza approvata con la decisione 502): la delibera scelta dà «Deliberata il»
 * (il giorno della riunione) e il numero del verbale; la motivazione diventa una nota facoltativa. Niente si collega finché il piano non si salva.
 * La proposta cerca le delibere che parlano della gestione scelta.
 */
const delibera = ref<DeliberaDelMenu | null>(null);
watch(delibera, (d) => {
  form.delibera_id = d?.id ?? null;
  form.data_delibera_assemblea = d?.deliberata_il ?? '';
  form.clearErrors('data_delibera_assemblea');
});
// La delibera arrivata con l'indirizzo («Crea il piano straordinario», il piano di ripianamento) si sceglie con la prima gestione: quella dell'indirizzo,
// o la prima scelta a mano. Cambiando gestione dopo, la delibera si toglie come sempre.
let deliberaDallIndirizzo: DeliberaDelMenu | null = props.deliberaProposta ?? null;
watch(() => form.gestione_id, () => {
  if (deliberaDallIndirizzo !== null && form.gestione_id) {
    delibera.value = deliberaDallIndirizzo;
    // Il piano preparato dalla delibera si apre con il nome già scritto: quello della gestione, che porta il nome del punto e l'anno.
    form.nome = nomeDelPianoPreparato(form.nome, gestioneScelta.value?.nome);
    deliberaDallIndirizzo = null;
    return;
  }
  delibera.value = null;
});
/** «Preparato dalla delibera del 15/12/2026, punto 4»: finché la delibera scelta è quella arrivata con l'indirizzo. */
const preparatoDallaDelibera = computed(() => {
  const d = props.deliberaProposta;
  if (!d || delibera.value?.id !== d.id) return null;
  const giorno = d.deliberata_il ? d.deliberata_il.split('-').reverse().join('/') : null;
  return `Preparato dalla delibera${giorno ? ` del ${giorno}` : ''}${d.punto ? `, punto ${d.punto.numero}` : ''}. Niente è salvato finché non premi «Salva il piano».`;
});
const giornoDellaDelibera = computed(() => delibera.value?.deliberata_il ? delibera.value.deliberata_il.split('-').reverse().join('/') : null);
const vuotoDellaDelibera = computed(() => gestioneScelta.value
  ? `Nessuna assemblea di ${props.condominio.nome} ha deliberato sulla gestione «${gestioneScelta.value.nome}»`
  : `Nessuna assemblea di ${props.condominio.nome} ha deliberato su questa gestione`);
const etichettaMotivazione = computed(() => form.tipo_autorizzazione === 'urgenza' ? 'Il motivo dell\'urgenza'
  : form.tipo_autorizzazione === 'delibera' ? (conLaDelibera.value ? 'Una nota (facoltativa)' : 'Motivazione / Estremi Verbale') : 'Motivazione o nota');

const partenzaSuggerita = computed<string | null>(() => {
  const gestione = props.gestioni?.find(g => g.id === form.gestione_id);

  return partenzaCalendario(form.data_prima_scadenza, (gestione as any)?.data_inizio);
});

// --- LETTURA DEEP-LINK DA DASHBOARD ---
onMounted(() => {
  const urlParams = new URLSearchParams(window.location.search);
  
  if (urlParams.get('tipo') === 'straordinario') {
    form.tipo = 'straordinario';
  }
  
  if (urlParams.get('origine') === 'dashboard') {
    form.origine = 'dashboard';
    showBannerPreselezione.value = true;
  }

  // Salva gli ID PRIMA che il watcher sovrascriva l'URL
  const collected: string[] = [];
  
  urlParams.forEach((value, key) => {
    if (key === 'fatture[]' || key.startsWith('fatture[')) {
      collected.push(value);
    }
  });
  preselectedFattureIds.value = collected;

  if (urlParams.has('gestione_id')) {
    const id = urlParams.get('gestione_id');
    form.gestione_id = id ? Number(id) : null;
    preselectedGestioneId.value = form.gestione_id;
  }

  // Coda 743: il piano del ripianamento si apre sulla gestione della pregressa, con il nome già scritto; in un altro esercizio solo sulla scheda, che dice dove.
  if (props.ripianamento) {
    form.tipo = 'straordinario';
    if (!props.ripianamento.altrove) {
      if (!form.gestione_id) form.gestione_id = props.ripianamento.gestione_id;
      if (!form.nome) form.nome = props.ripianamento.voce;
    }
  }

  // «Rendi incassabili questi saldi»: la gestione, il nome, una rata, i saldi sommati alla prima rata ordinaria, nessuna voce. `origine` dice al server
  // che è il piano dei saldi (`PianoRateController::store`): non prende voci e si genera subito.
  if (props.precompilato) {
    form.tipo = 'ordinario';
    form.origine = 'saldi';
    form.genera_subito = true;
    form.gestione_id = props.precompilato.gestione_id;
    form.nome = props.precompilato.nome;
    form.numero_rate = props.precompilato.numero_rate;
    form.metodo_distribuzione = props.precompilato.metodo_distribuzione;
  }

  if (urlParams.has('conto_id')) {
    const id = Number(urlParams.get('conto_id'));
    preselectedContoId.value = Number.isFinite(id) && id > 0 ? id : null;
  }
});

// --- BIVIO ORDINARIO/STRAORDINARIO ---
const isLoadingFatture = ref(false)
const fattureStraordinarie = ref<any[]>([])

/**
 * Coda 686 (a), decisioni 506 e 510: a carrello vuoto la pagina dice che cosa la scheda mostra e che cosa non si vede, con l'azione giusta — la fattura
 * su una voce del piano dei conti (si mette a rate con «Dalle voci del preventivo», con la voce già scelta se si può), quelle «Fuori preventivo» di un
 * altro esercizio, quelle già per intero in un altro piano. Il server lo dice in una lettura (`FetchFuoriDalCarrelloController`), chiesta solo a carrello vuoto.
 */
interface FuoriDalCarrello {
  gestione: string;
  sulle_voci: { totale: number; fatture: { id: number; fornitore: string; numero_documento: string; importo: number; voce: string; altre_voci: number; conto_id: number }[] };
  altri_esercizi: { esercizio_id: number; esercizio: string; quante: number }[];
  gia_nei_piani: { totale: number; fatture: { id: number; fornitore: string; numero_documento: string; piani: string[] }[] };
}
const fuoriDalCarrello = ref<FuoriDalCarrello | null>(null);
const qualcosaFuoriDalCarrello = computed(() => !!fuoriDalCarrello.value && (fuoriDalCarrello.value.sulle_voci.totale > 0
  || fuoriDalCarrello.value.altri_esercizi.length > 0 || fuoriDalCarrello.value.gia_nei_piani.totale > 0));
const nomeGestioneScelta = computed(() => fuoriDalCarrello.value?.gestione ?? gestioneScelta.value?.nome ?? '');

/** Dalla fattura sulla voce all'altra scheda, con la voce già scelta se il menu la offre (accesa: ha ancora qualcosa da mettere a rate). */
const mettiARateDalleVoci = (contoId: number | null) => {
  form.tipo = 'ordinario';
  const voce = contoId === null ? null : capitoliDisponibili.value.find(c => c.id === contoId);
  if (voce && !voce.disabled && !form.capitoli_ids.includes(voce.id)) {
    form.capitoli_ids = [...form.capitoli_ids, voce.id];
  }
};

/** Coda 686 (b): senza fatture scelte il piano «Spese fuori preventivo» non si manda; il pulsante dice perché. */
const senzaFattureScelte = computed(() => form.tipo === 'straordinario' && !fattureStraordinarie.value.some(f => f.selezionata) && !ripianamentoNelPiano.value);

// Quando si arriva da un CTA della Dashboard, il tipo di piano non è una
// scelta dell'utente: la Dashboard lo ha già determinato in base alla voce
// da finanziare (sforo su capitolo esistente vs sopravvenienza/ad personam).
// Blocchiamo quindi l'opzione non pertinente invece di lasciarla cliccabile
// e mandare l'utente a sbattere contro un errore di validazione a valle.
// Il contesto Dashboard resta valido SOLO finché la gestione selezionata è
// ancora quella per cui la Dashboard ha calcolato quel contesto: se
// l'utente la cambia dal select "Gestione di riferimento" (rimane
// liberamente modificabile), il lock e l'etichetta si sbloccano per la
// nuova gestione invece di restare ancorati alla precedente.
const isDashboardContextValid = computed(() =>
  showBannerPreselezione.value && form.gestione_id === preselectedGestioneId.value
)
const isOrdinarioDisabled = computed(() => isDashboardContextValid.value && form.tipo !== 'ordinario')
const isStraordinarioDisabled = computed(() => isDashboardContextValid.value && form.tipo !== 'straordinario')

// La scheda «Dalle voci del preventivo» dice «Rata integrativa» quando è chiaro che non si tratta della prima emissione dell'anno:
// - arrivo dalla Dashboard per uno sforo "orfano" sulla gestione ancora selezionata (segnale certo)
// - oppure la gestione selezionata ha già un piano rate "preventivo iniziale" ordinario (segnale euristico dal backend, utile per chi crea
//   il piano manualmente senza passare dalla Dashboard)
// Decisione 510: il nome della scheda resta quello che dice che cosa si mette a rate; l'integrativa è un'etichetta accanto, non un altro nome (fino alla
// 1.12.0-beta.11 la scheda diventava «Piano Rata Integrativa»).
const isPianoIntegrativo = computed(() =>
  form.tipo === 'ordinario' && (isDashboardContextValid.value || !!props.hasPianoEsistente)
)
/** Decisione 510: le due schede dicono che cosa mettono a rate, non la natura della gestione (decisione 11). */
const SCHEDA_DALLE_VOCI = 'Dalle voci del preventivo';
const SCHEDA_FUORI_PREVENTIVO = 'Spese fuori preventivo';

/** Coda 686 (e): il piano che verrebbe a € 0,00, detto prima di salvare. */
const pianoAZero = computed(() => motivoPianoAZero({
  tipo: form.tipo,
  gestione: form.gestione_id ? (gestioneScelta.value?.nome ?? '') : null,
  caricamento: isLoadingCapitoli.value || isLoadingFatture.value || isLoadingSaldi.value,
  conSaldi: mostraDistribuzioneSaldo.value && saldoGestioneCorrente.value.hasSaldi,
  vociDisponibili: capitoliDisponibili.value,
  vociScelte: form.capitoli_ids.length > 0 ? capitoliDettaglio.value : [],
  fattureScelte: fattureStraordinarie.value.filter(f => f.selezionata),
}));

// Nuovi campi form necessari per lo straordinario (aggiungili anche all'interfaccia nel backend)
const autorizzazioneStraordinaria = ref({
  tipo_autorizzazione: '',
  motivazione_autorizzazione: ''
})

// Helper fondamentale per i calcoli con MoneyInput (risolve il problema NaN).
// Il valore può arrivare in due notazioni diverse a seconda di come è nato:
// - "1.250,50" (italiana/mascherata) se l'utente ha digitato nel campo MoneyInput
// - "1250.50" (JS pura) se il campo non è mai stato toccato e resta al valore
//   iniziale che gli abbiamo passato noi (vedi toMoneyFieldString più sotto)
// Le due notazioni si distinguono dalla presenza della virgola: solo la prima
// la usa come separatore decimale. La regola è una sola, quella di `centesimiDaImporto` (che ricalca MoneyHelper::toCents() lato PHP),
// la stessa che usa il totale della creazione: qui si torna in euro, per i confronti con i residui che la pagina tiene in euro.
const parseMoney = (val: any): number => centesimiDaImporto(val) / 100;

// Converte un numero grezzo (es. dal backend) nella stringa da usare come valore
// INIZIALE di un v-model collegato a MoneyInput. Deve essere già in notazione JS
// pura (punto decimale, "1250.50"): se le passassimo la notazione italiana
// "1.250,50", v-money3 non riuscirebbe a fare Number() su di essa al mount e la
// ri-formatterebbe da solo in "1250.50" — che parseMoney() interpreterebbe già
// correttamente, ma è comunque più sicuro e prevedibile partire già in quel formato
// invece di affidarsi al comportamento interno della libreria.
const toMoneyFieldString = (val: number | string | null | undefined): string => {
  const n = typeof val === 'number' ? val : Number(val ?? 0);
  return (Number.isFinite(n) ? n : 0).toFixed(2);
};

const rimuoviCapitolo = (id: number) => {
  form.capitoli_ids = form.capitoli_ids.filter(cid => cid !== id);
}

// --- COMPETENZA DELLE VOCI (B2, S6, decisione 20) ---
//
// La tabella «Ripartizione manuale voci» esiste solo in emissione parziale; il piano madre di inizio anno
// lascia le voci vuote e il server include da solo le foglie dei capitoli orfani. La stagione del
// riscaldamento nasce proprio per quel piano, quindi questa sezione compare **sempre**: con le voci scelte
// se ce ne sono, altrimenti con i capitoli radice — e una competenza dichiarata su un capitolo che poi
// nel piano non entra (già in un altro piano attivo) il server la segnala nel messaggio, non la perde.
interface CompetenzaVoce { preset: PresetCompetenza; manuali: Tratto[]; fonte?: string }
const competenzeVoci = ref<Record<number, CompetenzaVoce>>({});

// Il capitolo radice di una voce, risalendo `parent_id` nell'elenco piatto.
const radiceDi = (c: Capitolo): number => {
  let corrente = c;
  const visti = new Set<number>();
  while (corrente.parent_id != null && !visti.has(corrente.id)) {
    visti.add(corrente.id);
    const padre = capitoliDisponibili.value.find(x => x.id === corrente.parent_id);
    if (!padre) break;
    corrente = padre;
  }
  return corrente.id;
};

const vociPerCompetenza = computed<Capitolo[]>(() => {
  // Sullo straordinario la competenza è la delibera (o quella dichiarata sulla fattura): niente periodi per voce
  // (decisioni 12 e 20; verifica S6, R1). La natura è della gestione, non del tipo del piano.
  if (form.tipo !== 'ordinario' || !form.gestione_id || gestioneStraordinaria.value) return [];
  if (form.capitoli_ids.length > 0) {
    return capitoliDisponibili.value.filter(c => form.capitoli_ids.includes(c.id));
  }
  // Piano che include tutto: i capitoli radice che hanno ancora qualcosa da finanziare, loro o una loro voce.
  // Un capitolo esaurito con tutte le voci esaurite non entra nel piano, e una competenza dichiarata lì non
  // servirebbe a nulla: a inizio anno sono tutti, su un'integrativa solo quelli che contano (verifica a video
  // del 20/09/2026: tre capitoli esauriti in elenco e la voce sforata assente).
  const radiciConResiduo = new Set(capitoliDisponibili.value.filter(c => !c.disabled).map(radiceDi));
  return capitoliDisponibili.value.filter(c => (c.is_radice ?? c.nome.startsWith('[')) && radiciConResiduo.has(c.id));
});

// Con molte voci l'elenco scorre (400 px) e si cerca per nome; il filtro è solo di vista, la validazione
// (`vociFuoriBase`, `competenzeConErrori`) resta su tutte.
const filtroVociCompetenza = ref('');
const mostraFiltroCompetenza = computed(() => vociPerCompetenza.value.length > 6);
const vociPerCompetenzaVisibili = computed<Capitolo[]>(() => {
  const q = filtroVociCompetenza.value.trim().toLowerCase();
  if (!mostraFiltroCompetenza.value || q === '') return vociPerCompetenza.value;
  return vociPerCompetenza.value.filter(v => v.nome.toLowerCase().includes(q));
});

const gestioneDelPiano = computed(() => props.gestioni.find(g => g.id === form.gestione_id) ?? null);
const basePiano = computed(() => periodoGestione(gestioneDelPiano.value, props.esercizio));

const competenzaDi = (id: number): CompetenzaVoce => {
  if (!competenzeVoci.value[id]) {
    // Se la voce ha già una competenza in un piano di questa gestione (il piano madre, per un'integrativa dopo uno
    // sforo) si parte da quella, e si dice da dove viene: il default «periodo della gestione» rimetteva sull'anno
    // intero il riscaldamento dichiarato sulla stagione, e i giorni di chi vende e chi compra cambiavano fra i due
    // piani senza che nessuno lo dicesse (verifica a video del 20/09/2026). L'amministratore la vede e la cambia.
    const dichiarata = capitoliDisponibili.value.find(c => c.id === id)?.competenza_dichiarata ?? null;
    if (dichiarata && dichiarata.tratti.length > 0) {
      const preset = presetDaiTratti(dichiarata.tratti, props.esercizio);
      competenzeVoci.value[id] = { preset, manuali: preset === 'manuale' ? dichiarata.tratti.map(t => ({ ...t })) : [], fonte: dichiarata.piano };
    } else {
      competenzeVoci.value[id] = { preset: 'gestione', manuali: [] };
    }
  }
  return competenzeVoci.value[id];
};

const trattiDiVoce = (id: number): Tratto[] => {
  const c = competenzaDi(id);
  return trattiDelPreset(c.preset, props.esercizio, c.manuali);
};

const erroreDiVoce = (id: number): string | null => {
  const c = competenzaDi(id);
  if (c.preset === 'gestione') return null;
  const tratti = trattiDiVoce(id);
  if (tratti.length === 0) return c.preset === 'manuale' ? 'Aggiungi almeno un tratto.' : 'L’esercizio non ha le date: il preset non si può calcolare.';
  return verificaTratti(tratti, props.esercizio);
};

const cambiaPreset = (id: number, preset: PresetCompetenza) => {
  const c = competenzaDi(id);
  c.preset = preset;
  // Cambiata a mano: non è più quella del piano da cui veniva.
  c.fonte = undefined;
  if (preset === 'manuale' && c.manuali.length === 0) c.manuali.push({ dal: '', al: '' });
};

const aggiungiTratto = (id: number) => { if (competenzaDi(id).manuali.length < 6) competenzaDi(id).manuali.push({ dal: '', al: '' }); };
const togliTratto = (id: number, indice: number) => { competenzaDi(id).manuali.splice(indice, 1); };

const vociFuoriBase = computed(() => vociPerCompetenza.value.filter(v => competenzaDi(v.id).preset !== 'gestione'));
const competenzeConErrori = computed(() => vociFuoriBase.value.some(v => erroreDiVoce(v.id) !== null));

const PRESET_ORDINE: PresetCompetenza[] = ['gestione', 'esercizio', 'stagione', 'manuale'];
const OPZIONI_PRESET = PRESET_ORDINE.map(p => ({ label: ETICHETTE_PRESET[p], value: p }));

// Il totale delle voci scelte, con le entrate che si sottraggono. I campi sono in euro: la conversione in centesimi avviene una
// volta sola, in `totaliDaImportiInEuro`, e qui si formatta dichiarando i centesimi (l'`euro` di questa pagina legge euro).
const totaleSelezionatoFormatted = computed(() =>
  fraseDaRipartire(totaliDaImportiInEuro(capitoliDettaglio.value), (centesimi) => euro(centesimi, { fromCents: true }))
);

const mostraDistribuzioneSaldo = computed(() => {
  if (props.saldoInfo.has_movimenti !== undefined) {
    return props.saldoInfo.applicabile && props.saldoInfo.has_movimenti;
  }
  return props.saldoInfo.applicabile && props.saldoInfo.saldo !== 0;
});

// ── Aggregato dinamico per la gestione correntemente selezionata.
// Usa saldiDettaglio (già caricati per gestione dal watch) invece di
// saldoInfo.motivo che è un aggregato globale dell'intero esercizio.
const saldoGestioneCorrente = computed(() => {
  const vuoto = { totale: 0, hasSaldi: false, countNominali: 0, countSolidali: 0 };
  if (!form.gestione_id || isLoadingSaldi.value) return vuoto;
  return {
    totale:        saldiDettaglio.value.reduce((acc, s) => acc + s.importo, 0),
    hasSaldi:      saldiDettaglio.value.some(s => s.importo !== 0),
    countNominali: saldiDettaglio.value.filter(s => s.tipo === 'nominale').length,
    countSolidali: saldiDettaglio.value.filter(s => s.tipo === 'solidale').length,
  };
});

// --- WATCHERS ---
watch(() => form.gestione_id, async (newId) => {
  saldiDettaglio.value = [];
  fuoriDalCarrello.value = null;
  if (!newId) return;

  isLoadingCapitoli.value = true;
  isLoadingSaldi.value = true;
  // D198 (b): il carrello è «in caricamento» da subito, non solo dopo le voci: prima mostrava per un attimo «nessuna fattura».
  isLoadingFatture.value = true;

  try {
    router.get(
      window.location.pathname,
      { gestione_id: newId },
      {
        only: ['saldoInfo', 'hasPianoEsistente'],
        preserveState: true,    
        preserveScroll: true,   
        onSuccess: (page: any) => {
           // CHIAMIAMO SEMPRE caricaDettagliGestione! 
           // Passiamo un flag per dirgli se scaricare anche i saldi
           const saldiApplicabili = page.props.saldoInfo?.applicabile || false;
           caricaDettagliGestione(newId, saldiApplicabili);
        },
        onError: () => {
           isLoadingCapitoli.value = false;
           isLoadingSaldi.value = false;
           isLoadingFatture.value = false;
        }
      }
    );
  } catch (e) {
    console.error("Errore ricaricamento saldoInfo", e);
    isLoadingCapitoli.value = false;
    isLoadingSaldi.value = false;
    isLoadingFatture.value = false;
  }
});

// Helper aggiornato
const caricaDettagliGestione = async (idGestione: string | number, caricaAncheSaldi: boolean) => {
  try {

    // 1. CARICHIAMO SEMPRE I CAPITOLI DI SPESA
    const resCap = await axios.get(route('admin.gestionale.fetch-capitoli-gestione', {
      condominio: props.condominio.id
    }), { 
      params: { 
        gestione_id: idGestione,
        esercizio_id: props.esercizio.id 
      } 
    });
    
    capitoliDisponibili.value = resCap.data;

    // La voce puntata dalla dashboard, se esiste in questa gestione ed è ancora finanziabile. Si consuma:
    // se l'utente cambia gestione, non si ri-applica.
    if (preselectedContoId.value !== null) {
      const voce = capitoliDisponibili.value.find(c => c.id === preselectedContoId.value);
      if (voce && !voce.disabled && !form.capitoli_ids.includes(voce.id)) {
        form.capitoli_ids = [...form.capitoli_ids, voce.id];
      }
      preselectedContoId.value = null;
    }

    // --- NUOVO: CARICHIAMO LE FATTURE STRAORDINARIE E PRESELEZIONIAMO ---

    isLoadingFatture.value = true;
    const resFatture = await axios.get(route('admin.gestionale.fetch-fatture-straordinarie', {
      condominio: props.condominio.id
    }), {
      params: {
        gestione_id: idGestione,
        esercizio_id: props.esercizio.id
      }
    });

    // DOPO (fix — usa la ref salvata in onMounted):
    fattureStraordinarie.value = resFatture.data.map((f: any) => ({
      ...f,
      selezionata: preselectedFattureIds.value.includes(f.id.toString()),
      // Stesso fix di importo_da_usare sopra: valore grezzo -> stringa,
      // altrimenti se l'importo suggerito non viene toccato resta un numero.
      importo_suggerito: toMoneyFieldString(f.residuo_da_finanziare)
    }));

    // Consuma i pre-selezionati: se l'utente cambia gestione, non si ri-applicano
    if (preselectedFattureIds.value.length > 0) {
      preselectedFattureIds.value = [];
    }

    // Coda 686 (a): a carrello vuoto, che cosa non si vede e dove andare. Un errore qui non ferma la pagina: resta la frase di sempre.
    fuoriDalCarrello.value = null;
    if (fattureStraordinarie.value.length === 0) {
      try {
        const resFuori = await axios.get(route('admin.gestionale.fetch-fatture-fuori-dal-carrello', {
          condominio: props.condominio.id
        }), { params: { gestione_id: idGestione, esercizio_id: props.esercizio.id } });
        fuoriDalCarrello.value = resFuori.data && Array.isArray(resFuori.data.altri_esercizi) ? resFuori.data : null;
      } catch (e) {
        console.error("Errore nel caricamento di ciò che il carrello non mostra", e);
      }
    }

    isLoadingFatture.value = false;

    // 2. CARICHIAMO I SALDI SOLO SE SERVONO
    if (caricaAncheSaldi) {
        const resSaldi = await axios.get(route('admin.gestionale.fetch-saldi-analitici', {
          condominio: props.condominio.id,
          esercizio: props.esercizio.id,
          gestione: idGestione
        }));

        saldiDettaglio.value = resSaldi.data.map((s: any) => ({
          ...s,
          ripartizione_mode: 'automatica',
          ripartizioni_custom: [],
          alla_data: null,
        }));
    } else {
        saldiDettaglio.value = [];
    }

  } catch (e) {
    console.error("Errore nel caricamento dati gestione", e);
  } finally {
    isLoadingCapitoli.value = false;
    isLoadingSaldi.value = false;
    isLoadingFatture.value = false;
  }
}

const saldiRaggruppati = computed(() => {
  const groups: Record<string, any> = {};

  saldiDettaglio.value.forEach(s => {
    const key = s.tipo === 'nominale'
      ? `nominale-${s.anagrafica_id}`
      : `solidale-${s.id}`;

    if (!groups[key]) {
      groups[key] = { nome: s.soggetto_nome, tipo: s.tipo, totale_soggetto: 0, voci: [] };
    }
    groups[key].voci.push(s);
    groups[key].totale_soggetto += s.importo; 
  });

  return groups;
});

watch(showRecurrence, (enabled) => {
  form.recurrence_enabled = enabled
  if (!enabled) form.recurrence_by_day = []
})

const usingByDay = computed(() =>
  showRecurrence.value && Array.isArray(form.recurrence_by_day) && form.recurrence_by_day.length > 0
)

const toggleDay = (dayValue: string) => {
  const index = form.recurrence_by_day.indexOf(dayValue);
  if (index > -1) form.recurrence_by_day.splice(index, 1);
  else form.recurrence_by_day.push(dayValue);
}

// La finestra lavora sulle scelte della pagina, e chiuderla le tiene comunque (decisione 689 (1)): la X, Esc, il clic fuori, «Chiudi» e
// «Conferma il riparto». Come prima della beta, quando c'era solo «Chiudi Modale»: chi chiude per abitudine non perde il riparto a mano né
// la scelta di a chi va il pregresso. Per tornare al riparto automatico c'è la scelta dentro la finestra («Automatico», o la regola).
const apriConfigurazioneSaldi = () => {
  if (!form.gestione_id || isLoadingSaldi.value) return;
  isModaleSaldiAperta.value = true;
}
const chiudiLaFinestra = () => { isModaleSaldiAperta.value = false; }
const aggiornaLaFinestra = (aperta: boolean) => { if (aperta) { apriConfigurazioneSaldi(); } else { chiudiLaFinestra(); } }

/** Issue 58: la scelta della finestra per un saldo dell'unità che tocca un passaggio. */
const scegliIlPregresso = (saldo: SaldoDettaglio, valore: { modo: ModoDelPregresso; alla_data: string | null }) => {
  saldo.ripartizione_mode = valore.modo;
  saldo.alla_data = valore.modo === 'manuale' ? null : valore.alla_data;
}

/** Le scelte del menu dei saldi dell'unità senza passaggi (vSelect, decisione 392): «Automatico» e «A mano». */
const OPZIONI_DEL_RIPARTO = [{ label: 'Automatico', value: 'automatica' }, { label: 'A mano', value: 'manuale' }];

/** I saldi dell'unità che toccano un passaggio (bozza della issue 58): la riga arancio della pagina e il riepilogo della finestra. */
const pregressiCheToccano = computed(() => saldiDettaglio.value.filter(s => s.tipo === 'solidale' && toccaUnPassaggio(s.riparto_previsto)));

/** La riga arancio sotto «Saldi iniziali rilevati»: quanti, quali, e a chi vanno con la scelta di adesso. */
const rigaDeiPregressi = computed(() => {
  const toccano = pregressiCheToccano.value;
  if (toccano.length === 0) return null;
  const elenco = toccano.map(s => `${s.immobile_nome}, ${euro(Math.abs(s.importo) / 100)}`).join('; ');
  if (toccano.length > 1) {
    return { titolo: `${toccano.length} pregressi dell'unità toccano un passaggio: ${elenco}.`, dettaglio: 'Controlla a chi vanno prima di generare.' };
  }
  const s = toccano[0];
  const scelta = sceltaInVigore(s.riparto_previsto, s.ripartizione_mode, s.alla_data);
  const va = scelta === null
    ? 'Lo dividi a mano.'
    : scelta.regola
      // Con le parole dell'etichetta della regola, che il server scrive: «titolare al 1 gennaio 2026», «eredi di Ugo Rossi», «a nome del
      // defunto» (rilievo 4: con la successione gli eredi non erano titolari quel giorno), «con l'arretrato di Ugo Rossi agli eredi» o «con
      // l'arretrato a nome di Ugo Rossi» quando accanto a loro c'è chi era già titolare (rilievo 3 del secondo scettico).
      ? `Va a ${elencoDeiNomi(scelta.quote.map(q => q.nome))}, ${scelta.etichetta.replace(/ · la regola$/, '')}.`
      : `Va a ${elencoDeiNomi(scelta.quote.map(q => q.nome))}, scelta tua.`;

  return { titolo: `1 pregresso dell'unità tocca un passaggio: ${elenco}.`, dettaglio: `${va} Controlla a chi va prima di generare.` };
});

/** Il riepilogo fisso in fondo alla finestra (decisione 608 (5)): quanti saldi, e a chi va ogni pregresso che tocca un passaggio. */
const riepilogoDeiSaldi = computed(() => {
  const n = saldiDettaglio.value.length;
  const base = `${n} ${n === 1 ? 'saldo' : 'saldi'}`;
  const toccano = pregressiCheToccano.value;
  if (toccano.length === 0) {
    const manuali = saldiDettaglio.value.filter(s => s.tipo === 'solidale' && s.ripartizione_mode === 'manuale').length;
    return { base, toccano: null, resto: manuali > 0 ? ` · ${manuali} ${manuali === 1 ? 'diviso' : 'divisi'} a mano.` : '.' };
  }
  if (toccano.length === 1) {
    const s = toccano[0];
    return { base, toccano: '1 tocca un passaggio', resto: s.ripartizione_mode === 'manuale' ? ': diviso a mano.' : `: va a ${aChiVa(s.riparto_previsto, s.ripartizione_mode, s.alla_data)}.` };
  }
  const regola = toccano.filter(s => sceltaInVigore(s.riparto_previsto, s.ripartizione_mode, s.alla_data)?.regola === true).length;
  const mano = toccano.filter(s => s.ripartizione_mode === 'manuale').length;
  const tue = toccano.length - regola - mano;
  const parti = [regola > 0 ? `${regola} con la regola` : '', tue > 0 ? `${tue} con una scelta tua` : '', mano > 0 ? `${mano} a mano` : ''].filter(Boolean);
  return { base, toccano: `${toccano.length} toccano un passaggio`, resto: `: ${parti.join(', ')}.` };
});

/** La descrizione sotto il titolo della finestra: la gestione, e quanti saldi dell'unità e nominali. */
const descrizioneDellaFinestra = computed(() => {
  const nome = props.gestioni.find(g => g.id === form.gestione_id)?.nome ?? '';
  const unita = saldiDettaglio.value.filter(s => s.tipo === 'solidale').length;
  const nominali = saldiDettaglio.value.filter(s => s.tipo === 'nominale').length;
  const parti = [unita > 0 ? `${unita} ${unita === 1 ? 'saldo dell\'unità' : 'saldi dell\'unità'}` : '', nominali > 0 ? `${nominali} ${nominali === 1 ? 'nominale' : 'nominali'}` : ''].filter(Boolean);
  return [nome, parti.join(', ')].filter(Boolean).join(' · ') + '.';
});

const aggiungiRigaRiparto = (saldo: SaldoDettaglio) => {
  // Stringa fin da subito, stesso motivo delle altre due righe corrette sopra.
  saldo.ripartizioni_custom.push({ anagrafica_id: 0, importo: toMoneyFieldString(0) });
}

watch(() => form.capitoli_ids, (newIds) => {
  capitoliDettaglio.value = capitoliDettaglio.value.filter(c => newIds.includes(c.id));
  newIds.forEach(id => {
    if (!capitoliDettaglio.value.find(c => c.id === id)) {
      const capOriginale = capitoliDisponibili.value.find(c => c.id === id);
      if (capOriginale) {
        const residuoIniziale = (capOriginale.residuo ?? 0) > 0 ? capOriginale.residuo : 0;
        capitoliDettaglio.value.push({
          id: id,
          nome: capOriginale.nome,
          tipo: capOriginale.tipo,
          importo_totale: capOriginale.importo_totale ?? 0,
          residuo: capOriginale.residuo ?? 0,
          // Stringa fin da subito (non un numero grezzo): se l'utente non tocca il campo,
          // il v-model di v-money3 non lo riformatta mai da solo, e un numero grezzo fallisce
          // la validazione 'string' lato server con un errore silenzioso (nessun redirect, nessun log).
          importo_da_usare: toMoneyFieldString(residuoIniziale),
          note: ''
        });
      }
    }
  });
});

// --- LOGICA CARRELLO STRAORDINARIO ---
const totaleStraordinarioSelezionatoFormatted = computed(() => {
  const tot = fattureStraordinarie.value
    .filter(f => f.selezionata)
    .reduce((acc, curr) => acc + parseMoney(curr.importo_suggerito), 0);
  return euro(tot);
});

const handleProcedi = (nota: string) => {
  form.accetta_scoperti = true
  form.nota_scoperti    = nota
  submit()
}

// Il primo cancello, se già accettato, resta nel form (useForm lo conserva fra un giro e l'altro): il
// secondo non riapre il primo.
const handleProcediDestinatari = (nota: string) => {
  form.accetta_destinatari = true
  form.nota_destinatari    = nota
  submit()
}

const submit = () => {
  // Coda 686 (b): senza una fattura scelta la richiesta sarebbe rifiutata; il pulsante lo dice già, e qui non parte.
  if (senzaFattureScelte.value) return;

  // 1. Prepariamo i capitoli solo se siamo in ordinario
  // Il piano dei saldi non manda voci.
  if (props.precompilato) form.capitoli_ids = [];
  form.capitoli_config = form.tipo === 'ordinario' && !props.precompilato
    ? capitoliDettaglio.value.map(c => ({ id: c.id, importo: c.importo_da_usare, note: c.note })) 
    : [];

  // La competenza per voce: solo le voci a video che escono dalla base (la base non si scrive).
  form.competenze_capitoli = form.tipo === 'ordinario'
    ? vociFuoriBase.value.map(v => ({ conto_id: v.id, tratti: trattiDiVoce(v.id) }))
    : [];

  // 2. Prepariamo le fatture solo se siamo in straordinario. Tornati a «Dalle voci del preventivo» il carrello di prima non viaggia: le sue fatture
  // (magari di un'altra gestione) si rifiuterebbero su un piano che non le usa.
  form.fatture_config = form.tipo === 'straordinario'
    ? fattureStraordinarie.value
      .filter(f => f.selezionata)
      .map(f => ({ id: f.id, importo: f.importo_suggerito }))
    : [];

  // Coda 743: il debito ereditato viaggia con il piano, solo sulla scheda delle fatture e sulla gestione della pregressa.
  form.ripianamento = form.tipo === 'straordinario'
    ? ripianamentoDaInviare(props.ripianamento, ripianamentoSiVede.value, ripianamentoAttivo.value, ripianamentoImporto.value, ripianamentoTabella.value)
    : null;

  // 3. Saldi config (comune a entrambi)
  // Issue 58: un saldo dell'unità con una scelta diversa dalla regola porta il giorno dei titolari (`alla_data`); con la regola non si manda.
  form.saldi_config = saldiDettaglio.value
    .filter(s => s.tipo === 'solidale' && (s.ripartizione_mode === 'manuale' || s.alla_data !== null))
    .map(s => s.ripartizione_mode === 'manuale'
      ? { saldo_id: s.id, ripartizioni: s.ripartizioni_custom }
      : { saldo_id: s.id, alla_data: s.alla_data });

  // 4. Invio. Coda 688: il giorno dei lavori viaggia solo con «Urgenza», sulle due schede (il campo resta com'è, per un secondo invio).
  form.transform((dati) => ({ ...dati, ...campiDellUrgenza(form.tipo_autorizzazione, schedaDelPiano.value, form.giorno_dei_lavori ?? '') })).post(route(...generateRoute(
    'gestionale.esercizi.piani-rate.store',
    { condominio: props.condominio.id, esercizio: props.esercizio.id }
  )), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      router.flushAll()
    },
  });
}

</script>

<template>
  <Head title="Crea nuovo piano rate" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Nuovo piano rate"
        :page-subtitle="`Generazione scadenze per l'esercizio: ${props.esercizio.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="props.ritorno?.url ?? generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
        :back-text="props.ritorno?.testo ?? 'Torna all\'elenco'"
      >
        <template #actions>
          <!--
            Le tre card in cima dicono DOVE sono le cose; la guida dice quale scegliere e
            perché — il tipo di piano, i tre metodi per i saldi, il calendario. È la pagina
            con più combinazioni del gestionale, e nessuna di quelle scelte è ovvia.
          -->
          <Button variant="outline" class="h-9 gap-2 font-medium shadow-sm" @click="mostraGuida = true">
            <BookOpen class="h-4 w-4" /> Guida
          </Button>
        </template>
      </PageHeaderGuide>

      <PianoRateGuide v-model:open="mostraGuida" />

      <form id="pianoRateForm" @submit.prevent="submit" class="space-y-6">

        <!-- «Crea il piano straordinario» (decisione 622 (1)): la gestione, la voce e la delibera sono già scelte; il piano nasce solo con «Salva il piano». -->
        <p v-if="preparatoDallaDelibera" class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200"
          data-testid="preparato-dalla-delibera">{{ preparatoDallaDelibera }}</p>

        <div v-if="props.precompilato" data-testid="piano-dei-saldi"
          class="space-y-1 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200">
          <p class="font-semibold">Nuovo piano rate, già compilato</p>
          <p><strong>Voci</strong>: nessuna: il piano chiede solo i saldi.</p>
          <p>Il piano nasce in bozza con una rata da {{ euroDeiSaldi(props.precompilato.totale) }}. Si approva con la data della delibera del consuntivo da cui vengono i saldi, poi si emette.</p>
        </div>

        <div v-if="showBannerPreselezione" class="bg-amber-100 border border-amber-300 text-amber-900 p-4 rounded-xl shadow-sm flex items-start gap-4">
          <div class="w-10 h-10 bg-amber-200 text-amber-700 rounded-full flex items-center justify-center shrink-0 mt-0.5">
            <AlertTriangle class="w-5 h-5" />
          </div>
          <div>
            <h4 class="font-black text-sm uppercase tracking-wide">Risoluzione scoperto</h4>
            <!-- Il testo segue la strada: lo sforo di una voce di preventivo non ha fatture nel carrello (il carrello
                 esiste solo sullo straordinario), e dirlo mandava a cercare un riquadro che non c'è. -->
            <p v-if="form.tipo === 'straordinario'" class="text-xs mt-1 text-amber-800/80 leading-relaxed">
              Il programma ha precompilato questo piano per finanziare le spese scoperte rilevate nella dashboard.
              Le fatture sono già nel carrello qui in basso: inserisci la causale e procedi.
            </p>
            <p v-else class="text-xs mt-1 text-amber-800/80 leading-relaxed">
              Il programma ha precompilato questo piano per lo sforo di preventivo rilevato nella dashboard: la voce
              sforata è già selezionata fra le voci di bilancio qui in basso, con l'importo dello sforo come importo
              da richiedere. Controlla, dai un nome al piano e procedi.
            </p>
          </div>
        </div>

        <div class="mb-6 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label class="flex flex-col p-5 rounded-xl border transition-all relative"
              :class="[
                form.tipo === 'ordinario' ? 'bg-blue-50 border-blue-400 shadow-md ring-1 ring-blue-400 dark:bg-blue-950/40 dark:border-blue-700' : 'border-slate-200 hover:bg-slate-50 bg-white dark:bg-slate-950 dark:border-slate-800 dark:hover:bg-slate-900',
                isOrdinarioDisabled ? 'opacity-50 cursor-not-allowed hover:bg-white dark:hover:bg-slate-950' : 'cursor-pointer'
              ]"
              :aria-disabled="isOrdinarioDisabled ? 'true' : undefined">
              <div class="flex items-start justify-between w-full mb-3">
                  <div class="flex flex-wrap items-center gap-2">
                    <div class="font-black text-sm" data-scheda="dalle-voci" :class="form.tipo === 'ordinario' ? 'text-blue-800 dark:text-blue-200' : 'text-slate-700 dark:text-slate-200'">{{ SCHEDA_DALLE_VOCI }}</div>
                    <span v-if="isPianoIntegrativo" class="text-[11px] font-semibold rounded-md px-1.5 py-0.5 bg-blue-100 text-blue-800 border border-blue-200 dark:bg-blue-900/40 dark:text-blue-200 dark:border-blue-800">Rata integrativa</span>
                  </div>
                  <input type="radio" v-model="form.tipo" value="ordinario" :disabled="isOrdinarioDisabled"
                      :aria-describedby="isOrdinarioDisabled ? 'tipo-ordinario-non-disponibile' : undefined"
                      class="w-4 h-4 mt-0.5 text-blue-600 border-slate-300 focus:ring-blue-600 disabled:cursor-not-allowed" />
              </div>
              <p class="text-xs leading-relaxed flex-1" :class="form.tipo === 'ordinario' ? 'text-blue-700/80 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'">
                Le voci del piano dei conti della gestione: il preventivo di inizio anno, i suoi sforamenti, le voci di una gestione straordinaria.
                <template v-if="isPianoIntegrativo"> Si aggiunge al piano già creato per questa gestione.</template>
              </p>
              <p v-if="isOrdinarioDisabled" id="tipo-ordinario-non-disponibile" class="text-[11px] text-slate-400 mt-2 flex items-center gap-1">
                  <Info class="w-3 h-3 shrink-0" /> Non disponibile: il tipo è già stato determinato in base alla voce da finanziare.
              </p>
          </label>
          <label class="flex flex-col p-5 rounded-xl border transition-all relative"
              :class="[
                form.tipo === 'straordinario' ? 'bg-amber-50 border-amber-400 shadow-md ring-1 ring-amber-400 dark:bg-amber-950/40 dark:border-amber-700' : 'border-slate-200 hover:bg-slate-50 bg-white dark:bg-slate-950 dark:border-slate-800 dark:hover:bg-slate-900',
                isStraordinarioDisabled ? 'opacity-50 cursor-not-allowed hover:bg-white dark:hover:bg-slate-950' : 'cursor-pointer'
              ]"
              :aria-disabled="isStraordinarioDisabled ? 'true' : undefined">
              <div class="flex items-start justify-between w-full mb-3">
                  <div class="font-black text-sm" data-scheda="fuori-preventivo" :class="form.tipo === 'straordinario' ? 'text-amber-800 dark:text-amber-200' : 'text-slate-700 dark:text-slate-200'">{{ SCHEDA_FUORI_PREVENTIVO }}</div>
                  <input type="radio" v-model="form.tipo" value="straordinario" :disabled="isStraordinarioDisabled"
                      :aria-describedby="isStraordinarioDisabled ? 'tipo-straordinario-non-disponibile' : undefined"
                      class="w-4 h-4 mt-0.5 text-amber-600 border-slate-300 focus:ring-amber-600 disabled:cursor-not-allowed" />
              </div>
              <p class="text-xs leading-relaxed flex-1" :class="form.tipo === 'straordinario' ? 'text-amber-700/80 dark:text-amber-300' : 'text-slate-500 dark:text-slate-400'">Le fatture con una riga «Fuori preventivo» o addebitata a una sola unità, con la delibera o l'urgenza (art. 1135 c.c.).</p>
              <p v-if="isStraordinarioDisabled" id="tipo-straordinario-non-disponibile" class="text-[11px] text-slate-400 mt-2 flex items-center gap-1">
                  <Info class="w-3 h-3 shrink-0" /> Non disponibile: il tipo è già stato determinato in base alla voce da finanziare.
              </p>
          </label>
        </div>
        <!-- Decisione 510: la riga sotto le schede. Sul forum un amministratore aveva scelto lo straordinario per una spesa registrata sulla voce di una gestione straordinaria:
             il criterio non è la natura della spesa, è come è registrata la fattura. La «i» lo dice con i due casi. -->
        <div class="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-400 leading-relaxed" data-testo="riga-sotto-le-schede">
          <span>Le spese registrate su una voce del piano dei conti, anche di una gestione straordinaria, si mettono a rate con «{{ SCHEDA_DALLE_VOCI }}».</span>
          <HoverCard>
            <HoverCardTrigger as-child>
              <button type="button" class="cursor-pointer shrink-0" aria-label="Un esempio delle due schede">
                <Info class="w-4 h-4 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors" />
              </button>
            </HoverCardTrigger>
            <HoverCardContent class="w-80 z-50 p-4 shadow-xl">
              <div class="space-y-2 text-xs text-slate-600 dark:text-slate-300">
                <p><strong class="text-slate-800 dark:text-slate-100">«{{ SCHEDA_DALLE_VOCI }}»</strong>: la gestione «Facciata 2026» ha la voce «Rifacimento facciata» da
                  € 24.000,00, e la fattura dell'impresa è registrata su quella voce. Scegli la gestione e la voce.</p>
                <p><strong class="text-slate-800 dark:text-slate-100">«{{ SCHEDA_FUORI_PREVENTIVO }}»</strong>: la fattura dell'idraulico per una perdita improvvisa è registrata
                  con la riga «Fuori preventivo», o addebitata al solo interno 3. Scegli la fattura nel carrello.</p>
              </div>
            </HoverCardContent>
          </HoverCard>
        </div>
        </div>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Configurazione iniziale</CardTitle>
            <CardDescription>Definisci il nome e la gestione di riferimento del piano rate.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="space-y-2">
                <Label for="nome">Nome identificativo</Label>
                <Input 
                    id="nome" 
                    v-model="form.nome" 
                    placeholder="es. Ordinario 2026" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                    v-on:focus="form.clearErrors('nome')"
                />
                <InputError :message="form.errors.nome" />
              </div>

              <div class="space-y-2">
                <Label for="gestione_id">Gestione di riferimento</Label>
                <v-select
                  class="mt-1 bg-white dark:bg-slate-950 text-sm"
                  id="gestione_id"
                  :options="gestioni"
                  label="nome"
                  v-model="form.gestione_id"
                  :reduce="g => g.id"
                  placeholder="Seleziona gestione..."
                  @update:modelValue="form.clearErrors('gestione_id')"
                />
                <InputError :message="form.errors.gestione_id" />
              </div>
            </div>

            <div class="space-y-2">
              <Label for="descrizione">Descrizione / Note esterne</Label>
              <Textarea
                id="descrizione"
                class="mt-1 w-full min-h-[80px] resize-none bg-white dark:bg-slate-950"
                v-model="form.descrizione"
                placeholder="Dettagli visibili nei documenti generati..."
              />
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Saldi pregressi</CardTitle>
            <CardDescription>Gestisci eventuali debiti o crediti dell'anno precedente.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">

            <!-- ── BANNER DINAMICO per gestione ─────────────────────────────── -->
            <div v-if="!saldoInfo.is_primo_anno">

              <!--
                Non aver ancora scelto la gestione NON è un problema, ed è l'unico stato di
                questa card che non lo è. Finiva nello stesso riquadro ambra con il triangolo
                di allarme usato per il blocco vero — «un'altra gestione ha già consumato il
                saldo» — perché il server, al primo caricamento, riusa `applicabile: false`
                per dire due cose diverse: «non si può» e «non lo so ancora».

                A chi apre la pagina la differenza non arriva: vede un avviso arancione prima
                ancora di aver toccato qualcosa, e si chiede cosa abbia sbagliato.
              -->
              <!--
                I due stati in cui non c'è niente da decidere — non hai ancora scelto la
                gestione, oppure i saldi sono già in un altro piano — sono una RIGA, non un
                riquadro. La card ha già titolo e sottotitolo: metterci dentro un box bordato
                per dire che non c'è nulla da fare è una scatola dentro una scatola, e pesa
                più dello stato in cui invece c'è da scegliere.

                E non sono ambra. Nessuno dei due è un problema: il secondo in particolare è
                la situazione normale dal secondo piano dell'anno in poi. Il triangolo
                arancione diceva «hai sbagliato qualcosa» a chi non aveva sbagliato niente.

                Nascondere del tutto sarebbe stato peggio: il testo del secondo caso risponde
                alla domanda che l'amministratore si farebbe comunque — «perché non vedo
                nessun saldo?» — e gli dice come averne. Un'assenza non spiegata si scambia
                per un dato mancante.
              -->
              <p v-if="!form.gestione_id" class="flex items-start gap-2 text-sm text-slate-500 dark:text-slate-400">
                <Info class="w-4 h-4 shrink-0 mt-0.5 text-slate-400" />
                <span>Scegli la gestione di riferimento qui sopra: i debiti e i crediti dell'anno precedente compariranno qui.</span>
              </p>

              <p v-else-if="!saldoInfo.applicabile" class="flex items-start gap-2 text-sm text-slate-500 dark:text-slate-400">
                <Info class="w-4 h-4 shrink-0 mt-0.5 text-slate-400" />
                <span>{{ saldoInfo.motivo }}</span>
              </p>

              <template v-else>

                <!-- Nessuna gestione ancora selezionata -->
                <div v-if="!form.gestione_id"
                     class="bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 rounded-lg p-4 flex items-start gap-3">
                  <Info class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-bold text-slate-600 dark:text-slate-300 text-sm">Saldi pregressi disponibili</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                      Seleziona una gestione per visualizzare i saldi specifici e il totale da riportare.
                    </p>
                  </div>
                </div>

                <!-- Gestione selezionata, fetch in corso -->
                <div v-else-if="isLoadingSaldi"
                     class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex items-center gap-3">
                  <LoaderCircle class="w-5 h-5 text-blue-500 animate-spin shrink-0" />
                  <p class="text-xs text-blue-700 dark:text-blue-300 font-medium">
                    Caricamento saldi per la gestione selezionata...
                  </p>
                </div>

                <!-- Gestione con saldi → totale dinamico -->
                <div v-else-if="saldoGestioneCorrente.hasSaldi"
                     class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex items-start gap-3">
                  <CheckCircle class="w-5 h-5 text-blue-600 shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-bold text-blue-900 dark:text-blue-100 text-sm">Saldi iniziali rilevati per questa gestione</h4>
                    <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                      <template v-if="saldoGestioneCorrente.countNominali > 0 && saldoGestioneCorrente.countSolidali > 0">
                        {{ saldoGestioneCorrente.countNominali }} nominali · {{ saldoGestioneCorrente.countSolidali }} solidali (Art. 63) —
                      </template>
                      <template v-else-if="saldoGestioneCorrente.countNominali > 0">
                        {{ saldoGestioneCorrente.countNominali }} saldi nominali —
                      </template>
                      <template v-else>
                        {{ saldoGestioneCorrente.countSolidali }} saldi solidali (Art. 63) —
                      </template>
                      Totale netto:
                      <!-- Il più sta sul DEBITO, come in SaldiDetailPanel: positivo = debito è
                           la convenzione dei dati, e questa pagina la stampava al rovescio. -->
                      <strong :class="saldoGestioneCorrente.totale > 0 ? 'text-red-700' : 'text-emerald-700'">
                        {{ euro(saldoGestioneCorrente.totale / 100, { forcePlus: true }) }}
                      </strong>
                    </p>
                  </div>
                </div>

                <!-- Gestione selezionata ma senza saldi -->
                <div v-else
                     class="bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 rounded-lg p-4 flex items-start gap-3">
                  <CheckCircle class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-bold text-slate-600 dark:text-slate-300 text-sm">Nessun saldo pregresso per questa gestione</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                      Non ci sono debiti o crediti da riportare per la gestione selezionata.
                    </p>
                  </div>
                </div>

                <!-- Issue 58 (bozza, scena 5): il pregresso dell'unità che tocca un passaggio si vede anche senza aprire la finestra. Sta dopo
                     la catena v-if / v-else-if / v-else qui sopra, non dentro: in mezzo, il v-else si legava a lei e «Nessun saldo pregresso»
                     compariva accanto ai saldi rilevati (rilievo 5 dello scettico della tappa 1 della 1.12.0-beta.14). -->
                <div v-if="!isLoadingSaldi && rigaDeiPregressi"
                     class="flex flex-wrap items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                     data-testid="pregressi-che-toccano">
                  <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                  <div class="min-w-0 flex-1">
                    <b class="block font-semibold">{{ rigaDeiPregressi.titolo }}</b>
                    {{ rigaDeiPregressi.dettaglio }}
                  </div>
                  <Button type="button" variant="outline" size="sm" class="ml-auto h-8 bg-white text-xs dark:bg-slate-950" @click="apriConfigurazioneSaldi">
                    <Wallet class="mr-1.5 h-3.5 w-3.5" /> Configura riparto saldi
                  </Button>
                </div>

              </template>
            </div>
            <!-- ── FINE BANNER ────────────────────────────────────────────── -->

            <div v-if="mostraDistribuzioneSaldo" class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
                
                <div>
                  <div class="flex items-center gap-2 mb-2">
                    <Label class="text-sm font-bold text-slate-700 dark:text-slate-300">Metodo di distribuzione</Label>
                    <HoverCard>
                      <HoverCardTrigger as-child>
                        <button type="button" class="cursor-pointer">
                          <Info class="w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" />
                        </button>
                      </HoverCardTrigger>
                      <HoverCardContent class="w-96 z-50 p-4 shadow-xl border-blue-100">
                        <div class="space-y-3 text-sm">
                          <h4 class="font-bold text-slate-800 flex items-center gap-2 border-b pb-2">
                            <Wallet class="w-4 h-4 text-blue-600" /> Gestione saldi pregressi
                          </h4>
                          <div class="space-y-2">
                            <p class="text-xs text-slate-600">
                              <strong class="text-emerald-700">Crea rata separata (Saldi iniziali):</strong><br>
                              <span class="opacity-90">Opzione consigliata. Crea una rata iniziale dedicata solo ai debiti/crediti dell'anno precedente. Fondamentale per la massima trasparenza e per gestire correttamente i subentri.</span>
                            </p>
                            <p class="text-xs text-slate-600">
                              <strong class="text-slate-700">Aggiungi alla prima rata:</strong><br>
                              <span class="opacity-90">Somma i vecchi debiti alla Rata 1 del nuovo preventivo. Metodo tradizionale, ma rende difficile distinguere la competenza.</span>
                            </p>
                            <p class="text-xs text-slate-600">
                              <strong class="text-slate-700">Spalma su tutte le rate:</strong><br>
                              <span class="opacity-90">Divide il debito/credito in parti uguali su tutte le rate dell'anno. Sconsigliato perché maschera l'importo reale della gestione corrente.</span>
                            </p>
                          </div>
                        </div>
                      </HoverCardContent>
                    </HoverCard>
                  </div>

                  <v-select
                    :options="[
                      { label: 'Crea rata separata (Rata 0 - Consigliata)', value: 'rata_zero' },
                      { label: 'Somma alla prima rata ordinaria', value: 'prima_rata' },
                      { label: 'Spalma matematicamente su tutte le rate', value: 'tutte_rate' }
                    ]"
                    class="bg-white dark:bg-slate-950 text-sm w-full v-select-custom"
                    v-model="form.metodo_distribuzione"
                    :reduce="opt => opt.value"
                    :clearable="false"
                    @update:modelValue="form.clearErrors('metodo_distribuzione')"
                  />
                  <InputError :message="form.errors.metodo_distribuzione" class="mt-1" />
                </div>

                <div class="flex flex-col justify-center h-full border-t md:border-t-0 md:border-l border-slate-100 dark:border-slate-800 pt-5 md:pt-0 md:pl-8">
                  
                  <div class="space-y-3 mb-4">
                    <h5 class="text-[11px] font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200">
                      Gestione saldi pregressi (Art. 63)
                    </h5>
                    <ul class="text-xs text-slate-600 dark:text-slate-400 space-y-2 leading-relaxed">
                      <li class="flex items-start gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-slate-300 mt-1.5 shrink-0"></div>
                        <span><strong>Saldi Nominali:</strong> Vengono emessi automaticamente in base all'intestazione. Non richiedono configurazione.</span>
                      </li>
                      <li class="flex items-start gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-indigo-400 mt-1.5 shrink-0"></div>
                        <span><strong>Saldi Solidali:</strong> Legati all'immobile, il sistema li ripartirà <strong>in automatico</strong> fra chi ne era titolare il primo giorno del loro esercizio, con un diritto reale sull'unità — mai l'inquilino, che verso il condominio non è debitore. Apri il pulsante qui sotto per <strong>vedere chi pagherà</strong> prima di generare, per scegliere a chi va un pregresso che tocca un passaggio, o per un riparto a mano.</span>
                      </li>
                    </ul>
                  </div>
                  
                  <div class="relative group/btn w-full mt-auto">
                    <Button 
                      type="button" 
                      variant="outline" 
                      class="w-full h-10 border-indigo-200 text-indigo-700 bg-indigo-50/30 hover:bg-indigo-50 hover:text-indigo-800 transition-colors font-bold text-xs uppercase tracking-widest shadow-sm"
                      :disabled="!form.gestione_id || isLoadingSaldi || !saldoGestioneCorrente.hasSaldi"
                      @click="apriConfigurazioneSaldi"
                    >
                      <LoaderCircle v-if="isLoadingSaldi" class="w-4 h-4 mr-2 animate-spin" />
                      <Wallet v-else class="w-4 h-4 mr-2" />
                      Configura riparto saldi
                    </Button>
                    
                    <!-- Tooltip contestuale in base allo stato -->
                    <div v-if="!form.gestione_id"
                         class="absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap text-[10px] font-bold tracking-wider uppercase bg-slate-800 text-white px-3 py-1.5 rounded opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none z-10">
                      Seleziona prima la gestione
                    </div>
                    <div v-else-if="!saldoGestioneCorrente.hasSaldi && !isLoadingSaldi"
                         class="absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap text-[10px] font-bold tracking-wider uppercase bg-slate-800 text-white px-3 py-1.5 rounded opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none z-10">
                      Nessun saldo da configurare
                    </div>
                  </div>
                </div>

              </div>
            </div>

          </CardContent>
        </Card>

        <Card v-if="form.tipo === 'ordinario' && !props.precompilato" data-testid="scelta-delle-voci" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Budget e capitoli di spesa</CardTitle>
            <CardDescription>Scegli quali voci di spesa includere nel piano rate e con quali importi.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            
            <div class="bg-blue-50/60 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 rounded-xl p-4 flex gap-3">
              <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
              <div class="text-sm text-blue-900 dark:text-blue-100 space-y-2 leading-relaxed">
                <p>
                  <strong class="font-bold text-blue-950 dark:text-white">Emissione globale (Default):</strong> 
                  Se lasci il campo sottostante vuoto, il piano include <strong>le voci del bilancio di questa gestione</strong> che non sono già in un altro piano rate di questo esercizio.
                </p>
                <p>
                  <strong class="font-bold text-blue-950 dark:text-white">Emissione parziale:</strong> 
                  Seleziona specifiche voci dal menu per creare un piano rate mirato (es. solo Riscaldamento). Una volta selezionate, potrai anche <strong>richiedere un importo parziale</strong> per ogni voce rispetto al budget totale.
                </p>
              </div>
            </div>

            <div>
              <Label :class="{ 'opacity-50': !form.gestione_id }" class="mb-1.5 block">Voci di bilancio incluse (lascia vuoto per includere tutte le voci libere in questo esercizio)</Label>
              <v-select
                multiple
                v-model="form.capitoli_ids"
                :options="capitoliDisponibili"
                label="nome"
                :reduce="c => c.id"
                :disabled="!form.gestione_id || isLoadingCapitoli"
                :selectable="(option: Capitolo) => !option.disabled"
                :placeholder="!form.gestione_id ? 'Seleziona prima una gestione...' : 'Ricerca e seleziona capitoli (opzionale)...'"
                class="bg-white dark:bg-slate-950 text-sm"
              >
                <template #spinner>
                  <LoaderCircle v-if="isLoadingCapitoli" class="w-4 h-4 animate-spin mr-2" />
                </template>
                <template #option="option: Capitolo">
                  <div :class="{ 'opacity-50 grayscale': option.disabled }" class="flex justify-between w-full items-center py-1">
                    <div class="flex flex-col">
                      <span :class="{ 'font-bold text-slate-800': option.nome.startsWith('[') }">{{ option.nome }}</span>
                      <span class="text-[10px] text-slate-500">Budget: {{ euro(option.importo_totale) }}</span>
                    </div>
                    <div class="flex items-center">
                      <span v-if="option.tipo === 'entrata'"
                            class="text-[10px] bg-sky-100 text-sky-700 px-2 py-0.5 rounded-md font-bold uppercase ml-2 border border-sky-200"
                            title="Le entrate si sottraggono alle spese">
                        Entrata
                      </span>
                      <!-- Una voce di spesa scritta con il meno non è «Esaurito»: un piano nuovo non la addebita. La nota del server dice perché. -->
                      <span v-if="option.spesa_negativa"
                            class="flex items-center gap-1 text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-md font-bold uppercase ml-2 border border-amber-200"
                            :title="option.note">
                        <Ban class="w-3 h-3" /> Importo negativo
                      </span>

                      <span v-else-if="option.is_sforo"
                            class="flex items-center gap-1 text-[10px] bg-rose-100 text-rose-600 px-2 py-0.5 rounded-md font-bold uppercase ml-2 border border-rose-200">
                        <TrendingDown class="w-3 h-3" /> Sforo: {{ euro(option.residuo) }}
                      </span>

                      <span v-else-if="option.disabled"
                            class="flex items-center gap-1 text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-md font-bold uppercase ml-2 border border-red-200">
                        <Ban class="w-3 h-3" /> Esaurito
                      </span>

                      <!-- Coda 73: questa voce ha ceduto budget con Sposta Spesa — il residuo
                           mostrato qui è quello che rientrerebbe scegliendola. -->
                      <span v-else-if="option.da_sposta_spesa"
                            class="flex items-center gap-1 text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-md font-bold uppercase ml-2 border border-indigo-200"
                            title="Questa voce ha ceduto budget con Sposta Spesa in un altro piano">
                        <ArrowRightLeft class="w-3 h-3" /> Da Sposta Spesa: {{ euro(option.residuo) }}
                      </span>

                      <span v-else
                            class="flex items-center gap-1 text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-md font-bold ml-2 border border-green-200">
                        <Wallet class="w-3 h-3" /> Disp: {{ euro(option.residuo) }}
                      </span>
                    </div>
                  </div>
                </template>
              </v-select>
            </div>

            <div v-if="capitoliDettaglio.length > 0" class="border border-slate-200 rounded-lg overflow-hidden bg-white shadow-sm">
              <div class="bg-slate-50 px-4 py-3 border-b border-slate-200 flex justify-between items-center">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2"><List class="w-4 h-4" /> Ripartizione manuale voci</span>
                <span class="text-sm font-bold text-primary bg-white px-3 py-1 rounded border shadow-sm">{{ totaleSelezionatoFormatted }}</span>
              </div>
              <div class="divide-y divide-slate-100 max-h-[350px] overflow-y-auto">
                <div v-for="(cap) in capitoliDettaglio" :key="cap.id" class="p-3 hover:bg-slate-50 transition-colors group">
                  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium text-sm text-slate-900 truncate">{{ cap.nome }}</div>
                      <div class="text-xs text-slate-500 mt-1 flex items-center gap-2">
                        <span class="bg-slate-100 px-1.5 py-0.5 rounded text-slate-600">Budget totale: {{ euro(cap.importo_totale) }}</span>
                        <span class="text-slate-300">|</span>
                        <span :class="{ 'text-green-700 bg-green-50': (cap.residuo - parseMoney(cap.importo_da_usare)) >= 0, 'text-red-600 bg-red-50': (cap.residuo - parseMoney(cap.importo_da_usare)) < 0 }" class="px-1.5 py-0.5 rounded font-medium transition-colors">
                            Residuo: {{ euro(cap.residuo - parseMoney(cap.importo_da_usare)) }}
                        </span>
                      </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                      <div class="w-32 relative">
                        <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">€</span>
                        <MoneyInput 
                          v-model="cap.importo_da_usare" 
                          :money-options="moneyOptions"
                          :lazy="true"
                          class="pl-6 h-9 text-right text-sm font-bold" 
                          :class="{ 'border-red-500 bg-red-50 text-red-700': parseMoney(cap.importo_da_usare) > cap.residuo }" 
                        />
                      </div>
                      <div class="w-40 sm:w-56"><Input v-model="cap.note" placeholder="Note (es. Acconto invernale)..." class="h-9 text-xs" /></div>
                      <button @click="rimuoviCapitolo(cap.id)" type="button" class="text-slate-400 hover:text-red-500 p-2"><Trash2 class="w-4 h-4" /></button>
                    </div>
                  </div>
                  <div v-if="parseMoney(cap.importo_da_usare) > cap.residuo" class="text-[11px] text-red-600 mt-2 flex items-center gap-1 font-medium bg-red-50 p-1.5 rounded">
                    <AlertTriangle class="w-3 h-3" /> Attenzione: L'importo richiesto supera il residuo disponibile a bilancio.
                  </div>
                </div>
              </div>
            </div>

            <!-- Competenza delle voci (B2, S6, decisione 20): sempre, anche quando il piano include tutto -->
            <div v-if="vociPerCompetenza.length > 0" class="border border-slate-200 rounded-lg overflow-hidden bg-white shadow-sm">
              <div class="bg-slate-50 px-4 py-3 border-b border-slate-200 flex flex-col gap-1">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2"><CalendarRange class="w-4 h-4" /> Competenza delle voci</span>
                <p class="text-[11px] text-slate-500 leading-relaxed">
                  Il periodo su cui ogni voce matura: decide i giorni quando un'unità cambia titolare durante l'anno. Il default è il
                  periodo della gestione<template v-if="basePiano"> ({{ descriviTratti([basePiano]) }})</template>; il riscaldamento di
                  solito matura sulla sua stagione, non su tutto l'anno. Le voci lasciate sulla base non scrivono nulla.
                  <template v-if="form.capitoli_ids.length === 0"> Qui ci sono i capitoli: una competenza sul capitolo vale per tutte le sue voci.</template>
                </p>
                <div v-if="mostraFiltroCompetenza" class="relative mt-2">
                  <Search class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <input v-model="filtroVociCompetenza" type="search" placeholder="Cerca una voce per nome…"
                         class="h-8 w-full sm:max-w-xs rounded-md border border-slate-200 bg-white pl-8 pr-2 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-1 focus:ring-primary" />
                  <span v-if="filtroVociCompetenza.trim() !== ''" class="ml-2 text-[11px] text-slate-500">{{ vociPerCompetenzaVisibili.length }} su {{ vociPerCompetenza.length }}</span>
                </div>
              </div>
              <div class="divide-y divide-slate-100 max-h-[400px] overflow-y-auto">
                <div v-if="vociPerCompetenzaVisibili.length === 0" class="p-4 text-xs text-slate-500">Nessuna voce con questo nome.</div>
                <div v-for="voce in vociPerCompetenzaVisibili" :key="voce.id" class="p-3 hover:bg-slate-50 transition-colors">
                  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium text-sm text-slate-900 truncate">{{ voce.nome }}</div>
                      <div class="text-[11px] mt-1" :class="competenzaDi(voce.id).preset === 'gestione' ? 'text-slate-400' : 'text-primary font-medium'">
                        {{ competenzaDi(voce.id).preset === 'gestione' ? (basePiano ? descriviTratti([basePiano]) : 'periodo della gestione') : descriviTratti(trattiDiVoce(voce.id)) }}
                        <span v-if="competenzaDi(voce.id).fonte" class="text-slate-400 font-normal"> · come nel piano «{{ competenzaDi(voce.id).fonte }}»</span>
                      </div>
                    </div>
                    <div class="shrink-0 w-full sm:w-72">
                      <!-- Decisione 392: il menu con v-select, come gli altri della pagina (era il <select> del browser). -->
                      <v-select
                        :options="OPZIONI_PRESET"
                        :model-value="competenzaDi(voce.id).preset"
                        :reduce="(o: { value: PresetCompetenza }) => o.value"
                        :clearable="false"
                        :searchable="false"
                        :aria-label="`Competenza della voce ${voce.nome}`"
                        class="bg-white dark:bg-slate-950 text-xs"
                        :class="{ 'text-slate-400': competenzaDi(voce.id).preset === 'gestione' }"
                        @update:modelValue="(p: PresetCompetenza) => cambiaPreset(voce.id, p)"
                      />
                    </div>
                  </div>

                  <div v-if="competenzaDi(voce.id).preset === 'manuale'" class="mt-3 space-y-2">
                    <div v-for="(t, i) in competenzaDi(voce.id).manuali" :key="i" class="flex flex-wrap items-center gap-2">
                      <span class="text-[11px] text-slate-500 w-14">Tratto {{ i + 1 }}</span>
                      <Input type="date" v-model="t.dal" class="h-9 w-40 text-xs" />
                      <span class="text-slate-400 text-xs">–</span>
                      <Input type="date" v-model="t.al" :min="t.dal || undefined" class="h-9 w-40 text-xs" />
                      <button type="button" @click="togliTratto(voce.id, i)" class="text-slate-400 hover:text-red-500 p-1" :disabled="competenzaDi(voce.id).manuali.length === 1"><Trash2 class="w-4 h-4" /></button>
                    </div>
                    <button v-if="competenzaDi(voce.id).manuali.length < 6" type="button" @click="aggiungiTratto(voce.id)" class="text-[11px] font-bold text-primary hover:underline flex items-center gap-1"><Plus class="w-3 h-3" /> Aggiungi un tratto</button>
                  </div>

                  <p v-if="erroreDiVoce(voce.id)" class="text-[11px] text-red-600 mt-2 flex items-center gap-1 font-medium bg-red-50 p-1.5 rounded">
                    <AlertTriangle class="w-3 h-3 shrink-0" /> {{ erroreDiVoce(voce.id) }}
                  </p>
                  <InputError :message="(form.errors as Record<string, string>)[`competenze_capitoli.${vociFuoriBase.findIndex(v => v.id === voce.id)}`]" class="mt-1" />
                </div>
              </div>
            </div>

          </CardContent>
        </Card>

        <Card v-if="form.tipo === 'straordinario'" class="border-dashed border-amber-200 shadow-sm bg-amber-50/30">
          <!--
            ⚠️ **A carrello vuoto la card perde l'intestazione, e resta un riquadro solo.**
            Non è il caso generale: l'intestazione è il segnaposto della sezione mentre si
            scorre un modulo lungo, e negli altri due stati resta. Ma quando la gestione è
            scelta e non c'è niente da ripartire, dentro la card vive **una frase sola** che
            si spiega da sé — e sopra quella frase il titolo più la sua descrizione erano
            due righe di preambolo per un contenuto di due righe.
          -->
          <CardHeader
            v-if="isLoadingFatture || !form.gestione_id || fattureStraordinarie.length > 0 || ripianamentoSiVede"
            class="pb-3 border-b border-amber-200/50 border-dashed mb-4"
          >
            <CardTitle class="text-base font-semibold text-amber-900 flex items-center gap-2">
              <AlertTriangle class="w-5 h-5 text-amber-500" /> Il carrello delle fatture
            </CardTitle>
            <!--
              Descrive, non comanda. «Seleziona le fatture...» era un ordine impartito anche
              nei due stati in cui non c'è niente da selezionare — la gestione non ancora
              scelta, e la gestione senza fatture fuori preventivo — ed è quello che faceva
              sembrare l'intestazione staccata dal corpo. L'invito a scegliere vive dove
              scegliere si può: nell'elenco, che ha le caselle e il totale.
            -->
            <CardDescription class="text-amber-700">Le fatture impreviste o ad personam che questo piano può finanziare.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            
            <!--
              ⚠️ **I commenti stanno QUI, sopra la catena, e non fra un ramo e l'altro.**
              Vue tollera **un** nodo commento fra `v-if` e `v-else-if`, non due: con due
              consecutivi la catena si spezza in silenzio, il corpo della card resta vuoto
              e non c'è nessun errore in console. Misurato a video su questa stessa card.

              Sui tre stati, che non hanno lo stesso peso:

              - **«non hai ancora scelto»** è un'attesa, non un vuoto. La card «Saldi
                pregressi», un dito più su, dice già questa identica cosa con una nota in
                linea: darle qui uno stato vuoto centrato faceva parlare due riquadri
                adiacenti in due lingue diverse per la stessa attesa.
              - **«non c'è niente»** è il vuoto vero, e si prende lo stato grande — ma
                **senza un titolo proprio**: la card ne ha già uno tre righe più su, e due
                titoli in colonna si facevano concorrenza invece di leggersi insieme. Il
                corpo **continua** l'intestazione — il fatto, poi la via d'uscita — invece
                di ripeterla: «fatture impreviste o ad personam» sta già nella descrizione
                della card, e ridirlo qui era ciò che faceva sembrare le due metà sconnesse.
            -->
            <!-- Coda 743: in un esercizio che non è quello della pregressa (o con quell'esercizio chiuso) il ripianamento non si prepara: la pagina lo dice. -->
            <div v-if="ripianamentoFermo" data-blocco="ripianamento-altrove"
              class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
              <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              <div class="min-w-0 flex-1">
                <b class="block font-semibold">Il debito ereditato da ripianare</b>
                {{ ripianamentoFermo }}
              </div>
            </div>

            <!-- Coda 743 (decisione 655): il debito ereditato da ripianare, dal compito «Deficit da recuperare». Si scrive con il piano, si toglie con lui. -->
            <div v-if="ripianamentoSiVede && props.ripianamento" data-blocco="ripianamento"
              class="space-y-3 rounded-lg border border-amber-200 bg-white p-4 dark:border-amber-800 dark:bg-slate-950">
              <div class="flex items-start gap-3">
                <Checkbox id="ripianamento_attivo" v-model="ripianamentoAttivo" class="mt-0.5" />
                <div class="min-w-0 flex-1 space-y-1">
                  <Label for="ripianamento_attivo" class="text-sm font-semibold text-slate-900 dark:text-slate-100">Il debito ereditato da ripianare</Label>
                  <ul class="list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
                    <li v-for="riga in righeDelRipianamento(props.ripianamento)" :key="riga">{{ riga }}</li>
                  </ul>
                </div>
              </div>
              <div v-if="ripianamentoAttivo" class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:pl-7">
                <div class="space-y-1.5">
                  <Label for="ripianamento_importo" class="text-xs">Quanto mettere a rate</Label>
                  <div class="relative">
                    <span class="absolute left-2 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">€</span>
                    <MoneyInput id="ripianamento_importo" v-model="ripianamentoImporto" :money-options="moneyOptions" :lazy="true"
                      class="h-9 pl-6 text-right text-sm" @update:model-value="form.clearErrors('ripianamento.importo' as any)" />
                  </div>
                  <p v-if="avvisoDellaCifra" class="text-xs text-amber-700 dark:text-amber-300" data-testo="cifra-fuori">{{ avvisoDellaCifra }}</p>
                  <InputError :message="(form.errors as Record<string, string>)['ripianamento.importo']" />
                </div>
                <div class="space-y-1.5">
                  <Label for="ripianamento_tabella" class="text-xs">La tabella del riparto</Label>
                  <v-select input-id="ripianamento_tabella" v-model="ripianamentoTabella" :options="props.ripianamento.tabelle" label="nome"
                    :reduce="(t: { id: number }) => t.id" :clearable="false" placeholder="Scegli la tabella..." class="bg-white text-sm dark:bg-slate-950"
                    @update:model-value="form.clearErrors('ripianamento.tabella_id' as any)" />
                  <InputError :message="(form.errors as Record<string, string>)['ripianamento.tabella_id']" />
                </div>
              </div>
              <InputError :message="(form.errors as Record<string, string>).ripianamento || (form.errors as Record<string, string>)['ripianamento.compito_id']" />
            </div>

            <div v-if="isLoadingFatture" class="flex justify-center py-8 text-amber-600 gap-3">
              <LoaderCircle class="w-5 h-5 animate-spin" /><span>Ricerca fatture impreviste in corso...</span>
            </div>
            
            <p v-else-if="!form.gestione_id" class="flex items-start gap-2 text-sm text-amber-700/80">
              <Info class="w-4 h-4 shrink-0 mt-0.5 text-amber-500" />
              <span>Scegli la gestione di riferimento qui sopra: le fatture fuori preventivo da ripartire compariranno qui.</span>
            </p>

            <!-- Coda 686 (a), decisione 510: il carrello vuoto dice che cosa la scheda mostra e che cosa non si vede, con l'azione giusta. -->
            <div v-else-if="fattureStraordinarie.length === 0 && !ripianamentoSiVede" data-blocco="carrello-vuoto" class="flex items-start gap-4 px-2 py-2 md:px-4 md:py-4">
              <div class="w-11 h-11 rounded-lg bg-amber-100/60 dark:bg-amber-900/30 text-amber-600 dark:text-amber-300 flex items-center justify-center shrink-0">
                <ReceiptText class="w-6 h-6" />
              </div>
              <div class="min-w-0 flex-1 space-y-2 text-sm text-amber-900 dark:text-amber-100">
                <p class="font-semibold" data-testo="titolo">Nessuna fattura da mettere a rate qui</p>
                <p class="text-amber-800 dark:text-amber-200" data-testo="che-cosa-mostra">
                  Qui ci sono le fatture di «{{ nomeGestioneScelta }}» con una riga «Fuori preventivo» o addebitata a una sola unità.
                </p>
                <template v-if="qualcosaFuoriDalCarrello && fuoriDalCarrello">
                  <p class="font-medium text-amber-900 dark:text-amber-100">Non si vedono qui:</p>
                  <ul class="space-y-1.5 text-amber-800 dark:text-amber-200" data-elenco="fuori-dal-carrello">
                    <li v-for="f in fuoriDalCarrello.sulle_voci.fatture" :key="`voce-${f.id}`" data-riga="sulla-voce" class="leading-relaxed">
                      {{ f.fornitore }}, {{ euro(f.importo) }}, sulla voce «{{ f.voce }}»<template v-if="f.altre_voci > 0"> e su {{ f.altre_voci === 1 ? 'un\'altra' : `altre ${f.altre_voci}` }}</template>:
                      <button v-if="!isOrdinarioDisabled" type="button" class="font-semibold underline underline-offset-2 hover:text-amber-950 dark:hover:text-white"
                        @click="mettiARateDalleVoci(f.conto_id)">mettila a rate con «{{ SCHEDA_DALLE_VOCI }}» →</button>
                      <span v-else>si mette a rate con «{{ SCHEDA_DALLE_VOCI }}».</span>
                    </li>
                    <li v-if="fuoriDalCarrello.sulle_voci.totale > fuoriDalCarrello.sulle_voci.fatture.length" data-riga="sulle-voci-altre" class="leading-relaxed">
                      {{ fuoriDalCarrello.sulle_voci.totale - fuoriDalCarrello.sulle_voci.fatture.length === 1 ? 'Un\'altra fattura' : `Altre ${fuoriDalCarrello.sulle_voci.totale - fuoriDalCarrello.sulle_voci.fatture.length} fatture` }}
                      sulle voci di «{{ nomeGestioneScelta }}»:
                      <button v-if="!isOrdinarioDisabled" type="button" class="font-semibold underline underline-offset-2 hover:text-amber-950 dark:hover:text-white"
                        @click="mettiARateDalleVoci(null)">«{{ SCHEDA_DALLE_VOCI }}» →</button>
                      <span v-else>si mettono a rate con «{{ SCHEDA_DALLE_VOCI }}».</span>
                    </li>
                    <li v-for="e in fuoriDalCarrello.altri_esercizi" :key="`esercizio-${e.esercizio_id}`" data-riga="altro-esercizio" class="leading-relaxed">
                      {{ e.quante === 1 ? 'Una fattura «Fuori preventivo» di questa gestione è registrata' : `${e.quante} fatture «Fuori preventivo» di questa gestione sono registrate` }}
                      nell'esercizio «{{ e.esercizio }}»: {{ e.quante === 1 ? 'si mette a rate' : 'si mettono a rate' }} con un piano di quell'esercizio.
                    </li>
                    <li v-for="f in fuoriDalCarrello.gia_nei_piani.fatture" :key="`piano-${f.id}`" data-riga="gia-in-un-piano" class="leading-relaxed">
                      {{ f.fornitore }}, n. {{ f.numero_documento }}: è già per intero {{ f.piani.length === 1 ? 'nel piano' : 'nei piani' }} «{{ f.piani.join('», «') }}».
                    </li>
                    <li v-if="fuoriDalCarrello.gia_nei_piani.totale > fuoriDalCarrello.gia_nei_piani.fatture.length" data-riga="gia-in-un-piano-altre" class="leading-relaxed">
                      {{ fuoriDalCarrello.gia_nei_piani.totale - fuoriDalCarrello.gia_nei_piani.fatture.length === 1 ? 'Un\'altra fattura è già per intero' : `Altre ${fuoriDalCarrello.gia_nei_piani.totale - fuoriDalCarrello.gia_nei_piani.fatture.length} fatture sono già per intero` }}
                      in un altro piano.
                    </li>
                  </ul>
                </template>
                <p v-else class="text-amber-700/80 dark:text-amber-300/80" data-testo="nessuna-altra">
                  Registrane una fra le fatture passive con la riga «Fuori preventivo», oppure scegli un'altra gestione.
                </p>
              </div>
            </div>

            <div v-else-if="fattureStraordinarie.length > 0" class="border border-amber-200 rounded-lg overflow-hidden bg-white shadow-sm">
              <div class="bg-amber-50 px-4 py-3 border-b border-amber-200 flex flex-wrap justify-between items-center gap-2">
                <span class="text-xs font-bold text-amber-800 uppercase tracking-wider">Fatture disponibili</span>
                <span class="text-sm font-bold text-amber-700 bg-white px-3 py-1 rounded border border-amber-200 shadow-sm">Totale richiesto: {{ totaleStraordinarioSelezionatoFormatted }}</span>
              </div>
              <div class="divide-y divide-amber-100 max-h-[350px] overflow-y-auto">
                <div v-for="(fat) in fattureStraordinarie" :key="fat.id" class="p-3 hover:bg-amber-50/50 transition-colors" :class="{ 'bg-amber-50': fat.selezionata }">
                  <!-- Sul telefono la colonna di destra (da finanziare, importo) scende sotto il testo: in fila toglieva metà
                       della larghezza agli avvisi, che andavano a capo ogni due parole (segnalato da Vincenzo a video,
                       27/09/2026). Da `sm` in su la riga è quella di prima. -->
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                   <div class="flex flex-1 min-w-0 items-start gap-3 sm:items-center sm:gap-4">
                    <Checkbox v-model="fat.selezionata" class="mt-0.5 sm:mt-0" />
                    <div class="flex-1 min-w-0">
                      <div class="font-bold text-sm text-slate-900 break-words sm:truncate">{{ fat.fornitore }} — Doc. {{ fat.numero_documento }}</div>
                      <div class="text-xs text-slate-500 mt-1">Data: {{ fat.data_documento }} | Già finanziato: {{ euro(fat.gia_finanziato) }}</div>
                      <!-- Coda 165: la fattura rettificata da una nota del fornitore si offre al netto, e lo dice. -->
                      <div v-if="notaCollegataNelCarrello(fat)" class="text-[10px] text-slate-500 mt-0.5">{{ notaCollegataNelCarrello(fat) }}</div>
                      <!-- B2, S6 e decisione 26: la competenza dichiarata, o che cosa ne farà il riparto senza. Le varianti
                           (gestione, «Urgenza», pregressa senza periodo o col periodo dentro l'esercizio) stanno in
                           `avvisoFatturaNelCarrello`, provate una per una. -->
                      <div v-if="avvisoDi(fat)?.tono === 'nota'" class="text-[10px] text-slate-500 mt-0.5">{{ avvisoDi(fat)?.testo }}</div>
                      <div v-else-if="avvisoDi(fat)" class="text-[10px] font-semibold text-amber-700 mt-0.5 flex items-start gap-1">
                        <AlertTriangle class="w-3 h-3 shrink-0 mt-px" /> <span>{{ avvisoDi(fat)?.testo }}</span>
                      </div>
                      <!-- Coda 159: lo stesso fornitore ha una fattura annullata che un piano che ha incassato chiede ancora (`CarrelloDelloStraordinario`). -->
                      <div v-if="fat.avviso_sostituzione" class="text-xs font-semibold text-amber-700 mt-0.5 flex items-start gap-1">
                        <AlertTriangle class="w-3 h-3 shrink-0 mt-px" /> <span>{{ fat.avviso_sostituzione }}</span>
                      </div>
                    </div>
                   </div>
                    <div class="flex items-center justify-between gap-2 pl-7 sm:pl-0 sm:flex-col sm:items-end sm:justify-start sm:gap-1 shrink-0">
                      <span class="text-xs font-bold text-amber-600">Da finanziare: {{ euro(fat.residuo_da_finanziare) }}</span>
                      <div class="w-32 relative" v-if="fat.selezionata">
                        <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">€</span>
                        <MoneyInput 
                          v-model="fat.importo_suggerito" 
                          :money-options="moneyOptions"
                          :lazy="true"
                          class="pl-6 h-8 text-right text-xs font-bold border-amber-300 focus:ring-amber-500" 
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!--
              ⚠️ **Lo scudo legale compare solo quando c'è qualcosa da autorizzare.**
              È `required_if:tipo,straordinario` in `CreatePianoRateRequest`, quindi il
              modulo lo pretendeva anche a carrello vuoto — ma lo stesso request rifiuta il
              piano con «Scegli almeno una fattura da mettere a rate» (Coda 686 (b)). A carrello
              vuoto chiedeva dunque di giustificare una spesa che non esiste, per un piano che
              non può nascere; e il pulsante, senza fatture scelte, non manda più la richiesta.

              ⚠️ **La card, invece, resta.** Nasconderla toglierebbe l'unica cosa a schermo
              che spiega perché il salvataggio fallirà: si compilerebbe tutto il modulo per
              prendersi quell'errore alla fine, arrivato dal nulla.

              I valori già scritti non vengono azzerati: cambiando di nuovo gestione il
              riquadro riappare con dentro quello che l'amministratore aveva battuto.
            -->
            <div v-if="(fattureStraordinarie.length > 0 || ripianamentoSiVede) && !gestioneStraordinaria" class="bg-white dark:bg-slate-950 border border-amber-200 dark:border-amber-800 rounded-xl p-5 space-y-4">
              <h4 class="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <Info class="w-4 h-4 text-amber-500" /> Scudo Legale (Obbligatorio)
              </h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- D194: gli errori di questi due campi la pagina non li mostrava, e «Salva» restava muto. Ora stanno sotto il campo. -->
                <div class="space-y-2">
                  <Label for="tipo_autorizzazione">Tipo Autorizzazione</Label>
                  <v-select 
                    input-id="tipo_autorizzazione"
                    v-model="form.tipo_autorizzazione"
                    :options="[{label: 'Delibera Assembleare', value: 'delibera'}, {label: 'Urgenza (Art. 1135 c.c.)', value: 'urgenza'}]"
                    :reduce="o => o.value"
                    class="bg-white dark:bg-slate-950 text-sm"
                    placeholder="Seleziona..."
                    @update:modelValue="form.clearErrors('tipo_autorizzazione')"
                  />
                  <InputError :message="form.errors.tipo_autorizzazione" />
                </div>
                <!-- Con «Delibera assembleare» la delibera si sceglie qui sotto e questa diventa una nota facoltativa; con «Urgenza» è il motivo, obbligatorio. -->
                <div class="space-y-2">
                  <Label for="motivazione_autorizzazione">{{ etichettaMotivazione }}</Label>
                  <Input id="motivazione_autorizzazione" v-model="form.motivazione_autorizzazione"
                    :placeholder="form.tipo_autorizzazione === 'urgenza' ? 'Per esempio: perdita dalla colonna dell\'acqua, riparata subito' : 'Per esempio: impresa Edil Verdi, preventivo del 02/10'"
                    class="bg-white dark:bg-slate-950" v-on:focus="form.clearErrors('motivazione_autorizzazione')" />
                  <InputError :message="form.errors.motivazione_autorizzazione" />
                </div>
              </div>
            </div>


          </CardContent>
        </Card>

        <!-- Coda 688: sulla gestione straordinaria «La delibera, o l'urgenza», una scelta sola, uguale sulle due schede. Con la delibera, «Scegli la delibera»
             (o la data scritta a mano, senza «Gestisce le assemblee»); con l'urgenza il motivo e il giorno dei lavori, sulle due schede. -->
        <LaDeliberaOLUrgenza v-if="gestioneStraordinaria" v-model:tipo="form.tipo_autorizzazione" v-model:motivo="form.motivazione_autorizzazione"
          :giorno="form.giorno_dei_lavori ?? ''" :scheda="schedaDelPiano" :condominio-nome="props.condominio.nome"
          :fatture-senza-periodo="scelteSenzaCompetenza.correnti + scelteSenzaCompetenza.pregresse"
          :errori="{ tipo: form.errors.tipo_autorizzazione, motivo: form.errors.motivazione_autorizzazione, giorno: (form.errors as Record<string, string>).giorno_dei_lavori }"
          @update:giorno="(v: string) => { form.giorno_dei_lavori = v; form.clearErrors('giorno_dei_lavori' as any); }">
          <template #delibera>
            <div v-if="!conLaDelibera" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end" data-testid="deliberata-il-a-mano">
              <div class="space-y-2">
                <Label for="data_delibera_assemblea">Deliberata il</Label>
                <Input id="data_delibera_assemblea" type="date" v-model="form.data_delibera_assemblea" class="bg-white dark:bg-slate-950" />
                <InputError :message="form.errors.data_delibera_assemblea" />
              </div>
              <p class="text-xs text-slate-600 leading-relaxed dark:text-slate-400">Su una gestione straordinaria è il giorno che decide chi paga: chi era titolare dell'unità alla delibera risponde dell'intera spesa, anche se poi vende. Nessuna data è proposta: la scrivi tu dal verbale.</p>
            </div>
            <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
              <ScegliLaDelibera :key="`delibera-${form.gestione_id ?? 0}`" v-model="delibera" :condominio-id="props.condominio.id" :condominio-nome="props.condominio.nome"
                ruolo="delibera" oggetto-tipo="gestione" :oggetto-id="form.gestione_id" per-questo="questo piano" :vuoto="vuotoDellaDelibera"
                vuoto-sotto="Se l'assemblea deve ancora decidere, portala alla prossima: il piano resta in bozza e il punto ti viene proposto quando la prepari."
                :disabilitato="!form.gestione_id"
                info="Le delibere di questo condominio: in testa quelle che parlano di questa gestione, con il perché. Una riunione di carta la registri con «Registra una delibera a mano»." />
              <div class="space-y-1.5" data-testid="deliberata-il">
                <span class="block text-xs font-medium text-slate-600 dark:text-slate-400">Deliberata il</span>
                <p class="text-sm text-slate-900 dark:text-slate-100">
                  <template v-if="giornoDellaDelibera"><strong class="tabular-nums">{{ giornoDellaDelibera }}</strong> <span class="text-slate-500 dark:text-slate-400">dalla delibera</span></template>
                  <span v-else class="text-slate-500 dark:text-slate-400">dalla delibera</span>
                </p>
                <InputError :message="form.errors.data_delibera_assemblea || form.errors.delibera_id" />
                <p class="text-xs text-slate-600 leading-relaxed dark:text-slate-400">È il giorno che decide chi paga: chi era titolare quel giorno risponde della spesa, anche se poi vende. Si compila dalla data della riunione quando scegli la delibera.</p>
              </div>
            </div>
          </template>
        </LaDeliberaOLUrgenza>

        <!-- Fuori dalla card dello straordinario: la delibera è della GESTIONE (natura), e riguarda anche un piano
             «ordinario» aperto su una gestione straordinaria. -->
        <Card v-else-if="mostraDataDelibera" class="border-dashed shadow-sm" :class="deliberaObbligatoria ? 'border-amber-200 bg-amber-50/30' : 'border-slate-200 bg-slate-50/50'">
          <CardContent class="pt-6">
            <!-- B2 (decisione 12): la data della delibera, obbligatoria e senza default quando la gestione è straordinaria
                 e l'autorizzazione non è l'urgenza. È il giorno che decide chi paga (art. 63 disp. att. c.c.; Cass. 24654/2010):
                 con un passaggio di proprietà, tutto a chi era titolare quel giorno. -->
            <div class="bg-white border rounded-xl p-5 space-y-3" :class="deliberaObbligatoria ? 'border-amber-200' : 'border-slate-200'">
              <h4 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                <Info class="w-4 h-4" :class="deliberaObbligatoria ? 'text-amber-500' : 'text-slate-400'" />
                <template v-if="conLaDelibera">La delibera dell'assemblea</template><template v-else>Data della delibera dell'assemblea</template> <span v-if="deliberaObbligatoria" class="text-xs font-semibold uppercase tracking-widest text-amber-700">obbligatoria</span><span v-else class="text-xs font-normal text-slate-400">(facoltativa)</span>
              </h4>
              <template v-if="form.tipo_autorizzazione === 'urgenza' && gestioneStraordinaria">
                <p class="text-xs text-slate-600 leading-relaxed">
                  Intervento d'urgenza (art. 1135 c.c.): non c'è una delibera che faccia la competenza. La competenza di questa spesa si dichiara
                  <strong>sulla fattura</strong> («costo maturato dal … al …» o «spesa deliberata il …»): senza, il riparto si ferma e lo dice.
                  <template v-if="scelteSenzaCompetenza.correnti > 0"> <strong>{{ scelteSenzaCompetenza.correnti }}</strong> {{ scelteSenzaCompetenza.correnti === 1 ? 'fattura scelta non la ha' : 'fatture scelte non la hanno' }}: dichiarala prima di generare.</template>
                  <template v-if="scelteSenzaCompetenza.pregresse > 0"> <strong>{{ scelteSenzaCompetenza.pregresse }}</strong> {{ scelteSenzaCompetenza.pregresse === 1 ? 'pregressa scelta non ha il periodo' : 'pregresse scelte non hanno il periodo' }}: una pregressa non si modifica, si storna e si registra di nuovo con il periodo.</template>
                </p>
              </template>
              <template v-else>
                <!-- Senza «Gestisce le assemblee»: «Deliberata il» scritta a mano, come prima. -->
                <div v-if="!conLaDelibera" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end" data-testid="deliberata-il-a-mano">
                  <div class="space-y-2">
                    <Label for="data_delibera_assemblea">Deliberata il</Label>
                    <Input id="data_delibera_assemblea" type="date" v-model="form.data_delibera_assemblea" class="bg-white dark:bg-slate-950" />
                    <InputError :message="form.errors.data_delibera_assemblea" />
                  </div>
                  <p class="text-xs text-slate-600 leading-relaxed dark:text-slate-400">
                    <template v-if="gestioneStraordinaria">Su una gestione straordinaria è il giorno che decide chi paga: chi era titolare dell'unità alla delibera risponde dell'intera spesa, anche se poi vende. Nessuna data è proposta: la scrivi tu dal verbale.</template>
                    <template v-else>Su una gestione ordinaria la data non cambia il riparto (che va in proporzione ai giorni di competenza): resta come riferimento del verbale.</template>
                  </p>
                </div>
                <!-- «Scegli la delibera» (decisione 502): la data non si scrive più a mano due volte, nel testo e nel campo; viene dalla riunione. -->
                <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                  <ScegliLaDelibera :key="`delibera-${form.gestione_id ?? 0}`" v-model="delibera" :condominio-id="props.condominio.id" :condominio-nome="props.condominio.nome"
                    ruolo="delibera" oggetto-tipo="gestione" :oggetto-id="form.gestione_id" per-questo="questo piano" :vuoto="vuotoDellaDelibera"
                    vuoto-sotto="Se l'assemblea deve ancora decidere, portala alla prossima: il piano resta in bozza e il punto ti viene proposto quando la prepari."
                    :disabilitato="!form.gestione_id"
                    info="Le delibere di questo condominio: in testa quelle che parlano di questa gestione, con il perché. Una riunione di carta la registri con «Registra una delibera a mano»." />
                  <div class="space-y-1.5" data-testid="deliberata-il">
                    <span class="block text-xs font-medium text-slate-600 dark:text-slate-400">Deliberata il</span>
                    <p class="text-sm text-slate-900 dark:text-slate-100">
                      <template v-if="giornoDellaDelibera"><strong class="tabular-nums">{{ giornoDellaDelibera }}</strong> <span class="text-slate-500 dark:text-slate-400">dalla delibera</span></template>
                      <span v-else class="text-slate-500 dark:text-slate-400">dalla delibera</span>
                    </p>
                    <InputError :message="form.errors.data_delibera_assemblea || form.errors.delibera_id" />
                    <p class="text-xs text-slate-600 leading-relaxed dark:text-slate-400">
                      <template v-if="gestioneStraordinaria">È il giorno che decide chi paga: chi era titolare quel giorno risponde della spesa, anche se poi vende. Si compila dalla data della riunione quando scegli la delibera.</template>
                      <template v-else>Su una gestione ordinaria la data non cambia il riparto (che va in proporzione ai giorni di competenza): resta come riferimento del verbale.</template>
                    </p>
                  </div>
                </div>
              </template>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Piano scadenze</CardTitle>
            <CardDescription>Genera il calendario per la riscossione delle quote.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
              <div>
                <Label for="numero_rate">Numero Rate *</Label>
                <Input id="numero_rate" v-model.number="form.numero_rate" class="mt-1 bg-white dark:bg-slate-950" />
                <InputError :message="form.errors.numero_rate" />
              </div>
              <div v-if="!usingByDay">
                <Label for="giorno_scadenza">Giorno del mese</Label>
                <Input id="giorno_scadenza" v-model.number="form.giorno_scadenza" class="mt-1 bg-white dark:bg-slate-950" />
              </div>
              <!--
                La data di partenza si SUGGERISCE nel segnaposto, non si scrive nel campo:
                compilarla la trasformerebbe al primo salvataggio in una scelta esplicita che
                nessuno ha fatto, e il piano smetterebbe di seguire la gestione.
              -->
              <div>
                <!--
                  La spiegazione sta in un HoverCard e non sotto il campo, come per «Metodo di
                  distribuzione» qui accanto: tre campi affiancati di cui uno solo con due
                  righe di testo sotto sbilanciano la riga, e l'icona informativa è
                  l'affordance che questa pagina insegna già.
                -->
                <div class="flex items-center gap-2">
                  <Label for="data_prima_scadenza">Prima scadenza</Label>
                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="cursor-pointer">
                        <Info class="w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 z-50 p-4 shadow-xl border-blue-100">
                      <div class="space-y-2 text-sm">
                        <h4 class="font-bold text-slate-800 flex items-center gap-2 border-b pb-2">
                          <CalendarDays class="w-4 h-4 text-blue-600" /> Quando cade la prima rata
                        </h4>
                        <p class="text-xs text-slate-600">
                          <strong class="text-slate-700">Se la lasci vuota</strong><br>
                          <span class="opacity-90">
                            Si parte dall'inizio della gestione<template v-if="partenzaSuggerita">, il
                            <strong>{{ partenzaSuggerita.split('-').reverse().join('/') }}</strong></template>.
                            Il piano continua a seguire la gestione anche se un domani la sua data cambia.
                          </span>
                        </p>
                        <p class="text-xs text-slate-600">
                          <strong class="text-emerald-700">Se indichi una data</strong><br>
                          <span class="opacity-90">
                            La prima rata cade esattamente lì, con il suo giorno: il 30 settembre resta
                            il 30. Dalla seconda in poi comanda il <strong>giorno del mese</strong> qui a
                            fianco.
                          </span>
                        </p>
                      </div>
                    </HoverCardContent>
                  </HoverCard>
                </div>
                <Input
                  id="data_prima_scadenza"
                  type="date"
                  v-model="form.data_prima_scadenza"
                  class="mt-1 bg-white dark:bg-slate-950"
                />
                <InputError :message="form.errors.data_prima_scadenza" />
              </div>
              <div v-if="!props.precompilato" class="flex items-center pt-6">
                 <div class="flex items-center gap-2">
                  <Checkbox id="genera_subito" v-model="form.genera_subito" />
                  <Label for="genera_subito" class="cursor-pointer font-medium text-sm">Genera calcolo scadenze subito</Label>
                </div>
              </div>
            </div>

            <Separator />

            <div>
              <div class="flex items-center gap-2 mb-4">
                <Checkbox id="recurrenceToggle" v-model="showRecurrence" />
                <Label for="recurrenceToggle" class="cursor-pointer font-medium text-sm text-primary">Abilita regole di ricorrenza avanzate (Opzionale)</Label>
              </div>

              <div v-if="showRecurrence" class="bg-blue-50/40 border border-blue-100 p-4 rounded-lg space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <Label>Frequenza</Label>
                    <v-select :options="frequencies" v-model="form.recurrence_frequency" :reduce="o => o.value" class="mt-1 bg-white text-sm" />
                  </div>
                  <div>
                    <Label>Intervallo (es. ogni 2 mesi)</Label>
                    <Input v-model="form.recurrence_interval" class="mt-1 bg-white" />
                  </div>
                </div>
                <div>
                  <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">Forza in giorni specifici</Label>
                  <div class="flex flex-wrap gap-2">
                    <div v-for="day in weekdays" :key="day.value" @click="toggleDay(day.value)" class="flex items-center gap-2 bg-white px-3 py-1.5 rounded border cursor-pointer hover:border-primary/50">
                      <Checkbox :checked="form.recurrence_by_day.includes(day.value)" @update:checked="toggleDay(day.value)" />
                      <label class="text-xs font-medium cursor-pointer">{{ day.label }}</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </CardContent>
        </Card>

        <ScopertoWarning
            v-if="scopertiWarning"
            :scoperti="scopertiWarning"
            :processing="form.processing"
            @procedi="handleProcedi"
            class="mb-4"
        />
        <DestinatariWarning
            v-if="destinatariWarning"
            :destinatari="destinatariWarning"
            :processing="form.processing"
            @procedi="handleProcediDestinatari"
            class="mb-4"
        />

        <div v-if="flashMessage || erroriCarrello.length" class="space-y-2">
          <Alert v-if="flashMessage" :key="flashMessage.message" :message="flashMessage.message" :type="flashMessage.type" />
          <Alert v-for="errore in erroriCarrello" :key="errore" :message="errore" type="error" />
        </div>

        <!-- Coda 686 (e): il piano che verrebbe a € 0,00 lo dice prima di salvare (la frase dice perché e dove guardare). -->
        <div v-if="pianoAZero" data-avviso="piano-a-zero" role="status"
             class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
          <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-300" />
          <span>{{ pianoAZero }}</span>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
          <Link
            :href="props.ritorno?.url ?? generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
            class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-6 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
          >
            Annulla
          </Link>

          <!-- Coda 686 (b): senza una fattura scelta il pulsante non manda una richiesta destinata al rifiuto, e la scritta dice perché (non è un pulsante
               spento muto: resta raggiungibile da tastiera, e chi lo legge sa che cosa manca). P9 della Fase 1-bis della beta.12: la frase a dimensione
               normale e con la maiuscola solo all'inizio; «Salva piano rate» resta nella forma dei pulsanti di salvataggio del gestionale. -->
          <Button type="submit" :disabled="form.processing || competenzeConErrori"
                  :aria-disabled="senzaFattureScelte ? 'true' : undefined" data-pulsante="salva"
                  class="h-9 px-8 shadow-md gap-2"
                  :class="senzaFattureScelte
                    ? 'text-sm font-semibold opacity-60 cursor-not-allowed'
                    : 'text-[10px] font-bold uppercase tracking-widest'">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            <Plus v-else-if="!senzaFattureScelte" class="h-4 w-4" />
            {{ senzaFattureScelte ? 'Scegli almeno una fattura per salvare' : 'Salva piano rate' }}
          </Button>
        </div>

      </form>
    </div>

    <!-- Lo standard delle finestre (decisione 608): ancorata in alto, i gruppi con il titolo piccolo e grigio, il riepilogo e i pulsanti fissi
         in fondo; niente sotto i 12 px. La issue 58 (bozza `beta14_pregresso_passaggio_bozza.html`): il saldo dell'unità che tocca un passaggio
         dice il passaggio e lascia scegliere a chi va (`AChiVaIlPregresso`). -->
    <Dialog :open="isModaleSaldiAperta" @update:open="aggiornaLaFinestra">
      <DialogContent class="top-[5vh] flex max-h-[90vh] w-[95vw] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[720px]" data-testid="finestra-riparto-saldi">

        <DialogHeader class="border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800">
          <DialogTitle class="text-lg font-semibold">Riparto saldi iniziali</DialogTitle>
          <DialogDescription class="text-sm text-slate-500 dark:text-slate-400">{{ descrizioneDellaFinestra }}</DialogDescription>
        </DialogHeader>

        <div class="min-h-0 min-w-0 flex-1 overflow-y-auto px-5 text-sm" data-testid="corpo-finestra">

          <div v-if="isLoadingSaldi" class="flex justify-center gap-3 py-16 text-slate-500">
            <LoaderCircle class="h-5 w-5 animate-spin" /><span>Caricamento dei saldi…</span>
          </div>
          <div v-else-if="!form.gestione_id" class="py-16 text-center text-sm text-slate-500">Scegli prima la gestione.</div>
          <div v-else-if="Object.keys(saldiRaggruppati).length === 0" class="py-16 text-center text-sm text-slate-500">Nessun saldo iniziale per questa gestione.</div>

          <template v-else>
            <section v-for="(gruppo, gKey) in saldiRaggruppati" :key="gKey" class="border-b border-slate-100 py-4 last:border-b-0 dark:border-slate-800">
              <div v-for="saldo in gruppo.voci" :key="saldo.id" class="space-y-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ saldo.tipo === 'solidale' ? saldo.immobile_nome : gruppo.nome }}</span>
                    <span v-if="saldo.tipo === 'solidale'" class="rounded-md border border-indigo-200 bg-indigo-50 px-1.5 py-px text-xs font-semibold text-indigo-700 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300">Solidale · art. 63</span>
                    <span v-else class="rounded-md border border-slate-200 bg-slate-50 px-1.5 py-px text-xs font-semibold text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">Nominale</span>
                    <span v-if="saldo.tipo === 'solidale' && toccaUnPassaggio(saldo.riparto_previsto)" class="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-px text-xs font-semibold text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300" data-testid="tocca-un-passaggio">Tocca un passaggio</span>
                  </div>
                  <span class="shrink-0 text-base font-bold tabular-nums" :class="saldo.importo > 0 ? 'text-red-700 dark:text-red-400' : (saldo.importo < 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500')">
                    {{ euro(saldo.importo / 100, { forcePlus: true }) }}
                  </span>
                </div>
                <p v-if="saldo.tipo === 'solidale'" class="text-xs text-slate-500 dark:text-slate-400">
                  {{ saldo.is_debito ? 'Debito pregresso' : 'Credito a favore' }}<template v-if="saldo.riparto_previsto?.riferito_al"> al {{ giornoInParole(saldo.riparto_previsto.riferito_al) }}</template>, intestato all'unità.
                </p>
                <p v-else class="text-xs text-slate-500 dark:text-slate-400">
                  {{ saldo.is_debito ? 'Debito pregresso' : 'Credito a favore' }} su {{ saldo.immobile_nome }}: resta a <b class="font-semibold text-slate-700 dark:text-slate-200">{{ saldo.soggetto_nome }}</b>.
                </p>

                <template v-if="saldo.tipo === 'solidale'">
                  <!-- Il saldo dell'unità che tocca un passaggio: il passaggio, le scelte affiancate, la frase della solidarietà e il perché (issue 58). -->
                  <AChiVaIlPregresso v-if="toccaUnPassaggio(saldo.riparto_previsto)" :riparto="saldo.riparto_previsto!" :modo="saldo.ripartizione_mode"
                    :alla-data="saldo.alla_data" @scegli="scegliIlPregresso(saldo, $event)" />

                  <!-- Senza passaggi: «Automatico» (la regola, con l'anteprima di chi paga) o «A mano». -->
                  <template v-else>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                      <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Come si ripartisce</span>
                      <v-select :options="OPZIONI_DEL_RIPARTO" v-model="saldo.ripartizione_mode" :reduce="(o: any) => o.value" :clearable="false" class="w-48 bg-white text-sm" />
                    </div>

                    <!-- L'anteprima del riparto automatico, calcolata dal server con le stesse funzioni del generatore. -->
                    <div v-if="saldo.ripartizione_mode === 'automatica' && saldo.riparto_previsto" class="space-y-1.5 rounded-lg border p-3"
                         :class="saldo.riparto_previsto.risolvibile ? 'border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-900/40' : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30'">
                      <template v-if="saldo.riparto_previsto.risolvibile">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
                          <Users class="h-3.5 w-3.5" aria-hidden="true" /> A carico di: {{ saldo.riparto_previsto.ruolo_label }}<template v-if="saldo.riparto_previsto.riferito_al">, titolare al {{ giornoInParole(saldo.riparto_previsto.titolari_al ?? saldo.riparto_previsto.riferito_al) }}</template>
                        </p>
                        <div v-for="q in saldo.riparto_previsto.quote" :key="q.anagrafica_id"
                             class="flex items-center justify-between gap-3 rounded-md border border-slate-100 bg-white px-3 py-1.5 text-sm dark:border-slate-800 dark:bg-slate-950">
                          <span class="truncate font-semibold text-slate-700 dark:text-slate-200">{{ q.nome }}</span>
                          <span class="flex shrink-0 items-center gap-3">
                            <span class="text-xs text-slate-500 dark:text-slate-400">quota {{ q.quota.toLocaleString('it-IT') }} %</span>
                            <span class="font-bold tabular-nums" :class="q.importo > 0 ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'">{{ euro(q.importo / 100, { forcePlus: true }) }}</span>
                          </span>
                        </div>
                        <p class="text-xs leading-snug text-slate-500 dark:text-slate-400">
                          Va a chi era titolare il primo giorno del suo esercizio, fra chi ha un diritto reale: l'ordinaria all'usufruttuario (art. 1004 c.c.),
                          la straordinaria al proprietario (art. 1005); l'inquilino non è debitore verso il condominio. Se fra le parti vale un accordo diverso, scegli «A mano».
                        </p>
                      </template>
                      <template v-else>
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300">
                          <AlertTriangle class="h-3.5 w-3.5" aria-hidden="true" /> Questo pregresso non ha su chi cadere
                        </p>
                        <p class="text-xs leading-snug text-amber-800 dark:text-amber-200">{{ saldo.riparto_previsto.motivo }}</p>
                      </template>
                    </div>
                  </template>

                  <!-- «A mano» e «Divido a mano»: il riparto a mano di sempre, con la somma che deve tornare al centesimo. -->
                  <div v-if="saldo.ripartizione_mode === 'manuale'" class="space-y-2 rounded-lg border border-dashed border-indigo-200 bg-indigo-50/50 p-3 dark:border-indigo-800 dark:bg-indigo-950/30" data-testid="divido-a-mano">
                    <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300">Da dividere: {{ euro(Math.abs(saldo.importo) / 100) }}</p>
                    <div v-for="(rip, rIndex) in saldo.ripartizioni_custom" :key="rIndex" class="flex items-center gap-2">
                      <v-select :options="anagraficheDisponibili" label="nome" v-model="rip.anagrafica_id" :reduce="(a: any) => a.id" class="min-w-0 flex-1 bg-white text-sm" placeholder="Scegli la persona…" />
                      <div class="relative w-32 shrink-0">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-500">€</span>
                        <MoneyInput
                            v-model="rip.importo"
                            :money-options="moneyOptions"
                            :lazy="true"
                            class="h-8 pl-7 text-right text-sm"
                        />
                      </div>
                      <Button type="button" variant="ghost" size="icon" class="h-8 w-8 text-slate-500 hover:text-red-600" aria-label="Togli la persona" @click="saldo.ripartizioni_custom.splice(rIndex, 1)"><Trash2 class="h-4 w-4" /></Button>
                    </div>
                    <p v-if="saldo.ripartizioni_custom.length > 0 && Math.abs(saldo.ripartizioni_custom.reduce((acc: number, r: any) => acc + parseMoney(r.importo), 0) - Math.abs(saldo.importo) / 100) > 0.01"
                       class="flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                      <AlertTriangle class="h-3.5 w-3.5" /> La somma divisa ({{ euro(saldo.ripartizioni_custom.reduce((acc: number, r: any) => acc + parseMoney(r.importo), 0)) }}) non torna con il pregresso.
                    </p>
                    <Button type="button" variant="outline" size="sm" @click="aggiungiRigaRiparto(saldo)" class="h-8 bg-white text-xs dark:bg-slate-950"><Plus class="mr-1.5 h-3.5 w-3.5" /> Aggiungi una persona</Button>
                  </div>
                </template>
              </div>
            </section>

            <details class="group my-3 rounded-md border border-slate-200 dark:border-slate-700" data-testid="come-si-ripartiscono">
              <summary class="flex cursor-pointer list-none items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
                <Info class="h-3.5 w-3.5" aria-hidden="true" /> Come si ripartiscono i saldi
              </summary>
              <ul class="list-disc space-y-1 px-3 pb-2 pl-7 text-xs text-slate-600 dark:text-slate-300">
                <li><b>Nominali</b>: si addebitano alla persona indicata.</li>
                <li><b>Dell'unità</b>: a chi ne era titolare il primo giorno del loro esercizio, fra chi ha un diritto reale; mai all'inquilino.</li>
                <li>Se quel giorno comincia un passaggio, conta chi era titolare il giorno prima.</li>
                <li>Con una successione: con l'arretrato agli eredi vanno agli eredi, per le loro quote; con l'arretrato a nome del defunto restano al defunto.</li>
                <!-- Il confine della decisione 691 (1) (rilievo 8 del secondo scettico della tappa 1). -->
                <li>Al defunto solo se è morto dall'inizio dell'esercizio prima di quello del saldo: con una morte di prima, o con una successione registrata senza la scelta sull'arretrato, vanno agli eredi.</li>
                <li>Dove un passaggio li tocca, scegli tu a chi vanno: la scelta resta scritta nel piano.</li>
              </ul>
            </details>
          </template>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/60">
          <p class="flex min-w-[200px] flex-1 items-center gap-2 text-[13px] text-slate-600 dark:text-slate-300" role="status" data-testid="riepilogo-saldi">
            <span v-if="isLoadingSaldi" class="inline-flex shrink-0"><LoaderCircle class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /></span>
            <Wallet v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span class="min-w-0">{{ riepilogoDeiSaldi.base }}<template v-if="riepilogoDeiSaldi.toccano"> · <b class="font-semibold text-slate-900 dark:text-white">{{ riepilogoDeiSaldi.toccano }}</b></template>{{ riepilogoDeiSaldi.resto }}</span>
          </p>
          <div class="ml-auto flex flex-wrap justify-end gap-2">
            <Button type="button" variant="outline" @click="chiudiLaFinestra">Chiudi</Button>
            <Button type="button" :disabled="isLoadingSaldi" data-testid="conferma-il-riparto" @click="chiudiLaFinestra">Conferma il riparto</Button>
          </div>
        </div>

      </DialogContent>
    </Dialog>

  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>