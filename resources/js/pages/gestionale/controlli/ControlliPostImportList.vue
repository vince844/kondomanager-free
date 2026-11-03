<script setup lang="ts">
/**
 * «Da controllare dopo l'importazione».
 *
 * ## Perché non è una todolist
 *
 * Perché una lista che si spunta solo a mano mente: si spunta anche quando non si è fatto
 * niente, e in tre settimane diventa un elenco di cose già fatte che nessuno ha voglia di
 * ripulire. Qui **la maggior parte delle voci si chiude da sola**: «le tabelle sono collegate a
 * un capitolo?» è una query, non una domanda da fare all'amministratore, e viene rieseguita a
 * ogni apertura della pagina. Se il problema torna, la voce si riapre da sola.
 *
 * Restano a mano solo quelle che nessuna query può decidere — e ognuna di quelle **dice perché**,
 * invece di lasciar sospettare una dimenticanza.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { guideImport } from '@/pages/import/intestazione';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import Alert from '@/components/Alert.vue';
import { CheckCircle2, CircleAlert, Info, RotateCcw, Clock, FileDown, Archive, RefreshCw } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';

interface Voce {
  chiave: string; lotto: string; livello: string; codice: string;
  titolo: string; messaggio_originale: string; rimedio: string | null;
  perche: string | null; quante: number; righe: number[];
  origine: string; importata_il: string | null;
  stato: 'aperto' | 'risolto' | 'superato' | 'spuntato' | 'messo_da_parte';
  verificabile: boolean; superata: boolean; nota: string | null;
  destinazione: { etichetta: string; url: string } | null;
}

const props = defineProps<{
  condominio: { id: number; nome: string };
  voci: Voce[];
  aperte: number;
  puo_vedere_rapporto: boolean;
  /** Le importazioni che hanno scritto su questo condominio, dalla più recente. */
  importazioni: { uuid: string; quando: string | null; record: number }[];
}>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

/**
 * ⚠️ **Le briciole di pane portano da qualche parte.** Prima erano due voci con `href: '#'`, cioè
 * scritte e morte: chi arriva qui dal widget della dashboard non aveva modo di tornare al
 * condominio se non con il tasto indietro del browser. E il nome del condominio da solo non dice
 * *dove* si è: la radice della sezione è il gestionale, come in tutte le pagine sorelle.
 */
// Due voci, come le pagine vicine del gestionale. La terza — il nome del condominio — puntava
// allo stesso indirizzo di «Gestionale»: un passo che non porta da nessuna parte, e il nome
// compare già nel sottotitolo e nel pulsante di ritorno.
const headerBreadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: route('admin.gestionale.index', props.condominio.id) },
  { title: 'Da controllare' },
]);

const sottotitolo = computed(() => {
  const quante = props.aperte
    ? `${props.aperte} ${props.aperte === 1 ? 'cosa ancora da sistemare' : 'cose ancora da sistemare'}`
    : 'niente in sospeso';

  return `${props.condominio.nome} · ${quante}`;
});

const mostraChiuse = ref(false);

const aperte = computed(() => props.voci.filter((v) => v.stato === 'aperto' || v.stato === 'superato'));
const chiuse = computed(() => props.voci.filter((v) => v.stato !== 'aperto' && v.stato !== 'superato'));

const ETICHETTA_STATO: Record<string, string> = {
  risolto: 'sistemato',
  spuntato: 'controllato da te',
  messo_da_parte: 'messa da parte',
  superato: 'non più modificabile',
};

// Molti messaggi cominciano già con una guillemet: incorniciarli sempre produce un doppio
// paio, che a schermo sembra un errore di stampa.
function traVirgolette(t: string): string {
  return t.startsWith('«') ? t : `«${t}»`;
}

function agisci(v: Voce, azione: 'spunta' | 'metti_da_parte' | 'riapri') {
  router.put(
    route('admin.gestionale.controlli-import.aggiorna', [props.condominio.id, v.lotto]),
    { chiave: v.chiave, azione },
    { preserveScroll: true },
  );
}
</script>

<template>
  <Head title="Da controllare dopo l'importazione" />

  <AppLayout :breadcrumbs="[]">
    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
      <PageHeaderGuide
        page-title="Da controllare dopo l'importazione"
        :page-subtitle="sottotitolo"
        :guides="guideImport"
        :breadcrumbs="headerBreadcrumbs"
        :back-url="route('admin.gestionale.index', props.condominio.id)"
        :back-text="props.condominio.nome"
        :video-url="null"
      />

      <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" />

      <!-- Niente da fare: si dice, invece di mostrare una pagina vuota che sembra rotta -->
      <Card v-if="!props.voci.length">
        <CardContent class="flex items-center gap-3 pt-6 text-sm text-muted-foreground">
          <CheckCircle2 class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
          Nessuna importazione ha lasciato niente da controllare su questo condominio.
        </CardContent>
      </Card>

      <Card v-else-if="!aperte.length" class="border-emerald-300 bg-emerald-50/60 dark:border-emerald-900/60 dark:bg-emerald-950/20">
        <CardContent class="flex items-center gap-3 pt-6 text-sm">
          <CheckCircle2 class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
          Tutto sistemato. Quello che l'importazione aveva segnalato è a posto.
        </CardContent>
      </Card>

      <Card v-for="v in aperte" :key="v.chiave" :class="v.superata ? '' : 'border-amber-300 dark:border-amber-900/60'">
        <CardHeader class="pb-3">
          <CardTitle class="flex items-start gap-2 text-base">
            <Clock v-if="v.superata" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <CircleAlert v-else class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span>{{ v.titolo }}</span>
          </CardTitle>
          <CardDescription v-if="v.rimedio">{{ v.rimedio }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
          <!--
            La riga viva dice cosa manca adesso; il messaggio dell'import dice com'era quel
            giorno. Vanno tenuti separati: sovrascrivere il secondo col primo cancellerebbe la
            registrazione di cosa il file conteneva davvero.
          -->
          <p v-if="v.titolo !== v.messaggio_originale" class="text-xs text-muted-foreground">
            All'importazione{{ v.importata_il ? ' del ' + v.importata_il : '' }}:
            {{ traVirgolette(v.messaggio_originale) }}
            <template v-if="v.quante > 1"> — e altre {{ v.quante - 1 }} righe simili.</template>
          </p>

          <!--
            ⚠️ **Le due frasi sono simmetriche, e prima non lo erano.**

            «Questa non posso ricontrollarla da solo» stava qui, in un riquadro; il suo opposto —
            «si chiude da sola appena l'avrai sistemata» — stava *dentro la riga dei pulsanti*,
            come testo nudo fra due bottoni: sembrava l'etichetta di un pulsante mancante, e
            spingeva a cercare qualcosa da premere che non c'era.

            Sono la stessa informazione detta nei due versi — chi ricontrolla questa voce, io o
            tu — quindi vanno nello stesso posto e con la stessa forma.
          -->
          <p v-if="!v.verificabile && v.perche" class="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
            <Info class="mr-1 inline h-3 w-3" />
            Questa non posso ricontrollarla da solo: {{ v.perche }}
          </p>

          <p v-else-if="v.verificabile && !v.superata" class="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
            <RefreshCw class="mr-1 inline h-3 w-3" />
            Questa la ricontrollo da solo: sparisce da qui appena l'avrai sistemata, senza che tu
            debba spuntarla.
          </p>

          <div class="flex flex-wrap items-center gap-2">
            <a v-if="v.destinazione" :href="v.destinazione.url">
              <Button size="sm">{{ v.destinazione.etichetta }}</Button>
            </a>

            <!-- Spuntabile solo dove la spunta significa qualcosa -->
            <Button
              v-if="!v.verificabile || v.superata"
              size="sm"
              variant="outline"
              @click="agisci(v, 'spunta')"
            >
              L'ho controllato
            </Button>

            <!--
              ⚠️ Era «Non mi riguarda», ed era una frase che dice troppo: chiede di dichiarare che
              la cosa non ti compete, quando quasi sempre significa «adesso no». «Metti da parte»
              è il nome dell'azione anche nel motore (`metti_da_parte`), non pretende un giudizio,
              e non chiude nessuna porta — la voce resta fra quelle già viste e si riapre.

              Ed è un pulsante come gli altri: era `ghost`, cioè testo, e l'unica azione che toglie
              una riga dalla lista non deve sembrare meno di un'azione.
            -->
            <Button size="sm" variant="outline" @click="agisci(v, 'metti_da_parte')">
              <Archive class="mr-1.5 h-3.5 w-3.5" />
              Metti da parte
            </Button>
          </div>
        </CardContent>
      </Card>

      <!-- Le chiuse restano consultabili: «l'avevo già guardata?» è una domanda vera -->
      <div v-if="chiuse.length">
        <button class="text-sm text-muted-foreground underline" @click="mostraChiuse = !mostraChiuse">
          {{ mostraChiuse ? 'Nascondi' : 'Mostra' }} le {{ chiuse.length }} già a posto
        </button>

        <div v-if="mostraChiuse" class="mt-3 space-y-2">
          <div
            v-for="v in chiuse"
            :key="v.chiave"
            class="flex flex-wrap items-center gap-2 rounded-md border bg-muted/30 p-3 text-sm"
          >
            <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <span class="text-muted-foreground">{{ v.titolo }}</span>
            <Badge variant="outline">{{ ETICHETTA_STATO[v.stato] ?? v.stato }}</Badge>
            <Button
              v-if="v.stato === 'spuntato' || v.stato === 'messo_da_parte'"
              size="sm"
              variant="ghost"
              class="ml-auto"
              @click="agisci(v, 'riapri')"
            >
              <RotateCcw class="mr-1 h-3 w-3" /> riapri
            </Button>
          </div>
        </div>
      </div>

      <!--
        ⚠️ **La strada di ritorno al rapporto, che prima non c'era.**

        Qui c'era una riga di testo con un link, disegnata `v-if="voci.length"`: spariva quando i
        controlli erano finiti, cioè esattamente quando il rapporto serve — è il documento che si
        allega al passaggio di consegne, e chi chiude la pagina di esito senza scaricarlo non
        aveva più modo di tornarci. Una ricevuta che si può vedere una volta sola non è una
        ricevuta.

        Ora è una scheda, sempre presente finché c'è un'importazione, con dentro il PDF.
      -->
      <Card v-if="props.puo_vedere_rapporto && props.importazioni.length">
        <CardHeader class="pb-3">
          <CardTitle class="flex items-center gap-2 text-base">
            <FileDown class="h-4 w-4" /> Le importazioni di questo condominio
          </CardTitle>
          <CardDescription>
            Il rapporto è la ricevuta di cosa è entrato e da quale riga di quale file: si allega al
            passaggio di consegne e si archivia.
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-2">
          <div
            v-for="i in props.importazioni"
            :key="i.uuid"
            class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
          >
            <span class="text-muted-foreground">
              {{ i.quando ?? 'in corso' }} · {{ i.record }} record ·
              <span class="font-mono text-xs uppercase">{{ i.uuid.slice(0, 8) }}</span>
            </span>
            <span class="flex items-center gap-2">
              <a :href="route('import.rapporto', i.uuid)" target="_blank">
                <Button variant="outline" size="sm">
                  <FileDown class="mr-1.5 h-3.5 w-3.5" /> Rapporto (PDF)
                </Button>
              </a>
              <Link :href="route('import.esito', i.uuid)">
                <Button variant="ghost" size="sm">Rivedi l'esito</Button>
              </Link>
            </span>
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
