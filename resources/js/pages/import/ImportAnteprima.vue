<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * S4 — «Ecco cosa sto per scrivere».
 *
 * L'ordine delle sezioni è la parte che conta: **chi possiede cosa viene prima dei numeri**,
 * perché è la cosa che rende una migrazione utile o inutile. Un'unità senza titolare non riceve
 * rate, non compare in morosità e non entra in nessun riparto — e il concorrente, dopo dodici
 * livelli importati, lascia le unità con proprietario e inquilino vuoti senza dirlo a nessuno.
 *
 * Subito dopo il riquadro della **quadratura**: il totale non l'abbiamo chiesto, l'abbiamo letto
 * nel file che l'amministratore ha appena caricato.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ImportGuide from '@/components/guides/ImportGuide.vue';
import { guideImport } from '@/pages/import/intestazione';
import BadgeStato from '@/components/import/BadgeStato.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import Alert from '@/components/Alert.vue';
import { Lock, CircleAlert, Users, Building2, Table2, Wallet, CheckCircle2, AlertTriangle } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

const props = defineProps<{
  lotto: { uuid: string; stato: string };
  anteprima: {
    condominio: null | {
      nome: string; codice_fiscale: string | null; indirizzo: string;
      esercizio: null | { etichetta: string; dal: string; al: string; solare: boolean };
    };
    collisioni: {
      totale: number;
      voci: {
        chiave_decisione: string; cosa: string; nome: string;
        esistente: string; motivo: string; scelte: string[];
        /**
         * Il condominio che c'è già, e chi importa non ha «Modifica condomini»: la scelta non si offre (la conferma la rifiuterebbe). La
         * persona che c'è già, e chi importa non ha «Modifica anagrafiche»: resta solo «Lascia com'è» (`scelte` lo dice).
         */
        senza_permesso?: boolean;
      }[];
    };
    titolarita: {
      totale: number; unita_coinvolte: number; unita_senza_titolare: string[];
      elenco: { unita: string; persona: string; ruolo: string; nota: string | null }[];
    };
    persone: {
      totale: number; senza_codice_fiscale: number;
      da_dividere: { chiave: string; nome: string; pezzi: string[] }[];
    };
    unita: { totale: number; elenco: { chiave: string; nome: string }[] };
    tabelle: { nome: string; partecipanti: number; somma: number; parziale: boolean; parti_uguali: boolean }[];
    saldi: null | {
      righe: number; totale_riferimento: string | null; somma_righe: string;
      arrotondamenti: string; scarto: string | null; quadra: boolean;
      /** Da dove vengono le righe: «riparto consuntivo», «foglio dei saldi». */
      fonte: string;
      /** C'è un totale con cui confrontarsi? Senza, non c'è né verde né rosso: non c'è giudizio. */
      verificabile: boolean;
      da_titolari_cessati: number; importo_cessati: string;
      /** Quanti saldi dichiarano l'esercizio d'origine, e quali esercizi scritti non sono quello del file (si cercano in archivio). */
      con_esercizio_origine?: number; esercizi_origine_da_cercare?: string[];
    };
    totale_record: number;
  };
  decisioni: Record<string, string>;
  confermabile: boolean;
  /**
   * Coda 759 (1) e (2), decisione 707 (2): perché i saldi non entrerebbero (`motivi`, con la strada per correggerlo), se si può
   * importare il resto senza (`possibile`: i saldi sono l'unica cosa che ferma la conferma) e che cosa non entra in quel caso.
   */
  senza_saldi: {
    possibile: boolean;
    motivi: { codice: string; motivo: string; strada: string }[];
    non_entra: { cosa: string; dettaglio: string }[];
  };
  /** Coda 646, decisione 707 (4): gli esercizi che resteranno aperti dopo la conferma, quando sono più d'uno. */
  esercizi_aperti: null | { nomi: string[]; piu_recente: string; titolo: string; testo: string };
}>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const mostraGuida = ref(false);

const headerBreadcrumbs = [{ title: 'Importa dati', href: route('import.index') }, { title: 'Verifica', href: route('import.verifica', props.lotto.uuid) }, { title: 'Conferma' }];

function decidi(chiave: string, scelta: string) {
  router.put(route('import.decisione', props.lotto.uuid), { chiave, scelta }, { preserveScroll: true });
}

// La conferma parte una volta sola: mentre la richiesta è in corso i due pulsanti sono spenti. Un doppio clic mandava due conferme, e la
// seconda, su un'importazione appena entrata, la riportava a metà (il server ora la rimanda all'esito, e due conferme dello stesso lotto
// non lavorano insieme; qui non si manda nemmeno).
const inviando = ref(false);
const mentreInvia = {
  onStart: () => { inviando.value = true; },
  onFinish: () => { inviando.value = false; },
};

function conferma() {
  if (inviando.value) return;
  router.post(route('import.conferma', props.lotto.uuid), {}, mentreInvia);
}

/** «Importa senza i saldi»: la stessa conferma, senza il livello dei saldi. Il server ricontrolla che sia l'unico a fermarla. */
function confermaSenzaSaldi() {
  if (inviando.value) return;
  router.post(route('import.conferma', props.lotto.uuid), { senza_saldi: true }, mentreInvia);
}

const decisioniAperte = computed(() =>
  props.anteprima.persone.da_dividere.filter((d) => !props.decisioni['soggetto:' + d.chiave]).length,
);

// I duplicati sono decisioni come le altre, e vanno contate qui. Finché il motore li scopriva
// solo scrivendo, la conferma partiva, si fermava a metà e l'esito chiedeva una scelta che
// nessuna schermata sapeva offrire: l'importazione diventava incompletabile alla seconda volta
// sullo stesso archivio.
type Collisione = (typeof props)['anteprima']['collisioni']['voci'][number];

// La decisione presa che vale. «Unisci» su una persona senza il permesso di modificarla non vale (la conferma la rifiuterebbe): la voce
// resta aperta, con la scelta che resta.
function decisa(c: Collisione): string | undefined {
  const d = props.decisioni[c.chiave_decisione];

  return d && c.senza_permesso && !c.scelte.includes(d) ? undefined : d;
}

const collisioniAperte = computed(() =>
  props.anteprima.collisioni.voci.filter((c) => !decisa(c)).length,
);

// Il condominio che c'è già, senza il permesso di scriverci dentro: non è una decisione che resta, è una porta chiusa, e accanto al
// pulsante spento si dice quale.
const senzaPermesso = computed(() => props.anteprima.collisioni.voci.find((c) => c.senza_permesso && c.cosa === 'condominio') ?? null);

// «Lascia com'è» va bene per una persona, non per un esercizio: lì la scelta non è conservare
// un dato ma **riusare** il contenitore, e chiamarla con il nome sbagliato fa esitare.
// L'esercizio si riconosce dal periodo, non dal nome: dirgli «con lo stesso nome» sarebbe
// falso proprio nel punto in cui l'amministratore sta decidendo se fidarsi.
const MOTIVI: Record<string, string> = {
  codice_fiscale: 'con lo stesso codice fiscale',
  periodo: 'sullo stesso periodo',
  nome: 'con lo stesso nome',
};

function motivoInParole(motivo: string): string {
  return MOTIVI[motivo] ?? MOTIVI.nome;
}

function etichettaScelta(scelta: string, cosa: string): string {
  if (scelta === 'unisci') return 'Unisci';
  return cosa === 'esercizio' ? 'Usa quello che c\'è già' : 'Lascia com\'è';
}

// Il campione non è un limite: è un'anteprima, e «e altre 19» senza modo di vederle è una
// frase che informa e basta. Il taglio vive qui — la schermata riceve l'elenco intero — così
// «Vedile tutte» può davvero toglierlo.
const RIGHE_MOSTRATE = 12;
const UNITA_MOSTRATE = 8;

const tutteTitolarita = ref(false);
const tutteUnita = ref(false);

const titolaritaVisibili = computed(() =>
  tutteTitolarita.value
    ? props.anteprima.titolarita.elenco
    : props.anteprima.titolarita.elenco.slice(0, RIGHE_MOSTRATE),
);

const unitaVisibili = computed(() =>
  tutteUnita.value
    ? props.anteprima.unita.elenco
    : props.anteprima.unita.elenco.slice(0, UNITA_MOSTRATE),
);

// Il sottotitolo dell'intestazione: condominio, esercizio e se è non solare. Nel titolo grande
// non ci sta, e sotto è dove l'amministratore controlla di stare importando il condominio giusto.
const sottotitoloConferma = computed(() => {
  const c = props.anteprima.condominio;
  if (!c) return 'Controlla che sia tutto giusto prima di scrivere.';
  const e = c.esercizio;
  if (!e) return c.nome;
  return `${c.nome} · esercizio ${e.etichetta} (${e.dal} – ${e.al})${e.solare ? '' : ' · non solare'}`;
});

const puoConfermare = computed(
  () => props.confermabile && decisioniAperte.value === 0 && collisioniAperte.value === 0,
);

// Il pulsante principale è spento per i saldi (Coda 759): il motivo sta accanto, e «Importa senza i saldi» si può premere quando
// le altre decisioni sono prese, come il pulsante principale.
const fermoPerISaldi = computed(() => !props.confermabile && props.senza_saldi.motivi.length > 0);
const puoConfermareSenzaSaldi = computed(
  () => props.senza_saldi.possibile && decisioniAperte.value === 0 && collisioniAperte.value === 0,
);
</script>

<template>
  <Head title="Cosa sto per scrivere" />

  <AppLayout :breadcrumbs="[]">
    <div class="space-y-6 px-4 py-6">
      <PageHeaderGuide
        page-title="Ecco cosa sto per scrivere"
        :page-subtitle="sottotitoloConferma"
        :guides="guideImport"
        :breadcrumbs="headerBreadcrumbs"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="mostraGuida = true"
      />

      <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" />

      <!--
        ══ Chi esiste già: PRIMA di tutto, perché blocca ══
        Va in testa e non dentro la tabella delle persone come nel mockup: è l'unica cosa in
        questa schermata che impedisce di proseguire, e un blocco che si scopre scorrendo è il
        difetto che questa importazione esiste per non commettere.
      -->
      <Card
        v-if="props.anteprima.collisioni.totale"
        class="border-amber-300 bg-amber-50/60 dark:border-amber-900/60 dark:bg-amber-950/20"
      >
        <CardHeader>
          <CardTitle class="flex items-center gap-2 text-base">
            <CircleAlert class="h-4 w-4 text-amber-600 dark:text-amber-400" />
            {{ props.anteprima.collisioni.totale === 1 ? 'Una cosa esiste già' : props.anteprima.collisioni.totale + ' cose esistono già' }}
          </CardTitle>
          <CardDescription>
            Non posso decidere io se sono la stessa cosa. «Unisci» aggiorna quello che hai in
            archivio con i dati del file; «lascia com'è» tiene il tuo e ci collega comunque
            unità, tabelle e saldi.
            <!--
              Senza questa riga la schermata sembra un ciclo: si risponde a tutto, la pagina si
              ricarica e compaiono due domande nuove. Nascono dalla divisione — le due persone
              che ne escono possono esistere già a loro volta — ed è corretto, ma va detto,
              perché il sospetto di essere in un anello è il momento in cui si chiude la scheda.
            -->
            <template v-if="props.anteprima.persone.da_dividere.length">
              Dividendo un nome doppio possono comparire voci nuove qui sotto: le due persone che
              ne nascono a volte esistono già ciascuna per conto suo.
            </template>
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-2">
          <div
            v-for="c in props.anteprima.collisioni.voci"
            :key="c.chiave_decisione"
            class="rounded-md border bg-background p-3 text-sm"
            :class="decisa(c) ? 'border-emerald-300 dark:border-emerald-900/60' : ''"
          >
            <div class="flex flex-wrap items-center gap-2">
              <Badge variant="outline">{{ c.cosa }}</Badge>
              <span class="font-medium">{{ c.nome }}</span>
              <span class="text-muted-foreground">
                — in archivio c'è già «{{ c.esistente }}»,
                {{ motivoInParole(c.motivo) }}
              </span>
            </div>

            <p v-if="c.motivo === 'nome'" class="mt-1 text-xs text-muted-foreground">
              Sul solo nome ci si sbaglia: due «Rossi Mario» esistono davvero. Controlla prima di unire.
            </p>

            <!-- La persona che c'è già, senza «Modifica anagrafiche»: «Lascia com'è» resta, e porta in fondo. -->
            <p v-if="c.senza_permesso && c.cosa === 'persona'" class="mt-2 text-muted-foreground" data-senza-permesso-persona>
              Per aggiornare «{{ c.esistente }}» con i dati del file («Unisci») serve il permesso «Modifica anagrafiche»: chiedilo a chi
              amministra lo studio. Senza, scegli «Lascia com'è»: la scheda resta com'è e le unità vengono collegate lo stesso.
            </p>

            <p v-if="c.senza_permesso && c.cosa === 'condominio'" class="mt-2 text-destructive" data-senza-permesso>
              Per importare dentro «{{ c.esistente }}» serve il permesso «Modifica condomini»: chiedilo a chi amministra lo studio, oppure
              chiedigli di fare lui l'importazione.
            </p>

            <div v-else-if="!decisa(c)" class="mt-2 flex flex-wrap gap-2">
              <Button
                v-for="scelta in c.scelte"
                :key="scelta"
                size="sm"
                :variant="scelta === 'unisci' ? 'default' : 'outline'"
                @click="decidi(c.chiave_decisione, scelta)"
              >
                {{ etichettaScelta(scelta, c.cosa) }}
              </Button>
            </div>

            <p v-else class="mt-2 text-muted-foreground">
              Hai scelto: <strong>{{ etichettaScelta(decisa(c)!, c.cosa).toLowerCase() }}</strong>.
              <button
                v-if="c.scelte.length > 1"
                class="underline"
                @click="decidi(c.chiave_decisione, props.decisioni[c.chiave_decisione] === 'unisci' ? 'salta' : 'unisci')"
              >
                cambia
              </button>
            </p>
          </div>
        </CardContent>
      </Card>

      <!-- ══ Chi possiede cosa: PRIMA di tutto il resto ══ -->
      <Card>
        <CardHeader class="flex-row items-start justify-between space-y-0">
          <div>
            <CardTitle class="flex items-center gap-2 text-base">
              <Users class="h-4 w-4" /> Chi possiede cosa
            </CardTitle>
            <CardDescription>
              {{ props.anteprima.titolarita.totale }} titolarità su
              {{ props.anteprima.titolarita.unita_coinvolte }} unità
            </CardDescription>
          </div>
          <Badge v-if="props.anteprima.titolarita.unita_senza_titolare.length" variant="destructive">
            {{ props.anteprima.titolarita.unita_senza_titolare.length }} unità senza titolare
          </Badge>
          <BadgeStato v-else stato="ok">tutte assegnate</BadgeStato>
        </CardHeader>

        <CardContent class="space-y-3">
          <div
            v-if="props.anteprima.titolarita.unita_senza_titolare.length"
            class="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm"
          >
            <p class="flex items-start gap-2 font-medium">
              <CircleAlert class="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
              <span>
                {{ props.anteprima.titolarita.unita_senza_titolare.length }} unità resterebbero
                senza nessuno: {{ props.anteprima.titolarita.unita_senza_titolare.join(', ') }}
              </span>
            </p>
            <p class="mt-1 pl-6 text-muted-foreground">
              Un'unità senza titolare non riceve rate, non compare in morosità e non entra nei
              riparti: l'importazione risulterebbe riuscita e inutile. Puoi importare lo stesso e
              assegnarli dopo, ma prima di emettere il primo piano rate vanno sistemati.
            </p>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Unità</TableHead>
                <TableHead>Persona</TableHead>
                <TableHead>Ruolo</TableHead>
                <TableHead>Nota dal file</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="(t, i) in titolaritaVisibili" :key="i">
                <TableCell class="font-mono text-xs">{{ t.unita }}</TableCell>
                <TableCell>{{ t.persona }}</TableCell>
                <TableCell><Badge variant="outline">{{ t.ruolo }}</Badge></TableCell>
                <TableCell class="text-xs text-muted-foreground">
                  <Badge v-if="t.nota" variant="secondary">{{ t.nota }}</Badge>
                  <span v-else>—</span>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
          <p
            v-if="props.anteprima.titolarita.elenco.length > titolaritaVisibili.length"
            class="text-xs text-muted-foreground"
          >
            …e altre {{ props.anteprima.titolarita.elenco.length - titolaritaVisibili.length }}.
            <button class="underline" @click="tutteTitolarita = true">Vedile tutte</button>
          </p>
          <p v-else-if="tutteTitolarita && props.anteprima.titolarita.elenco.length > RIGHE_MOSTRATE"
             class="text-xs text-muted-foreground">
            <button class="underline" @click="tutteTitolarita = false">Mostrane solo alcune</button>
          </p>
        </CardContent>
      </Card>

      <!-- ══ I saldi, con la riga della quadratura ══ -->
      <Card v-if="props.anteprima.saldi">
        <CardHeader class="flex-row items-start justify-between space-y-0">
          <div>
            <CardTitle class="flex items-center gap-2 text-base">
              <Wallet class="h-4 w-4" /> Saldi di apertura
            </CardTitle>
            <CardDescription>{{ props.anteprima.saldi.righe }} posizioni dal {{ props.anteprima.saldi.fonte }}</CardDescription>
          </div>
          <!--
            ⚠️ **Tre stati, non due.** `quadra()` risponde «no» anche quando non c'era niente da
            quadrare: senza totale `scartoCents()` è `null`, e in PHP `null === 0` è falso. A
            schermo diventava un «non quadrano» rosso su un modello compilato a mano — dove il
            totale non lo chiediamo apposta, perché lo scriverebbe la stessa persona che ha
            scritto le righe. Accusare chi non ha sbagliato è il difetto che questo importatore
            esiste per non commettere.
          -->
          <BadgeStato v-if="props.anteprima.saldi.verificabile && props.anteprima.saldi.quadra" stato="ok">quadrano</BadgeStato>
          <Badge v-else-if="props.anteprima.saldi.verificabile" variant="destructive">non quadrano</Badge>
          <Badge v-else variant="outline">niente da quadrare</Badge>
        </CardHeader>

        <CardContent class="space-y-3">
          <div
            class="rounded-lg border p-4 text-sm tabular-nums"
            :class="!props.anteprima.saldi.verificabile
              ? 'bg-muted/40'
              : props.anteprima.saldi.quadra
                ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/30'
                : 'border-destructive/40 bg-destructive/5'"
          >
            <div v-if="props.anteprima.saldi.verificabile" class="flex justify-between gap-6 py-0.5">
              <span>Saldo finale scritto nel tuo riparto</span>
              <span>{{ props.anteprima.saldi.totale_riferimento }}</span>
            </div>
            <div class="flex justify-between gap-6 py-0.5">
              <span>Somma delle {{ props.anteprima.saldi.righe }} righe che sto importando</span>
              <span>{{ props.anteprima.saldi.somma_righe }}</span>
            </div>
            <!--
              La riga degli arrotondamenti è una riga **del riparto di Danea**: dice che quella
              stampa ne aveva una e che l'abbiamo assorbita nel totale. Su un foglio compilato a
              mano non esiste, e mostrarla a zero fa cercare all'amministratore una riga che non
              ha mai scritto.
            -->
            <div v-if="props.anteprima.saldi.verificabile" class="flex justify-between gap-6 py-0.5 text-muted-foreground">
              <span>arrotondamenti del riparto, assorbiti</span>
              <span>{{ props.anteprima.saldi.arrotondamenti }}</span>
            </div>
            <div
              v-if="props.anteprima.saldi.da_titolari_cessati"
              class="flex justify-between gap-6 py-0.5 text-muted-foreground"
            >
              <span>di cui su titolari cessati, portati sull'unità (art. 63)</span>
              <span>{{ props.anteprima.saldi.importo_cessati }}</span>
            </div>
            <div v-if="props.anteprima.saldi.con_esercizio_origine" data-testid="saldi-esercizio-origine" class="py-0.5 text-muted-foreground">
              {{ props.anteprima.saldi.con_esercizio_origine === 1 ? '1 saldo dichiara' : `${props.anteprima.saldi.con_esercizio_origine} saldi dichiarano` }} l'esercizio d'origine.
              <template v-if="props.anteprima.saldi.esercizi_origine_da_cercare?.length">
                <template v-if="props.anteprima.saldi.esercizi_origine_da_cercare.length === 1">
                  «{{ props.anteprima.saldi.esercizi_origine_da_cercare[0] }}» non è l'esercizio di questo file: lo cerco fra gli esercizi del condominio quando
                  importi; se non c'è, quei saldi entrano con l'origine non dichiarata.
                </template>
                <template v-else>
                  {{ props.anteprima.saldi.esercizi_origine_da_cercare.map((e) => `«${e}»`).join(', ') }} non sono esercizi di questo file: li cerco fra gli
                  esercizi del condominio quando importi; se non ci sono, quei saldi entrano con l'origine non dichiarata.
                </template>
              </template>
            </div>
            <div
              v-if="props.anteprima.saldi.verificabile"
              class="mt-2 flex justify-between gap-6 border-t pt-2 font-semibold"
              :class="props.anteprima.saldi.quadra ? 'border-emerald-300 text-emerald-700 dark:border-emerald-900/60 dark:text-emerald-400' : 'border-destructive/40 text-destructive'"
            >
              <span>Scarto</span>
              <span class="flex items-center gap-1">
                {{ props.anteprima.saldi.scarto }}
                <CheckCircle2 v-if="props.anteprima.saldi.quadra" class="h-4 w-4" />
              </span>
            </div>
          </div>

          <!--
            Coda 759 (2): «Se non tornasse, non ti farei proseguire» e il pulsante restava acceso. Adesso, se i saldi non possono
            entrare, il motivo è qui, in rosso, con la strada per correggerlo (bozza approvata, scena 2); il pulsante qui sotto è
            spento, e accanto c'è «Importa senza i saldi» (decisione 707 (2)).
          -->
          <div
            v-if="props.senza_saldi.motivi.length"
            class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
            data-saldi-motivo
          >
            <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <div class="space-y-1">
              <p v-for="m in props.senza_saldi.motivi" :key="m.codice">
                <strong>I saldi non entrano.</strong> {{ m.motivo }} {{ m.strada }}
              </p>
              <p v-if="props.senza_saldi.possibile">Oppure importa tutto il resto senza i saldi, con il pulsante qui sotto.</p>
            </div>
          </div>

          <p v-else-if="props.anteprima.saldi.verificabile" class="text-xs text-muted-foreground">
            Il totale non te l'ho chiesto: è scritto dentro il file che hai caricato.
            Se non tornasse, i saldi non entrerebbero.
          </p>
          <p v-if="!props.anteprima.saldi.verificabile" class="text-xs text-muted-foreground">
            Questo file non porta un totale di controllo, quindi la somma qui sopra non la posso
            confrontare con niente: è la somma di quello che hai scritto tu. Confrontala con
            l'ultimo rendiconto approvato prima di emettere il primo piano rate.
          </p>
        </CardContent>
      </Card>

      <!-- ══ Persone, con le decisioni inline ══ -->
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center gap-2 text-base">
            <Users class="h-4 w-4" /> Persone
          </CardTitle>
          <CardDescription>
            {{ props.anteprima.persone.totale }} soggetti
            <template v-if="props.anteprima.persone.senza_codice_fiscale">
              · {{ props.anteprima.persone.senza_codice_fiscale }} senza codice fiscale
            </template>
          </CardDescription>
        </CardHeader>

        <CardContent class="space-y-2">
          <div
            v-for="d in props.anteprima.persone.da_dividere"
            :key="d.chiave"
            class="rounded-md border p-3 text-sm"
            :class="props.decisioni['soggetto:' + d.chiave]
              ? 'border-input bg-muted/40'
              : 'border-amber-300 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/30'"
          >
            <p class="font-medium">«{{ d.nome }}» sembra contenere due persone in un campo solo.</p>

            <div v-if="!props.decisioni['soggetto:' + d.chiave]" class="mt-2 flex flex-wrap gap-2">
              <Button size="sm" @click="decidi('soggetto:' + d.chiave, 'dividi')">
                Dividi in due
              </Button>
              <Button size="sm" variant="outline" @click="decidi('soggetto:' + d.chiave, 'lascia')">
                Lascia com'è
              </Button>
              <span class="self-center text-xs text-muted-foreground">
                Dividendo nascerebbero: {{ d.pezzi.join(' e ') }}
              </span>
            </div>

            <p v-else class="mt-1 text-xs text-muted-foreground">
              Hai scelto:
              <strong>{{ props.decisioni['soggetto:' + d.chiave] === 'dividi' ? 'dividi in due' : "lascia com'è" }}</strong>.
              <button class="underline" @click="decidi('soggetto:' + d.chiave, props.decisioni['soggetto:' + d.chiave] === 'dividi' ? 'lascia' : 'dividi')">
                cambia
              </button>
            </p>
          </div>

          <p v-if="!props.anteprima.persone.da_dividere.length" class="text-sm text-muted-foreground">
            Nessuna decisione da prendere: entrano tutte come sono.
          </p>
        </CardContent>
      </Card>

      <!-- ══ Unità e tabelle ══ -->
      <div class="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
              <Building2 class="h-4 w-4" /> Unità immobiliari
            </CardTitle>
            <CardDescription>{{ props.anteprima.unita.totale }} unità</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="flex flex-wrap gap-1">
              <Badge v-for="u in unitaVisibili" :key="u.chiave" variant="outline">
                {{ u.nome }}
              </Badge>
              <!-- Il «+N» era muto: adesso è il modo di vedere le altre -->
              <button
                v-if="props.anteprima.unita.elenco.length > unitaVisibili.length"
                @click="tutteUnita = true"
              >
                <Badge variant="secondary">
                  +{{ props.anteprima.unita.elenco.length - unitaVisibili.length }}
                </Badge>
              </button>
              <button v-else-if="tutteUnita && props.anteprima.unita.elenco.length > UNITA_MOSTRATE"
                      @click="tutteUnita = false">
                <Badge variant="secondary">mostrane meno</Badge>
              </button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
              <Table2 class="h-4 w-4" /> Tabelle millesimali
            </CardTitle>
            <CardDescription>{{ props.anteprima.tabelle.length }} tabelle</CardDescription>
          </CardHeader>
          <CardContent class="space-y-1 text-sm">
            <div v-for="t in props.anteprima.tabelle" :key="t.nome" class="flex flex-wrap items-center gap-2">
              <span>{{ t.nome }}</span>
              <span class="text-xs text-muted-foreground">{{ t.partecipanti }} unità · {{ t.somma }}</span>
              <Badge v-if="t.parziale" variant="outline">parziale</Badge>
              <Badge v-if="t.parti_uguali" variant="outline">parti uguali</Badge>
            </div>
            <p class="pt-1 text-xs text-muted-foreground">
              «Parziale» e «parti uguali» sono modi normali di ripartire, non errori.
            </p>
          </CardContent>
        </Card>
      </div>

      <!-- ══ Conferma ══ -->
      <Card>
        <CardContent class="space-y-4 pt-6">
          <!--
            Coda 646, decisione 707 (4): dopo la conferma resteranno aperti due esercizi. Un avviso giallo che non blocca, sopra il
            pulsante (bozza approvata, scena 3): dove vanno incassi, pagamenti e saldi da qui in poi, e dove vanno le rate.
          -->
          <div
            v-if="props.esercizi_aperti"
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
            data-esercizi-aperti
          >
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <p><strong>{{ props.esercizi_aperti.titolo }}</strong> {{ props.esercizi_aperti.testo }}</p>
          </div>

          <!--
            «Importa senza i saldi» (decisione 707 (2)): prima di premerlo si legge che cosa non entra, con lo scarto in cifre nel
            motivo qui sopra, e chi scrive i saldi dopo.
          -->
          <div
            v-if="props.senza_saldi.possibile"
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
            data-senza-saldi
          >
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <div class="space-y-1">
              <p><strong>Con «Importa senza i saldi» entra tutto il resto.</strong> Non entrano:</p>
              <ul class="list-disc space-y-0.5 pl-5">
                <li v-for="n in props.senza_saldi.non_entra" :key="n.cosa">
                  <strong>{{ n.cosa }}</strong>: {{ n.dettaglio }}
                </li>
              </ul>
              <p>I saldi li scrivi tu, dopo, dalla pagina «Saldi iniziali» del condominio, prima di emettere il primo piano rate.</p>
            </div>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
              <h2 class="font-semibold">Tutto qui</h2>
              <p class="text-sm text-muted-foreground">
                <!-- Il pulsante disabilitato si spiega da sé, e dice **quale** decisione manca -->
                <template v-if="senzaPermesso">
                  <span class="text-destructive">
                    Per importare dentro «{{ senzaPermesso.esistente }}» serve il permesso «Modifica condomini».
                  </span>
                </template>
                <template v-else-if="collisioniAperte">
                  {{ collisioniAperte === 1 ? 'Resta da decidere' : 'Restano da decidere' }}
                  {{ collisioniAperte }} {{ collisioniAperte === 1 ? 'cosa che esiste già' : 'cose che esistono già' }},
                  qui sopra.
                </template>
                <template v-else-if="decisioniAperte">
                  Restano {{ decisioniAperte }}
                  {{ decisioniAperte === 1 ? 'decisione da prendere' : 'decisioni da prendere' }}.
                </template>
                <!-- Coda 759: il pulsante principale spento per i saldi, con il motivo accanto. -->
                <template v-else-if="fermoPerISaldi">
                  <span class="text-destructive" data-motivo-pulsante>
                    {{ props.senza_saldi.motivi.map((m) => m.motivo).join(' ') }}
                  </span>
                </template>
                <template v-else-if="!props.confermabile">
                  Restano errori da correggere: li trovi nella verifica dei file.
                </template>
                <template v-else>
                  Dopo la conferma potrai comunque annullare, finché nessuna operazione userà questi dati.
                </template>
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <Link v-if="fermoPerISaldi" :href="route('import.index')">
                <Button variant="outline">Ricarica i file</Button>
              </Link>
              <Link v-else :href="route('import.verifica', props.lotto.uuid)">
                <Button variant="outline">Torna indietro</Button>
              </Link>
              <Button
                v-if="props.senza_saldi.possibile"
                variant="outline"
                :disabled="!puoConfermareSenzaSaldi || inviando"
                data-azione="importa-senza-saldi"
                @click="confermaSenzaSaldi"
              >
                Importa senza i saldi
              </Button>
              <Button :disabled="!puoConfermare || inviando" data-azione="importa" @click="conferma">
                Importa {{ props.anteprima.totale_record }} record
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <p class="flex items-center gap-2 text-xs text-muted-foreground">
        <Lock class="h-3.5 w-3.5" />
        Niente è ancora entrato in Kondomanager.
      </p>
    </div>

    <ImportGuide v-model:open="mostraGuida" />
  </AppLayout>
</template>
