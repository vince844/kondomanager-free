<script setup lang="ts">
/**
 * S5 — Esito: il rapporto, e cosa conviene fare adesso.
 *
 * Due cose che il §13.3 e il §13.2 rendono obbligatorie:
 *
 * 1. **Il rapporto è il documento di consegna.** Dice cosa è entrato per livello, cosa è stato
 *    saltato e perché. È quello che l'amministratore mostra al suo successore.
 * 2. **L'annullamento dice sempre se è possibile e perché.** Un pulsante grigio senza
 *    spiegazione è un pulsante che genera una email di assistenza.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ImportGuide from '@/components/guides/ImportGuide.vue';
import { guideImport } from '@/pages/import/intestazione';
import BadgeStato from '@/components/import/BadgeStato.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CheckCircle2, CircleAlert, Info, FileDown } from 'lucide-vue-next';

interface RigaRapporto {
  livello: string;
  etichetta: string;
  creati: number;
  uniti: number;
  saltati: number;
  riuscito: boolean;
  gia_a_posto: boolean;
  /** Saltato perché il dato non c'era: è un'assenza dichiarata, non un guasto. */
  saltato: boolean;
  prerequisiti_mancanti: { codice: string; cosa_manca: string; rimedio: string }[];
  rilievi: { messaggio: string; rimedio: string | null; riga: number | null }[];
  avvisi: { messaggio: string; rimedio: string | null; riga: number | null }[];
}

const props = defineProps<{
  lotto: {
    uuid: string; stato: string; condominio: string | null;
    condominio_id: number | null; url_condominio: string | null;
    url_controlli: string | null; url_rapporto: string | null;
    /** Dove si crea il preventivo che l'importazione non porta. Null se non c'è un esercizio aperto. */
    url_piano_conti: string | null;
    riferimento: string; completato_at: string | null;
  };
  rapporto: {
    livelli?: RigaRapporto[];
    saldi?: { scarto: string | null; quadra: boolean } | null;
    generato_il?: string;
  };
  creati: number;
}>();

const mostraGuida = ref(false);

const headerBreadcrumbs = [{ title: 'Importa dati', href: route('import.index') }, { title: 'Esito' }];

const livelli = computed(() => props.rapporto.livelli ?? []);
const completato = computed(() => props.lotto.stato === 'completato');
const avvisi = computed(() => livelli.value.flatMap((l) => l.avvisi ?? []));
const bloccato = computed(() => livelli.value.find((l) => !l.riuscito));

// ⚠️ **Un livello a zero non è un livello andato male, ed è la differenza che l'amministratore
// non può dedurre da solo.** «Capitoli di spesa · 0 · 0 · 0» lo lascia a chiedersi se ha saltato
// un foglio o sbagliato qualcosa. Non ha sbagliato niente: il modello compilabile a mano **non
// chiede** il preventivo di spesa, ed è una scelta dichiarata — quello lo decide lui, e c'è una
// schermata fatta apposta. La riga sotto la tabella lo dice, e dice anche da dove si passa.
const senzaDati = computed(() => livelli.value.filter(
  (l) => l.riuscito && l.gia_a_posto && !l.creati && !l.uniti && !l.saltati,
));

const capitoliNonImportati = computed(() => senzaDati.value.some((l) => l.livello === 'capitoli'));

// ⚠️ **Il riquadro dello scarto ha quattro stati, non due, e per due di essi non diceva niente.**
//
// 1. quadra           → verde, lo scarto è € 0,00 e non c'è altro da dire;
// 2. NON quadra       → rosso. È lo stato che l'amministratore capisce di meno: i saldi **non
//                       sono entrati affatto** (`LivelloSaldi` si blocca prima di scrivere), ma
//                       il riquadro mostrava solo un numero rosso, e un numero non dice né cosa
//                       è successo né cosa fare;
// 3. non verificabile → il file non porta un totale: il controllo non è fallito, non c'è stato;
// 4. nessun saldo     → il riquadro non compare.
//
// Il difetto era che 2 e 3 si somigliavano a schermo e sono opposti: uno è «ho controllato ed è
// sbagliato, non ho scritto niente», l'altro è «non c'era niente da controllare, ho scritto tutto».
const saldiEntrati = computed(
  () => livelli.value.find((l) => l.livello === 'saldi')?.riuscito ?? false,
);

// La stessa griglia della verifica, con gli stessi numeri: la rima visiva fra le due schermate
// è metà dell'effetto — si riconosce di essere arrivati in fondo alla stessa procedura.
const sottotitoloEsito = computed(() => [
  props.lotto.condominio,
  props.lotto.completato_at,
  `${props.creati} record creati`,
  `lotto ${props.lotto.riferimento}`,
].filter(Boolean).join(' · '));

// Lo scarto è `null` quando il riparto non portava la riga del totale: non c'era nulla con
// cui confrontare la somma, quindi non è «sbagliato», è «mai verificato».
const quadraturaVerificabile = computed(() => props.rapporto.saldi?.scarto != null);

const conteggi = computed(() => livelli.value.reduce(
  (acc, l) => ({
    creati: acc.creati + l.creati,
    uniti: acc.uniti + l.uniti,
    saltati: acc.saltati + l.saltati,
  }),
  { creati: 0, uniti: 0, saltati: 0 },
));
</script>

<template>
  <Head title="Esito dell'importazione" />

  <AppLayout :breadcrumbs="[]">
    <div class="space-y-6 px-4 py-6">
      <PageHeaderGuide
        :page-title="completato ? 'Importazione completata' : 'Importazione interrotta'"
        :page-subtitle="sottotitoloEsito"
        :guides="guideImport"
        :breadcrumbs="headerBreadcrumbs"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="mostraGuida = true"
      />

      <!-- Quando si è fermata, il perché viene prima di tutto -->
      <Card v-if="bloccato" class="border-amber-300 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/30">
        <CardHeader>
          <CardTitle class="text-base">Si è fermata a «{{ bloccato.etichetta }}»</CardTitle>
          <CardDescription>Quello che era già entrato resta: non è stato annullato niente.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-2 text-sm">
          <div v-for="(p, i) in bloccato.prerequisiti_mancanti" :key="'p' + i">
            <p class="font-medium">{{ p.cosa_manca }}</p>
            <p class="text-muted-foreground">{{ p.rimedio }}</p>
          </div>
          <div v-for="(r, i) in bloccato.rilievi" :key="'r' + i">
            <p class="font-medium">
              <template v-if="r.riga">Riga {{ r.riga }} — </template>{{ r.messaggio }}
            </p>
            <p v-if="r.rimedio" class="text-muted-foreground">{{ r.rimedio }}</p>
          </div>
        </CardContent>
      </Card>

      <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <!--
          ⚠️ **Le tre schede dei contatori portavano un numero e basta**, e accanto alla quarta —
          che spiega cosa fare — sembravano vuote: il confronto le faceva leggere come schede non
          finite. La soluzione non è rimpicciolirle: è che sotto quel numero c'era qualcosa da
          dire e non lo stavamo dicendo. «Uniti» e «saltati» non si distinguono da soli, e la
          differenza è precisamente la promessa dell'importatore — non tocco quello che c'è già.
        -->
        <Card :class="conteggi.creati ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/30' : ''">
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Creati</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ conteggi.creati }}</p>
            <p class="mt-1 text-xs leading-snug text-muted-foreground">
              Righe nuove, scritte adesso in archivio.
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Uniti a esistenti</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ conteggi.uniti }}</p>
            <p class="mt-1 text-xs leading-snug text-muted-foreground">
              C'erano già: ho aggiunto solo i dati che mancavano, senza sovrascrivere i tuoi.
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Saltati</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ conteggi.saltati }}</p>
            <p class="mt-1 text-xs leading-snug text-muted-foreground">
              Identici a quello che c'era: non ho toccato niente.
            </p>
          </CardContent>
        </Card>

        <!-- Il numero che in anteprima toglieva la paura, ritrovato a cose fatte -->
        <Card
          v-if="props.rapporto.saldi"
          :class="quadraturaVerificabile
            ? (props.rapporto.saldi.quadra
              ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/30'
              : 'border-destructive/50')
            : 'border-amber-300 bg-amber-50/60 dark:border-amber-900/60 dark:bg-amber-950/20'"
        >
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Scarto sui saldi</p>
            <!--
              Tre stati, non due: `quadra` è falso sia quando lo scarto non è zero sia quando
              non è mai stato calcolato. Con due soli rami il riquadro usciva rosso — cioè
              «verificato e sbagliato» — su un file che semplicemente non portava il totale.
            -->
            <template v-if="quadraturaVerificabile">
              <p class="mt-1 text-2xl font-semibold tabular-nums">
                {{ props.rapporto.saldi.scarto }}
              </p>
              <p v-if="!props.rapporto.saldi.quadra" class="mt-1 text-xs leading-snug text-muted-foreground">
                <strong class="text-foreground">I saldi non sono entrati</strong>: la somma delle
                righe non corrisponde al totale scritto nel file, e un pregresso sbagliato si
                trascina in ogni riparto. Riesporta il riparto completo — senza filtri e senza
                righe cancellate a mano — e ricaricalo: il resto è già in archivio e non si perde.
              </p>
              <p v-else-if="saldiEntrati" class="mt-1 text-xs leading-snug text-muted-foreground">
                La somma delle righe corrisponde al totale scritto nel tuo file. Non devi
                ricontrollare niente.
              </p>
            </template>
            <!--
              ⚠️ «Non verificabile» da solo è un verdetto senza seguito: dice che non abbiamo
              controllato e lascia l'amministratore a chiedersi se deve fare qualcosa. Deve fare
              qualcosa, ed è una cosa sola e precisa — confrontare il totale con l'ultimo
              rendiconto approvato **prima** di emettere il primo piano rate, perché dopo quel
              momento un saldo sbagliato si trascina in ogni riparto.
            -->
            <template v-else>
              <p class="mt-1 text-base font-semibold">Da confrontare tu</p>
              <p class="mt-1 text-xs leading-snug text-muted-foreground">
                Il file non porta un totale di controllo. Apri
                <strong>Saldi</strong> e confronta il totale con l'ultimo rendiconto approvato:
                falla adesso, prima del primo piano rate.
              </p>
            </template>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader class="flex-row items-center justify-between space-y-0">
          <CardTitle class="text-base">Cosa ho importato</CardTitle>
          <!-- Il rapporto è il documento di consegna: senza un file da allegare vive dentro
               questa pagina e basta, e chi deve darne conto non ha niente in mano. -->
          <a v-if="props.lotto.url_rapporto" :href="props.lotto.url_rapporto" target="_blank">
            <Button variant="outline" size="sm">
              <FileDown class="mr-1 h-3.5 w-3.5" /> Scarica il rapporto (PDF)
            </Button>
          </a>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Livello</TableHead>
                <TableHead class="text-right">Creati</TableHead>
                <TableHead class="text-right">Uniti</TableHead>
                <TableHead class="text-right">Saltati</TableHead>
                <TableHead>Esito</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="l in livelli" :key="l.livello">
                <TableCell>{{ l.etichetta }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ l.creati }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ l.uniti }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ l.saltati }}</TableCell>
                <TableCell>
                  <!--
                    ⚠️ **«Era già a posto» su un livello che non aveva niente da importare è una
                    frase che non si capisce.** Chi compila il modello a mano non porta i capitoli
                    di spesa — è una scelta dichiarata, il preventivo lo decide lui dopo — e si
                    trovava la riga «Capitoli di spesa · 0 · 0 · 0 · era già a posto», che sembra
                    dire che il piano dei conti c'era già.

                    Le due cose sono diverse e vanno dette diverse: «l'avevi già» (qualcosa nei
                    file, niente da scrivere) e «non c'era nei tuoi file» (niente da nessuna parte).
                  -->
                  <!--
                    ⚠️ **«Saltato» prima di «fermato».** Un livello che non ha eseguito perché il
                    file non c'era usciva in rosso con scritto «fermato»: chi non carica le tabelle
                    millesimali — che è una scelta, non un errore — si vedeva un allarme addosso.
                    La distinzione esiste dalla beta.5 su `PrerequisitoMancante::$bloccante`, ma
                    non arrivava fin qui.
                  -->
                  <BadgeStato v-if="l.saltato" stato="neutro">non fornito</BadgeStato>
                  <Badge v-else-if="!l.riuscito" variant="destructive">fermato</Badge>
                  <BadgeStato v-else-if="l.gia_a_posto && !l.creati && !l.uniti && !l.saltati" stato="neutro">
                    non nei tuoi file
                  </BadgeStato>
                  <BadgeStato v-else-if="l.gia_a_posto" stato="neutro">era già a posto</BadgeStato>
                  <BadgeStato v-else stato="ok">completo</BadgeStato>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>

          <div
            v-if="senzaDati.length"
            class="mt-4 space-y-2 rounded-lg border bg-muted/40 p-4 text-sm text-muted-foreground"
          >
            <p v-if="capitoliNonImportati">
              <strong class="text-foreground">I capitoli di spesa non li ho importati, e non è un
              errore tuo.</strong>
              Nel modello compilabile a mano il preventivo non te lo chiedo: è l'unica cosa che
              stai per decidere tu, e chiedertela in un foglio Excel ti farebbe fare due volte un
              lavoro che il prodotto sa già ospitare. Lo crei dal menu del condominio,
              <strong class="text-foreground">Piani conti</strong>, aggiungendo i capitoli e sotto
              le loro voci.
            </p>
            <p v-if="capitoliNonImportati && props.lotto.url_piano_conti">
              <Link :href="props.lotto.url_piano_conti" class="font-medium text-foreground underline">
                Vai al piano dei conti di {{ props.lotto.condominio }}
              </Link>
            </p>
            <p v-if="senzaDati.some((l) => l.livello !== 'capitoli')">
              Le altre righe segnate «non nei tuoi file» sono dati che i file caricati non
              contenevano: puoi aggiungerli quando vuoi dalle schermate del condominio, oppure
              ricaricare un file che li porti.
            </p>
          </div>
        </CardContent>
      </Card>

      <Card v-if="avvisi.length">
        <CardHeader>
          <CardTitle class="flex items-center gap-2 text-base">
            <Info class="h-4 w-4" /> Cosa conviene controllare
          </CardTitle>
          <CardDescription>
            Non hanno impedito niente, ma è meglio saperle.
            <template v-if="props.lotto.url_controlli">
              Non serve annotarle: le ritrovi sempre in
              <a :href="props.lotto.url_controlli" class="underline">Da controllare</a>,
              dentro il condominio — e quelle che il sistema sa ricontrollare si chiudono da sole.
            </template>
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-2 text-sm">
          <div v-for="(a, i) in avvisi" :key="'a' + i" class="rounded-md bg-muted/50 p-3">
            <p><template v-if="a.riga">Riga {{ a.riga }} — </template>{{ a.messaggio }}</p>
            <p v-if="a.rimedio" class="mt-1 text-muted-foreground">{{ a.rimedio }}</p>
          </div>
        </CardContent>
      </Card>

      <!--
        L'annullamento non ha una scadenza: ha una condizione, e va spiegata.
        Il percorso che la valuta non esiste ancora, quindi qui si dice cosa sarà — senza un
        pulsante che finge di poterlo fare.
      -->
      <Card class="border-dashed">
        <CardContent class="space-y-1 pt-6">
          <h2 class="font-medium text-muted-foreground">Annullare questa importazione</h2>
          <p class="text-sm text-muted-foreground">
            <!--
              ⚠️ Qui c'era «Il comando arriva con la 1.10.1», scritto quando la 1.10.1 era il
              futuro. Adesso siamo alla 1.11 e quella riga era una scadenza mancata scritta in
              schermata — la forma di bugia che si nota per prima, perché il numero di versione
              l'amministratore ce l'ha in fondo alla pagina. Una promessa senza data è meglio di
              una promessa con la data sbagliata.
            -->
            Ogni entità creata da questo lotto è annotata, quindi l'annullamento
            <strong>sarà</strong> possibile finché nessuna operazione avrà usato questi dati — non
            entro una scadenza, ma finché non servono a qualcos'altro.
            <strong class="text-foreground">Il comando però non c'è ancora.</strong>
          </p>
          <p class="text-sm text-muted-foreground">
            Se devi disfare adesso: le voci si tolgono dalle schermate del condominio — unità,
            anagrafiche, tabelle, saldi — e il condominio intero si elimina dalla sua scheda, che
            porta via con sé tutto quello che è entrato con questa importazione.
          </p>
        </CardContent>
      </Card>

      <div class="flex flex-wrap gap-2 border-t pt-4">
        <Link v-if="props.lotto.url_condominio" :href="props.lotto.url_condominio">
          <Button>Vai al condominio</Button>
        </Link>
        <a v-if="props.lotto.url_controlli && avvisi.length" :href="props.lotto.url_controlli">
          <Button variant="outline">Da controllare ({{ avvisi.length }})</Button>
        </a>
        <Link :href="route('import.index')">
          <Button variant="outline">Importa un altro condominio</Button>
        </Link>
      </div>
    </div>

    <ImportGuide v-model:open="mostraGuida" />
  </AppLayout>
</template>
