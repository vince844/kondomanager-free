<script setup lang="ts">
/**
 * S3 — Verifica: i quattro contatori e i rilievi che li spiegano (§10.2, §14.1).
 *
 * Tre scelte che non sono estetiche:
 *
 * 1. **Quattro contatori, non cinque.** Gli avvisi non ne hanno uno: una riga con un avviso è
 *    una riga **valida**, e dargli un riquadro accanto agli errori la farebbe sembrare un
 *    problema — in una schermata che esiste per distinguere il grave dal trascurabile.
 * 2. **Ogni errore porta il suo rimedio.** «Riga 14: ruolo non valido» è una diagnosi; senza il
 *    «cosa fare», l'unica strada che resta all'amministratore è rinunciare.
 * 3. **Il colore lo usa solo lo stato.** La palette è neutra apposta: se tutta la pagina è
 *    colorata, il rosso non si vede più.
 */
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ImportGuide from '@/components/guides/ImportGuide.vue';
import { guideImport } from '@/pages/import/intestazione';
import BadgeStato from '@/components/import/BadgeStato.vue';
import StepperImport from '@/components/import/StepperImport.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import Alert from '@/components/Alert.vue';
import { Lock, ChevronRight, CircleAlert, CircleHelp, Info } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface RilievoUI {
  severita: string;
  codice: string;
  messaggio: string;
  rimedio: string | null;
  riga: number | null;
  colonna: string | null;
}

interface LivelloUI {
  chiave: string;
  etichetta: string;
  contatori: { totali: number; valide: number; da_decidere: number; errori: number };
  confermabile: boolean;
  errori: RilievoUI[];
  da_decidere: RilievoUI[];
  avvisi: RilievoUI[];
}

const props = defineProps<{
  lotto: { uuid: string; stato: string };
  livelli: LivelloUI[];
  letture: { file: string; tipo: string; righe: number }[];
  confermabile: boolean;
  senza_errori: boolean;
  passi: { chiave: string; etichetta: string; stato: 'pronto' | 'errori' | 'assente' }[];
}>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const mostraGuida = ref(false);

const headerBreadcrumbs = [{ title: 'Importa dati', href: route('import.index') }, { title: 'Riconoscimento', href: route('import.riconoscimento', props.lotto.uuid) }, { title: 'Verifica' }];

const totali = computed(() => props.livelli.reduce(
  (acc, l) => ({
    totali: acc.totali + l.contatori.totali,
    valide: acc.valide + l.contatori.valide,
    da_decidere: acc.da_decidere + l.contatori.da_decidere,
    errori: acc.errori + l.contatori.errori,
  }),
  { totali: 0, valide: 0, da_decidere: 0, errori: 0 },
));

const avvisiTotali = computed(() => props.livelli.reduce((n, l) => n + l.avvisi.length, 0));
</script>

<template>
  <Head title="Verifica dei file" />

  <AppLayout :breadcrumbs="[]">
    <div class="space-y-6 px-4 py-6">
      <PageHeaderGuide
        page-title="Verifica"
        page-subtitle="Ogni riga controllata prima di scrivere qualsiasi cosa."
        :guides="guideImport"
        :breadcrumbs="headerBreadcrumbs"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="mostraGuida = true"
      />

      <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" />

      <!--
        Dove sei e quanto manca. I pallini non sono cliccabili apposta: il percorso resta una
        pagina sola, e lo stepper serve a orientare, non a rimettere la navigazione a tappe che
        creava il vicolo cieco (decisioni mostrate qui, rispondibili solo nella schermata dopo).
      -->
      <Card>
        <CardContent class="pt-6">
          <StepperImport :passi="props.passi" />
        </CardContent>
      </Card>

      <!-- I quattro contatori. Non cinque: l'avviso non toglie validità a una riga. -->
      <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Card>
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Righe lette</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ totali.totali }}</p>
          </CardContent>
        </Card>

        <Card :class="totali.valide ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/30' : ''">
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Valide</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums" :class="totali.valide ? 'text-emerald-700 dark:text-emerald-400' : ''">
              {{ totali.valide }}
            </p>
          </CardContent>
        </Card>

        <Card :class="totali.da_decidere ? 'border-amber-300 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/30' : ''">
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Da decidere</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums" :class="totali.da_decidere ? 'text-amber-700 dark:text-amber-400' : ''">
              {{ totali.da_decidere }}
            </p>
          </CardContent>
        </Card>

        <Card :class="totali.errori ? 'border-destructive/40 bg-destructive/5' : ''">
          <CardContent class="pt-6">
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Errori</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums" :class="totali.errori ? 'text-destructive' : ''">
              {{ totali.errori }}
            </p>
          </CardContent>
        </Card>
      </div>

      <!-- Cosa ho letto da quale file: risponde a «ha visto tutto?» prima che lo chieda -->
      <Card v-if="props.letture.length">
        <CardHeader>
          <CardTitle class="text-base">Cosa ho letto</CardTitle>
        </CardHeader>
        <CardContent>
          <ul class="space-y-1 text-sm">
            <li v-for="l in props.letture" :key="l.file + l.tipo" class="flex justify-between gap-4">
              <span><span class="font-mono text-xs">{{ l.file }}</span> → {{ l.tipo }}</span>
              <span class="tabular-nums text-muted-foreground">{{ l.righe }} righe</span>
            </li>
          </ul>
        </CardContent>
      </Card>

      <div v-for="livello in props.livelli" :key="livello.chiave" class="space-y-3">
        <Card>
          <CardHeader class="flex-row items-start justify-between space-y-0">
            <div>
              <CardTitle class="text-base">{{ livello.etichetta }}</CardTitle>
              <CardDescription>
                {{ livello.contatori.valide }} valide su {{ livello.contatori.totali }}
              </CardDescription>
            </div>
            <!--
              Tre stati e non due. «Non confermabile» copriva insieme gli errori e le decisioni,
              e sul livello con due nomi doppi da dividere usciva un «da sistemare» rosso mentre
              gli errori erano zero e il piede della pagina diceva che si poteva proseguire: la
              stessa confusione fra errore e decisione che bloccava il passaggio, rimasta sul
              badge dopo essere stata tolta dalla pagina.
            -->
            <Badge v-if="livello.contatori.errori" variant="destructive">da sistemare</Badge>
            <BadgeStato v-else-if="livello.contatori.da_decidere" stato="warn">
              {{ livello.contatori.da_decidere }} da decidere
            </BadgeStato>
            <BadgeStato v-else stato="ok">pronto</BadgeStato>
          </CardHeader>

          <CardContent class="space-y-3">
            <!-- Gli errori: ognuno con la riga come la vede in Excel, e il rimedio -->
            <div
              v-for="(r, i) in livello.errori"
              :key="'e' + i"
              class="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm"
            >
              <p class="flex items-start gap-2 font-medium">
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                <span>
                  <template v-if="r.riga">Riga {{ r.riga }}<template v-if="r.colonna">, colonna «{{ r.colonna }}»</template> — </template>
                  {{ r.messaggio }}
                </span>
              </p>
              <p v-if="r.rimedio" class="mt-1 pl-6 text-muted-foreground">{{ r.rimedio }}</p>
            </div>

            <!-- Le decisioni: non sono errori, sono cose che non possiamo scegliere noi -->
            <div
              v-for="(r, i) in livello.da_decidere"
              :key="'d' + i"
              class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900/60 dark:bg-amber-950/30"
            >
              <p class="flex items-start gap-2 font-medium">
                <CircleHelp class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                <span>
                  <template v-if="r.riga">Riga {{ r.riga }} — </template>{{ r.messaggio }}
                </span>
              </p>
              <p v-if="r.rimedio" class="mt-1 pl-6 text-muted-foreground">{{ r.rimedio }}</p>
            </div>

            <!-- Gli avvisi: richiudibili, perché non tolgono validità a niente -->
            <Collapsible v-if="livello.avvisi.length">
              <CollapsibleTrigger class="flex w-full items-center justify-between rounded-md border px-3 py-2 text-sm">
                <span class="flex items-center gap-2">
                  <Info class="h-4 w-4 text-muted-foreground" />
                  {{ livello.avvisi.length }}
                  {{ livello.avvisi.length === 1 ? 'avviso' : 'avvisi' }} — non bloccano l'importazione
                </span>
                <ChevronRight class="h-4 w-4 text-muted-foreground" />
              </CollapsibleTrigger>
              <CollapsibleContent class="space-y-2 pt-2">
                <div v-for="(r, i) in livello.avvisi" :key="'a' + i" class="rounded-md bg-muted/50 p-3 text-sm">
                  <p>
                    <template v-if="r.riga">Riga {{ r.riga }} — </template>{{ r.messaggio }}
                  </p>
                  <p v-if="r.rimedio" class="mt-1 text-muted-foreground">{{ r.rimedio }}</p>
                </div>
              </CollapsibleContent>
            </Collapsible>

            <p v-if="!livello.errori.length && !livello.da_decidere.length && !livello.avvisi.length"
               class="text-sm text-muted-foreground">
              Nessun rilievo.
            </p>
          </CardContent>
        </Card>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
        <span class="text-sm text-muted-foreground">
          <template v-if="props.confermabile">
            Tutto pronto: {{ avvisiTotali }} {{ avvisiTotali === 1 ? 'avviso' : 'avvisi' }} da leggere, niente da sistemare.
          </template>
          <template v-else-if="props.senza_errori">
            Nessun errore. Restano {{ totali.da_decidere }}
            {{ totali.da_decidere === 1 ? 'decisione' : 'decisioni' }} da prendere: le rispondi
            nella schermata di conferma, dove vedi anche cosa cambia.
          </template>
          <template v-else>
            Ci sono errori da correggere nel file: sistemali e ricarica.
          </template>
        </span>

        <div class="flex gap-2">
          <Link :href="route('import.riconoscimento', props.lotto.uuid)">
            <Button variant="outline">Indietro</Button>
          </Link>
          <Link v-if="props.senza_errori" :href="route('import.anteprima', props.lotto.uuid)">
            <Button>Vedi cosa entrerà →</Button>
          </Link>
          <Button v-else disabled>Vedi cosa entrerà →</Button>
        </div>
      </div>

      <p class="flex items-center gap-2 text-xs text-muted-foreground">
        <Lock class="h-3.5 w-3.5" />
        Niente è ancora entrato in Kondomanager.
      </p>
    </div>

    <ImportGuide v-model:open="mostraGuida" />
  </AppLayout>
</template>
