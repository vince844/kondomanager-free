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
import { CheckCircle2, CircleAlert, Info, RotateCcw, Clock } from 'lucide-vue-next';
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
  messo_da_parte: 'non ti riguarda',
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

          <p v-if="!v.verificabile && v.perche" class="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
            <Info class="mr-1 inline h-3 w-3" />
            Questa non posso ricontrollarla da solo: {{ v.perche }}
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
            <span v-else class="text-xs text-muted-foreground">
              Si chiude da sola appena l'avrai sistemata.
            </span>

            <Button size="sm" variant="ghost" @click="agisci(v, 'metti_da_parte')">
              Non mi riguarda
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

      <p v-if="props.puo_vedere_rapporto && props.voci.length" class="border-t pt-4 text-xs text-muted-foreground">
        Queste voci arrivano dalle importazioni di questo condominio.
        <Link :href="route('import.esito', props.voci[0].lotto)" class="underline">
          Vedi il rapporto completo dell'ultima
        </Link>
      </p>
    </div>
  </AppLayout>
</template>
