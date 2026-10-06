<script setup lang="ts">
/**
 * «Registra passaggio»: vendita o donazione, inizio e fine di una locazione, usufrutto
 * (§6.3 di `docs/pertinenze_vendita_locazione.md`; decisioni 12, 13 e 14 del progetto sul subentro).
 *
 * È il verbo che **conserva** la storia dell'unità. La lezione viene da fuori settore — in SAP HR
 * «Change» sovrascrive e «Copy» conserva, e gli utenti sbagliano da vent'anni — e qui il verbo che
 * conserva è primario e dominante; «Modifica associazione» resta nel menu secondario per correggere
 * un dato, «Dissocia» per una riga senza storia.
 *
 * Tre colonne: due di modulo, una **sticky** con il pannello «Cosa cambierà», che il server calcola
 * a ogni modifica del modulo (`POST …/passaggi/anteprima`, con debounce) e che questo file **non
 * ricalcola mai**. Otto campi nell'ordine del §6.3; **una data sola** — il giorno prima lo calcola il
 * programma — e nessun valore predefinito su quella data (decisione 12: «non si tira a indovinare»).
 *
 * Il cancello (1) della decisione 14: se il passaggio tocca rate già emesse o cambia un destinatario
 * di un piano già generato, il pulsante resta disabilitato finché non si spunta «Ho letto cosa
 * cambierà» e non si scrive una nota di almeno dieci caratteri. Se non tocca nulla, è attivo subito.
 * Le quote che il passaggio non tocca (`cancello.informazioni`, beta.38) si elencano sopra il pulsante,
 * senza spunta: quelle che restano per legge a chi le ha e quelle in cui la parte di chi entra è zero
 * (decisione 28.8 c).
 *
 * Da S5 `store` scrive (`RegistraSubentroAction`): il pannello porta gli importi del conguaglio proposto
 * per gestione e la rinuncia motivata; gli errori di dominio dell'action tornano come errori di campo.
 */
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import PassaggioProprietaGuide from '@/components/guides/PassaggioProprietaGuide.vue';
import AnteprimaPassaggio from '@/components/gestionale/immobili/AnteprimaPassaggio.vue';
import PassaggioPertinenzeCard from '@/components/gestionale/immobili/PassaggioPertinenzeCard.vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import BadgeRuolo from '@/components/gestionale/immobili/BadgeRuolo.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { usePermission } from '@/composables/permissions';
import { useDateConverter } from '@/composables/useDateConverter';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from 'vue-select';
import { ArrowRightLeft, CalendarDays, Scale, LoaderCircle, Check, UserPlus, Info, AlertTriangle, FileSignature, Home, KeyRound, KeySquare, Landmark, BellRing, Paperclip, Lock, ScrollText, Plus, X } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';
import type { AnteprimaPassaggioDati, PersonaDelCondominio, PertinenzaCollegata, TipoPassaggio, TitolareAttuale } from '@/types/gestionale/passaggi';
import { cambiaSpunta, dividiVoci, voceSpuntata } from '@/lib/gestionale/passaggi/vociDaSpostare';
import { nudiInteriPossibili as nudiInteriPossibiliPer } from '@/lib/gestionale/passaggi/nudiInteri';
import { percentualeIt } from '@/lib/gestionale/passaggi/percentuale';
import { partiUguali, quoteCheNonTornano } from '@/lib/gestionale/passaggi/quoteEredi';
import { fraseArretrato, rinunciaEffettiva as laRinunciaVale } from '@/lib/gestionale/passaggi/rinunciaConguaglio';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  tipo: TipoPassaggio;
  /** Dal menu di una riga (`?riga=`): quella riga è già scelta in «Chi esce», se è fra i candidati. */
  rigaPreselezionata?: number | null;
  titolari: TitolareAttuale[];
  /** All'estinzione: le righe di nuda proprietà e d'usufrutto dell'unità, anche chiuse, per guardarle il giorno dell'estinzione (G6). */
  righeDellEstinzione?: TitolareAttuale[];
  oggi?: string;
  anagrafiche: PersonaDelCondominio[];
  pertinenze: PertinenzaCollegata[];
  rateEmesseCount: number;
}>();

const { generatePath, generateRoute } = usePermission();
const { toItalian } = useDateConverter();
const showGuide = ref(false);

// --- Il tipo decide tutto il resto -------------------------------------------------------------

const TIPI: { id: TipoPassaggio; titolo: string; sotto: string; icona: any }[] = [
  { id: 'vendita', titolo: 'Vendita o donazione', sotto: 'cambia il proprietario', icona: Home },
  { id: 'inizio_locazione', titolo: 'Inizio locazione', sotto: 'entra un inquilino', icona: KeyRound },
  { id: 'fine_locazione', titolo: 'Fine locazione', sotto: 'esce l\'inquilino', icona: KeySquare },
  { id: 'usufrutto', titolo: 'Usufrutto', sotto: 'costituzione o estinzione', icona: Landmark },
  { id: 'successione', titolo: 'Successione', sotto: 'muore un proprietario', icona: ScrollText },
];
const tipoCorrente = computed(() => TIPI.find(t => t.id === props.tipo) ?? TIPI[0]);

const eLocazione = computed(() => props.tipo === 'inizio_locazione' || props.tipo === 'fine_locazione');
// Il regime del contratto si chiede sul **box** (§6.6), cioè sulle unità di categoria `pertinenza`: su un
// negozio o un ufficio (`unita_non_abitativa`) la locazione è per legge a uso diverso e la domanda non ha senso.
const eBox = computed(() => props.immobile.tipologia?.categoria === 'pertinenza');
// Fase 1-bis della beta.34, R18: dal prospetto degli oneri accessori il regime serve anche sull'appartamento — decide se
// la nota cita l'art. 9 L. 392/1978 — e si chiede a ogni conduttore che entra, non solo sul box. Resta fuori il negozio.
const chiediRegime = computed(() => props.immobile.tipologia?.categoria !== 'unita_non_abitativa'
  && (props.tipo === 'inizio_locazione' || (props.tipo === 'fine_locazione' && !!form.anagrafica_entrante_id)));

/** I titolari fra cui si sceglie «chi esce», secondo il tipo. */
const RUOLI_USCENTE: Record<TipoPassaggio, string[]> = {
  vendita: ['proprietario', 'nuda_proprietario'],
  inizio_locazione: [],
  fine_locazione: ['inquilino'],
  usufrutto: ['proprietario', 'usufruttuario'],
  // Decisione 64: muore il proprietario pieno, un comproprietario o il nudo proprietario; la morte dell'usufruttuario è l'estinzione.
  successione: ['proprietario', 'nuda_proprietario'],
};
const proprietari = computed(() => props.titolari.filter(t => ['proprietario', 'nuda_proprietario'].includes(t.tipologia)));
const nudoProprietario = computed(() => props.titolari.find(t => t.tipologia === 'nuda_proprietario'));
// Con più nudi proprietari tornano pieni, ciascuno alla sua quota, e il conguaglio si divide fra loro (S8-30). Decisione 57
// (1.11.0-beta.43, D2): con un altro usufrutto in corso solo i nudi dell'usufrutto che finisce, non chi esce; senza, tutti,
// chi esce compreso (la sua parte resta a suo nome).
const nudiProprietari = computed(() => props.titolari.filter(t => t.tipologia === 'nuda_proprietario'));

// --- Il modulo -----------------------------------------------------------------------------------

// Dal menu di una riga usufruttuario si arriva già in «estinzione»: è l'unico sottotipo che la fa uscire.
const sottotipoIniziale = props.tipo === 'usufrutto'
  ? (props.titolari.find(t => t.id === props.rigaPreselezionata)?.tipologia === 'usufruttuario' ? 'estinzione' : 'costituzione')
  : null;

const form = useForm({
  tipo: props.tipo as string,
  sottotipo: sottotipoIniziale as 'costituzione' | 'estinzione' | 'riserva_usufrutto' | 'legato' | null,
  riga_uscente_id: (props.rigaPreselezionata ?? null) as number | null,
  anagrafica_entrante_id: null as number | null,
  // Senza valore predefinito, di proposito.
  decorrenza: '' as string,
  quota: '100' as string,
  tipologia: (props.tipo === 'vendita' ? 'proprietario'
    : props.tipo === 'usufrutto' ? (sottotipoIniziale === 'estinzione' ? 'proprietario' : 'usufruttuario')
    : 'inquilino') as string,
  copia_autentica: false,
  copia_autentica_il: '' as string,
  estremi_titolo: '',
  nota: '',
  data_fine_locazione: '' as string,
  regime_contratto: null as string | null,
  pertinenze: [] as number[],
  /** Copia del titolo o del contratto: alla registrazione (S5) finisce fra i Documenti dell'unità, agganciata al passaggio. */
  allegato_titolo: null as File | null,
  /** Promemoria in agenda prima della scadenza del contratto (S5: `InboxService`, evento agganciato al passaggio). */
  // S5: rinuncia alla coppia di conguaglio proposta, con la ragione (nessuna riga in saldi).
  rinuncia_conguaglio: false,
  nota_conguaglio: '',
  promemoria_scadenza: false,
  promemoria_giorni: 60 as number,
  ho_letto: false,
  nota_cancello: '',
  // Decisioni 31.5 e 31.6 (beta.41): alla costituzione e alla riserva d'usufrutto, chi paga l'ordinaria dal giorno dell'atto.
  // La legge (art. 1004 c.c.) è già scelta; le voci da spostare sono tutte spuntate, e qui si tengono quelle senza spunta.
  ordinaria_dopo_atto: 'usufruttuario' as 'usufruttuario' | 'voce',
  voci_da_tenere: [] as number[],
  // Decisione 57 (1.11.0-beta.43, D2): all'estinzione, con un altro usufrutto in corso, i nudi che tornano pieni — senza una
  // scelta già fatta. Il server la chiede solo quando i dati non lo dicono.
  nudi_che_tornano: [] as number[],
  // Decisione 62: «tutti i nudi, ciascuno per la sua quota» (la donazione congiunta), al posto delle caselle.
  nudi_per_quota: false,
  // 1.11.0-beta.44: all'estinzione, l'atto prevede l'accrescimento all'altro usufruttuario. Nessuna spunta già messa.
  accrescimento: false,
  // Decisione 65: gli eredi con la quota che ereditano, che insieme fanno quella del defunto; l'arretrato del defunto, senza una
  // scelta già fatta; l'erede che riceve le bozze di un piano fermo, chiesto solo quando serve.
  eredi: [{ anagrafica_id: null, quota: '' }] as { anagrafica_id: number | null; quota: string }[],
  arretrato: null as 'eredi' | 'defunto' | null,
  erede_di_riferimento: null as number | null,
});

const ANTICIPI = [30, 60, 90, 180] as const;
const promemoriaAperto = ref(false);
function etichettaAnticipo(g: number): string {
  return g === 180 ? '6 mesi' : `${g} giorni`;
}
const allegatoInput = ref<HTMLInputElement | null>(null);
function togliAllegato() {
  form.allegato_titolo = null;
  if (allegatoInput.value) allegatoInput.value.value = '';
}

/**
 * Le date si digitano (`text-input`): il calendario si apre sotto il campo mentre si scrive e, senza
 * questo, restava aperto anche dopo Invio o Tab, sopra la frase «X risulterà titolare fino al…» che è la
 * cosa da leggere. `text-submit` è l'evento con cui VueDatePicker dice «data confermata da tastiera».
 */
const dpDecorrenza = ref<any>(null);
const dpFineLocazione = ref<any>(null);
const dpCopiaAutentica = ref<any>(null);

/** Data digitata e confermata con Invio o Tab: il calendario, che si era aperto sotto il campo, si chiude. */
function chiudiCalendario(dp: any) {
  // Dopo il tick della libreria, che sul submit riapre/riposiziona il menu prima di renderlo.
  setTimeout(() => dp?.closeMenu?.(), 60);
}

/** L'errore di scrittura non appartiene a un campo: `store` lo manda come `passaggio`. */
const erroreScrittura = computed(() => (form.errors as Record<string, string | undefined>).passaggio ?? null);
// La rete sotto i campi (verifica S5, R12): gli errori dell'action su chiavi senza un campo visibile nel tipo
// corrente (`pertinenze`, `quota` e `anagrafica_entrante_id` nell'estinzione, `riga_uscente_id` già chiusa)
// finiscono qui, con il nome leggibile; `passaggio` ha già il suo riquadro.
const ETICHETTE_ERRORI: Record<string, string> = {
  riga_uscente_id: 'chi esce', anagrafica_entrante_id: 'chi entra', decorrenza: 'la data del passaggio', quota: 'la quota',
  tipologia: 'il ruolo', pertinenze: 'le pertinenze', nota_cancello: 'la nota del cancello', nota_conguaglio: 'la ragione della rinuncia al conguaglio',
  allegato_titolo: "l'allegato", promemoria_giorni: "l'anticipo del promemoria", copia_autentica_il: 'la data della copia autentica', nudi_che_tornano: 'chi torna proprietario pieno', nudi_per_quota: 'chi torna proprietario pieno', estinzione: 'chi torna proprietario pieno',
  eredi: 'gli eredi', arretrato: "l'arretrato del defunto", erede_di_riferimento: "l'erede di riferimento", accrescimento: "l'accrescimento", rinuncia_conguaglio: 'la rinuncia al conguaglio',
};
// `ordinaria_impronta` ha il suo riquadro nella scheda dell'ordinaria, che sopravvive al ricalcolo del pannello.
const erroriSenzaRiquadro = computed(() => Object.fromEntries(Object.entries(form.errors as Record<string, string>).filter(([k]) => k !== 'passaggio' && k !== 'ordinaria_impronta')));

const candidatiUscente = computed(() => {
  const ruoli = props.tipo === 'usufrutto'
    ? (form.sottotipo === 'estinzione' ? ['usufruttuario'] : ['proprietario'])
    : RUOLI_USCENTE[props.tipo];
  return props.titolari.filter(t => ruoli.includes(t.tipologia));
});
const uscente = computed(() => props.titolari.find(t => t.id === form.riga_uscente_id) ?? null);

// Un titolare solo: già selezionato. Cambia il sottotipo: si riparte.
watch(candidatiUscente, (c) => {
  form.riga_uscente_id = c.length === 1 ? c[0].id : (c.some(t => t.id === form.riga_uscente_id) ? form.riga_uscente_id : null);
}, { immediate: true });

/**
 * La vendita con riserva d'usufrutto (beta.38, decisione 28): chi vende resta, sulla stessa quota, come usufruttuario;
 * chi compra entra nudo proprietario. Si dichiara con la casella — il programma non la deduce dal ruolo scelto, e il
 * server rifiuta un nudo proprietario da un proprietario pieno senza la dichiarazione.
 */
const puoRiservare = computed(() => props.tipo === 'vendita' && uscente.value?.tipologia === 'proprietario');
const riserva = computed({
  get: () => form.sottotipo === 'riserva_usufrutto',
  set: (v: boolean) => {
    form.sottotipo = v ? 'riserva_usufrutto' : null;
    form.tipologia = v ? 'nuda_proprietario' : (uscente.value?.tipologia ?? 'proprietario');
    // La nuda proprietà di tutta la quota di chi vende: con meno resterebbe un pezzo di nessuno.
    if (v && uscente.value) {
      form.quota = String(uscente.value.quota);
    }
  },
});
watch(puoRiservare, (p) => { if (!p && riserva.value) riserva.value = false; });

// Quota e ruolo precompilati da chi esce, non con 100 (§6.3, campi 4 e 5). La quota non si cambia (decisione 37, 1.11.0-beta.42):
// il passaggio porta tutta la quota di chi esce, e la vendita di una parte della propria quota non è ancora prevista (Coda 175).
watch(uscente, (u) => {
  if (!u) return;
  form.quota = String(u.quota);
  if (props.tipo === 'vendita') form.tipologia = riserva.value ? 'nuda_proprietario' : u.tipologia;
  // Decisione 65: gli eredi entrano nel ruolo del defunto.
  if (props.tipo === 'successione') form.tipologia = u.tipologia;
}, { immediate: true });

// --- Decisione 65: la successione ------------------------------------------------------------------
const eSuccessione = computed(() => props.tipo === 'successione');
/** Le persone fra cui scegliere gli eredi: quelle del condominio e quelle create qui, meno il defunto. */
const candidatiEredi = computed(() => {
  const visti = new Set<number>();
  return [...personeAggiunte.value, ...props.anagrafiche].filter(a => a.id !== uscente.value?.anagrafica.id && !visti.has(a.id) && visti.add(a.id));
});
function aggiungiErede() {
  form.eredi.push({ anagrafica_id: null, quota: '' });
}
function togliErede(i: number) {
  if (form.erede_di_riferimento !== null && form.eredi[i]?.anagrafica_id === form.erede_di_riferimento) form.erede_di_riferimento = null;
  form.eredi.splice(i, 1);
  if (!form.eredi.length) aggiungiErede();
}
/** «Dividi in parti uguali»: la quota del defunto, in centesimi di punto, con il centesimo che avanza ai primi. */
function dividiInPartiUguali() {
  partiUguali(uscente.value?.quota ?? form.quota, form.eredi.length).forEach((q, i) => { form.eredi[i].quota = q; });
}
const quoteNonTornano = computed(() => eSuccessione.value && uscente.value ? quoteCheNonTornano(form.eredi.map(e => e.quota), uscente.value.quota) : null);
/**
 * Il legatario riceve l'unità ma non eredita il patrimonio: l'arretrato del defunto resta a suo nome, per forza (decisione 65). Rilievo
 * L20 della Fase 1-bis: togliendo la spunta, la scelta che il legato aveva imposto torna da fare, se prima non c'era.
 */
let arretratoPrimaDelLegato: typeof form.arretrato = null;
const legato = computed({
  get: () => form.sottotipo === 'legato',
  set: (v: boolean) => {
    if (v && form.sottotipo !== 'legato') arretratoPrimaDelLegato = form.arretrato;
    if (!v && form.sottotipo === 'legato') form.arretrato = arretratoPrimaDelLegato;
    form.sottotipo = v ? 'legato' : null;
    if (v) form.arretrato = 'defunto';
  },
});
/** L'erede di riferimento: lo chiede il server quando ci sono bozze di un piano fermo e gli eredi sono più d'uno. */
const chiediRiferimento = computed(() => eSuccessione.value && form.eredi.length > 1 && (!!(form.errors as Record<string, string>).erede_di_riferimento || form.erede_di_riferimento !== null));
const erediScelti = computed(() => form.eredi.map(e => candidatiEredi.value.find(a => a.id === e.anagrafica_id)).filter((a): a is PersonaDelCondominio => !!a));
watch(() => form.eredi.map(e => e.anagrafica_id).join(','), () => {
  if (form.erede_di_riferimento !== null && !form.eredi.some(e => e.anagrafica_id === form.erede_di_riferimento)) form.erede_di_riferimento = null;
});

watch(() => form.sottotipo, (s) => {
  if (props.tipo !== 'usufrutto') return;
  form.tipologia = s === 'estinzione' ? 'proprietario' : 'usufruttuario';
  form.anagrafica_entrante_id = null;
});

// --- Decisione 57 (D2): chi torna proprietario pieno all'estinzione ---------------------------------
const estinzione = computed(() => props.tipo === 'usufrutto' && form.sottotipo === 'estinzione');
/**
 * Rilievo G6 del giro sulle correzioni della .43: la scheda guarda le righe in corso il giorno dell'estinzione, come il server
 * (`NudiDellEstinzione::per`), non quelle di oggi. Senza la data, oggi.
 */
// Rilievo HT8: senza data, il giorno di oggi dell'utente come lo dà il server (toISOString darebbe il giorno UTC).
const giornoEstinzione = computed(() => form.decorrenza || props.oggi || '');
const inCorsoIl = (t: TitolareAttuale, g: string) => (!t.data_inizio || t.data_inizio <= g) && (!t.data_fine || t.data_fine >= g);
const righeEstinzione = computed(() => props.righeDellEstinzione ?? []);
const nudiAllaDecorrenza = computed(() => righeEstinzione.value.filter(t => t.tipologia === 'nuda_proprietario' && inCorsoIl(t, giornoEstinzione.value)));
/** Con un altro usufrutto in corso, i nudi che possono tornare pieni: non chi esce, che resta nudo dell'altra parte. */
const nudiCandidati = computed(() => nudiAllaDecorrenza.value.filter(t => t.anagrafica.id !== uscente.value?.anagrafica.id));
const altriUsufrutti = computed(() => righeEstinzione.value.some(t => t.tipologia === 'usufruttuario' && t.id !== uscente.value?.id && inCorsoIl(t, giornoEstinzione.value)));
/** Se qualche insieme di nudi interi vale proprio l'usufrutto che finisce: altrimenti le caselle non servono (decisione 62). */
const nudiInteriPossibili = computed(() => nudiInteriPossibiliPer(nudiCandidati.value.map(t => t.quota), uscente.value?.quota ?? 0));
/** Decisione 61: l'estinzione che il programma non sa registrare (l'errore non è di un campo del modulo). */
const erroreEstinzione = computed(() => (form.errors as Record<string, string | undefined>).estinzione);
/**
 * La scelta si mostra quando sull'unità, il giorno dell'estinzione, c'è un altro usufrutto in corso, i nudi possibili sono più
 * d'uno e valgono più dell'usufrutto che finisce, il server non ha già deciso (registro, tutti, consolidamento) e non si è
 * fermato (decisione 61). E comunque quando il server la chiede (la rete del rilievo T10). Il server decide: se la scelta manca,
 * l'anteprima torna con l'errore sul campo.
 */
const sceltaDeiNudi = computed(() => {
  if (!estinzione.value || !uscente.value || erroreEstinzione.value) return false;
  if (form.errors.nudi_che_tornano && nudiCandidati.value.length > 1) return true;
  if (anteprima.value?.nudi && !['scelta', 'per_quota'].includes(anteprima.value.nudi.da)) return false;
  const somma = nudiCandidati.value.reduce((tot, t) => tot + Number(t.quota), 0);
  // Una nuda sola che vale più dell'usufrutto si consolida per legge: nessuna scelta (rilievo T10).
  return altriUsufrutti.value && nudiCandidati.value.length > 1 && somma > Number(uscente.value.quota) + 0.001;
});
/** I nudi che tornano pieni secondo il server, se l'ha già detto; prima, senza un altro usufrutto tutti, con un altro i candidati (T9). */
const nudiCheTornano = computed(() => {
  const righe = anteprima.value?.nudi?.righe;
  return righe ? righeEstinzione.value.filter(t => righe.includes(t.id)) : (altriUsufrutti.value ? nudiCandidati.value : nudiAllaDecorrenza.value);
});
/**
 * Rilievo GT8: prima dell'anteprima, con un altro usufrutto e una nuda sola che vale più dell'usufrutto, la nuda torna piena solo
 * per la parte che finisce (il consolidamento di legge): la scheda lo dice già.
 */
const consolidaPrevisto = computed(() => {
  if (anteprima.value?.nudi || !altriUsufrutti.value || nudiCandidati.value.length !== 1 || !uscente.value) return null;
  return Number(nudiCandidati.value[0].quota) > Number(uscente.value.quota) + 0.001 ? Number(uscente.value.quota) : null;
});
/** Una quota in forma italiana: «37,5», «50» (rilievo GT11). */
const quotaIt = (q: number | string) => Number(q).toLocaleString('it-IT', { maximumFractionDigits: 2 });
// Cambiano chi esce o il sottotipo: la scelta di prima non vale più.
// Rilievi G6 e GT9: anche quando cambia la data, e con la scelta se ne vanno gli errori che la riguardavano.
watch([() => form.riga_uscente_id, () => form.sottotipo, () => form.decorrenza], () => {
  form.nudi_che_tornano = [];
  form.nudi_per_quota = false;
  form.clearErrors(...(['estinzione', 'nudi_che_tornano'] as any[]));
});
// «Ciascuno per la sua quota» e le caselle sono due risposte diverse: una toglie l'altra.
watch(() => form.nudi_per_quota, (perQuota) => { if (perQuota) form.nudi_che_tornano = []; });

/**
 * 1.11.0-beta.44: l'accrescimento si offre all'estinzione quando sull'unità, quel giorno, c'è un altro usufruttuario. Non quando la
 * nuda è di più nudi proprietari (decisione 67, punto 2): il programma non sa quale usufrutto stia sopra quale nuda, e il server lo
 * rifiuta; la scheda lo dice al posto della casella.
 */
const piuNudi = computed(() => new Set(nudiAllaDecorrenza.value.map(t => t.anagrafica.id)).size > 1);
const offriAccrescimento = computed(() => estinzione.value && altriUsufrutti.value && !piuNudi.value);
watch(offriAccrescimento, (o) => { if (!o) form.accrescimento = false; });

const serveEntrante = computed(() =>
  props.tipo === 'vendita' || props.tipo === 'inizio_locazione' || (props.tipo === 'usufrutto' && form.sottotipo !== 'estinzione'),
);
const entranteFacoltativo = computed(() => props.tipo === 'fine_locazione');
const nessunEntrante = ref(false);

/**
 * Le persone fra cui scegliere chi entra. Nella vendita tutte quelle del condominio meno chi esce (un
 * comproprietario può comprare la quota dell'altro); nella locazione e nell'usufrutto anche meno i
 * titolari attuali: un proprietario non è inquilino di se stesso. Il server ripete il controllo.
 */
const candidatiEntrante = computed(() => {
  const esclusi = new Set<number>();
  if (uscente.value) esclusi.add(uscente.value.anagrafica.id);
  if (props.tipo !== 'vendita') props.titolari.forEach(t => esclusi.add(t.anagrafica.id));
  return props.anagrafiche.filter(a => !esclusi.has(a.id));
});
const entrante = computed(() => props.anagrafiche.find(a => a.id === form.anagrafica_entrante_id) ?? null);

// Nella vendita chi entra è proprietario o nudo proprietario: gli altri due ruoli hanno il loro tipo di
// passaggio, e il server lo pretende (`AnteprimaPassaggioRequest::ruoliEntrante()`).
const RUOLI = [
  { id: 'proprietario', label: 'Proprietario' },
  { id: 'nuda_proprietario', label: 'Nudo proprietario' },
];
const ruoloModificabile = computed(() => props.tipo === 'vendita' && !riserva.value);

const REGIMI = [
  { id: 'abitativo', label: 'Abitativo (L. 431/1998)' },
  { id: 'uso_diverso', label: 'Uso diverso dall\'abitativo (art. 27 L. 392/1978)' },
  { id: 'atipica', label: 'Locazione atipica (artt. 1571 ss. c.c.)' },
  { id: 'comodato', label: 'Comodato' },
];

// --- La frase delle due date, e cosa manca --------------------------------------------------------

/** La frase sotto la data la scrive il server (è nel `riferimento` dell'anteprima); qui solo l'attesa. */
const mancante = computed<string[]>(() => {
  const m: string[] = [];
  if (candidatiUscente.value.length > 0 && !form.riga_uscente_id && props.tipo !== 'inizio_locazione') m.push('chi esce');
  if (candidatiUscente.value.length === 0 && props.tipo !== 'inizio_locazione') m.push(props.tipo === 'fine_locazione' ? 'un inquilino in corso da cui uscire' : 'un titolare in corso da cui uscire');
  if (!form.decorrenza) m.push('la data dell\'atto');
  if (serveEntrante.value && !form.anagrafica_entrante_id) m.push('chi entra');
  // «Nessuno — l'unità resta sfitta» è una scelta esplicita (§6.6), non ciò che il modulo presume quando
  // nessuno è stato scelto: finché non si sceglie un inquilino o non si spunta la casella, si aspetta.
  if (entranteFacoltativo.value && !nessunEntrante.value && !form.anagrafica_entrante_id) m.push('chi entra, oppure la spunta «Nessuno — l\'unità resta sfitta»');
  if (form.copia_autentica && !form.copia_autentica_il) m.push('la data in cui hai ricevuto la copia autentica');
  if (props.tipo === 'usufrutto' && form.sottotipo === 'estinzione' && !nudoProprietario.value && !form.accrescimento) m.push('un nudo proprietario registrato, che torni proprietario pieno');
  if (eSuccessione.value) {
    if (form.eredi.some(e => !e.anagrafica_id)) m.push('chi sono gli eredi');
    else if (quoteNonTornano.value) m.push(`le quote degli eredi (${quoteNonTornano.value})`);
    if (!form.arretrato) m.push('la scelta sull\'arretrato del defunto');
  }
  return m;
});
const completo = computed(() => mancante.value.length === 0);

// --- L'anteprima: il server calcola, la pagina mostra -------------------------------------------

const anteprima = ref<AnteprimaPassaggioDati | null>(null);
const anteprimaInCorso = ref(false);
const anteprimaErrore = ref(false);
/** Il server ha rifiutato un dato del modulo (422): l'errore è sul campo, il pannello lo dice e aspetta. */
const anteprimaBloccata = ref(false);
/**
 * Il modulo è cambiato e il pannello non l'ha ancora letto: vero dal primo tasto fino alla risposta.
 * Senza, nei 350 ms del debounce il pulsante restava attivo con il pannello — e il cancello — del modulo
 * precedente: cambiare «chi esce» e cliccare subito avrebbe aggirato il cancello (1).
 */
const anteprimaSuperata = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let ultimaRichiesta = 0;

function corpoAnteprima() {
  return {
    tipo: form.tipo,
    sottotipo: form.sottotipo,
    riga_uscente_id: props.tipo === 'inizio_locazione' ? null : form.riga_uscente_id,
    anagrafica_entrante_id: nessunEntrante.value || eSuccessione.value ? null : form.anagrafica_entrante_id,
    decorrenza: form.decorrenza,
    quota: form.quota,
    tipologia: form.tipologia,
    copia_autentica: form.copia_autentica,
    copia_autentica_il: form.copia_autentica ? (form.copia_autentica_il || null) : null,
    estremi_titolo: form.estremi_titolo || null,
    nota: form.nota || null,
    data_fine_locazione: form.data_fine_locazione || null,
    regime_contratto: form.regime_contratto,
    pertinenze: form.pertinenze,
    // Cambiano il conguaglio: entrano nell'anteprima. Il server li legge solo alla costituzione e alla riserva d'usufrutto.
    ordinaria_dopo_atto: form.ordinaria_dopo_atto,
    voci_da_tenere: form.voci_da_tenere,
    nudi_che_tornano: estinzione.value && !form.accrescimento ? form.nudi_che_tornano : [],
    nudi_per_quota: estinzione.value && !form.accrescimento ? form.nudi_per_quota : false,
    accrescimento: estinzione.value ? form.accrescimento : false,
    // Decisione 65: le quote come le scrive l'amministratore («33,33»), al server con il punto.
    ...(eSuccessione.value ? {
      eredi: form.eredi.map(e => ({ anagrafica_id: e.anagrafica_id, quota: String(e.quota).trim().replace(',', '.') })),
      arretrato: form.arretrato,
      erede_di_riferimento: form.eredi.length > 1 ? form.erede_di_riferimento : null,
    } : {}),
  };
}

async function calcolaAnteprima() {
  if (!completo.value) {
    // Anche una risposta ancora in volo va scartata: il modulo a cui rispondeva non esiste più.
    ++ultimaRichiesta;
    anteprima.value = null; anteprimaErrore.value = false; anteprimaBloccata.value = false;
    anteprimaInCorso.value = false; anteprimaSuperata.value = false;
    return;
  }
  const n = ++ultimaRichiesta;
  anteprimaInCorso.value = true;
  try {
    const { data } = await axios.post<AnteprimaPassaggioDati>(
      route(generateRoute('gestionale.immobili.passaggi.anteprima'), { condominio: props.condominio.id, immobile: props.immobile.id }),
      corpoAnteprima(),
    );
    if (n !== ultimaRichiesta) return; // è arrivata una risposta più vecchia di quella che aspettiamo
    anteprima.value = data;
    anteprimaErrore.value = false;
    anteprimaBloccata.value = false;
    form.clearErrors();
  } catch (e: any) {
    if (n !== ultimaRichiesta) return;
    if (e?.response?.status === 422) {
      // Una regola del server (decorrenza prima dell'inizio, entrante uguale a uscente…): si mostra
      // sul campo, e il pannello dice che aspetta la correzione.
      const errori = e.response.data?.errors ?? {};
      form.clearErrors();
      Object.keys(errori).forEach(k => form.setError(k as any, errori[k][0]));
      anteprima.value = null;
      anteprimaErrore.value = false;
      anteprimaBloccata.value = true;
    } else {
      anteprimaErrore.value = true;
    }
  } finally {
    if (n === ultimaRichiesta) { anteprimaInCorso.value = false; anteprimaSuperata.value = false; }
  }
}

watch(() => JSON.stringify(corpoAnteprima()), () => {
  anteprimaSuperata.value = true;
  if (timer) clearTimeout(timer);
  timer = setTimeout(calcolaAnteprima, 350);
}, { immediate: true });
onBeforeUnmount(() => { if (timer) clearTimeout(timer); });

// --- Chi paga l'ordinaria dal giorno dell'atto (decisioni 31.5 e 31.6) ---------------------------

const ordinaria = computed(() => anteprima.value?.ordinaria?.applicabile ? anteprima.value.ordinaria : null);
// Decisione 31.8: una voce in un piano approvato ha la ripartizione bloccata e non si sposta; il server lo ripete.
const vociDivise = computed(() => dividiVoci(ordinaria.value?.voci ?? []));
const vociLibere = computed(() => vociDivise.value.libere);
const vociBloccate = computed(() => vociDivise.value.bloccate);
/**
 * Rilievo S1 della Fase 1-bis: la registrazione ha trovato un elenco di voci diverso da quello mostrato (un piano riportato
 * in bozza, una voce nuova). Il pannello si ricalcola, e il messaggio resta finché non si riprova: il ricalcolo, al
 * successo, pulisce gli errori del modulo.
 */
const vociCambiate = ref<string | null>(null);

// --- Il cancello (1) e la conferma --------------------------------------------------------------

const cancelloRichiesto = computed(() => anteprima.value?.cancello.richiesto ?? false);
// Le quote che restano per legge a chi le ha (beta.38, decisione del 29/09/2026): si dicono sempre, e non chiedono la spunta.
const informazioniCancello = computed(() => anteprima.value?.cancello.informazioni ?? []);
/** Rilievo T4 della Fase 1-bis della .43: gli avvisi senza spunta che non riguardano quote lasciate dov'erano (decisione 58). */
const avvisiCancello = computed(() => anteprima.value?.cancello.avvisi ?? []);
const cancelloSoddisfatto = computed(() => !cancelloRichiesto.value || (form.ho_letto && form.nota_cancello.trim().length >= 10));
// La rinuncia al conguaglio vale solo se c'è una coppia proposta, e vuole la sua ragione.
// La rinuncia vale solo se il pannello propone una coppia: se sparisce (cambio di controparte o di data) la
// spunta non blocca il pulsante e non parte col modulo; se ricompare, la spunta è ancora lì (verifica S5, R13). Lo stesso con
// l'arretrato agli eredi, dove il server la rifiuta (rilievo X11 della Fase 1-bis della .44): `form.arretrato`, il campo che il
// server controlla.
const coppieProposte = computed(() => (anteprima.value?.rate.conguaglio?.coppie.length ?? 0) > 0);
const rinunciaEffettiva = computed(() => laRinunciaVale(anteprima.value?.rate.conguaglio?.coppie.length ?? 0, form.rinuncia_conguaglio, eSuccessione.value ? form.arretrato : null));
// Rilievo X8: con la rinuncia, a nome del defunto resta tutta la sua posizione.
const fraseDellArretrato = computed(() => fraseArretrato(anteprima.value?.rate.arretrato, rinunciaEffettiva.value));
const rinunciaSoddisfatta = computed(() => !rinunciaEffettiva.value || form.nota_conguaglio.trim().length >= 10);
const puoConfermare = computed(() => completo.value && anteprima.value !== null && !anteprimaErrore.value && !anteprimaInCorso.value && !anteprimaSuperata.value && !anteprimaBloccata.value && cancelloSoddisfatto.value && rinunciaSoddisfatta.value && !form.processing);

function submit() {
  vociCambiate.value = null;
  // Il file e il promemoria non entrano nell'anteprima (non cambiano le conseguenze): viaggiano solo con la
  // scrittura, che con un File dentro parte come multipart.
  form.transform(() => ({
    ...corpoAnteprima(),
    allegato_titolo: form.allegato_titolo,
    promemoria_scadenza: form.promemoria_scadenza,
    promemoria_giorni: form.promemoria_scadenza ? form.promemoria_giorni : null,
    ho_letto: form.ho_letto,
    nota_cancello: form.nota_cancello,
    rinuncia_conguaglio: rinunciaEffettiva.value,
    nota_conguaglio: rinunciaEffettiva.value ? form.nota_conguaglio : null,
    // Rilievo S1: l'elenco delle voci che il pannello ha mostrato; se sul server è cambiato, la registrazione si ferma.
    ordinaria_impronta: ordinaria.value?.impronta ?? null,
  }))
    .post(route(generateRoute('gestionale.immobili.passaggi.store'), { condominio: props.condominio.id, immobile: props.immobile.id }), {
      preserveScroll: true,
      onError: (errori) => {
        if (errori.ordinaria_impronta) {
          vociCambiate.value = errori.ordinaria_impronta;
          calcolaAnteprima();
        }
      },
    });
}

// --- «Crea nuova anagrafica» senza lasciare la pagina --------------------------------------------

const nuovaAperta = ref(false);
/** Nella successione la persona nuova va nella riga dell'erede da cui si è aperto il dialogo. */
const nuovaPerErede = ref<number | null>(null);
function apriNuovaPerErede(i: number) {
  nuovaPerErede.value = i;
  nuovaAperta.value = true;
}
watch(nuovaAperta, (aperta) => { if (!aperta) setTimeout(() => { nuovaPerErede.value = null; }, 300); });
const nuova = useForm({ nome: '', codice_fiscale: '', indirizzo: '', email: '' });
const personeAggiunte = ref<PersonaDelCondominio[]>([]);
const tuttiICandidati = computed(() => [...personeAggiunte.value, ...candidatiEntrante.value]);

async function creaAnagrafica() {
  nuova.processing = true;
  nuova.clearErrors();
  try {
    const { data } = await axios.post<PersonaDelCondominio>(
      route(generateRoute('gestionale.immobili.passaggi.anagrafica'), { condominio: props.condominio.id, immobile: props.immobile.id }),
      { nome: nuova.nome, codice_fiscale: nuova.codice_fiscale || null, indirizzo: nuova.indirizzo, email: nuova.email || null },
    );
    personeAggiunte.value.unshift(data);
    if (nuovaPerErede.value !== null && form.eredi[nuovaPerErede.value]) form.eredi[nuovaPerErede.value].anagrafica_id = data.id;
    else form.anagrafica_entrante_id = data.id;
    nuovaAperta.value = false;
    nuova.reset();
  } catch (e: any) {
    const errori = e?.response?.data?.errors ?? {};
    Object.keys(errori).forEach(k => nuova.setError(k as any, errori[k][0]));
    if (!Object.keys(errori).length) nuova.setError('nome', 'Non sono riuscito a creare l\'anagrafica: riprova.');
  } finally {
    nuova.processing = false;
  }
}

// --- Testata --------------------------------------------------------------------------------------

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'Anagrafiche', href: generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'Registra passaggio', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Chi esce, chi entra',
    description: 'Il periodo di chi esce si chiude, quello di chi entra si apre: la storia dell\'unità resta intera, niente si sovrascrive.',
    icon: ArrowRightLeft,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Da quando',
    description: 'Una data sola, quella dell\'atto. Il giorno prima lo calcola il programma. Nessun valore predefinito: la data decide chi paga.',
    icon: CalendarDays,
    colorVariant: 'amber' as const,
  },
  {
    title: 'Cosa cambia nei conti',
    description: 'Le rate già emesse non si toccano. Il conguaglio e chi resta obbligato li vedi nel pannello «Cosa cambierà» prima di confermare.',
    icon: Scale,
    colorVariant: 'emerald' as const,
  },
]);

const urlElenco = computed(() => generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id }));
function urlTipo(t: TipoPassaggio) {
  return route(generateRoute('gestionale.immobili.passaggi.create'), { condominio: props.condominio.id, immobile: props.immobile.id, tipo: t });
}
</script>

<template>
  <Head title="Registra passaggio" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Registra passaggio"
        :page-subtitle="`${tipoCorrente.titolo} su ${immobile.nome} (Int. ${immobile.interno})`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="urlElenco"
        back-text="Annulla e torna ai titolari"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="showGuide = true"
      />

      <ImmobileLayout>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

          <!-- ============================ Il modulo (due colonne) ============================ -->
          <form @submit.prevent="submit" class="lg:col-span-2 space-y-6">

            <!-- Il tipo: la prima domanda decide tutto il resto -->
            <nav class="grid grid-cols-2 md:grid-cols-5 gap-2" aria-label="Tipo di passaggio">
              <Link
                v-for="t in TIPI" :key="t.id"
                :href="urlTipo(t.id)"
                class="flex flex-col items-start gap-1 rounded-xl border p-3 transition-all"
                :class="t.id === tipo
                  ? 'bg-indigo-50 border-indigo-400 ring-1 ring-indigo-400 dark:bg-indigo-900/20 dark:border-indigo-500'
                  : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700 dark:hover:bg-slate-900'"
                :aria-current="t.id === tipo ? 'page' : undefined"
              >
                <!-- Icona e titolo sulla stessa riga (Checkpoint 1: «più elegante»). -->
                <span class="flex items-center gap-2">
                  <component :is="t.icona" class="w-4 h-4 shrink-0" :class="t.id === tipo ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400'" />
                  <span class="text-[12px] font-bold leading-tight" :class="t.id === tipo ? 'text-indigo-900 dark:text-indigo-200' : 'text-slate-700 dark:text-slate-300'">{{ t.titolo }}</span>
                </span>
                <span class="text-[10px] text-slate-500 dark:text-slate-400 leading-tight pl-6">{{ t.sotto }}</span>
              </Link>
            </nav>

            <!-- Errore di scrittura senza campo (`passaggio`): il catch di store, o un errore che nessun campo può ospitare. -->
            <FormErrorSummary :errors="erroriSenzaRiquadro" :labels="ETICHETTE_ERRORI" titolo="La registrazione è stata rifiutata" />
            <div v-if="erroreScrittura" class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/50 dark:bg-amber-900/10 p-4 flex items-start gap-3">
              <AlertTriangle class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
              <p class="text-sm text-amber-900 dark:text-amber-200 leading-relaxed">{{ erroreScrittura }}</p>
            </div>

            <!-- ---------------------------- 1. Chi esce ---------------------------- -->
            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">
                  <template v-if="tipo === 'inizio_locazione'">Chi resta</template>
                  <template v-else>Chi esce</template>
                </CardTitle>
                <CardDescription>
                  <template v-if="tipo === 'inizio_locazione'">Chi risponde verso il condominio non cambia.</template>
                  <template v-else-if="tipo === 'successione'">Chi esce è il defunto: proprietario pieno, comproprietario o nudo proprietario. Se era usufruttuario, la sua morte è l'estinzione dell'usufrutto.</template>
                  <template v-else-if="tipo === 'usufrutto'">Costituzione: il proprietario pieno diventa nudo proprietario. Estinzione: l'usufruttuario esce e il nudo proprietario torna proprietario pieno; con un altro usufrutto in corso sull'unità, torna proprietario pieno solo il nudo proprietario dell'usufrutto che finisce.</template>
                  <template v-else>I titolari in corso oggi su questa unità. Se è uno solo, è già selezionato. Un periodo già chiuso da «Modifica» non compare qui e non si può più registrare come passaggio.</template>
                </CardDescription>
              </CardHeader>
              <CardContent class="space-y-4">

                <!-- Usufrutto: il sottotipo -->
                <div v-if="tipo === 'usufrutto'" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <label v-for="s in (['costituzione', 'estinzione'] as const)" :key="s"
                    class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-all"
                    :class="form.sottotipo === s ? 'bg-purple-50 border-purple-400 ring-1 ring-purple-400 dark:bg-purple-900/20' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                    <input type="radio" v-model="form.sottotipo" :value="s" class="w-4 h-4 mt-0.5 text-purple-600 border-slate-300 focus:ring-purple-600" />
                    <span class="flex flex-col">
                      <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ s === 'costituzione' ? 'Costituzione' : 'Estinzione' }}</span>
                      <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ s === 'costituzione' ? 'Entra un usufruttuario; il proprietario resta come nudo proprietario.' : 'L\'usufruttuario esce; torna proprietario pieno il nudo proprietario dell\'usufrutto che finisce.' }}</span>
                    </span>
                  </label>
                </div>

                <!-- Inizio locazione: riga informativa, non selezionabile (§6.6) -->
                <div v-if="tipo === 'inizio_locazione'" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 p-4">
                  <p v-if="proprietari.length" class="text-sm text-slate-800 dark:text-slate-200">
                    {{ proprietari.length === 1 ? 'Il proprietario resta' : 'I proprietari restano' }}
                    <template v-for="(p, i) in proprietari" :key="p.id">
                      <strong>{{ p.anagrafica.nome }}</strong><span v-if="i < proprietari.length - 1">, </span>
                    </template>.
                    La locazione si aggiunge, non sostituisce.
                  </p>
                  <p v-else class="text-sm text-amber-800 dark:text-amber-300 flex items-start gap-2">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                    Nessun proprietario risulta oggi su questa unità. Puoi registrare la locazione, ma verso il condominio non risponde nessuno: associa prima il proprietario.
                  </p>
                </div>

                <!-- Radio dei titolari -->
                <template v-else>
                  <div v-if="candidatiUscente.length" class="space-y-2">
                    <label v-for="t in candidatiUscente" :key="t.id"
                      class="flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-all"
                      :class="form.riga_uscente_id === t.id ? 'bg-blue-50 border-blue-400 ring-1 ring-blue-400 dark:bg-blue-900/20 dark:border-blue-500' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700 dark:hover:bg-slate-900'">
                      <input type="radio" v-model="form.riga_uscente_id" :value="t.id" class="w-4 h-4 text-blue-600 border-slate-300 focus:ring-blue-600 shrink-0" />
                      <!-- Su uno schermo stretto quota e data scendono sotto il nome invece di schiacciarlo (verifica a video della .43). -->
                      <span class="flex flex-1 min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                        <BadgeRuolo :ruolo="t.tipologia" taglia="md" />
                        <span class="flex flex-col min-w-0 flex-1 basis-24">
                          <span class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{{ t.anagrafica.nome }}</span>
                          <span class="text-[10px] uppercase tracking-widest text-slate-400 truncate">{{ t.anagrafica.codice_fiscale || 'CF non inserito' }}</span>
                        </span>
                        <span class="ml-auto flex items-center gap-3 whitespace-nowrap">
                          <span class="text-xs text-slate-600 dark:text-slate-400 tabular-nums">{{ quotaIt(t.quota) }}&nbsp;%</span>
                          <span class="text-xs text-slate-500 dark:text-slate-400" v-if="t.data_inizio">dal {{ toItalian(t.data_inizio) }}</span>
                        </span>
                      </span>
                    </label>
                  </div>
                  <div v-else class="rounded-lg border border-dashed border-slate-300 dark:border-slate-700 p-4 text-sm text-slate-600 dark:text-slate-400 flex items-start gap-2">
                    <Info class="w-4 h-4 shrink-0 mt-0.5 text-slate-400" />
                    <span>
                      <template v-if="tipo === 'fine_locazione'">Nessun inquilino risulta in corso su questa unità: non c'è una locazione da chiudere.</template>
                      <template v-else-if="tipo === 'usufrutto' && form.sottotipo === 'estinzione'">Nessun usufruttuario risulta in corso su questa unità.</template>
                      <template v-else>Nessun titolare in corso da cui uscire. Se l'unità è nuova o vuota, usa «Associa soggetto» dall'elenco; un periodo già chiuso da «Modifica» non si registra più come passaggio.</template>
                    </span>
                  </div>
                  <InputError :message="form.errors.riga_uscente_id" />
                </template>
              </CardContent>
            </Card>

            <!-- ---------------------------- 2. Data dell'atto ---------------------------- -->
            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">
                  {{ eLocazione ? (tipo === 'inizio_locazione' ? 'Inizio della locazione' : 'Fine della locazione') : eSuccessione ? 'Data del decesso' : 'Data dell\'atto' }}
                </CardTitle>
                <CardDescription>
                  <template v-if="tipo === 'inizio_locazione'">Il primo giorno della locazione. La scadenza, se la conosci, è solo un promemoria.</template>
                  <template v-else-if="eSuccessione">Gli eredi sono titolari dal giorno del decesso, il defunto fino al giorno prima. La data in cui lo hai saputo e quella della dichiarazione di successione non spostano niente.</template>
                  <template v-else>Una data sola. Il giorno prima lo calcola il programma.</template>
                </CardDescription>
              </CardHeader>
              <CardContent class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                  <div>
                    <Label for="decorrenza" class="mb-1.5 block">{{ tipo === 'fine_locazione' ? 'Primo giorno senza l\'inquilino' : eSuccessione ? 'Il giorno del decesso' : 'Da quando' }}</Label>
                    <VueDatePicker
                      ref="dpDecorrenza"
                      v-model="form.decorrenza"
                      @text-submit="chiudiCalendario(dpDecorrenza)"
                      model-type="yyyy-MM-dd"
                      text-input
                      :text-input-options="{ format: 'dd/MM/yyyy' }"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                      placeholder="Scegli la data"
                      class="w-full"
                    />
                    <InputError :message="form.errors.decorrenza" />
                  </div>
                  <div v-if="tipo === 'inizio_locazione'">
                    <Label for="data_fine_locazione" class="mb-1.5 block">Scadenza del contratto <span class="text-slate-400 font-normal">(facoltativa)</span></Label>
                    <VueDatePicker
                      ref="dpFineLocazione"
                      v-model="form.data_fine_locazione"
                      @text-submit="chiudiCalendario(dpFineLocazione)"
                      model-type="yyyy-MM-dd"
                      text-input
                      :text-input-options="{ format: 'dd/MM/yyyy' }"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                      placeholder="Lascia vuoto se non la conosci"
                      class="w-full"
                    />
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-snug">La data di fine è una scadenza, non un automatismo: il programma non chiude la locazione da solo.</p>
                    <InputError :message="form.errors.data_fine_locazione" />
                    <!-- Il promemoria: l'agenda del gestionale (scadenziario) c'è già; qui un rigo discreto e un
                         popover per l'anticipo (Checkpoint 1: «meno invasivo, dentro un popup»). Opt-in, mai automatico. -->
                    <div v-if="form.data_fine_locazione" class="mt-2 flex flex-wrap items-center gap-2 text-[12px]">
                      <template v-if="form.promemoria_scadenza">
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-indigo-50 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200 px-2 py-1">
                          <BellRing class="w-3.5 h-3.5" /> In agenda {{ etichettaAnticipo(form.promemoria_giorni) }} prima della scadenza
                        </span>
                        <Popover v-model:open="promemoriaAperto">
                          <PopoverTrigger as-child>
                            <button type="button" class="text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 underline-offset-2 hover:underline">cambia</button>
                          </PopoverTrigger>
                          <PopoverContent side="bottom" align="start" class="w-72 p-4 space-y-3">
                            <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">Quanto prima?</p>
                            <div class="grid grid-cols-2 gap-2">
                              <button v-for="g in ANTICIPI" :key="g" type="button" @click="form.promemoria_giorni = g; promemoriaAperto = false"
                                class="rounded-md border px-2 py-1.5 text-[12px] transition-colors"
                                :class="form.promemoria_giorni === g ? 'border-indigo-400 bg-indigo-50 text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-200' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900'">
                                {{ etichettaAnticipo(g) }}
                              </button>
                            </div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">La voce compare nell'agenda del condominio e resta finché non la chiudi.</p>
                          </PopoverContent>
                        </Popover>
                        <button type="button" @click="form.promemoria_scadenza = false" class="text-slate-500 hover:text-rose-600 underline-offset-2 hover:underline">togli</button>
                      </template>
                      <Popover v-else v-model:open="promemoriaAperto">
                        <PopoverTrigger as-child>
                          <button type="button" class="inline-flex items-center gap-1.5 text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400">
                            <BellRing class="w-3.5 h-3.5" /> Ricordamelo in agenda
                          </button>
                        </PopoverTrigger>
                        <PopoverContent side="bottom" align="start" class="w-72 p-4 space-y-3">
                          <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">Quanto prima della scadenza?</p>
                          <div class="grid grid-cols-2 gap-2">
                            <button v-for="g in ANTICIPI" :key="g" type="button" @click="form.promemoria_giorni = g; form.promemoria_scadenza = true; promemoriaAperto = false"
                              class="rounded-md border border-slate-200 dark:border-slate-700 px-2 py-1.5 text-[12px] hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
                              {{ etichettaAnticipo(g) }}
                            </button>
                          </div>
                          <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">Una voce nell'agenda del condominio, che resta finché non la chiudi. Niente parte da solo: la scegli tu.</p>
                        </PopoverContent>
                      </Popover>
                      <InputError :message="form.errors.promemoria_giorni" />
                    </div>
                  </div>
                </div>

                <!-- La frase calcolata: la scrive il server, nel riferimento dell'anteprima -->
                <p v-if="anteprima?.riferimento.frase" class="rounded-lg bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-700 px-4 py-3 text-sm text-slate-800 dark:text-slate-200 flex items-start gap-2">
                  <CalendarDays class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>{{ anteprima.riferimento.frase }}</span>
                </p>
                <p v-else-if="form.decorrenza && !completo" class="text-[12px] text-slate-500 dark:text-slate-400 italic">
                  Appena il modulo è completo, qui compare chi risulterà titolare fino a quando e chi da quando.
                </p>
              </CardContent>
            </Card>

            <!-- ---------------------------- Decisione 65: gli eredi ---------------------------- -->
            <Card v-if="eSuccessione" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ legato ? 'Chi riceve l\'unità' : 'Gli eredi' }}</CardTitle>
                <CardDescription>Chi ha accettato l'eredità, ogni erede con la quota dell'unità che riceve: insieme fanno la quota del defunto. Entrano nello stesso ruolo, in comunione. Un erede minorenne si registra come gli altri: verso il condominio paga, con i beni dell'erede, chi ne ha la rappresentanza.</CardDescription>
              </CardHeader>
              <CardContent class="space-y-4">
                <div v-for="(e, i) in form.eredi" :key="i" class="grid grid-cols-12 gap-3 items-start">
                  <div class="col-span-12 sm:col-span-7">
                    <div class="flex items-center justify-between mb-1.5">
                      <Label :for="`erede_${i}`">{{ legato ? 'Legatario' : 'Erede' }} {{ form.eredi.length > 1 ? i + 1 : '' }}</Label>
                      <button type="button" tabindex="-1" @click="apriNuovaPerErede(i)" class="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                        <UserPlus class="w-3.5 h-3.5" /> Crea nuova anagrafica
                      </button>
                    </div>
                    <v-select :id="`erede_${i}`" class="w-full bg-white dark:bg-slate-950 text-sm" :options="candidatiEredi" v-model="e.anagrafica_id"
                      :reduce="(a: PersonaDelCondominio) => a.id" label="nome" placeholder="Cerca o seleziona…"
                      :selectable="(a: PersonaDelCondominio) => !form.eredi.some((x, j) => j !== i && x.anagrafica_id === a.id)">
                      <template #option="{ nome, codice_fiscale, indirizzo }">
                        <div class="flex flex-col py-0.5">
                          <span class="font-medium text-sm">{{ nome }}</span>
                          <span class="text-[11px] text-slate-400 truncate">{{ codice_fiscale || indirizzo || '' }}</span>
                        </div>
                      </template>
                      <template #no-options="{ search }">
                        <div class="py-2 px-3 text-sm text-slate-500 text-left">
                          <template v-if="search">Nessuna persona del condominio corrisponde a «{{ search }}».</template>
                          <template v-else>Nessun'altra persona del condominio da scegliere.</template>
                          Usa «Crea nuova anagrafica» qui sopra.
                        </div>
                      </template>
                    </v-select>
                    <InputError :message="(form.errors as Record<string, string>)[`eredi.${i}.anagrafica_id`]" />
                  </div>
                  <div class="col-span-9 sm:col-span-4">
                    <Label :for="`quota_erede_${i}`" class="mb-1.5 block">Quota (%)</Label>
                    <Input :id="`quota_erede_${i}`" v-model="e.quota" inputmode="decimal" class="w-full bg-white dark:bg-slate-950 tabular-nums" placeholder="es. 50" />
                    <InputError :message="(form.errors as Record<string, string>)[`eredi.${i}.quota`]" />
                  </div>
                  <div class="col-span-3 sm:col-span-1 pt-7 flex justify-end">
                    <button v-if="form.eredi.length > 1" type="button" @click="togliErede(i)" class="h-9 w-9 inline-flex items-center justify-center rounded-md text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30" :aria-label="`Togli l'erede ${i + 1}`">
                      <X class="w-4 h-4" />
                    </button>
                  </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                  <button type="button" @click="aggiungiErede" class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                    <Plus class="w-4 h-4" /> Aggiungi un erede
                  </button>
                  <button v-if="form.eredi.length > 1 && uscente" type="button" @click="dividiInPartiUguali" class="text-[13px] font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200">
                    Dividi in parti uguali
                  </button>
                  <span v-if="uscente" class="text-[12px] text-slate-500 dark:text-slate-400">La quota di {{ uscente.anagrafica.nome }}: {{ quotaIt(uscente.quota) }}&nbsp;%</span>
                </div>
                <p v-if="quoteNonTornano && form.eredi.every(e => e.anagrafica_id && String(e.quota).trim() !== '')" class="text-[12px] text-amber-800 dark:text-amber-300 flex items-start gap-1.5">
                  <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" /> {{ quoteNonTornano.charAt(0).toUpperCase() + quoteNonTornano.slice(1) }}.
                </p>
                <InputError :message="form.errors.eredi" />

                <!-- L'erede di riferimento: solo quando il server lo chiede (bozze di un piano fermo, più eredi). -->
                <div v-if="chiediRiferimento" class="rounded-lg border border-indigo-200 bg-indigo-50/60 dark:border-indigo-800/60 dark:bg-indigo-950/20 p-4 space-y-2">
                  <Label for="erede_di_riferimento" class="block text-indigo-900 dark:text-indigo-200">Erede di riferimento</Label>
                  <p class="text-[12px] text-indigo-900/80 dark:text-indigo-200/80 leading-snug">Il piano rate non si ricalcola più, e le sue rate non ancora emesse si intestano a una persona sola: a chi? Gli altri eredi, su questo piano, non hanno rate: pagano la loro parte con le righe di saldo, che entrano nel piano dopo. Il conguaglio toglie l'importo di quelle rate solo all'erede di riferimento.</p>
                  <v-select id="erede_di_riferimento" class="w-full bg-white dark:bg-slate-950 text-sm" :options="erediScelti" v-model="form.erede_di_riferimento" :reduce="(a: PersonaDelCondominio) => a.id" label="nome" placeholder="Scegli l'erede…" />
                  <InputError :message="(form.errors as Record<string, string>).erede_di_riferimento" />
                </div>

                <!-- Il legato: chi riceve l'unità non eredita il patrimonio. -->
                <label class="flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 cursor-pointer select-none transition-colors"
                  :class="legato ? 'bg-purple-50 border-purple-300 dark:bg-purple-900/20 dark:border-purple-800' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                  <input type="checkbox" v-model="legato" class="w-4 h-4 mt-0.5 accent-purple-600 rounded border-slate-300" />
                  <span class="text-[13px] leading-relaxed text-slate-700 dark:text-slate-300">
                    <strong class="font-semibold text-slate-900 dark:text-slate-100">L'unità è un legato</strong> (il testamento la lascia a una o più persone che non ereditano il patrimonio)
                    <span v-if="legato" class="block text-slate-600 dark:text-slate-400">Chi riceve l'unità paga i contributi dal giorno del decesso; l'arretrato del defunto resta a suo nome, e ne rispondono gli eredi.</span>
                  </span>
                </label>
                <InputError :message="form.errors.sottotipo" />
              </CardContent>
            </Card>

            <!-- ---------------------------- Decisione 65 (2): l'arretrato del defunto ---------------------------- -->
            <Card v-if="eSuccessione" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">L'arretrato del defunto</CardTitle>
                <CardDescription>Le rate emesse al defunto e non pagate, le sue bozze e i suoi saldi: la sua posizione verso il condominio. Se è un debito, ne risponde ogni erede per la sua quota (art. 754 c.c.).</CardDescription>
              </CardHeader>
              <CardContent class="space-y-3">
                <label class="flex items-start gap-3 rounded-lg border p-3 transition-all"
                  :class="[legato ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer', form.arretrato === 'eredi' ? 'bg-emerald-50 border-emerald-400 ring-1 ring-emerald-400 dark:bg-emerald-900/20' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700']">
                  <input type="radio" v-model="form.arretrato" value="eredi" :disabled="legato" class="w-4 h-4 mt-0.5 text-emerald-600 border-slate-300 focus:ring-emerald-600" />
                  <span class="flex flex-col">
                    <!-- Decisione 69 (2): niente «la proposta della legge»: l'art. 754 c.c. vale con tutte e due le strade, e dove scrivere il debito è una scelta. -->
                    <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">Agli eredi, per quota</span>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400">Righe di saldo sulla stessa gestione, di segno opposto: la posizione del defunto passa a ogni erede per la sua quota. Con il conguaglio ogni erede risponde della sua quota di tutto, e la posizione del defunto si chiude. Le rate in bozza dei piani che non si ricalcolano più, anche quelle di prima del decesso, vanno all'erede di riferimento, salvo quelle con un pagamento; la parte degli altri eredi si regola con le loro righe di saldo, che entrano nel piano dopo.</span>
                  </span>
                </label>
                <label class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-all"
                  :class="form.arretrato === 'defunto' ? 'bg-slate-100 border-slate-400 ring-1 ring-slate-400 dark:bg-slate-800' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                  <input type="radio" v-model="form.arretrato" value="defunto" class="w-4 h-4 mt-0.5 text-slate-700 border-slate-300 focus:ring-slate-600" />
                  <span class="flex flex-col">
                    <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">A nome del defunto («eredi di …»)</span>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400">Resta dov'è, e lo studio lo chiede agli eredi; chi versa al posto del defunto si registra con «Versato da».<template v-if="legato"> Con il legato è l'unica scelta.</template></span>
                  </span>
                </label>
                <p v-if="fraseDellArretrato" class="rounded-lg bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-700 px-4 py-3 text-sm text-slate-800 dark:text-slate-200 flex items-start gap-2">
                  <Scale class="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" /><span>{{ fraseDellArretrato }}</span>
                </p>
                <InputError :message="(form.errors as Record<string, string>).arretrato" />
              </CardContent>
            </Card>

            <!-- ---------------------------- 3–5. Chi entra, quota, ruolo ---------------------------- -->
            <Card v-if="serveEntrante || entranteFacoltativo" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Chi entra</CardTitle>
                <CardDescription>
                  <template v-if="entranteFacoltativo">Un nuovo inquilino, oppure nessuno: l'unità resta sfitta.</template>
                  <template v-else>Una persona del condominio, o una nuova, senza lasciare la pagina.</template>
                </CardDescription>
              </CardHeader>
              <CardContent class="space-y-5">

                <label v-if="entranteFacoltativo" class="flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-all"
                  :class="nessunEntrante ? 'bg-slate-100 border-slate-400 ring-1 ring-slate-400 dark:bg-slate-800' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                  <input type="checkbox" v-model="nessunEntrante" @change="nessunEntrante && (form.anagrafica_entrante_id = null)" class="w-4 h-4 accent-slate-900 dark:accent-slate-300 rounded border-slate-300" />
                  <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Nessuno — l'unità resta sfitta</span>
                </label>

                <div v-if="!nessunEntrante" class="grid grid-cols-1 sm:grid-cols-12 gap-x-6 gap-y-5">
                  <div class="sm:col-span-6">
                    <div class="flex items-center justify-between mb-1.5">
                      <Label for="anagrafica_entrante_id">{{ eLocazione ? 'Inquilino' : tipo === 'usufrutto' ? 'Usufruttuario' : 'Acquirente o donatario' }}</Label>
                      <!-- `tabindex=-1`: con Tab dalla data si arriva al select di chi entra, non a questo pulsante. -->
                      <button type="button" tabindex="-1" @click="nuovaAperta = true" class="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                        <UserPlus class="w-3.5 h-3.5" /> Crea nuova anagrafica
                      </button>
                    </div>
                    <v-select
                      id="anagrafica_entrante_id"
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="tuttiICandidati"
                      v-model="form.anagrafica_entrante_id"
                      :reduce="(a: PersonaDelCondominio) => a.id"
                      label="nome"
                      placeholder="Cerca o seleziona…"
                    >
                      <template #option="{ nome, codice_fiscale, indirizzo }">
                        <div class="flex flex-col py-0.5">
                          <span class="font-medium text-sm">{{ nome }}</span>
                          <span class="text-[11px] text-slate-400 truncate">{{ codice_fiscale || indirizzo || '' }}</span>
                        </div>
                      </template>
                      <!-- Il testo predefinito di vue-select è in inglese («Sorry, no matching options»). -->
                      <template #no-options="{ search }">
                        <div class="py-2 px-3 text-sm text-slate-500 text-left">
                          <template v-if="search">Nessuna persona del condominio corrisponde a «{{ search }}».</template>
                          <template v-else>Nessun'altra persona del condominio da scegliere.</template>
                          Usa «Crea nuova anagrafica» qui sopra.
                        </div>
                      </template>
                    </v-select>
                    <InputError :message="form.errors.anagrafica_entrante_id" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="quota" class="mb-1.5 block">Quota (%)</Label>
                    <Input id="quota" v-model="form.quota" :readonly="uscente !== null" inputmode="decimal"
                      class="w-full bg-white dark:bg-slate-950 tabular-nums" :class="uscente !== null ? 'text-slate-500' : ''" />
                    <!-- Decisione 37 (1.11.0-beta.42): al posto della casella «La quota cambia», che lasciava una parte dell'unità di nessuno. -->
                    <p v-if="uscente" class="mt-1.5 text-[11px] leading-snug text-slate-500 dark:text-slate-400">La quota di chi esce: il passaggio la porta tutta. Passarne solo una parte non è ancora previsto.</p>
                    <InputError :message="form.errors.quota" />
                  </div>

                  <div class="sm:col-span-4">
                    <Label for="tipologia" class="mb-1.5 block">Ruolo</Label>
                    <v-select v-if="ruoloModificabile" class="w-full bg-white dark:bg-slate-950 text-sm" :options="RUOLI" label="label" v-model="form.tipologia" :reduce="(r: any) => r.id" :clearable="false" />
                    <div v-else class="h-9 flex items-center"><BadgeRuolo :ruolo="form.tipologia" taglia="md" /></div>
                  </div>
                  <!-- La riserva d'usufrutto si dichiara (decisione 28): solo quando vende un proprietario pieno. -->
                  <div v-if="puoRiservare" class="sm:col-span-12">
                    <label class="flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 cursor-pointer select-none transition-colors"
                      :class="riserva ? 'bg-purple-50 border-purple-300 dark:bg-purple-900/20 dark:border-purple-800' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                      <input type="checkbox" v-model="riserva" class="w-4 h-4 mt-0.5 accent-purple-600 rounded border-slate-300" />
                      <span class="text-[13px] leading-relaxed text-slate-700 dark:text-slate-300">
                        <strong class="font-semibold text-slate-900 dark:text-slate-100">Chi vende o dona resta usufruttuario</strong> (vendita o donazione con riserva d'usufrutto)
                        <span v-if="riserva" class="block text-slate-600 dark:text-slate-400">
                          {{ uscente?.anagrafica.nome }} resta sulla stessa quota come usufruttuario, chi compra entra nudo proprietario. Chi paga l'ordinaria dal giorno dell'atto lo scegli qui sotto, quando il pannello ha letto il modulo. Le straordinarie seguono la competenza: la data della delibera, o quella dichiarata sulla fattura.
                        </span>
                      </span>
                    </label>
                    <InputError :message="form.errors.sottotipo" />
                  </div>
                  <!-- L'errore del ruolo è una frase intera (la via che esiste): un riquadro a tutta larghezza, non un testo
                       rosso accalcato sotto la colonna stretta. -->
                  <div v-if="form.errors.tipologia" class="sm:col-span-12 -mt-1 flex items-start gap-2.5 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-[13px] leading-relaxed text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-200">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5 text-rose-600" />
                    <span>{{ form.errors.tipologia }}</span>
                  </div>

                  <div v-if="chiediRegime" class="sm:col-span-12">
                    <Label for="regime_contratto" class="mb-1.5 block">Regime del contratto <span class="text-slate-400 font-normal">{{ eBox ? "(sul box cambia cosa spetta all'inquilino)" : '(lo legge il prospetto degli oneri accessori)' }}</span></Label>
                    <v-select id="regime_contratto" class="w-full bg-white dark:bg-slate-950 text-sm" :options="REGIMI" label="label" v-model="form.regime_contratto" :reduce="(r: any) => r.id" placeholder="Scegli il regime…" />
                    <InputError :message="form.errors.regime_contratto" />
                  </div>
                </div>
              </CardContent>
            </Card>

            <!-- Decisioni 31.5 e 31.6 (beta.41): alla costituzione e alla riserva d'usufrutto, chi paga l'ordinaria dal giorno
                 dell'atto. La legge è già scelta; le voci sono tutte spuntate. Le conseguenze le scrive il server. -->
            <Card v-if="ordinaria" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Chi paga l'ordinaria dal giorno dell'atto</CardTitle>
                <CardDescription>La legge la mette a carico dell'usufruttuario (art. 1004 c.c.), ma le parti possono essersi accordate diversamente. La scelta vale per il conguaglio delle rate già emesse e resta scritta nel passaggio; con la legge, le voci che passano all'«Usufruttuario» valgono anche per i piani generati o ricalcolati dopo.</CardDescription>
              </CardHeader>
              <CardContent class="space-y-4">
                <div v-if="vociCambiate" class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/50 dark:bg-amber-900/10 px-3.5 py-2.5 flex items-start gap-2.5">
                  <AlertTriangle class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                  <p class="text-[13px] text-amber-900 dark:text-amber-200 leading-relaxed">{{ vociCambiate }}</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <label class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-all"
                    :class="form.ordinaria_dopo_atto === 'usufruttuario' ? 'bg-purple-50 border-purple-400 ring-1 ring-purple-400 dark:bg-purple-900/20' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                    <input type="radio" v-model="form.ordinaria_dopo_atto" value="usufruttuario" class="w-4 h-4 mt-0.5 text-purple-600 border-slate-300 focus:ring-purple-600" />
                    <span class="flex flex-col gap-0.5">
                      <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">All'usufruttuario<template v-if="ordinaria.usufruttuario">, {{ ordinaria.usufruttuario }}</template></span>
                      <span class="text-[11px] font-medium text-purple-700 dark:text-purple-300">Proposta di legge, art. 1004 c.c.</span>
                      <span class="text-[11px] text-slate-500 dark:text-slate-400">Le spese ordinarie sono di chi gode del bene. Le voci sul «Proprietario» che si possono spostare passano all'«Usufruttuario», così i piani generati dopo fanno lo stesso. Le voci create dopo partono dal «Proprietario», e nei piani generati o ricalcolati dopo la spesa addebitata direttamente all'unità va al nudo proprietario (nel conguaglio delle rate già emesse resta all'usufruttuario).</span>
                    </span>
                  </label>
                  <label class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-all"
                    :class="form.ordinaria_dopo_atto === 'voce' ? 'bg-purple-50 border-purple-400 ring-1 ring-purple-400 dark:bg-purple-900/20' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                    <input type="radio" v-model="form.ordinaria_dopo_atto" value="voce" class="w-4 h-4 mt-0.5 text-purple-600 border-slate-300 focus:ring-purple-600" />
                    <span class="flex flex-col gap-0.5">
                      <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">Come dice ogni voce</span>
                      <span class="text-[11px] text-slate-500 dark:text-slate-400">Le voci restano come sono: quelle sul «Proprietario» vanno al nudo proprietario<template v-if="ordinaria.nudo">, {{ ordinaria.nudo }}</template>, anche nel conguaglio. Per un accordo fra le parti: scrivilo nelle note interne della riga del titolare, dalla scheda dell'unità (guida «Ruoli e usufrutto»).</span>
                    </span>
                  </label>
                </div>

                <div v-if="form.ordinaria_dopo_atto === 'usufruttuario' && vociLibere.length" class="space-y-2">
                  <p class="text-[13px] text-slate-700 dark:text-slate-300 leading-relaxed">
                    <strong class="font-semibold text-slate-900 dark:text-slate-100">Voci che passano dal «Proprietario» all'«Usufruttuario»</strong>.
                    Togli la spunta a quelle che devono restare al nudo proprietario: per esempio una piccola spesa straordinaria messa fra le ordinarie.
                  </p>
                  <label v-for="v in vociLibere" :key="v.id"
                    class="flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 cursor-pointer select-none transition-colors"
                    :class="voceSpuntata(v, form.voci_da_tenere) ? 'bg-white border-purple-300 dark:bg-slate-950 dark:border-purple-800' : 'bg-white border-slate-200 dark:bg-slate-950 dark:border-slate-700'">
                    <input type="checkbox" :checked="voceSpuntata(v, form.voci_da_tenere)" @change="form.voci_da_tenere = cambiaSpunta(form.voci_da_tenere, v.id)" class="w-4 h-4 mt-0.5 accent-purple-600 rounded border-slate-300" />
                    <span class="flex flex-col gap-0.5 min-w-0">
                      <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ v.conto }}</span>
                      <span class="text-[11px] text-slate-500 dark:text-slate-400">
                        {{ v.tabella }} · {{ v.gestione }}<template v-if="v.percentuale < 100"> · il {{ v.percentuale.toLocaleString('it-IT') }} % sul «Proprietario»</template>
                      </span>
                      <span v-if="!voceSpuntata(v, form.voci_da_tenere)" class="text-[11px] text-slate-600 dark:text-slate-400 leading-snug">Resta sul «Proprietario»: nei piani che verranno andrà al nudo proprietario<template v-if="ordinaria.nudo">, {{ ordinaria.nudo }}</template>.</span>
                      <span v-else-if="v.altre_unita.length" class="text-[11px] text-slate-600 dark:text-slate-400 leading-snug">
                        Vale per tutta la tabella: cambia anche
                        <template v-for="(u, i) in v.altre_unita" :key="u.immobile_id">
                          <template v-if="i > 0">{{ i === v.altre_unita.length - 1 ? ' e ' : ', ' }}</template>
                          <strong class="font-medium">{{ u.immobile }}</strong> ({{ u.nudi ? `da ${u.nudi} a ${u.usufruttuari}` : `a ${u.usufruttuari}` }}<template v-if="u.importo_formattato && u.importo">, {{ u.importo_formattato }} nell'ultimo piano</template>)</template>.
                      </span>
                    </span>
                  </label>
                </div>

                <!-- Rilievo T-B1 della revisione della Fase 1-ter: il rimedio dipende dal piano che blocca la voce (con o senza rate a
                     giornale, da fatture), e lo sa il server. Il riquadro mostra le sue frasi invece di un testo fisso. -->
                <div v-if="form.ordinaria_dopo_atto === 'usufruttuario' && vociBloccate.length && ordinaria.frasi_bloccate?.length" class="flex items-start gap-2.5 rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950 px-3.5 py-2.5 text-[13px] leading-relaxed text-slate-700 dark:text-slate-300">
                  <Lock class="w-4 h-4 shrink-0 mt-0.5 text-slate-400" />
                  <span class="space-y-1.5">
                    <span v-for="(f, i) in ordinaria.frasi_bloccate" :key="i" class="block">{{ f }}</span>
                  </span>
                </div>

                <!-- La prima frase del server è la conclusione, con la data e i nomi; le altre dicono ciò che l'elenco qui sopra già mostra. -->
                <p v-if="ordinaria.frasi.length" class="rounded-lg bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-700 px-4 py-3 text-sm text-slate-800 dark:text-slate-200 flex items-start gap-2">
                  <Info class="w-4 h-4 shrink-0 mt-0.5 text-slate-400" /><span>{{ ordinaria.frasi[0] }}</span>
                </p>
                <!-- Decisione 33 (1.11.0-beta.42): la scelta agisce sulla voce, per tutto l'anno; un usufrutto della gestione nato con
                     la scelta opposta, anche finito, cambia per i suoi giorni, e lo si dice prima della conferma. -->
                <p v-for="(f, i) in ordinaria.frasi_altri_usufrutti ?? []" :key="'altri-' + i" class="rounded-lg bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-700 px-4 py-3 text-sm text-slate-800 dark:text-slate-200 flex items-start gap-2">
                  <Info class="w-4 h-4 shrink-0 mt-0.5 text-slate-400" /><span>{{ f }}</span>
                </p>
                <InputError :message="form.errors.ordinaria_dopo_atto" />
              </CardContent>
            </Card>

            <!-- Usufrutto in estinzione: chi torna pieno -->
            <Card v-if="tipo === 'usufrutto' && form.sottotipo === 'estinzione'" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ form.accrescimento ? 'A chi va l\'usufrutto' : 'Chi torna proprietario pieno' }}</CardTitle>
              </CardHeader>
              <CardContent class="space-y-4">
                <!-- 1.11.0-beta.44: con un altro usufruttuario in corso, l'atto può prevedere l'accrescimento. Nessuna spunta già messa:
                     senza patto, negli atti fra vivi, la legge porta al consolidamento con la nuda proprietà. -->
                <label v-if="offriAccrescimento" class="flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 cursor-pointer select-none transition-colors"
                  :class="form.accrescimento ? 'bg-purple-50 border-purple-300 dark:bg-purple-900/20 dark:border-purple-800' : 'bg-white border-slate-200 hover:bg-slate-50 dark:bg-slate-950 dark:border-slate-700'">
                  <input type="checkbox" v-model="form.accrescimento" class="w-4 h-4 mt-0.5 accent-purple-600 rounded border-slate-300" />
                  <span class="text-[13px] leading-relaxed text-slate-700 dark:text-slate-300">
                    <strong class="font-semibold text-slate-900 dark:text-slate-100">L'usufrutto si accresce all'altro usufruttuario</strong> (l'atto lo prevede, o è un legato di usufrutto congiunto, artt. 675 e 678 c.c.)
                    <span class="block text-slate-600 dark:text-slate-400">{{ form.accrescimento
                      ? 'L\'usufrutto di chi muore va agli usufruttuari che restano, in proporzione alla loro quota; la nuda proprietà resta nuda, e il conguaglio dell\'ordinaria va a loro.'
                      : 'Senza la spunta, negli atti fra vivi, vale la regola di legge quando l\'atto non dice altro: la nuda proprietà si riunisce all\'usufrutto che finisce. Nel legato di usufrutto congiunto è la legge stessa a portare all\'accrescimento: spunta la casella.' }}</span>
                  </span>
                </label>
                <p v-if="estinzione && altriUsufrutti && piuNudi" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 px-3.5 py-2.5 text-[12px] leading-relaxed text-slate-600 dark:text-slate-400">
                  La nuda proprietà di questa unità è di più nudi proprietari: il programma non sa quale usufrutto stia sopra quale nuda, e l'accrescimento all'altro usufruttuario non si registra da qui. Se l'atto lo prevede, o se l'usufrutto è un legato a più persone insieme (dove l'accrescimento lo vuole la legge, artt. 675 e 678 c.c.), correggi le righe a mano da «Modifica associazione»; altrimenti, negli atti fra vivi, vale la regola di legge qui sotto.
                </p>
                <InputError :message="(form.errors as Record<string, string>).accrescimento" />
                <template v-if="!form.accrescimento">
                <!-- Decisione 57 (D2): con un altro usufrutto in corso, torna piena solo la nuda dell'usufrutto che finisce. Sui
                     titolari censiti a mano lo dice l'amministratore, senza una scelta già fatta. -->
                <!-- Decisione 61: il programma non sa quale nuda torna piena, e lo dice; niente caselle. -->
                <p v-if="erroreEstinzione" class="text-sm text-amber-800 dark:text-amber-300 flex items-start gap-2">
                  <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" /> {{ erroreEstinzione }}
                </p>
                <div v-else-if="sceltaDeiNudi" class="space-y-2">
                  <template v-if="nudiInteriPossibili">
                    <p class="text-sm text-slate-600 dark:text-slate-400">Su questa unità c'è un altro usufrutto in corso. Spunta i nudi proprietari della parte su cui finisce l'usufrutto di {{ uscente?.anagrafica.nome }} ({{ quotaIt(uscente?.quota ?? 0) }}&nbsp;%): tornano proprietari pieni solo loro, e insieme valgono quanto l'usufrutto.</p>
                    <label v-for="n in nudiCandidati" :key="n.id" class="flex items-center gap-2 text-sm text-slate-800 dark:text-slate-200" :class="form.nudi_per_quota ? 'opacity-50' : 'cursor-pointer'">
                      <input type="checkbox" :value="n.id" v-model="form.nudi_che_tornano" :disabled="form.nudi_per_quota" class="rounded border-slate-300 dark:border-slate-600" />
                      <BadgeRuolo :ruolo="n.tipologia" taglia="md" /> <strong>{{ n.anagrafica.nome }}</strong>
                      <span class="text-slate-500 tabular-nums">{{ quotaIt(n.quota) }} %</span>
                    </label>
                  </template>
                  <p v-else class="text-sm text-slate-600 dark:text-slate-400">Su questa unità c'è un altro usufrutto in corso, e nessuna combinazione di nudi proprietari interi vale l'usufrutto di {{ uscente?.anagrafica.nome }} ({{ quotaIt(uscente?.quota ?? 0) }}&nbsp;%): se la nuda proprietà è in comune fra i nudi proprietari, scegli la casella qui sotto; altrimenti l'estinzione si registra a mano da «Modifica associazione».</p>
                  <!-- Decisione 62: la donazione congiunta. Nessuna casella già spuntata. -->
                  <label class="flex items-start gap-2 text-sm text-slate-800 dark:text-slate-200 cursor-pointer pt-1">
                    <input type="checkbox" v-model="form.nudi_per_quota" class="mt-0.5 rounded border-slate-300 dark:border-slate-600" />
                    <span>Tutti i nudi proprietari, ciascuno per la sua quota: la nuda proprietà è in comune fra i nudi proprietari (per esempio una donazione ai figli con la riserva d'usufrutto per i due genitori). Ogni nudo proprietario torna proprietario pieno per la sua parte dell'usufrutto che finisce e resta nudo proprietario del resto.</span>
                  </label>
                  <template v-if="anteprima?.nudi?.da === 'per_quota'">
                    <p v-for="n in nudiCheTornano" :key="n.id" class="text-sm text-slate-800 dark:text-slate-200 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                      <BadgeRuolo :ruolo="n.tipologia" taglia="md" /> <strong>{{ n.anagrafica.nome }}</strong>
                      <span v-if="anteprima.nudi.consolida[n.id] !== undefined" class="text-slate-500">— dalla data indicata risulterà proprietario pieno per {{ percentualeIt(anteprima.nudi.consolida[n.id]) }} e resterà nudo proprietario del resto.</span>
                      <span v-else class="text-slate-500">— dalla data indicata risulterà proprietario pieno di tutta la sua quota ({{ quotaIt(n.quota) }}&nbsp;%).</span>
                    </p>
                  </template>
                  <InputError :message="form.errors.nudi_che_tornano" />
                </div>
                <div v-else-if="nudiCheTornano.length" class="space-y-1.5">
                  <p v-for="n in nudiCheTornano" :key="n.id" class="text-sm text-slate-800 dark:text-slate-200 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <BadgeRuolo :ruolo="n.tipologia" taglia="md" /> <strong>{{ n.anagrafica.nome }}</strong>
                    <span v-if="nudiCheTornano.length > 1" class="text-slate-500 tabular-nums">{{ quotaIt(n.quota) }} %</span>
                    <span v-if="anteprima?.nudi?.consolida?.[n.id] !== undefined" class="text-slate-500">— dalla data indicata risulterà proprietario pieno per {{ percentualeIt(anteprima.nudi.consolida[n.id]) }}, la parte dell'usufrutto che finisce, e resterà nudo proprietario del resto.</span>
                    <span v-else-if="consolidaPrevisto !== null" class="text-slate-500">— dalla data indicata risulterà proprietario pieno per {{ percentualeIt(consolidaPrevisto) }}, la parte dell'usufrutto che finisce, e resterà nudo proprietario del resto.</span>
                    <span v-else class="text-slate-500">— dalla data indicata risulterà proprietario{{ nudiCheTornano.length > 1 ? ' alla sua quota' : '' }}.</span>
                  </p>
                  <p v-if="nudiCheTornano.length > 1" class="text-xs text-slate-500 dark:text-slate-400">Il conguaglio delle rate già emesse all'usufruttuario si divide come dice il pannello «Cosa cambierà».</p>
                  <p v-if="anteprima?.nudi?.da === 'registro'" class="text-xs text-slate-500 dark:text-slate-400">Su questa unità c'è un altro usufrutto in corso: torna proprietario pieno solo il nudo proprietario dell'usufrutto che finisce, come risulta dal passaggio da cui l'usufrutto è nato.</p>
                  <InputError :message="form.errors.nudi_che_tornano" />
                </div>
                <p v-else class="text-sm text-amber-800 dark:text-amber-300 flex items-start gap-2">
                  <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" /> Nessun nudo proprietario è registrato su questa unità: senza, l'estinzione non sa a chi tornare.
                </p>
                </template>
              </CardContent>
            </Card>

            <!-- ---------------------------- 6. Pertinenze ---------------------------- -->
            <PassaggioPertinenzeCard v-if="pertinenze.length && (tipo === 'vendita' || tipo === 'usufrutto' || tipo === 'successione')" :pertinenze="pertinenze" :successione="tipo === 'successione'" :legato="legato" v-model="form.pertinenze" />
            <InputError v-if="pertinenze.length" :message="form.errors.pertinenze" />

            <!-- ---------------------------- 7–8. Documento e nota ---------------------------- -->
            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">{{ eLocazione ? 'Contratto e note' : 'Titolo di provenienza e note' }}</CardTitle>
                <CardDescription>
                  <template v-if="eLocazione">Gli estremi del contratto, se li hai. Le note restano interne.</template>
                  <template v-else-if="eSuccessione">La dichiarazione di successione, il testamento o l'atto di accettazione: qualunque documento dica chi sono gli eredi. La copia autentica del titolo qui non serve: libera chi vende, non c'entra con chi muore.</template>
                  <template v-else>Rogito, verbale di separazione omologato, decreto di trasferimento all'asta: qualunque titolo valga per la trascrizione.</template>
                </CardDescription>
              </CardHeader>
              <CardContent class="space-y-5">
                <div>
                  <Label for="estremi_titolo" class="mb-1.5 block">{{ eLocazione ? 'Estremi del contratto' : eSuccessione ? 'Estremi del documento' : 'Estremi dell\'atto' }} <span class="text-slate-400 font-normal">(facoltativi)</span></Label>
                  <Input id="estremi_titolo" v-model="form.estremi_titolo" class="w-full bg-white dark:bg-slate-950" :placeholder="eLocazione ? 'es. contratto registrato il 12/06/2026, n. 4521' : eSuccessione ? 'es. dichiarazione di successione presentata il 12/09/2026' : 'es. atto notaio Verdi, rep. 12345 del 30/04/2026'" />
                  <InputError :message="form.errors.estremi_titolo" />
                </div>

                <div v-if="tipo === 'vendita'" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 p-4 space-y-3">
                  <label class="flex items-start gap-3 cursor-pointer select-none">
                    <input type="checkbox" v-model="form.copia_autentica" class="w-4 h-4 mt-0.5 accent-slate-900 dark:accent-slate-300 rounded border-slate-300" />
                    <span class="flex flex-col">
                      <span class="text-sm font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-2"><FileSignature class="w-4 h-4 text-slate-400" /> Ho ricevuto copia autentica del titolo</span>
                      <!-- V4 della verifica a video (decisione 28.3): con la riserva la copia non libera chi vende, che resta usufruttuario. -->
                      <span v-if="riserva" class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Con la riserva d'usufrutto la copia non libera chi vende: resta usufruttuario, e per i contributi successivi risponde in solido con il nudo proprietario (art. 67 ult. co. disp. att. c.c.). Si registra comunque, come per ogni vendita.</span>
                      <span v-else class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">È l'unico campo con un effetto giuridico diretto: finché il condominio non la riceve, chi vende resta obbligato per i contributi successivi (art. 63 co. 5 disp. att. c.c.).</span>
                    </span>
                  </label>
                  <div v-if="form.copia_autentica" class="pl-7">
                    <div class="max-w-xs">
                      <Label for="copia_autentica_il" class="mb-1.5 block text-xs">Ricevuta il</Label>
                      <VueDatePicker ref="dpCopiaAutentica" v-model="form.copia_autentica_il" @text-submit="chiudiCalendario(dpCopiaAutentica)" model-type="yyyy-MM-dd" text-input :text-input-options="{ format: 'dd/MM/yyyy' }" format="dd/MM/yyyy" locale="it" :enable-time-picker="false" auto-apply placeholder="Data di ricezione" class="w-full" />
                    </div>
                    <div v-if="form.errors.copia_autentica_il" class="mt-2 flex items-start gap-2.5 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-[13px] leading-relaxed text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-200">
                      <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5 text-rose-600" />
                      <span>{{ form.errors.copia_autentica_il }}</span>
                    </div>
                  </div>
                </div>

                <div>
                  <Label for="allegato_titolo" class="mb-1.5 block">{{ eLocazione ? 'Allega il contratto' : eSuccessione ? 'Allega il documento' : 'Allega copia del titolo' }} <span class="text-slate-400 font-normal">(PDF, facoltativo)</span></Label>
                  <!-- L'input nativo è nascosto: il suo testo («Choose file», «No file chosen») lo scrive il browser nella
                       sua lingua, non noi (Checkpoint 2). Il pulsante e il nome del file sono nostri. -->
                  <div class="flex flex-wrap items-center gap-3">
                    <input
                      id="allegato_titolo"
                      ref="allegatoInput"
                      type="file"
                      accept="application/pdf"
                      class="sr-only"
                      @change="form.allegato_titolo = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                    <button type="button" @click="allegatoInput?.click()"
                      class="inline-flex h-8 items-center gap-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                      <Paperclip class="w-3.5 h-3.5 text-slate-400" /> {{ form.allegato_titolo ? 'Cambia file' : 'Scegli il PDF' }}
                    </button>
                    <span v-if="form.allegato_titolo" class="text-sm text-slate-700 dark:text-slate-300 truncate max-w-[18rem]" :title="form.allegato_titolo.name">{{ form.allegato_titolo.name }}</span>
                    <span v-else class="text-sm text-slate-400">Nessun file scelto</span>
                    <button v-if="form.allegato_titolo" type="button" @click="togliAllegato" class="text-[11px] text-slate-500 hover:text-rose-600">Togli</button>
                  </div>
                  <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-snug">
                    Alla registrazione il file finisce fra i <strong>Documenti dell'unità</strong>, agganciato a questo passaggio: lo ritrovi dallo storico.<template v-if="tipo === 'vendita'"> Non sostituisce la casella «Ho ricevuto copia autentica», che è la dichiarazione con effetto giuridico.</template>
                  </p>
                  <InputError :message="form.errors.allegato_titolo" />
                </div>

                <div>
                  <Label for="nota" class="mb-1.5 block">Nota <span class="text-slate-400 font-normal">(interna)</span></Label>
                  <Textarea id="nota" v-model="form.nota" rows="3" class="w-full bg-white dark:bg-slate-950 resize-none" placeholder="Quello che vuoi ricordare di questo passaggio…" />
                  <InputError :message="form.errors.nota" />
                </div>
              </CardContent>
            </Card>

            <!-- ---------------------------- Il cancello (1) e la conferma ---------------------------- -->
            <!-- Beta.38, decisione del 29/09/2026: le quote che il passaggio lascia a chi le ha si dicono qui, con o senza
                 cancello, e non chiedono la spunta. Il piede non dice perché, né cita articoli: le ragioni sono diverse (la
                 legge, i soli saldi pregressi, una spesa tutta di chi esce, decisione 28.8 c) e le dice il pannello. -->
            <div v-if="informazioniCancello.length && anteprima" class="rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/40 p-5">
              <div class="flex items-start gap-3">
                <Info class="w-5 h-5 text-slate-500 shrink-0 mt-0.5" />
                <div class="space-y-1">
                  <p class="text-sm font-bold text-slate-800 dark:text-slate-200">Quote che questo passaggio non tocca</p>
                  <ul class="text-[13px] text-slate-700 dark:text-slate-300 list-disc pl-4 space-y-0.5">
                    <li v-for="m in informazioniCancello" :key="m">{{ m }}</li>
                  </ul>
                  <p class="text-[11px] text-slate-500 dark:text-slate-400">Restano a chi le ha e nessuna cambia intestatario: per queste non serve la spunta.</p>
                </div>
              </div>
            </div>
            <div v-if="avvisiCancello.length && anteprima" class="rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/40 p-5">
              <div class="flex items-start gap-3">
                <Info class="w-5 h-5 text-slate-500 shrink-0 mt-0.5" />
                <div class="space-y-1">
                  <p class="text-sm font-bold text-slate-800 dark:text-slate-200">Da sapere</p>
                  <ul class="text-[13px] text-slate-700 dark:text-slate-300 list-disc pl-4 space-y-0.5">
                    <li v-for="m in avvisiCancello" :key="m">{{ m }}</li>
                  </ul>
                </div>
              </div>
            </div>
            <div v-if="cancelloRichiesto && anteprima" class="rounded-xl border-2 border-amber-300 bg-amber-50 dark:border-amber-700/60 dark:bg-amber-900/10 p-5 space-y-4">
              <div class="flex items-start gap-3">
                <AlertTriangle class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div class="space-y-1">
                  <p class="text-sm font-bold text-amber-900 dark:text-amber-200">Questo passaggio tocca conti già in corso</p>
                  <ul class="text-[13px] text-amber-800 dark:text-amber-300 list-disc pl-4 space-y-0.5">
                    <li v-for="m in anteprima.cancello.motivi" :key="m">{{ m }}</li>
                  </ul>
                </div>
              </div>
              <label class="flex items-center gap-3 cursor-pointer select-none">
                <input type="checkbox" v-model="form.ho_letto" class="w-4 h-4 accent-amber-700 rounded border-amber-400" />
                <span class="text-sm font-semibold text-amber-900 dark:text-amber-200">Ho letto cosa cambierà</span>
              </label>
              <div>
                <Label for="nota_cancello" class="mb-1.5 block text-amber-900 dark:text-amber-200">Perché lo registri così <span class="font-normal text-amber-700 dark:text-amber-400">(almeno dieci caratteri, resta con l'operazione)</span></Label>
                <Textarea id="nota_cancello" v-model="form.nota_cancello" rows="2" class="w-full bg-white dark:bg-slate-950 resize-none border-amber-300" :placeholder="eSuccessione ? 'es. dichiarazione di successione letta, eredi e quote controllati' : 'es. rogito del 30/04, date controllate con il notaio'" />
                <p class="text-[11px] mt-1" :class="form.nota_cancello.trim().length >= 10 ? 'text-emerald-700' : 'text-amber-700 dark:text-amber-400'">{{ form.nota_cancello.trim().length }}/10</p>
                <InputError :message="form.errors.nota_cancello" />
              </div>
            </div>

            <div class="flex items-center justify-between gap-3 pt-2">
              <p class="text-[11px] text-slate-500 dark:text-slate-400 max-w-md leading-snug">
                <template v-if="anteprimaErrore">Il pannello non ha risposto: finché non riesce a calcolare le conseguenze, il passaggio non si registra.</template>
                <template v-else-if="anteprimaBloccata">Un dato del modulo non è accettabile: correggi il campo segnato e il pannello riparte da lì.</template>
                <template v-else-if="!completo">Compila il modulo: il pulsante si attiva quando il pannello «Cosa cambierà» ha calcolato le conseguenze.</template>
                <template v-else-if="anteprimaInCorso || anteprimaSuperata">Il pannello sta rileggendo il modulo.</template>
                <template v-else-if="cancelloRichiesto && !cancelloSoddisfatto">Spunta «Ho letto cosa cambierà» e scrivi la nota per attivare la registrazione.</template>
                <template v-else-if="!rinunciaSoddisfatta">Hai rinunciato al conguaglio proposto: scrivi perché nel pannello (almeno dieci caratteri).</template>
                <template v-else>Niente viene scritto finché non confermi.</template>
              </p>
              <div class="flex items-center gap-3 shrink-0">
                <Link :href="urlElenco" class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm">Annulla</Link>
                <Button type="submit" :disabled="!puoConfermare" class="h-9 px-8 text-sm font-semibold shadow-md gap-2">
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  <Check v-else class="h-4 w-4" />
                  Registra passaggio
                </Button>
              </div>
            </div>
          </form>

          <!-- ============================ Il pannello (colonna sticky) ============================ -->
          <AnteprimaPassaggio :dati="anteprima" :in-corso="anteprimaInCorso || anteprimaSuperata" :errore="anteprimaErrore" :bloccato="anteprimaBloccata" :mancante="mancante" :tipo="props.tipo"
            v-model:rinuncia="form.rinuncia_conguaglio" v-model:nota-rinuncia="form.nota_conguaglio" />
          <InputError :message="form.errors.nota_conguaglio" class="mt-2" />
        </div>
      </ImmobileLayout>
    </div>
  </GestionaleLayout>

  <PassaggioProprietaGuide v-model:open="showGuide" />

  <!-- «Crea nuova anagrafica», senza lasciare la pagina -->
  <Dialog :open="nuovaAperta" @update:open="nuovaAperta = $event">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Nuova anagrafica</DialogTitle>
        <DialogDescription>Il minimo per registrare il passaggio: il resto si completa dopo, dalla scheda della persona. Verrà associata a {{ condominio.nome }}.</DialogDescription>
      </DialogHeader>
      <form @submit.prevent="creaAnagrafica" class="space-y-4">
        <div>
          <Label for="nuova_nome" class="mb-1.5 block">Nome e cognome, o ragione sociale</Label>
          <Input id="nuova_nome" v-model="nuova.nome" class="w-full" autofocus />
          <InputError :message="nuova.errors.nome" />
        </div>
        <div>
          <Label for="nuova_cf" class="mb-1.5 block">Codice fiscale <span class="text-slate-400 font-normal">(facoltativo)</span></Label>
          <Input id="nuova_cf" v-model="nuova.codice_fiscale" class="w-full uppercase" />
          <InputError :message="nuova.errors.codice_fiscale" />
        </div>
        <div>
          <Label for="nuova_indirizzo" class="mb-1.5 block">Indirizzo</Label>
          <Input id="nuova_indirizzo" v-model="nuova.indirizzo" class="w-full" placeholder="Via, numero, comune" />
          <InputError :message="nuova.errors.indirizzo" />
        </div>
        <div>
          <Label for="nuova_email" class="mb-1.5 block">Email <span class="text-slate-400 font-normal">(facoltativa)</span></Label>
          <Input id="nuova_email" v-model="nuova.email" type="email" class="w-full" />
          <InputError :message="nuova.errors.email" />
        </div>
        <DialogFooter class="gap-2">
          <Button type="button" variant="outline" @click="nuovaAperta = false">Annulla</Button>
          <Button type="submit" :disabled="nuova.processing" class="gap-2">
            <LoaderCircle v-if="nuova.processing" class="h-4 w-4 animate-spin" />
            <UserPlus v-else class="h-4 w-4" /> Crea e seleziona
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>

<style src="vue-select/dist/vue-select.css"></style>
