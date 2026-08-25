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
import { Lock, CircleAlert, Users, Building2, Table2, Wallet, CheckCircle2 } from 'lucide-vue-next';
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
      da_titolari_cessati: number; importo_cessati: string;
    };
    totale_record: number;
  };
  decisioni: Record<string, string>;
  confermabile: boolean;
}>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const mostraGuida = ref(false);

const headerBreadcrumbs = [{ title: 'Importa dati', href: route('import.index') }, { title: 'Verifica', href: route('import.verifica', props.lotto.uuid) }, { title: 'Conferma' }];

function decidi(chiave: string, scelta: string) {
  router.put(route('import.decisione', props.lotto.uuid), { chiave, scelta }, { preserveScroll: true });
}

function conferma() {
  router.post(route('import.conferma', props.lotto.uuid));
}

const decisioniAperte = computed(() =>
  props.anteprima.persone.da_dividere.filter((d) => !props.decisioni['soggetto:' + d.chiave]).length,
);

// I duplicati sono decisioni come le altre, e vanno contate qui. Finché il motore li scopriva
// solo scrivendo, la conferma partiva, si fermava a metà e l'esito chiedeva una scelta che
// nessuna schermata sapeva offrire: l'importazione diventava incompletabile alla seconda volta
// sullo stesso archivio.
const collisioniAperte = computed(() =>
  props.anteprima.collisioni.voci.filter((c) => !props.decisioni[c.chiave_decisione]).length,
);

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
            :class="props.decisioni[c.chiave_decisione] ? 'border-emerald-300 dark:border-emerald-900/60' : ''"
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

            <div v-if="!props.decisioni[c.chiave_decisione]" class="mt-2 flex flex-wrap gap-2">
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
              Hai scelto: <strong>{{ etichettaScelta(props.decisioni[c.chiave_decisione], c.cosa).toLowerCase() }}</strong>.
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
            <CardDescription>{{ props.anteprima.saldi.righe }} posizioni dal riparto consuntivo</CardDescription>
          </div>
          <BadgeStato v-if="props.anteprima.saldi.quadra" stato="ok">quadrano</BadgeStato>
          <Badge v-else variant="destructive">non quadrano</Badge>
        </CardHeader>

        <CardContent class="space-y-3">
          <div
            class="rounded-lg border p-4 text-sm tabular-nums"
            :class="props.anteprima.saldi.quadra
              ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/30'
              : 'border-destructive/40 bg-destructive/5'"
          >
            <div class="flex justify-between gap-6 py-0.5">
              <span>Saldo finale scritto nel tuo riparto</span>
              <span>{{ props.anteprima.saldi.totale_riferimento ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-6 py-0.5">
              <span>Somma delle {{ props.anteprima.saldi.righe }} righe che sto importando</span>
              <span>{{ props.anteprima.saldi.somma_righe }}</span>
            </div>
            <div class="flex justify-between gap-6 py-0.5 text-muted-foreground">
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
            <div
              class="mt-2 flex justify-between gap-6 border-t pt-2 font-semibold"
              :class="props.anteprima.saldi.quadra ? 'border-emerald-300 text-emerald-700 dark:border-emerald-900/60 dark:text-emerald-400' : 'border-destructive/40 text-destructive'"
            >
              <span>Scarto</span>
              <span class="flex items-center gap-1">
                {{ props.anteprima.saldi.scarto ?? '—' }}
                <CheckCircle2 v-if="props.anteprima.saldi.quadra" class="h-4 w-4" />
              </span>
            </div>
          </div>

          <p class="text-xs text-muted-foreground">
            Il totale non te l'ho chiesto: è scritto dentro il file che hai caricato.
            Se non tornasse, non ti farei proseguire.
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
        <CardContent class="flex flex-wrap items-center justify-between gap-4 pt-6">
          <div>
            <h2 class="font-semibold">Tutto qui</h2>
            <p class="text-sm text-muted-foreground">
              <!-- Il pulsante disabilitato si spiega da sé, e dice **quale** decisione manca -->
              <template v-if="collisioniAperte">
                {{ collisioniAperte === 1 ? 'Resta da decidere' : 'Restano da decidere' }}
                {{ collisioniAperte }} {{ collisioniAperte === 1 ? 'cosa che esiste già' : 'cose che esistono già' }},
                qui sopra.
              </template>
              <template v-else-if="decisioniAperte">
                Restano {{ decisioniAperte }}
                {{ decisioniAperte === 1 ? 'decisione da prendere' : 'decisioni da prendere' }}.
              </template>
              <template v-else>
                Dopo la conferma potrai comunque annullare, finché nessuna operazione userà questi dati.
              </template>
            </p>
          </div>
          <div class="flex gap-2">
            <Link :href="route('import.verifica', props.lotto.uuid)">
              <Button variant="outline">Torna indietro</Button>
            </Link>
            <Button :disabled="!puoConfermare" @click="conferma">
              Importa {{ props.anteprima.totale_record }} record
            </Button>
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
