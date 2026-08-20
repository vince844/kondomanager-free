<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { Head, useForm, Link, router } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import InputError from '@/components/InputError.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import QuoteMillesimiGuide from '@/components/guides/QuoteMillesimiGuide.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Plus, LoaderCircle, Trash2, Table as TableIcon, Info, Hash, Home, Check, Search, ArrowUp, ArrowDown, ArrowUpDown, X, Layers, Building2, DoorOpen, Shapes } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogCancel } from "@/components/ui/alert-dialog";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import vSelect from "vue-select";
import "vue-select/dist/vue-select.css";
import { trans } from "laravel-vue-i18n";
import type { BreadcrumbItem } from "@/types";
import type { Tabella } from "@/types/gestionale/tabelle";
import type { Building } from "@/types/buildings";
import type { Millesimo } from "@/types/gestionale/millesimi";
import type { Immobile } from "@/types/gestionale/immobili";

const props = defineProps<{
  condominio: Building;
  tabella: Tabella;
  millesimi: Millesimo[];
  immobili: Immobile[];
}>()

const showNoImmobiliDialog = ref(false);

/**
 * Il pannello di guida in testa alla pagina.
 *
 * ⚠️ Aggiunto nella beta.61, e non per completezza: quella beta ha portato qui l'associazione in
 * blocco, la ricerca, l'ordinamento e — soprattutto — il millesimo che si può lasciare vuoto. Le
 * tre distinzioni fra riga assente, zero e vuoto **non si indovinano**: si somigliano a schermo e
 * il motore le tratta in modo diverso. Una funzione che cambia chi paga cosa, e che nessuno
 * spiega, si scopre al primo riparto sbagliato.
 */
const showGuide = ref(false);
const alertMessage = ref("");

// Extract raw data from Proxy objects
const rawMillesimi = JSON.parse(JSON.stringify(props.millesimi));
const rawImmobili = JSON.parse(JSON.stringify(props.immobili));

const decimaliTabella = Math.max(0, props.tabella.numero_decimali ?? 2);

/**
 * I decimali che la **colonna** conserva: `quote_tabella.valore` è `decimal(12,5)`.
 *
 * ⚠️ **`numero_decimali` governa come il valore si mostra, mai cosa si conserva** — decisione presa
 * il 20/08/2026, chiudendo la coda ⑪ che era stata aperta l'11/08 da una domanda di Vincenzo e
 * lasciata in sospeso proprio su questo bivio («vincola solo la visualizzazione, oppure anche
 * l'inserimento, oppure arrotonda in salvataggio?»).
 *
 * Fino alla .60 valeva la terza: la pagina arrotondava **al caricamento** e all'uscita dal campo, e
 * impediva di battere oltre i decimali dichiarati. Tre ragioni per cui era la scelta sbagliata:
 *
 * 1. **Nessun calcolo ne ha bisogno.** Il motore normalizza `valore / somma dei valori` sui numeri
 *    come stanno nella colonna: `numero_decimali` non entra in nessuna ripartizione.
 * 2. **Cambiava un documento approvato.** Una tabella millesimale la redige un tecnico e la approva
 *    l'assemblea: il numero nel programma dev'essere quello sulla carta.
 * 3. **Aprire una pagina e salvarla riscriveva i valori**, e un valore piccolo poteva diventare
 *    **zero** — cioè «non partecipa» — senza che nessuno toccasse niente. Trovato dalla revisione
 *    avversariale della beta.61.
 *
 * E contraddiceva l'importatore, che sulla stessa colonna aveva già preso la posizione opposta e
 * l'aveva scritta (`CanonicalTabella::decimaliNecessari()`): «I millesimi reali arrivano con quattro
 * decimali, mentre `tabelle` nasce con `numero_decimali = 2`. **Arrotondare in ingresso è una
 * perdita silenziosa**». L'importatore alza la dichiarazione per stare dietro al dato; la pagina
 * abbassava il dato per stare dentro la dichiarazione.
 */
const DECIMALI_COLONNA = 5;

/**
 * Porta il valore digitato alla precisione della tabella, **quando si esce dalla casella**.
 *
 * ## Perché non una maschera vera
 *
 * Il primo tentativo usava `MoneyInput` (`v-money3`), che è quello che il gestionale adopera
 * per gli importi. Risolveva la virgola e limitava i decimali, ma con la convenzione dei campi
 * monetari: **le cifre scorrono da destra**. Digitando `200` compariva `2,00`, e per scrivere
 * duecento millesimi bisognava battere `20000`.
 *
 * Sugli importi ha senso — si digitano i centesimi — ma i millesimi sono numeri tondi, e
 * costringere a contare gli zeri è peggio del problema che la maschera risolveva. Verificato a
 * video prima di tenerla.
 *
 * ## Cosa fa questa, invece
 *
 * Mentre si digita non tocca niente: `333.3` a metà parola resta `333.3`. All'uscita dal campo
 * porta il valore ai decimali dichiarati dalla tabella, e basta.
 *
 * ## Perché il punto e non la virgola
 *
 * Un primo giro usava la virgola, che è il separatore italiano e quello del resto del
 * gestionale. Sbagliato **qui**, per due ragioni. La prima: su questa pagina ogni altro campo
 * numerico — ultima lettura, quota fissa, coefficiente di dispersione — è un input grezzo col
 * punto, quindi la virgola rendeva i millesimi l'eccezione invece della regola. La seconda, che
 * pesa di più: col punto **il valore che si vede è quello che si salva**. Nessuna conversione
 * al `submit`, nessun `transform`, e nessuna ambiguità fra separatore decimale e delle migliaia
 * — che è esattamente da dove sono usciti tutti i guai di questa funzione.
 *
 * Accetta comunque anche la virgola in ingresso: chi la batte per abitudine non deve essere
 * corretto da un messaggio d'errore, gli si normalizza il valore e basta.
 */
const normalizzaAllaPrecisione = (v: unknown): string => {
  const grezzo = String(v ?? "").trim();
  if (grezzo === "") return "";

  const n = Number(grezzo.replace(",", "."));
  if (!Number.isFinite(n)) return grezzo; // Testo non numerico: lo rifiuta il server, con il suo messaggio.

  // ⚠️ **Non si arrotonda: si tolgono gli zeri di coda.** Vedi la nota qui sopra.
  const conTuttiIDecimali = n.toFixed(DECIMALI_COLONNA);
  const senzaZeriInCoda = conTuttiIDecimali.replace(/0+$/, "").replace(/\.$/, "");

  const decimaliRimasti = senzaZeriInCoda.includes(".")
    ? senzaZeriInCoda.length - senzaZeriInCoda.indexOf(".") - 1
    : 0;

  // I decimali dichiarati sono un **minimo di leggibilità**, non un tetto: `500` su una tabella a
  // due si scrive `500.00`, ma `228.5002` resta `228.5002` anche se la tabella ne dichiara due.
  return decimaliRimasti >= decimaliTabella ? senzaZeriInCoda : n.toFixed(decimaliTabella);
};

/**
 * Impedisce di **digitare** più decimali di quanti la tabella ne dichiari.
 *
 * Il normalizzatore all'uscita dal campo non basta: fino al `blur` la casella accetta
 * `500.345`, e l'amministratore vede a schermo un numero che il sistema poi cambia sotto i suoi
 * occhi. Meglio non farglielo scrivere: su una tabella a due decimali il terzo non entra.
 *
 * **Tronca, non arrotonda** — `500.345` resta `500.34`. Arrotondare mentre si digita
 * significherebbe che battere una cifra ne cambia un'altra già scritta, che a schermo si legge
 * come un errore del programma.
 *
 * Cosa lascia passare di proposito: il separatore da solo (`500.` è uno stato legittimo di chi
 * sta per scrivere i decimali) e la virgola, che viene raddrizzata all'uscita — chi la batte per
 * abitudine non va corretto con un errore.
 *
 * Con `numero_decimali` a zero il separatore non si può proprio scrivere.
 */
const limitaDecimaliDigitati = (valore: string): string => {
  // Solo cifre e separatori: lettere e simboli non entrano.
  let s = valore.replace(/[^\d.,]/g, "");

  // Un separatore solo: i successivi si ignorano invece di produrre `1.2.3`.
  const primo = s.search(/[.,]/);
  if (primo !== -1) {
    s = s.slice(0, primo + 1) + s.slice(primo + 1).replace(/[.,]/g, "");
  }

  // ⚠️ **Il tetto resta quello dichiarato dalla tabella, e la distinzione conta.**
  //
  // Chiudendo la coda ⑪ questo limite era stato alzato ai cinque decimali della colonna, e la
  // correzione era eccessiva — segnalato da Vincenzo: «se imposto i decimali della tabella a 3
  // perché dovrei poter digitare 4 decimali?».
  //
  // Battere è un atto **deliberato della stessa persona** che ha impostato `numero_decimali`: se
  // ha scelto tre e ne scrive quattro sta contraddicendo sé stessa, e fermarla non toglie niente,
  // perché il blocco agisce *mentre scrive* — prima che il dato esista. Caricare e salvare invece
  // non è un atto deliberato: lì l'arrotondamento riscriveva in silenzio numeri arrivati da
  // altrove, ed è quella metà che la coda ⑪ ha tolto.
  //
  // Il caso che sembrava richiedere il limite più largo — un documento a quattro decimali su una
  // tabella che ne dichiara due — **dall'importatore non nasce**: `decimaliNecessari()` alza la
  // dichiarazione per stare dietro al dato. Nasce solo abbassando i decimali di una tabella già
  // piena, e lì i valori restano intatti (non si arrotonda più): chi vuole scriverne di più fini
  // alza l'impostazione.
  if (decimaliTabella === 0) return s.replace(/[.,]/g, "");

  const parti = s.match(/^(\d*)([.,])(\d*)$/);
  if (!parti) return s;

  return parti[1] + parti[2] + parti[3].slice(0, decimaliTabella);
};

/**
 * Applica il limite e **riallinea la casella**.
 *
 * Il `v-model` da solo non basta: quando il valore filtrato coincide con quello che il modello
 * aveva già, Vue non ridisegna il campo e a schermo resterebbe la cifra di troppo appena
 * battuta, pur non essendo nel modello. Scrivere `el.value` a mano chiude quella finestra.
 */
const onInputValore = (evento: Event, quota: { valore: string }): void => {
  const el = evento.target as HTMLInputElement;
  const pulito = limitaDecimaliDigitati(el.value);

  if (el.value !== pulito) el.value = pulito;
  quota.valore = pulito;
};

/**
 * Un modulo solo per tutte le tabelle.
 *
 * ⚠️ Fino alla beta.50 le tabelle di tipo `acqua` e `riscaldamento` avevano cinque campi in più —
 * `has_contatore`, `ultima_lettura`, `coeff_dispersione`, `quota_fissa`, `quota_variabile` — che
 * si compilavano, si validavano e si salvavano nella colonna `coefficienti`. **Nessun calcolo li
 * leggeva:** il motore ripartisce su `valore`, come per qualunque altra tabella. Chi li compilava
 * credeva di ripartire a consumo e ripartiva a millesimi.
 *
 * ⚠️ **I due tipi di tabella non sono stati tolti, e non erano il difetto.** Una tabella di tipo
 * `acqua` con unità di misura «metri cubi», dove si scrivono i consumi di ciascuna unità, è già
 * una ripartizione a consumo che funziona. Mancava la gestione dei **contatori** e la quota
 * fissa/variabile della UNI 10200: quello è il modulo previsto per la v1.15.
 */
/**
 * Un contatore per dare a ogni riga una **chiave stabile**, indipendente da id e posizione.
 *
 * ⚠️ Prima la chiave era `q.id ?? idx`: le righe salvate usavano l'id del database, quelle nuove
 * la posizione. Su un'installazione appena creata, dove le quote hanno id 1, 2, 3…, due righe
 * finiscono con la stessa chiave — Vue emette «Duplicate keys found during update» e **disegna una
 * riga in più di quante ne ha il modello**, con un'unità ripetuta e il totale che contraddice
 * l'elenco sopra di sé.
 *
 * Sui dati di oggi il caso non si raggiunge (gli id vanno da 67 in su), ma la beta.61 lo avvicina
 * da due lati: l'associazione in blocco crea molte righe con `id` nullo tutte insieme, e ogni
 * cestino premuto sopra di esse rifà lo scenario. E la chiave per posizione è comunque
 * incompatibile con il filtro e l'ordinamento, che spostano le righe a video.
 */
let contatoreChiavi = 0;
const prossimaChiave = () => `riga-${++contatoreChiavi}`;

const form = useForm({
  quote: rawMillesimi.map((q: Millesimo) => {
    return {
      chiave: prossimaChiave(),
      id: q.id as number | null,
      immobile: q.immobile as Immobile | null, 
      valore: normalizzaAllaPrecisione(q.valore)
    }
  }),
});

/**
 * La chiave è un dato **del client**: non deve viaggiare nella richiesta.
 *
 * `transform` toglie il campo un istante prima di partire, senza toccare `form.quote` — che resta
 * quello su cui gira il `v-for`.
 */
form.transform((dati: any) => ({
  ...dati,
  quote: (dati.quote ?? []).map(({ chiave, ...resto }: any) => resto),
}));

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Tabelle', href: generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id }) },
  { title: props.tabella.nome, href: '#' },
  { title: 'Quote', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Assegnazione Quote',
    description: "Associa ogni unità immobiliare al corrispondente valore millesimale.",
    icon: TableIcon,
    colorVariant: 'blue' as const
  },
  {
    title: 'Ripartire a consumo',
    description: "Scegli «metri cubi» o «persone» come unità di misura e scrivi qui i consumi: il riparto li usa come i millesimi.",
    icon: Info,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Totale sempre a vista',
    // Il testo precedente diceva «verifica che la somma corrisponda al limite previsto», e
    // prometteva due cose che non esistevano: una verifica (che il gestionale non fa) e un
    // limite (che non c'è — il totale giusto dipende dalla tabella, e lo sa l'amministratore).
    // Dalla beta.48 il totale c'è davvero, e questa riga dice quello che fa: mostrarlo.
    description: "In fondo all'elenco trovi la somma dei valori inseriti, aggiornata mentre digiti.",
    icon: Hash,
    colorVariant: 'amber' as const
  }
]);

// Calcola immobili disponibili
const immobiliDisponibili = computed(() => {
  const usedIds = form.quote.map((q: any) => q.immobile?.id).filter(Boolean);
  return rawImmobili.filter((i: Immobile) => !usedIds.includes(i.id));
});

/**
 * Le opzioni **della singola riga**: le disponibili più l'unità che quella riga ha già scelto.
 *
 * ⚠️ `immobiliDisponibili` esclude tutti gli id già usati in `form.quote`, **compreso quello della
 * riga che si sta disegnando**: una riga non ancora salvata mostra la tendina, e la tendina non
 * contiene l'unità che quella riga aveva selezionato. Riaprendola, l'amministratore vede la propria
 * scelta sparita dall'elenco — che si legge come una selezione persa.
 *
 * Oggi si nota poco, perché le righe nuove si compilano una alla volta. Dopo un'associazione in
 * blocco sarebbero decine.
 */
const opzioniPerRiga = (riga: { immobile: Immobile | null }): Immobile[] =>
  riga.immobile ? [riga.immobile, ...immobiliDisponibili.value] : immobiliDisponibili.value;

/**
 * Quante unità dell'anagrafica non sono ancora state associate a questa tabella.
 *
 * Sta a video accanto al pulsante, e non solo dentro l'avviso: il limite di questa pagina si
 * capisce **prima** di sbatterci contro, non dopo.
 */
const unitaDisponibili = computed(() => immobiliDisponibili.value.length);

const urlAnagraficaUnita = computed(() =>
  route(generateRoute('gestionale.immobili.index'), { condominio: props.condominio.id })
);

/**
 * ⚠️ **Era un `<a href>`, e buttava via il lavoro senza chiedere niente.** Reperto della revisione
 * avversariale della beta.52.
 *
 * Questo modulo vive tutto nel client — `form.quote`, nessun salvataggio automatico, si scrive
 * solo al `submit`. Un amministratore che compila quaranta righe di millesimi, si accorge che
 * un'unità manca e preme il pulsante **che il dialogo stesso lo invita a premere** perdeva le
 * quaranta righe con un caricamento completo di pagina. E il tasto «indietro» del browser
 * rifaceva la richiesta al server, restituendo i valori vecchi: il lavoro non era nemmeno
 * recuperabile.
 *
 * Prima di questa beta dal dialogo si poteva solo chiudere, quindi il percorso non esisteva:
 * **è il collegamento nuovo ad aver reso raggiungibile la perdita**. È esattamente ciò che la
 * Fase 1-bis prescrive di chiedersi prima di aggiungere un comando dove non ce n'era uno.
 *
 * La guardia è quella già in uso nel progetto (`GirocontoNew.vue:251`), e la navigazione passa da
 * Inertia invece che dal browser.
 */
const vaiAlleUnita = () => {
  if (form.isDirty && !confirm('Uscire senza salvare? I millesimi inseriti andranno persi.')) return;
  router.visit(urlAnagraficaUnita.value);
};

/**
 * ⚠️ **Il messaggio di questa pagina è costato una segnalazione sul forum** (15/08/2026): un
 * amministratore con 67 unità si è fermato a 40 e ha chiesto «perché questo limite così
 * stringente?».
 *
 * Non c'era nessun limite. Il tetto è — ed è sempre stato — **il numero di unità immobiliari
 * presenti in anagrafica**, perché questa pagina non le crea, le associa soltanto: quel
 * condominio ne aveva 40 inserite. Ma il testo diceva «hai già raggiunto il numero massimo di
 * righe consentite», che si legge come un tetto imposto dal programma, e l'amministratore ha
 * concluso che il gestionale non reggesse il suo condominio.
 *
 * Il difetto non era il comportamento: era che il programma sapeva la ragione e non la diceva.
 *
 * ## Due correzioni, e la seconda non è cosmetica
 *
 * La guardia confrontava `form.quote.length` con `rawImmobili.length` — cioè **il numero di
 * righe** con il numero di unità. Coincidono finché ogni riga ha il suo immobile, ma una riga
 * appena aggiunta e non ancora compilata occupa un posto nel conteggio senza consumare nessuna
 * unità: con 40 unità, 39 righe piene più una vuota bloccavano l'aggiunta mentre un'unità era
 * ancora libera. La domanda giusta non è «quante righe ho», è «c'è ancora un'unità da
 * associare», e adesso è quella che si pone.
 *
 * Il testo, poi, viene dal file di lingua e non è più cablato qui: la chiave
 * `gestionale.tabelle_quote.max_rows_reached` **esisteva già in quattro lingue e non la usava
 * nessuno**, quindi un amministratore portoghese leggeva l'avviso in italiano.
 */
const addImmobile = () => {
  if (unitaDisponibili.value === 0) {
    alertMessage.value = trans('gestionale.tabelle_quote.max_rows_reached', {
      count: String(rawImmobili.length),
    });
    showNoImmobiliDialog.value = true;
    return;
  }

  // Una riga sola per tutti i tipi: vedi la nota sul modulo qui sopra.
  let nuovoImmobile: any = {};

  {
    nuovoImmobile = {
      chiave: prossimaChiave(),
      id: null,
      valore: "",
      immobile: null
    };
  }

  form.quote = [...form.quote, nuovoImmobile];
};

const removeImmobile = (index: number | string) => {
  form.quote.splice(Number(index), 1);
};

/* ─────────────────────────────────────────────────────────────────────────────
 * Ricerca e ordinamento: **si filtra la vista, mai l'elenco**.
 *
 * ⚠️ È il vincolo che decide tutto il resto, e non è una preferenza di stile. Questa pagina è un
 * modulo unico: `form.quote` è l'array che parte tutto insieme con un `put`, e il salvataggio
 * comincia a server con `whereNotIn('id', $idsPresenti)->delete()`. Se il filtro agisse
 * sull'array, le righe nascoste **uscirebbero dalla richiesta** e il server le leggerebbe come
 * «cancellate»: cercare un'unità e salvare distruggerebbe tutte le altre.
 *
 * Perciò il filtro produce **un elenco di posizioni visibili**, il `v-for` scorre quello, e ogni
 * riga resta legata al suo posto vero nell'array. Stessa cosa per l'ordinamento: si ordina la
 * vista, non i dati. Un test lo presidia — `QuoteList.test.ts`, «salvare con un filtro attivo non
 * perde nessuna riga».
 * ───────────────────────────────────────────────────────────────────────────── */

/** Accetta sia `1000.5` sia `1000,5`: il placeholder usa il punto, le tastiere italiane no. */
const parseValore = (v: unknown): number => {
  const n = parseFloat(String(v ?? "").replace(",", "."));
  return Number.isFinite(n) ? n : 0;
};

const ricerca = ref("");
const colonnaOrdinata = ref<"immobile" | "valore" | null>(null);
const versoOrdinamento = ref<"asc" | "desc">("asc");

/**
 * L'ordinamento per millesimi si fa su **un'istantanea dei valori**, non sui valori vivi.
 *
 * ⚠️ Senza, la colonna si riordina a ogni battuta: si clicca «Millesimi», si comincia a
 * correggere una cifra e la riga **scappa da sotto le dita** — al primo tasto va al suo nuovo
 * posto, la casella perde il fuoco e il resto del numero finisce nel vuoto. Misurato dalla
 * revisione avversariale della beta.61: ordinando 10-20-30-40-50 e battendo `999` sulla prima,
 * l'elenco diventa 2-3-4-5-1 e il fuoco passa al `body`. Ordinando per **immobile** non succede,
 * perché il nome non si può battere: è la dipendenza dal valore a farlo.
 *
 * L'istantanea si rinfresca quando si cambia colonna o verso, quando cambia la ricerca e quando
 * l'elenco cresce o si accorcia — cioè quando l'ordine **deve** cambiare — e mai mentre si
 * digita. Riordinare si ottiene ricliccando l'intestazione, che è un gesto voluto.
 */
const istantaneaValori = ref<Record<string, number>>({});

const scattaIstantanea = () => {
  const scatto: Record<string, number> = {};
  form.quote.forEach((q: any) => { scatto[q.chiave] = parseValore(q.valore); });
  istantaneaValori.value = scatto;
};

watch(
  [colonnaOrdinata, versoOrdinamento, ricerca, () => form.quote.length],
  scattaIstantanea,
  { immediate: true },
);

/** Il testo su cui la ricerca confronta: quello che la riga mostra a video, niente di nascosto. */
const testoDellaRiga = (immobile: Immobile | null): string =>
  [
    immobile?.nome,
    immobile?.interno,
    immobile?.piano,
    (immobile as any)?.palazzina?.name,
    (immobile as any)?.scala?.name,
    (immobile as any)?.tipologia_immobile?.nome,
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase();

/**
 * Le posizioni delle righe da disegnare, nell'ordine in cui vanno disegnate.
 *
 * Due regole che sembrano dettagli e non lo sono:
 *
 * 1. **Una riga senza unità non si nasconde mai.** Non ha niente su cui confrontare un testo, e
 *    soprattutto è quella che l'amministratore ha appena creato: farla sparire perché non
 *    corrisponde a una ricerca scritta prima è il modo più rapido di far credere che il pulsante
 *    non funzioni.
 * 2. **Le righe senza unità stanno in fondo**, sempre. Così il «+» dell'ultima riga sta davvero
 *    sull'ultima, e non salta a metà elenco quando si ordina per millesimi.
 */
const righeVisibili = computed(() => {
  const termine = ricerca.value.trim().toLowerCase();

  const tutte = form.quote.map((q: any, idx: number) => ({ q, idx }));

  const filtrate = termine === ""
    ? tutte
    : tutte.filter(({ q }: any) => !q.immobile || testoDellaRiga(q.immobile).includes(termine));

  const senzaUnita = filtrate.filter(({ q }: any) => !q.immobile);
  const conUnita = filtrate.filter(({ q }: any) => q.immobile);

  if (colonnaOrdinata.value !== null) {
    const segno = versoOrdinamento.value === "asc" ? 1 : -1;

    conUnita.sort((a: any, b: any) => {
      if (colonnaOrdinata.value === "valore") {
        const va = istantaneaValori.value[a.q.chiave] ?? parseValore(a.q.valore);
        const vb = istantaneaValori.value[b.q.chiave] ?? parseValore(b.q.valore);
        return (va - vb) * segno;
      }

      return String(a.q.immobile?.nome ?? "").localeCompare(
        String(b.q.immobile?.nome ?? ""),
        "it",
        { numeric: true, sensitivity: "base" },
      ) * segno;
    });
  }

  return [...conUnita, ...senzaUnita];
});

/** La posizione dell'ultima riga **a video**: è lì, e solo lì, che compare il «+». */
const ultimaPosizioneVisibile = computed(() => {
  const righe = righeVisibili.value;
  return righe.length > 0 ? righe[righe.length - 1].idx : null;
});

/** Quante righe la ricerca sta nascondendo: si dice, non si lascia indovinare. */
const righeNascoste = computed(() => form.quote.length - righeVisibili.value.length);

/**
 * Quanti errori del server stanno su righe che **la ricerca sta nascondendo**.
 *
 * ⚠️ Senza questo, il filtro produceva un vicolo cieco: si preme «Salva quote», il server rifiuta
 * per un valore sbagliato su una riga fuori dal filtro, e a video **non succede niente** — nessun
 * messaggio, nessun campo rosso, perché l'unico posto in cui l'errore compare è dentro la riga
 * (`InputError`), che non è disegnata. Il pulsante sembra rotto.
 *
 * La riga d'avviso dell'elenco vuoto non basta: quella compare solo con zero righe a video, e il
 * caso peggiore è quello in cui a video ne resta una e l'errore sta su un'altra.
 */
const erroriNascosti = computed(() => {
  const visibili = new Set(righeVisibili.value.map(({ idx }: any) => idx));
  const errori = form.errors as Record<string, string>;

  return Object.keys(errori).filter((chiave) => {
    const posizione = chiave.match(/^quote\.(\d+)\./);
    return posizione !== null && !visibili.has(Number(posizione[1]));
  }).length;
});

const ordinaPer = (colonna: "immobile" | "valore") => {
  if (colonnaOrdinata.value === colonna) {
    // Terzo clic: si torna all'ordine dell'elenco vero, che è un'informazione anche quello.
    if (versoOrdinamento.value === "desc") {
      colonnaOrdinata.value = null;
      versoOrdinamento.value = "asc";
      return;
    }
    versoOrdinamento.value = "desc";
    return;
  }

  colonnaOrdinata.value = colonna;
  versoOrdinamento.value = "asc";
};

/** Quante righe hanno davvero un'unità: il piè di pagina non deve contare quelle vuote. */
/* ─────────────────────────────────────────────────────────────────────────────
 * Associare in blocco.
 *
 * Nasce da una segnalazione sul forum: con 67 unità, associarle una per una significa 67 giri di
 * tendina. Misurato durante la ricognizione: settanta clic su «Aggiungi immobile» costano circa
 * dieci volte un assegnamento unico, perché ogni clic rifà l'intero array e ridisegna l'elenco.
 *
 * ⚠️ **I criteri offerti sono solo quelli che hanno dati in questo condominio.** Sui dati veri
 * `scala_id` è valorizzato su **zero** unità su 42, e `palazzina_id` solo in due condomìni su
 * sette: un elenco fisso di criteri mostrerebbe voci che non producono niente, e un criterio che
 * restituisce sempre un elenco vuoto insegna a non fidarsi del menu.
 *
 * ⚠️ **Le righe nascono senza valore**, ed è il motivo per cui questa funzione arriva solo adesso:
 * fino alla .60 il millesimo era obbligatorio, quindi una tabella riempita in blocco non si poteva
 * più salvare finché non la si compilava tutta. Ora si associa oggi e si compila domani — e se il
 * piano rate parte con dei millesimi ancora vuoti, la generazione si ferma e lo dice.
 * ───────────────────────────────────────────────────────────────────────────── */

const modaleBlocco = ref(false);
const criterioScelto = ref<"tutte" | "palazzina" | "scala" | "tipologia">("tutte");

/** Le unità non ancora associate: sono le sole su cui l'associazione in blocco ha senso. */
const unitaMancanti = computed<Immobile[]>(() => immobiliDisponibili.value);

/** Raggruppa le mancanti per una chiave, saltando quelle che non ce l'hanno. */
const raggruppa = (chiave: (i: any) => string | null): { nome: string; unita: Immobile[] }[] => {
  const gruppi = new Map<string, Immobile[]>();

  unitaMancanti.value.forEach((i: any) => {
    const nome = chiave(i);
    if (!nome) return;
    if (!gruppi.has(nome)) gruppi.set(nome, []);
    gruppi.get(nome)!.push(i);
  });

  return [...gruppi.entries()]
    .map(([nome, unita]) => ({ nome, unita }))
    .sort((a, b) => a.nome.localeCompare(b.nome, "it", { numeric: true }));
};

const perPalazzina = computed(() => raggruppa((i) => i.palazzina?.name ?? null));
const perScala = computed(() => raggruppa((i) => i.scala?.name ?? null));
const perTipologia = computed(() => raggruppa((i) => i.tipologia_immobile?.nome ?? null));

/**
 * I criteri da offrire, con il loro conto. Compaiono solo se producono almeno un gruppo: è la
 * differenza fra un menu che descrive questo condominio e uno che descrive il programma.
 */
const criteriDisponibili = computed(() => {
  const criteri: { chiave: "tutte" | "palazzina" | "scala" | "tipologia"; etichetta: string; icona: any; gruppi: { nome: string; unita: Immobile[] }[] }[] = [
    { chiave: "tutte", etichetta: "Tutte le unità mancanti", icona: Layers, gruppi: [] },
  ];

  if (perPalazzina.value.length > 0) criteri.push({ chiave: "palazzina", etichetta: "Per palazzina", icona: Building2, gruppi: perPalazzina.value });
  if (perScala.value.length > 0) criteri.push({ chiave: "scala", etichetta: "Per scala", icona: DoorOpen, gruppi: perScala.value });
  if (perTipologia.value.length > 0) criteri.push({ chiave: "tipologia", etichetta: "Per tipologia", icona: Shapes, gruppi: perTipologia.value });

  return criteri;
});

const gruppiDelCriterio = computed(
  () => criteriDisponibili.value.find((c) => c.chiave === criterioScelto.value)?.gruppi ?? [],
);

/* ── L'anteprima ───────────────────────────────────────────────────────────
 *
 * ⚠️ Il primo giro associava al clic sul gruppo, senza mostrare **quali** unità. «Abitazione 7»
 * dice quante, non quali — e su un criterio che l'amministratore non ha scelto lui (la tipologia
 * la decide l'anagrafica) è una differenza che conta: sette righe entrate per sbaglio si tolgono
 * una per una col cestino.
 *
 * Le caselle nascono tutte spuntate, così il caso normale resta un clic solo: si guarda, e se va
 * bene si conferma. Chi vuole escluderne una la toglie.
 */
const gruppoAperto = ref<string | null>(null);
const selezionate = ref<Set<number>>(new Set());

/** Le unità che l'anteprima sta mostrando: tutte le mancanti, o quelle del gruppo aperto. */
const unitaInAnteprima = computed<Immobile[]>(() => {
  if (criterioScelto.value === "tutte") return unitaMancanti.value;

  return gruppiDelCriterio.value.find((g) => g.nome === gruppoAperto.value)?.unita ?? [];
});

const anteprimaAperta = computed(
  () => criterioScelto.value === "tutte" || gruppoAperto.value !== null,
);

const spuntaTutte = () => {
  selezionate.value = new Set(unitaInAnteprima.value.map((i) => i.id));
};

const apriGruppo = (nome: string | null) => {
  gruppoAperto.value = nome;
  spuntaTutte();
};

const commuta = (id: number) => {
  const nuove = new Set(selezionate.value);
  nuove.has(id) ? nuove.delete(id) : nuove.add(id);
  selezionate.value = nuove;
};

const tutteSpuntate = computed(
  () => unitaInAnteprima.value.length > 0 && unitaInAnteprima.value.every((i) => selezionate.value.has(i.id)),
);

/** Il criterio cambia: si torna all'elenco dei gruppi, e per «tutte» l'anteprima è già lì. */
const scegliCriterio = (chiave: "tutte" | "palazzina" | "scala" | "tipologia") => {
  criterioScelto.value = chiave;
  gruppoAperto.value = null;
  if (chiave === "tutte") spuntaTutte();
};

/** Una riga di descrizione compatta, per riconoscere l'unità senza aprire l'anagrafica. */
const descriviUnita = (i: any): string =>
  [
    i.palazzina?.name ? `Palazzina ${i.palazzina.name}` : null,
    i.scala?.name ? `Scala ${i.scala.name}` : null,
    i.interno ? `Interno ${i.interno}` : null,
    i.tipologia_immobile?.nome ?? null,
  ].filter(Boolean).join(' · ') || '—';

const apriModaleBlocco = () => {
  if (unitaMancanti.value.length === 0) {
    alertMessage.value = trans('gestionale.tabelle_quote.max_rows_reached', {
      count: String(rawImmobili.length),
    });
    showNoImmobiliDialog.value = true;
    return;
  }

  criterioScelto.value = "tutte";
  gruppoAperto.value = null;
  spuntaTutte();
  modaleBlocco.value = true;
};

/**
 * Aggiunge in un colpo solo le unità indicate.
 *
 * ⚠️ Filtra su quelle **davvero mancanti**: a server non esiste nessuna regola `distinct`, e
 * l'unica difesa contro il doppione è l'indice unico `(tabella_id, immobile_id)`, che uscirebbe
 * come pagina di errore invece che come messaggio. Qui non può succedere, perché si parte da
 * `immobiliDisponibili`, ma il filtro resta: è la riga che rende la funzione sicura da riusare.
 */
const associaInBlocco = (unita: Immobile[]) => {
  const giaPresenti = new Set(form.quote.map((q: any) => q.immobile?.id).filter(Boolean));

  const nuove = unita
    .filter((i) => !giaPresenti.has(i.id))
    .map((i) => ({ chiave: prossimaChiave(), id: null, valore: "", immobile: i }));

  if (nuove.length === 0) return;

  // Un assegnamento solo: rifare l'array a ogni unità costa circa dieci volte tanto.
  form.quote = [...form.quote, ...nuove];
  modaleBlocco.value = false;

  // Una ricerca attiva nasconderebbe righe appena create che non corrispondono al filtro: dopo
  // un'associazione in blocco si vuole vedere cosa è entrato.
  ricerca.value = "";
};

const righeAssociate = computed(() => form.quote.filter((q: any) => q.immobile).length);

/**
 * Quante righe hanno un'unità e **non hanno ancora un valore**.
 *
 * ⚠️ Guarda il campo **vuoto**, non il valore ≤ 0: dalla beta.61 lo zero significa «non partecipa»
 * ed è legittimo — è così che sono fatte le tabelle parziali vere, l'ascensore senza i piani terra
 * o le scale senza i negozi con ingresso su strada. Contarlo qui vorrebbe dire chiedere di
 * compilare righe già decise, su nove tabelle su sedici.
 *
 * È la stessa distinzione che fa il server: `CalcoloQuoteService` avvisa sul NULL e tace sullo zero.
 */
const righeDaCompilare = computed(
  () => form.quote.filter((q: any) => q.immobile && String(q.valore ?? '').trim() === '').length,
);

// Funzione per generare placeholder dinamico in base al numero di decimali
const valorePlaceholder = (decimali: number) => {
  if (!decimali || decimali < 0) return "0";
  return "0." + "0".repeat(decimali);
};



/* ─────────────────────────────────────────────────────────────────────────────
 * Il totale a video mentre si digita.
 *
 * Fino alla beta.48 questa pagina non aveva **nessun** totale: si compilavano i millesimi riga
 * per riga e ci si accorgeva di un refuso al primo riparto, o mai — perché il motore normalizza
 * su `valore / sommaValori`, quindi una tabella che somma a 900 ripartisce comunque il 100%
 * della spesa facendola pagare agli altri, e nessun controllo contabile ha niente da segnalare.
 *
 * Il posto giusto per dirlo è questo: qui il refuso si corregge nel momento in cui lo si batte.
 *
 * ⚠️ **Il totale non giudica, e non è una svista.** Non c'è confronto con 1000 né con nessun
 * altro valore atteso: sui dati veri nove tabelle su quindici non sommano a 1000 e sono tutte
 * corrette — parziali, a parti uguali, o arrotondate dal tecnico e approvate così in assemblea.
 * Il numero che l'amministratore deve vedere lo sa lui; il gestionale glielo mostra e tace.
 *
 * *(Un confronto con un totale dichiarato per tabella è stato progettato e messo da parte
 * l'11/08/2026 — vedi `docs/validatore_coerenza_millesimi.md`.)*
 * ───────────────────────────────────────────────────────────────────────────── */

const decimali = computed(() => Math.max(0, props.tabella.numero_decimali ?? 2));

/** Le stesse righe che conta il motore: i valori a zero non partecipano. */
const totaleCorrente = computed(() => {
  const somma = form.quote.reduce((acc, q) => {
    const v = parseValore(q.valore);
    return v > 0 ? acc + v : acc;
  }, 0);

  // Arrotonda ai decimali della tabella: senza, 333,33 × 3 uscirebbe 999,9899999999999.
  return Number(somma.toFixed(decimali.value));
});

/** Formatta con i decimali della tabella, senza zeri inutili: 1000 e non 1000,00. */
/**
 * Il totale si scrive **come i valori che somma**: stessi decimali, stesso separatore, nessun
 * raggruppamento delle migliaia.
 *
 * Niente `Intl.NumberFormat`, che qui produrrebbe la virgola e reintrodurrebbe due convenzioni
 * nella stessa colonna. Il punto delle migliaia sarebbe pure peggio: `1.200,00` sotto una
 * colonna di `500.00` è la stessa ambiguità che ha fatto leggere `333.33333` come
 * `333.333,33`.
 */
const formattaValore = (n: number) => n.toFixed(decimali.value);

const submit = () => {
  // Nessuna conversione prima di partire: le caselle tengono già il numero nella forma che il
  // server valida (`numeric`, quindi punto decimale). È il vantaggio pratico di aver scelto il
  // punto — quello che si vede è quello che si salva.
  //
  // Una virgola battuta per abitudine viene normalizzata all'uscita dal campo, quindi non
  // arriva fin qui; e se il campo non viene mai lasciato, `numeric` la rifiuta con il suo
  // messaggio invece di far passare un valore ambiguo.
  form.put(
    route("admin.gestionale.tabelle.quote.update", {
      condominio: props.condominio.id,
      tabella: props.tabella.id,
    }),
    { preserveScroll: true }
  );
};

</script>

<template>
  <Head title="Millesimi tabella" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Associazione millesimi"
        :page-subtitle="`Gestione delle quote per la tabella: ${props.tabella.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })"
        back-text="Torna alle tabelle"
        :has-text-guide="true"
        text-guide-title="Guida"
        @open-text-guide="showGuide = true"
      >
      </PageHeaderGuide>

      <form @submit.prevent="submit" class="space-y-6">
        
        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <!--
            ⚠️ `flex-wrap` e non `flex-row` secco: a 375 px il contatore più il pulsante non ci
            stanno accanto al titolo, e **la pagina intera scorreva in orizzontale** — misurato,
            420 px di contenuto in un viewport da 375. Il difetto era già lì, e non nella tabella
            (che scorre dentro il proprio contenitore, come deve): lo produceva questa riga.
          -->
          <CardHeader class="pb-3 border-b border-dashed mb-0 flex flex-row flex-wrap items-center justify-between gap-y-3">
            <div class="space-y-1">
              <CardTitle class="text-base font-semibold">Elenco Unità Associate</CardTitle>
              <CardDescription>Specifica i valori millesimali per ogni unità immobiliare.</CardDescription>
            </div>
            <!--
              Il contatore sta **accanto al pulsante e non dentro l'avviso**: il limite di questa
              pagina — una riga per ogni unità presente in anagrafica — si deve capire prima di
              sbatterci contro. Un avviso che arriva al clic spiega un blocco già avvenuto; un
              numero a vista lo previene.

              ⚠️ **Il pulsante resta attivo anche a zero, ed è una scelta.** Disabilitarlo era la
              prima idea e sembrava più pulita, ma lascia l'amministratore davanti a un pulsante
              spento senza dirgli dove si rimedia — e rende irraggiungibile il messaggio che
              spiega la ragione, cioè proprio ciò che mancava a chi ha aperto la segnalazione.
              Il contatore previene il clic inutile; il dialogo, quando il clic arriva lo stesso,
              spiega e porta all'anagrafica.
            -->
            <!--
              La ricerca sta **accanto ai comandi**, non su una riga propria: è un comando anche
              lei, e una fascia dedicata la faceva sembrare un filtro di pagina invece che uno
              strumento di questo elenco. La nota sulle righe nascoste le sta **sotto**, allineata
              al campo che la produce, così la colonna dei comandi non cambia altezza quando
              compare — e la frase si legge come una conseguenza di ciò che si è appena scritto.

              ⚠️ Compare **solo oltre le otto righe**: sotto quella soglia si legge tutto a colpo
              d'occhio e una casella in più sarebbe rumore. Chi ha 67 unità la trova subito, chi ne
              ha cinque non la vede mai.
            -->
            <div class="flex flex-col items-start gap-1.5">
              <div class="flex flex-wrap items-center gap-3">
                <!--
                  ⚠️ `|| ricerca !== ''`: la soglia decide **quando offrire** la ricerca, non
                  quando toglierla di mano. Cancellando righe con un filtro acceso si poteva
                  scendere sotto la soglia — la casella spariva, il filtro restava, e restava un
                  elenco vuoto senza nessun comando per spegnerlo. La casella se ne va solo quando
                  non sta filtrando niente.
                -->
                <div v-if="form.quote.length > 8 || ricerca !== ''" class="flex flex-col gap-1.5">
                  <div class="relative w-56">
                    <!-- Stesso grigio della nota qui sotto: a `text-slate-400` la lente misurava 2,56 in chiaro. -->
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-500 dark:text-slate-400" />
                    <Input
                      v-model="ricerca"
                      type="search"
                      placeholder="Cerca unità…"
                      class="h-8 bg-white pl-9 text-sm dark:bg-slate-950"
                    />
                    <button
                      v-if="ricerca !== ''"
                      type="button"
                      title="Annulla la ricerca"
                      @click="ricerca = ''"
                      class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100"
                    >
                      <X class="h-3.5 w-3.5" />
                    </button>
                  </div>

                  <!--
                    La nota sta **dentro il contenitore della ricerca**, non in fondo alla colonna
                    dei comandi: a 375 px i tre comandi vanno a capo uno per riga, e una nota
                    appesa in fondo si sarebbe letta come commento del pulsante «Aggiungi
                    immobile» invece che del campo che la produce.
                  -->
                  <p v-if="righeNascoste > 0" class="text-[11px] leading-snug text-slate-500 dark:text-slate-400">
                    <span class="tabular-nums">{{ righeNascoste }}</span>
                    {{ righeNascoste === 1 ? 'riga nascosta dalla ricerca' : 'righe nascoste dalla ricerca' }}
                    — restano nel salvataggio.
                  </p>
                </div>

                <!--
                  ⚠️ **Il `Badge` a pillola stonava accanto al pulsante**, e la ragione è che erano
                  due linguaggi diversi affiancati: pillola tonda con testo minuscolo contro
                  rettangolo squadrato con maiuscoletto spaziato. Segnalato da Vincenzo guardando
                  la pagina.

                  Qui la geometria è quella del pulsante — stessa altezza, stesso `rounded-md`,
                  stesso `text-[10px]` maiuscoletto spaziato — così i due oggetti si leggono come
                  una coppia. A distinguerli è il **bordo tratteggiato e l'assenza di ombra**, che
                  su questa pagina significano già «valore calcolato, non cliccabile»: è
                  esattamente il trattamento della casella del totale in fondo all'elenco.

                  La stessa forma per entrambi gli stati: cambia solo l'icona, verde quando non
                  resta niente da fare. Due forme diverse per due stati dello stesso dato
                  farebbero saltare l'occhio a ogni ricalcolo.
                -->
                <div
                  class="inline-flex h-8 items-center gap-2 rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400"
                >
                  <template v-if="unitaDisponibili > 0">
                  <Home class="w-3 h-3 shrink-0" />
                  <!-- «unità» è invariabile: nessun plurale da gestire, a differenza della riga del totale. -->
                  <span class="tabular-nums">{{ unitaDisponibili }} da associare</span>
                  </template>
                  <!--
                  ⚠️ **«Tutte associate» non significa «finita».** Con la casella «associa tutti gli
                  immobili esistenti» — e ora con l'associazione in blocco — si arriva a zero unità
                  libere avendo tutte le caselle dei millesimi vuote: la pagina si dichiarava finita
                  mentre non lo era, e l'unico segnale era un totale a zero senza commento.
                  Associare e compilare sono due lavori diversi, e questo contatore ne segue uno
                  solo: quando il primo è finito passa a raccontare il secondo.
                  -->
                  <template v-else-if="righeDaCompilare > 0">
                  <Hash class="w-3 h-3 shrink-0" />
                  <span class="tabular-nums">{{ righeDaCompilare }} da compilare</span>
                  </template>
                  <template v-else>
                  <Check class="w-3 h-3 shrink-0 text-emerald-600 dark:text-emerald-500" />
                  <span>Tutte associate</span>
                  </template>
                </div>

                <!--
                  «Aggiungi immobile» aggiunge **una** riga; questo ne aggiunge molte. Stanno
                  accanto perché sono lo stesso gesto a due scale, e il secondo esiste per la
                  segnalazione del forum: con 67 unità, associarle una per una vuol dire 67 giri
                  di tendina.
                -->
                <Button
                  type="button"
                  @click="apriModaleBlocco"
                  class="h-8 px-4 text-[10px] font-bold uppercase tracking-widest gap-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 shadow-sm"
                >
                  <Layers class="w-3.5 h-3.5" />
                  Associa in blocco
                </Button>

                <Button
                  type="button"
                  @click="addImmobile"
                  class="h-8 px-4 text-[10px] font-bold uppercase tracking-widest gap-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 shadow-sm"
                >
                  <Plus class="w-3.5 h-3.5" />
                  Aggiungi immobile
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent class="p-0">
            <div class="overflow-x-auto">
              <Table>
                
                <TableHeader class="bg-slate-50/50 dark:bg-slate-900/50">
                  <TableRow>
                    <!--
                      L'intestazione è cliccabile e lo dice: la freccia compare solo sulla colonna
                      ordinata, e il terzo clic riporta all'ordine dell'elenco vero. Un ordinamento
                      che non si può togliere nasconde l'informazione «in che ordine stanno
                      davvero», che su una tabella millesimale conta.
                    -->
                    <TableHead class="w-[500px] p-0">
                      <button
                        type="button"
                        @click="ordinaPer('immobile')"
                        :class="[
                          'flex h-full w-full items-center gap-1.5 px-4 py-3 text-left hover:text-slate-900 dark:hover:text-slate-100',
                          colonnaOrdinata === 'immobile' ? 'font-semibold text-slate-900 dark:text-slate-100' : '',
                        ]"
                      >
                        Immobile
                        <ArrowUp v-if="colonnaOrdinata === 'immobile' && versoOrdinamento === 'asc'" class="h-3 w-3" />
                        <ArrowDown v-else-if="colonnaOrdinata === 'immobile'" class="h-3 w-3" />
                        <ArrowUpDown v-else class="h-3 w-3" />
                      </button>
                    </TableHead>
                    <TableHead class="p-0">
                      <button
                        type="button"
                        @click="ordinaPer('valore')"
                        :class="[
                          'flex h-full w-full items-center gap-1.5 px-4 py-3 text-left hover:text-slate-900 dark:hover:text-slate-100',
                          colonnaOrdinata === 'valore' ? 'font-semibold text-slate-900 dark:text-slate-100' : '',
                        ]"
                      >
                        {{ props.tabella.quota.charAt(0).toUpperCase() + props.tabella.quota.slice(1) }}
                        <ArrowUp v-if="colonnaOrdinata === 'valore' && versoOrdinamento === 'asc'" class="h-3 w-3" />
                        <ArrowDown v-else-if="colonnaOrdinata === 'valore'" class="h-3 w-3" />
                        <ArrowUpDown v-else class="h-3 w-3" />
                      </button>
                    </TableHead>

                    <TableHead class="text-center w-[110px]">Azioni</TableHead>
                  </TableRow>
                </TableHeader>

                <TableBody>
                  <!--
                    ⚠️ Si scorre `righeVisibili`, che porta con sé **la posizione vera** di ogni riga
                    (`idx`): il filtro e l'ordinamento cambiano ciò che si vede, mai `form.quote`.
                    È quell'array a partire con il `put`, e a server il salvataggio comincia
                    cancellando le righe non presenti nella richiesta.
                  -->
                  <TableRow v-for="{ q, idx } in righeVisibili" :key="q.chiave" class="border-b border-dashed last:border-0">

                    <!-- Immobile -->
                    <TableCell>
                      <!-- Mostra come testo se l'immobile è già associato (ha un ID) -->
                      <div v-if="q.id && q.immobile">
                        <div class="font-medium">{{ q.immobile?.nome ?? '—' }}</div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400">
                          Palazzina: {{ q.immobile?.palazzina?.name ?? "—" }} |
                          Scala: {{ q.immobile?.scala?.name ?? "—" }} |
                          Interno: {{ q.immobile?.interno ?? "—" }} |
                          Piano: {{ q.immobile?.piano ?? "—" }} |
                          Sup: {{ q.immobile?.superficie ?? "—" }} m²
                        </div>
                      </div>
                      
                      <!-- Mostra dropdown per selezionare un nuovo immobile -->
                      <div v-else>
                        <v-select
                          class="w-full vs--wide-dropdown bg-white dark:bg-slate-950 text-sm"
                          :options="opzioniPerRiga(q)"
                          v-model="q.immobile"
                          append-to-body
                          placeholder="Seleziona immobile"
                          :reduce="(i: Immobile) => i"
                          :value="q.immobile"
                          @input="(value: Immobile) => { q.immobile = value }"
                          label="nome"
                          :getOptionLabel="(option: Immobile) => option.nome"
                        >
                          <!-- Template per le opzioni nel dropdown -->
                          <template #option="option">
                            <div class="flex flex-col py-2">
                              <span class="font-medium">{{ option.nome }}</span>
                              <span class="text-xs text-gray-500 mt-1">
                                Palazzina: {{ option.palazzina?.name ?? "—" }} |
                                Scala: {{ option.scala?.name ?? "—" }} |
                                Interno: {{ option.interno ?? "—" }} |
                                Piano: {{ option.piano ?? "—" }} |
                                Sup: {{ option.superficie ?? "—" }} m²
                              </span>
                            </div>
                          </template>

                          <!-- Template per l'opzione selezionata -->
                          <template #selected-option="option">
                            <div v-if="option" class="flex flex-col">
                              <span class="font-medium">{{ option.nome }}</span>
                            </div>
                            <div v-else class="text-gray-400">Seleziona immobile</div>
                          </template>
                        </v-select>
                        <InputError :message="(form.errors as Record<string, string>)[`quote.${idx}.immobile.id`]" />
                      </div>
                    </TableCell>

                    <!-- Millesimi -->
                    <TableCell>
                      <!--
                        ⚠️ **`:model-value` e non `v-model`, ed è una correzione della revisione
                        avversariale.** `Input.vue` usa `useVModel(..., { passive: true })`:
                        l'emissione verso il padre passa da un `watch` e arriva **dopo** il gestore
                        `@input`. Con `v-model`, `onInputValore` ripuliva la casella e scriveva il
                        valore filtrato, e un istante dopo il modello veniva risovrascritto col
                        testo grezzo. Battendo lettere si otteneva una casella **visibilmente
                        vuota** con dentro `"abc"` — cioè proprio il segnale che questa beta
                        introduce, «vuoto = da compilare», falsificato dal lato client: il contatore
                        continuava a dire «Tutte associate» e il salvataggio falliva su un campo che
                        sembrava bianco.
                        Con `:model-value` il gestore resta l'unico che scrive.
                      -->
                      <Input
                        :model-value="q.valore"
                        class="w-28 bg-white dark:bg-slate-950"
                        inputmode="decimal"
                        :placeholder="valorePlaceholder(props.tabella.numero_decimali)"
                        @input="onInputValore($event, q)"
                        @blur="q.valore = normalizzaAllaPrecisione(q.valore)"
                      />
                      <InputError :message="(form.errors as Record<string, string>)[`quote.${idx}.valore`]" />
                    </TableCell>



                    <!--
                      Azioni. Il «+» compare **solo sull'ultima riga**, accanto al cestino.

                      Nasce da una segnalazione sul forum (15/08/2026): con 67 unità, ogni riga
                      aggiunta costringeva a risalire in cima alla pagina per premere «Aggiungi
                      immobile», scendere di nuovo e compilare. Il pulsante in alto resta — è da lì
                      che si comincia, ed è lì che sta anche l'associazione in blocco — ma la riga
                      successiva si chiede da dove si sta lavorando.

                      Solo sull'ultima, e non su tutte: un «+» per riga sarebbe un comando che
                      significa la stessa cosa ripetuto quaranta volte, e non è chiaro se aggiunga
                      *sotto quella riga* o in fondo. Uno solo, in fondo, non ha ambiguità.
                    -->
                    <TableCell class="text-center">
                      <!--
                        ⚠️ **Il «+» sta prima del cestino, e i due sono staccati.** Nella prima
                        stesura erano nell'ordine opposto e separati da 2 px: un comando che si
                        preme a ripetizione — si compila una riga, se ne aggiunge un'altra —
                        appiccicato a uno che cancella. Segnalato da Vincenzo guardando la pagina.
                        Il cestino resta **sempre nella stessa colonna**, anche sulle righe che il
                        «+» non ce l'hanno: è il segnaposto qui sotto a tenergli il posto, così la
                        mano non deve ritararsi riga per riga.
                      -->
                      <div class="flex items-center justify-center gap-2">
                        <Button
                          v-if="idx === ultimaPosizioneVisibile"
                          size="icon"
                          variant="ghost"
                          type="button"
                          title="Aggiungi una riga"
                          @click="addImmobile"
                          class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                        >
                          <Plus class="h-4 w-4" />
                        </Button>
                        <span v-else class="inline-block h-9 w-9" aria-hidden="true"></span>

                        <!--
                          ⚠️ **`type="button"`, e non è pignoleria.** Dentro un `<form>` un
                          `<button>` senza tipo vale `submit`: il cestino toglieva la riga **e
                          faceva partire il salvataggio dell'intera tabella**, senza che nessuno
                          avesse premuto «Salva quote». A server quel salvataggio comincia con
                          `whereNotIn(...)->delete()`, quindi la cancellazione diventava definitiva
                          all'istante.
                          Il difetto è più vecchio di questa beta, ma fino alla .60 aveva un freno
                          per caso: con `quote.*.valore` obbligatorio, una tabella con una riga
                          ancora vuota faceva fallire quel salvataggio involontario. Rendendo il
                          valore facoltativo il freno è saltato, quindi il difetto è di questa
                          beta anche se il codice non lo è.
                        -->
                        <Button type="button" size="icon" variant="ghost" @click="removeImmobile(idx)" class="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/50">
                          <Trash2 class="h-4 w-4" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>

                  <!--
                    Un elenco che si svuota senza dire perché si legge come dati persi. Qui la
                    riga dice che cosa è successo e offre il comando per rimediare — che è anche
                    l'unico posto in cui compare, visto che con zero righe a video non c'è nessuna
                    «ultima riga» a cui appendere il «+».
                  -->
                  <TableRow v-if="righeVisibili.length === 0 && form.quote.length > 0" class="hover:bg-transparent">
                    <TableCell colspan="3" class="py-8 text-center">
                      <p class="text-sm text-slate-600 dark:text-slate-400">
                        Nessuna unità corrisponde a «{{ ricerca }}».
                      </p>
                      <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                        <span class="tabular-nums">{{ form.quote.length }}</span>
                        {{ form.quote.length === 1 ? 'riga è nascosta' : 'righe sono nascoste' }} dalla ricerca, e
                        {{ form.quote.length === 1 ? 'resta' : 'restano' }} nel salvataggio.
                      </p>
                      <Button
                        type="button"
                        variant="ghost"
                        class="mt-3 h-8 px-3 text-[10px] font-bold uppercase tracking-widest"
                        @click="ricerca = ''"
                      >
                        Annulla la ricerca
                      </Button>
                    </TableCell>
                  </TableRow>

                </TableBody>

                <!--
                  Il totale sta in un `<tfoot>` vero, non in una riga in fondo al corpo: è
                  un'informazione sulla tabella, non un'altra unità immobiliare, e il tag lo dice
                  anche a chi legge con uno screen reader.

                  Si somma sulle righe a schermo e non si chiede al server: deve muoversi mentre
                  si digita, o non serve a niente. E non blocca il salvataggio — una tabella si
                  compila una riga per volta, e le righe intermedie per definizione non tornano.
                -->
                <TableFooter v-if="form.quote.length">
                  <TableRow class="hover:bg-transparent">
                    <!-- Stessa struttura delle righe sopra: etichetta e sotto-riga di contesto. -->
                    <TableCell>
                      <div class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
                        Totale
                      </div>
                      <!--
                        ⚠️ Contava `form.quote.length`, cioè **le righe**, comprese quelle vuote: la
                        stessa schermata poteva dire «70 unità associate» in fondo e «70 da
                        associare» in cima. Qui si contano le righe che hanno davvero un'unità, e
                        quelle ancora da compilare si dicono a parte.
                      -->
                      <div class="text-[11px] text-slate-500 dark:text-slate-400 font-normal">
                        <span class="tabular-nums">{{ righeAssociate }}</span>
                        {{ righeAssociate === 1 ? 'unità associata' : 'unità associate' }}
                        <template v-if="righeDaCompilare > 0">
                          · <span class="tabular-nums">{{ righeDaCompilare }}</span> da compilare
                        </template>
                      </div>
                    </TableCell>

                    <!--
                      Stessa geometria delle caselle della colonna (`h-9 w-28 rounded-md px-3`),
                      così il numero cade esattamente sotto i valori invece di galleggiare a
                      sinistra. Ma **non è un `<input>`**, ed è deliberato: il totale è calcolato,
                      non digitabile, e una casella disabilitata si legge come «potresti
                      modificarlo, ma adesso no». Il bordo tratteggiato e il fondo pieno dicono
                      «stessa colonna, altro mestiere».
                    -->
                    <TableCell>
                      <div
                        class="inline-flex h-9 w-28 items-center rounded-md border border-dashed border-slate-300 bg-slate-100/70 px-3 text-sm font-bold text-slate-900 tabular-nums dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-100"
                      >
                        {{ formattaValore(totaleCorrente) }}
                      </div>
                    </TableCell>

                    <TableCell></TableCell>
                  </TableRow>
                </TableFooter>

              </Table>
            </div>
          </CardContent>
        </Card>

        <!--
          L'avviso sta **accanto al pulsante che sembra rotto**, non in cima alla pagina: è lì che
          si guarda dopo aver premuto «Salva quote» e non essere successo niente.
        -->
        <div v-if="erroriNascosti > 0" class="flex items-center justify-end gap-3">
          <p class="text-[11px] text-amber-700 dark:text-amber-500">
            <span class="tabular-nums">{{ erroriNascosti }}</span>
            {{ erroriNascosti === 1 ? 'riga da correggere è nascosta' : 'righe da correggere sono nascoste' }}
            dalla ricerca: il salvataggio non può andare a buon fine finché non {{ erroriNascosti === 1 ? 'la sistemi' : 'le sistemi' }}.
          </p>
          <Button type="button" variant="ghost" class="h-8 px-3 text-[10px] font-bold uppercase tracking-widest" @click="ricerca = ''">
            Mostra tutte
          </Button>
        </div>

        <div class="flex items-center justify-end gap-3">
          <Link
            :href="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })"
            class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-6 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
          >
            Annulla
          </Link>

          <Button 
            type="submit"
            :disabled="form.processing" 
            class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
          >
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            <Plus v-else class="h-3.5 w-3.5" />
            Salva Quote
          </Button>
        </div>

      </form>
    </div>
  </GestionaleLayout>

  <!--
    L'avviso quando non resta nessuna unità da associare.

    Il titolo non è più «Attenzione»: non è successo niente di preoccupante, e chiamare
    attenzione su un fatto normale è metà del motivo per cui la segnalazione è arrivata sul
    forum. Il dialogo dice cosa manca e **dove si rimedia**, con il collegamento all'anagrafica:
    l'amministratore che sbatte qui deve poter uscire dalla pagina che gli sta dicendo di no.
  -->

  <!--
    La modale dell'associazione in blocco.

    ⚠️ **Non chiede il millesimo.** Sarebbe stata la scelta prudente — righe già valorizzate, e
    nessuna tabella incompleta — ma avrebbe preteso un numero nel momento in cui l'amministratore
    quel numero non ce l'ha ancora: si associa aprendo la planimetria, i millesimi arrivano dal
    tecnico. Le righe nascono vuote, la pagina dice quante ne restano da compilare, e la
    generazione del piano rate si ferma se qualcuna è ancora tale.
  -->
  <Dialog v-model:open="modaleBlocco">
    <DialogContent class="sm:max-w-[560px]">
      <DialogHeader>
        <DialogTitle>Associa più unità insieme</DialogTitle>
        <DialogDescription>
          Le unità entrano nell'elenco <strong>senza millesimo</strong>: i valori si compilano dopo,
          anche in più sedute.
        </DialogDescription>
      </DialogHeader>

      <!-- I criteri: compaiono solo quelli che in questo condominio producono qualcosa. -->
      <div class="flex flex-wrap gap-2">
        <Button
          v-for="criterio in criteriDisponibili"
          :key="criterio.chiave"
          type="button"
          variant="ghost"
          :class="[
            'h-8 gap-2 border px-3 text-[10px] font-bold uppercase tracking-widest',
            criterioScelto === criterio.chiave
              ? 'border-slate-900 bg-slate-900 text-white hover:bg-slate-900 hover:text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900'
              : 'border-slate-200 text-slate-600 dark:border-slate-800 dark:text-slate-400',
          ]"
          @click="scegliCriterio(criterio.chiave)"
        >
          <component :is="criterio.icona" class="h-3.5 w-3.5" />
          {{ criterio.etichetta }}
        </Button>
      </div>

      <!-- I gruppi, quando il criterio ne ha: si sceglie quale guardare. -->
      <div v-if="!anteprimaAperta" class="max-h-72 space-y-1.5 overflow-y-auto">
        <button
          v-for="gruppo in gruppiDelCriterio"
          :key="gruppo.nome"
          type="button"
          class="flex w-full items-center justify-between rounded-md border border-dashed px-4 py-3 text-left hover:bg-slate-50 dark:hover:bg-slate-900/50"
          @click="apriGruppo(gruppo.nome)"
        >
          <span class="text-sm font-medium">{{ gruppo.nome }}</span>
          <span class="text-[11px] font-bold uppercase tracking-widest text-slate-500 tabular-nums dark:text-slate-400">
            {{ gruppo.unita.length }} da associare
          </span>
        </button>
      </div>

      <!--
        L'anteprima. ⚠️ Le caselle nascono **tutte spuntate**: il caso normale resta un clic
        solo — si guarda e si conferma — e chi vuole escluderne una la toglie.
      -->
      <template v-else>
        <div class="flex items-center justify-between border-b border-dashed pb-2">
          <button
            v-if="gruppoAperto !== null"
            type="button"
            class="text-[11px] font-bold uppercase tracking-widest text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
            @click="gruppoAperto = null"
          >
            ‹ Tutti i gruppi
          </button>
          <span v-else class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
            {{ unitaInAnteprima.length }} da associare
          </span>

          <button
            type="button"
            class="text-[11px] font-bold uppercase tracking-widest text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
            @click="tutteSpuntate ? (selezionate = new Set()) : spuntaTutte()"
          >
            {{ tutteSpuntate ? 'Deseleziona tutte' : 'Seleziona tutte' }}
          </button>
        </div>

        <div class="max-h-64 space-y-0.5 overflow-y-auto">
          <label
            v-for="u in unitaInAnteprima"
            :key="u.id"
            class="flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-900/50"
          >
            <Checkbox :model-value="selezionate.has(u.id)" @update:model-value="commuta(u.id)" />
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-medium">{{ u.nome }}</span>
              <span class="block text-[11px] text-slate-500 dark:text-slate-400">{{ descriviUnita(u) }}</span>
            </span>
          </label>
        </div>
      </template>

      <DialogFooter>
        <Button type="button" variant="ghost" class="h-9 px-6 text-[10px] font-bold uppercase tracking-widest" @click="modaleBlocco = false">
          Annulla
        </Button>
        <Button
          v-if="anteprimaAperta"
          type="button"
          :disabled="selezionate.size === 0"
          class="h-9 px-6 text-[10px] font-bold uppercase tracking-widest gap-2"
          @click="associaInBlocco(unitaInAnteprima.filter((u) => selezionate.has(u.id)))"
        >
          <Plus class="h-3.5 w-3.5" />
          Associa {{ selezionate.size }} {{ selezionate.size === 1 ? 'unità' : 'unità' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <QuoteMillesimiGuide v-model:open="showGuide" />

  <AlertDialog v-model:open="showNoImmobiliDialog">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Non ci sono altre unità da associare</AlertDialogTitle>
        <AlertDialogDescription>
          {{ alertMessage }}
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <!--
          ⚠️ **`Button variant="outline"` e non lo stile in maiuscoletto della pagina.** La prima
          stesura aveva copiato il trattamento del pulsante «Aggiungi immobile» —
          `text-[10px] uppercase tracking-widest` — e accanto ad «Chiudi», che è
          `AlertDialogCancel` in tondo, i due si leggevano come due linguaggi diversi.

          La convenzione dei footer delle modali in questo progetto è il `Button` standard in
          tondo: «Annulla» / «Conferma rifiuto» in `Dashboard.vue`, «Annulla» / «Continua» in
          `ConfirmDialog.vue`. Il maiuscoletto è il vocabolario delle **azioni di pagina**, non
          delle modali — e questo era l'unico pulsante del progetto che lo portava dentro una.
        -->
        <Button type="button" variant="outline" @click="vaiAlleUnita">
          Vai alle unità immobiliari
        </Button>
        <AlertDialogCancel>Chiudi</AlertDialogCancel>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

</template>

<style src="vue-select/dist/vue-select.css"></style>
