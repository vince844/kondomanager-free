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
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ImportGuide from '@/components/guides/ImportGuide.vue';
import { guideImport } from '@/pages/import/intestazione';
import BadgeStato from '@/components/import/BadgeStato.vue';
import StepperImport from '@/components/import/StepperImport.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import Alert from '@/components/Alert.vue';
import { Lock, ChevronRight, CircleAlert, CircleHelp, Info, Building2, CalendarDays } from 'lucide-vue-next';
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
  // `null` quando i file dichiarano da soli il condominio: in quel caso non c'è niente da
  // scegliere, e la testata della stampa vince comunque sulla scelta a mano.
  destinazione: {
    condomini: { id: number; nome: string; codice_fiscale: string | null }[];
    // L'esercizio **aperto** di ciascun condominio, per id: è un fatto da dichiarare, non una
    // scelta da offrire. Manca la voce per i condomìni che non ne hanno uno aperto.
    esercizio_aperto: Record<string, { nome: string; periodo: string }>;
    scelto_condominio: number | null;
    // Vero quando all'utente manca «Modifica condomini»: l'elenco arriva vuoto apposta, e la
    // ragione va detta — altrimenti sembra che non ci siano condomìni in archivio.
    senza_permesso: boolean;
  } | null;
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

/**
 * «In quale condominio vanno questi dati?»
 *
 * Serve a chi ha esportato da Danea con «Import/Export tramite Excel» invece che dalle stampe:
 * quegli export sono elenchi senza testata, quindi nessun file dice a chi appartengono le unità
 * e le persone che contengono. Senza questa tendina l'unica strada era tornare in Danea e
 * rifare l'esportazione in un altro modo — e chi non ha consuntivi non può nemmeno farlo.
 *
 * La tendina tiene una **stringa**: è quello che il componente restituisce, e convertire in
 * fondo, una volta sola, evita di avere in giro un valore che a volte è numero e a volte no.
 */

/**
 * «In quale condominio vanno questi dati?»
 *
 * ⚠️ **L'esercizio non si sceglie.** Per una manciata di ore è stato una seconda tendina, ed era
 * la superficie d'errore peggiore dell'intero importatore: la data di inizio dell'esercizio
 * diventa la `data_inizio` di ogni titolarità scritta, quindi puntare all'anno sbagliato non dà
 * nessun errore e nessun avviso — scrive numeri giusti nel periodo sbagliato, e ci si accorge al
 * primo riparto. La tendina elencava per giunta anche gli esercizi chiusi. Ora il programma usa
 * quello **aperto**, come fa ovunque, e qui lo dichiara.
 */
const sceltoCondominio = ref(props.destinazione?.scelto_condominio?.toString() ?? '');

const nomeCondominioScelto = computed(
  () => props.destinazione?.condomini.find((c) => c.id.toString() === sceltoCondominio.value)?.nome ?? '',
);

const esercizioDelCondominio = computed(() =>
  sceltoCondominio.value === '' ? null : (props.destinazione?.esercizio_aperto[sceltoCondominio.value] ?? null),
);

// Il piede della pagina deve dire «sceglilo qui sopra» **solo** se non manca altro: con anche
// un file illeggibile, quel messaggio manderebbe a cercare la soluzione nel posto sbagliato.
const mancaSoloIlCondominio = computed(
  () =>
    props.destinazione !== null &&
    // Se non c'è niente da scegliere, «sceglilo qui sopra» è un rimando a un vicolo cieco: due
    // frasi che si contraddicono a due righe di distanza.
    props.destinazione.condomini.length > 0 &&
    props.livelli.every((l) => l.errori.every((r) => r.codice === 'condominio.nessun_file_lo_dichiara')),
);

const salvataggioInCorso = ref(false);

function salvaDestinazione() {
  if (sceltoCondominio.value === '') return;

  router.put(
    route('import.destinazione', props.lotto.uuid),
    { condominio_id: Number(sceltoCondominio.value) },
    {
      preserveScroll: true,
      onStart: () => (salvataggioInCorso.value = true),
      onFinish: () => (salvataggioInCorso.value = false),
    },
  );
}
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

      <!--
        «In quale condominio vanno questi dati?» — compare solo quando nessun file
        lo dichiara, e sta **in fondo**, non dentro il riquadro del condominio. Quel riquadro
        esiste solo finché esiste l'errore: appena si sceglieva la destinazione spariva
        portandosi via anche le tendine, e chi aveva sbagliato condominio non aveva più modo
        né di accorgersene né di correggere. Trovato camminando dentro il flusso, non da un test.
      -->
      <div
        v-if="props.destinazione"
        class="rounded-lg border bg-card p-6 shadow-sm"
      >
        <p class="flex items-center gap-2 text-sm font-medium">
          <Building2 class="h-4 w-4 shrink-0 text-muted-foreground" />
          In quale condominio vanno questi dati?
        </p>

        <p v-if="props.destinazione.senza_permesso" class="mt-2 text-sm text-muted-foreground">
          Importare dentro un condominio che esiste già significa modificarlo, e a te manca il
          permesso «Modifica condomini»: chiedilo a chi amministra lo studio, oppure carica una
          stampa che porti la testata — quella dice da sé a quale condominio appartengono i dati.
        </p>

        <p v-else-if="!props.destinazione.condomini.length" class="mt-2 text-sm text-muted-foreground">
          Non hai ancora nessun condominio in archivio, quindi non c'è niente da scegliere. Creane
          uno — <strong>con il suo esercizio</strong> — dall'elenco condomìni e torna qui: i file
          caricati restano dove sono. Oppure carica una stampa che porti la testata, e
          l'importazione crea da sé sia il condominio sia l'esercizio.
        </p>

        <template v-else>
          <p class="mt-1 text-sm text-muted-foreground">
            Unità, persone e tabelle verranno aggiunte al condominio che scegli. Niente viene
            scritto adesso: la conferma è alla fine, dopo l'anteprima.
          </p>

          <!--
            **L'asimmetria fra le due strade va detta qui, non scoperta dopo.**

            Con una stampa che porta la testata, `LivelloCondominio` crea il condominio (piano dei
            conti compreso) e `LivelloEsercizi` crea l'esercizio dalla riga «Periodo:», già aperto
            e con la sua gestione: non serve preparare niente. Senza testata non si ricavano né il
            nome né le date, quindi entrambi devono esistere già — ed è una cosa che l'utente
            scopriva solo scegliendo, o peggio dopo la conferma.
          -->
          <p class="mt-2 rounded-md bg-muted/60 p-3 text-sm text-muted-foreground">
            Il condominio deve essere <strong>già in archivio, con un esercizio aperto</strong>: da
            questi file non si ricavano né il suo nome né le date dell'esercizio. Se non c'è
            ancora, crealo dall'elenco condomìni e torna qui.
            <br />
            Con una stampa che porta la testata — il «Consuntivo ripartizioni per unità» — non
            servirebbe: quella li dichiara, e l'importazione crea condominio ed esercizio da sé.
          </p>

          <div class="mt-4 max-w-md">
            <label class="mb-1 block text-sm font-medium leading-none">Condominio</label>
            <Select v-model="sceltoCondominio">
              <SelectTrigger class="w-full">
                <SelectValue>
                  {{ nomeCondominioScelto || 'Scegli il condominio' }}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="c in props.destinazione.condomini"
                  :key="c.id"
                  :value="c.id.toString()"
                >
                  {{ c.nome }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <!--
            L'esercizio si **dichiara**, non si sceglie. La sua data di inizio diventa la data di
            inizio di ogni titolarità scritta: una tendina qui sarebbe stata un modo di sbagliare
            l'anno senza ricevere nessun segnale. Il programma usa quello aperto, come ovunque.
          -->
          <p v-if="sceltoCondominio" class="mt-3 flex items-start gap-2 text-sm">
            <CalendarDays class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <span v-if="esercizioDelCondominio">
              Esercizio <strong>{{ esercizioDelCondominio.nome }}</strong>
              ({{ esercizioDelCondominio.periodo }}) — è quello aperto di questo condominio, ed è
              dove verranno registrati chi possiede cosa e i saldi di apertura.
            </span>
            <!--
              ⚠️ **Qui c'era scritto «unità, persone e tabelle entrerebbero lo stesso»: è falso.**
              `ImportRunner::esegui()` si ferma al primo livello che non passa, ed «Esercizi» è il
              secondo: cadendo lì, i cinque livelli dopo non vengono nemmeno tentati. Misurato:
              zero unità scritte. Una schermata che promette metà importazione e ne consegna zero
              è peggio di una che dice di no.
            -->
            <span v-else class="text-destructive">
              Questo condominio non ha un esercizio aperto, e senza non entra <strong>niente</strong>:
              ogni unità, persona e tabella vive dentro un esercizio. Aprine uno dal condominio e
              torna qui — i file caricati restano dove sono.
            </span>
          </p>

          <div class="mt-4">
            <Button :disabled="!sceltoCondominio || salvataggioInCorso" @click="salvaDestinazione">
              Usa questo condominio
            </Button>
          </div>
        </template>
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
          <template v-else-if="mancaSoloIlCondominio">
            <!--
              Questo errore non si corregge nel file e non si ricarica niente: si risponde qui
              sopra, scegliendo il condominio. Mandare a rifare l'esportazione chi non ha
              consuntivi da esportare è un vicolo cieco.
            -->
            Manca solo il condominio di destinazione: sceglilo qui sopra.
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
