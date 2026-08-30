<script setup lang="ts">
/**
 * S2 — Riconoscimento: cosa credo che sia ciascun file, e cosa ne ricavo.
 *
 * Tre scelte di disegno, tutte con un motivo:
 *
 * 1. **Il punteggio è un suggerimento, non un verdetto.** Su ogni riga c'è «Cambia»: quando il
 *    sistema sbaglia, l'ultima parola è dell'amministratore. Le alternative sono ordinate per
 *    probabilità, così non deve cercare il secondo classificato in un elenco alfabetico.
 * 2. **Cosa manca viene prima di cosa c'è.** Chi migra ha paura di perdere pezzi, non di averne
 *    troppi.
 * 3. **Le colonne ignorate si mostrano sempre**, anche quando è tutto verde: è l'unica risposta
 *    possibile a «ma le mie note dove sono finite?».
 */
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ImportGuide from '@/components/guides/ImportGuide.vue';
import { guideImport } from '@/pages/import/intestazione';
import BadgeStato from '@/components/import/BadgeStato.vue';
import vSelect from 'vue-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import Alert from '@/components/Alert.vue';
import { FileSpreadsheet, ChevronRight, Lock, AlertTriangle, X } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface FileRiconosciuto {
  id: number;
  nome: string;
  dimensione_kb: number;
  tipo: string | null;
  tipo_etichetta: string | null;
  produce: string | null;
  importabile: boolean;
  confidenza: number | null;
  fiducia: 'alta' | 'media' | 'nessuna';
  forzato: boolean;
  foglio: string | null;
  colonne_riconosciute: string[];
  colonne_ignorate: string[];
  errore: string | null;
}

const props = defineProps<{
  lotto: { uuid: string; stato: string };
  file: FileRiconosciuto[];
  tipi: { valore: string; etichetta: string; produce: string; importabile: boolean }[];
  mancanti: { cosa: string; serve_per: string; bloccante: boolean }[];
}>();

// Il flash si legge dai props di pagina: `<Alert />` senza props rende una scatola vuota
// con la sua × — un avviso che avvisa di niente, e in cima alla schermata più delicata.
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const mostraGuida = ref(false);

const headerBreadcrumbs = [{ title: 'Importa dati', href: route('import.index') }, { title: 'Riconoscimento' }];

// Il colore è il verdetto, il numero è il dettaglio: un 96% nero e un 72% nero non dicono
// niente di diverso, e il riconoscimento è proprio la cosa su cui l'utente deve poter esitare.
const statoFiducia = (f: string): 'ok' | 'warn' | 'neutro' =>
  f === 'alta' ? 'ok' : f === 'media' ? 'warn' : 'neutro';

function cambiaTipo(file: FileRiconosciuto, valore: string) {
  router.put(route('import.file.tipo', [props.lotto.uuid, file.id]), { report_type: valore }, {
    preserveScroll: true,
  });
}

/** Da un'opzione della tendina al valore che il server si aspetta. */
function valoreDelTipo(t: { valore: string }): string {
  return t.valore;
}

/**
 * La tendina ha scelto un tipo.
 *
 * ⚠️ **Il `null` si ignora invece di essere spedito.** `vue-select` emette `null` quando la
 * selezione si svuota, e mandarlo al server significherebbe chiedergli di dimenticare il tipo di
 * un file — cioè il contrario di quello che l'utente sta facendo. La tendina è `:clearable="false"`
 * proprio per non offrire quel gesto, e questa guardia è la seconda rete: la prima è una prop, e
 * una prop si toglie senza accorgersene.
 */
function tipoScelto(file: FileRiconosciuto, valore: string | null) {
  if (valore === null) {
    return;
  }

  cambiaTipo(file, valore);
}

function escludi(file: FileRiconosciuto) {
  router.delete(route('import.file.escludi', [props.lotto.uuid, file.id]), { preserveScroll: true });
}

const utilizzabili = () => props.file.filter((f) => f.tipo && f.importabile).length;
</script>

<template>
  <Head title="Riconoscimento dei file" />

  <AppLayout :breadcrumbs="[]">
    <div class="space-y-6 px-4 py-6">
      <PageHeaderGuide
        :page-title="`Ho riconosciuto ${props.file.filter((f) => f.tipo).length} file su ${props.file.length}`"
        page-subtitle="Controlla che sia tutto giusto. Se ho sbagliato, correggimi: il tipo lo decidi tu."
        :guides="guideImport"
        :breadcrumbs="headerBreadcrumbs"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="mostraGuida = true"
      />

      <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" />

      <Card>
        <CardContent class="pt-6">
          <div class="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>File</TableHead>
                <TableHead>Tipo riconosciuto</TableHead>
                <TableHead>Affidabilità</TableHead>
                <TableHead>Cosa ne ricavo</TableHead>
                <TableHead class="text-right">Azione</TableHead>
              </TableRow>
            </TableHeader>

            <TableBody>
              <TableRow v-for="f in props.file" :key="f.id">
                <TableCell>
                  <div class="flex items-start gap-2">
                    <FileSpreadsheet class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                    <div>
                      <div class="font-mono text-xs">{{ f.nome }}</div>
                      <div class="text-xs text-muted-foreground">
                        {{ f.dimensione_kb }} KB<template v-if="f.foglio"> · foglio «{{ f.foglio }}»</template>
                      </div>
                    </div>
                  </div>
                </TableCell>

                <TableCell>
                  <span v-if="f.tipo" class="text-sm">
                    {{ f.tipo_etichetta ?? f.tipo }}
                    <Badge v-if="f.forzato" variant="outline" class="ml-1">scelto da te</Badge>
                  </span>
                  <span v-else class="text-sm text-muted-foreground">non riconosciuto</span>
                </TableCell>

                <TableCell>
                  <BadgeStato v-if="f.confidenza !== null" :stato="statoFiducia(f.fiducia)">
                    {{ f.confidenza }}%
                  </BadgeStato>
                  <span v-else class="text-xs text-muted-foreground">—</span>
                </TableCell>

                <TableCell class="text-sm">
                  <span v-if="f.importabile">{{ f.produce }}</span>
                  <span v-else class="text-muted-foreground">
                    {{ f.produce ?? 'Non so cosa sia: scegli tu il tipo, oppure escludilo.' }}
                  </span>
                  <p v-if="f.errore" class="mt-1 text-xs text-destructive">{{ f.errore }}</p>
                </TableCell>

                <TableCell class="text-right">
                  <div class="flex items-center justify-end gap-1">
                    <!--
                      ⚠️ **`v-select` e non un `<select>` nudo.** Era l'unica tendina di tutto il
                      percorso di importazione disegnata dal browser invece che dal prodotto: in
                      mezzo a pulsanti e badge con il bordo del tema, si leggeva come un pezzo di
                      un'altra applicazione — e su questa riga si prende una decisione che cambia
                      cosa il file scrive in archivio. `vue-select` è la tendina del progetto (68
                      file la usano, contro quattro `<select>` rimasti), quindi qui si conforma,
                      come dice la regola della beta.38: *il default è conformarsi*.

                      `append-to-body` perché la tendina vive dentro una cella di tabella con
                      `overflow` proprio: senza, l'elenco si aprirebbe tagliato dalla riga.
                    -->
                    <v-select
                      class="w-56 text-xs"
                      :options="props.tipi"
                      label="etichetta"
                      :reduce="valoreDelTipo"
                      :model-value="f.tipo ?? null"
                      :clearable="false"
                      placeholder="Cambia…"
                      append-to-body
                      @update:model-value="(valore: string | null) => tipoScelto(f, valore)"
                    />
                    <!--
                      Era `variant="ghost"`, cioè testo nudo accanto a una tendina con il bordo:
                      l'unica azione distruttiva della schermata sembrava un'etichetta. Con il
                      bordo si legge come ciò che è — un'azione — e resta comunque più discreta
                      del pulsante primario, che è «Verifica i file».
                    -->
                    <Button variant="outline" size="sm" @click="escludi(f)">
                      <X class="mr-1.5 h-3.5 w-3.5" />
                      Escludi
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
          </div>
        </CardContent>
      </Card>

      <!-- Cosa manca, prima di cosa c'è -->
      <Card v-if="props.mancanti.length" class="border-amber-300 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/30">
        <CardHeader>
          <CardTitle class="flex items-center gap-2 text-base">
            <AlertTriangle class="h-4 w-4" />
            Cosa manca, se vuoi saperlo prima
          </CardTitle>
          <CardDescription>
            Puoi proseguire lo stesso e aggiungere questi file quando vuoi.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ul class="space-y-1 text-sm">
            <li v-for="m in props.mancanti" :key="m.cosa">
              Manca <strong>{{ m.cosa }}</strong>: serve per {{ m.serve_per }}.
              <Badge v-if="m.bloccante" variant="destructive" class="ml-1">senza, non si prosegue</Badge>
            </li>
          </ul>
        </CardContent>
      </Card>

      <!--
        Le colonne: **sempre**, anche quando è tutto verde — che è esattamente il caso in cui
        servono. È l'unica risposta possibile a «ma le mie note dove sono finite?», e una domanda
        che non trova risposta qui diventa un'email di assistenza. Il filtro guardava solo le
        ignorate, quindi su un file mappato alla perfezione il pannello spariva del tutto.
      -->
      <Collapsible
        v-for="f in props.file.filter((x) => x.colonne_riconosciute.length || x.colonne_ignorate.length)"
        :key="'col' + f.id"
      >
        <Card>
          <CollapsibleTrigger class="w-full">
            <CardHeader class="flex-row items-center justify-between space-y-0">
              <CardTitle class="text-sm font-medium">
                {{ f.nome }} — {{ f.colonne_riconosciute.length }} colonne riconosciute,
                {{ f.colonne_ignorate.length }} ignorate
              </CardTitle>
              <ChevronRight class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
          </CollapsibleTrigger>
          <CollapsibleContent>
            <CardContent class="grid gap-4 text-sm md:grid-cols-2">
              <div>
                <p class="mb-1 text-xs font-medium text-muted-foreground">Riconosciute</p>
                <div class="flex flex-wrap gap-1">
                  <BadgeStato v-for="c in f.colonne_riconosciute" :key="c" stato="ok">{{ c }}</BadgeStato>
                </div>
                <p v-if="!f.colonne_riconosciute.length" class="text-xs text-muted-foreground">
                  Nessuna.
                </p>
              </div>

              <div>
                <p class="mb-1 text-xs font-medium text-muted-foreground">Ignorate</p>
                <div v-if="f.colonne_ignorate.length" class="flex flex-wrap gap-1">
                  <Badge v-for="c in f.colonne_ignorate" :key="c" variant="outline">{{ c }}</Badge>
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                  <template v-if="f.colonne_ignorate.length">
                    Non hanno un corrispondente in Kondomanager. Se ti servono, scrivici.
                  </template>
                  <template v-else>
                    Nessuna: di questo file ho letto tutto.
                  </template>
                </p>
              </div>
            </CardContent>
          </CollapsibleContent>
        </Card>
      </Collapsible>

      <!--
        ⚠️ **«Sto solo leggendo» stava da solo sotto il pulsante, ed è il posto sbagliato.**

        È la frase che toglie la paura a chi sta per premere un pulsante su un archivio che non è
        suo, quindi va letta **mentre** si guarda quel pulsante, non dopo — sotto, in coda alla
        pagina, arrivava quando la decisione era già stata presa. Sale accanto al conteggio dei
        file, che è l'altra metà della stessa informazione: quanti ne uso, e che non scrivo niente.
      -->
      <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
        <div class="text-sm text-muted-foreground">
          {{ utilizzabili() }} file su {{ props.file.length }} verranno usati
          <span class="mt-0.5 flex items-center gap-1.5 text-xs">
            <Lock class="h-3.5 w-3.5 shrink-0" />
            Sto solo leggendo: niente è ancora entrato in Kondomanager.
          </span>
        </div>
        <Link :href="route('import.verifica', props.lotto.uuid)">
          <Button>Verifica i file →</Button>
        </Link>
      </div>
    </div>

    <ImportGuide v-model:open="mostraGuida" />
  </AppLayout>
</template>

<!--
  ⚠️ **Il foglio di stile di `vue-select` si importa qui, componente per componente.** È l'idioma
  del progetto — lo fanno tutti i file che usano la tendina — e non è ovvio: senza, il componente
  si monta e funziona, ma esce **senza bordo, con il valore sopra e la freccia sotto**. Non dà
  nessun errore, in console non compare niente, e a occhio sembra un problema di layout: l'ho visto
  solo guardando la pagina, che è il motivo per cui la verifica a video non si salta.
-->
<style src="vue-select/dist/vue-select.css"></style>
