<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { usePage, Link } from '@inertiajs/vue3';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';
import { getLocalTimeZone, DateFormatter, parseDate, CalendarDate } from '@internationalized/date';
import { Search, Plus, Zap, X, Calendar as CalendarIcon, UploadCloud } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

// Stesso Popover + RangeCalendar già usato nel toolbar di Eventi
// (resources/js/components/eventi/DataTableToolbar.vue) per lo stesso identico
// bisogno — un intervallo di date su un elenco — invece di due input type="date"
// nudi che l'amministratore doveva interpretare da soli.
// ⚠️ Anno a quattro cifre, non `dateStyle: 'short'`: quello scrive `01/09/26` mentre la colonna
// «Date» della tabella subito sotto scrive `01/09/2026` (`toLocaleDateString('it-IT')`), e due
// formati della stessa data nella stessa schermata si notano.
const df = new DateFormatter('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });

defineProps<{ table: Table<any> }>();
const page = usePage<{
  condominio: Building
  statiPagamento: { value: string; label: string }[]
  filters: {
    search?: string
    stato_pagamento?: string
    stato_approvazione?: string
    data_da?: string
    data_a?: string
  }
}>();
const { generateRoute } = usePermission();
const condominioId = computed(() => page.props.condominio.id);

const globalFilter = ref(page.props.filters?.search || '');
const statoPagamento = ref(page.props.filters?.stato_pagamento || '');

// ⚠️ `parseDate()` **lancia** su tutto ciò che non è esattamente `YYYY-MM-DD` valido — provato:
// `pippo`, `01/09/2026`, `2026-9-1`, `''` e perfino `2026-02-30` («Value out of range»), quindi
// una regex da sola non basterebbe. E il valore arriva da fuori: `FatturaPassivaController`
// **non valida** `data_da`/`data_a` e li rimanda grezzi nei props, quindi un indirizzo con una
// data storta è raggiungibile con un segnalibro vecchio o un collegamento incollato male.
// Senza questa guardia l'eccezione parte nel `<script setup>` e Vue non disegna il componente:
// spariscono la barra dei filtri, «Azzera filtri» e **«Nuova fattura»**, cioè l'azione principale
// della pagina. Un valore illeggibile deve valere «nessun filtro», che è il degrado che dava
// l'`<input type="date">` di prima.
const leggiData = (v: unknown) => {
  if (typeof v !== 'string' || v === '') return undefined;
  try {
    return parseDate(v);
  } catch {
    return undefined;
  }
};

// `dateRange` porta i `CalendarDate` che il componente capisce; `data_da`/`data_a` (i nomi
// che il backend si aspetta) si ricavano da lì, non il contrario.
//
// ⚠️ Un intervallo rovesciato va raddrizzato **in ingresso**: `RangeCalendar` scambia i due
// capi solo in uscita, quando li scegli tu, quindi un `?data_da=10/09&data_a=01/09` scritto a
// mano nell'indirizzo resterebbe rovesciato per sempre — il calendario lo mostrerebbe al
// contrario e il server risponderebbe con un elenco vuoto senza che niente lo spieghi.
const inizioLetto = leggiData(page.props.filters?.data_da);
const fineLetta = leggiData(page.props.filters?.data_a);
const daRaddrizzare = !!(inizioLetto && fineLetta && inizioLetto.compare(fineLetta) > 0);

const dateRange = ref<{ start: any; end: any }>(
  daRaddrizzare ? { start: fineLetta, end: inizioLetto } : { start: inizioLetto, end: fineLetta }
);

// ⚠️ Il verso di una data **sola** vive qui, non dentro `dateRange`, e la ragione è misurata:
// `RangeCalendar` normalizza `{ start: undefined, end: X }` rimettendo X come inizio, quindi
// tenere il verso nel modello del calendario significa vederselo riscrivere sotto — l'indirizzo
// diceva «fino al 10/09» e il pulsante «Dal 10/09». Il calendario tiene sempre la data come
// inizio, che è come vuole ragionare lui; il significato lo teniamo noi.
const versoSingola = ref<'dal' | 'fino'>(
  !inizioLetto && fineLetta ? 'fino' : 'dal'
);

// Con la sola `data_a` nell'indirizzo il calendario deve comunque ricevere la data come inizio,
// altrimenti non la disegna selezionata.
if (!inizioLetto && fineLetta) {
  dateRange.value = { start: fineLetta, end: undefined };
}

// Il pannello del calendario: serve saperlo chiuso per decidere quando un intervallo a metà
// va considerato una scelta finita (vedi il watch più sotto).
const calendarioAperto = ref(false);

// ⚠️ Niente `.toDate(timezone)` + `.toISOString()`: un `CalendarDate` non ha ora né fuso,
// «30 agosto» e basta, ma convertirlo in `Date` lo ancora alla mezzanotte locale — e
// `toISOString()` la riporta in UTC, che su un fuso **avanti** rispetto a UTC manda la data
// al giorno prima. Misurato: `new Date(2026, 8, 30)` a Roma (UTC+2 in estate) dà `2026-09-29`,
// a New York (UTC-4) dà `2026-09-30`. Cioè il difetto colpisce **noi** e non gli altri, che è
// il verso opposto di come questo commento lo raccontava nella prima stesura.
// `CalendarDate` sa già scriversi in `YYYY-MM-DD` da solo, senza passare da un'ora che non ha.
//
// ⚠️ `instanceof CalendarDate` e non `typeof date.toString`: `toString` ce l'hanno tutti gli
// oggetti, quindi il controllo precedente accettava qualunque cosa finisse in `dateRange` e ne
// scriveva la rappresentazione nell'indirizzo (`[object Object]` compreso).
const convertCalendarDateToString = (date: unknown): string | undefined => {
  return date instanceof CalendarDate ? date.toString() : undefined;
};

const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.fatture.index'), { condominio: condominioId.value }),
)

// Ogni filtro viaggia sempre, anche vuoto: `null` significa **togli**, mentre ometterlo
// lascerebbe in piedi quello di prima e non ci sarebbe più modo di svuotarlo.
// `stato_approvazione` non ha un controllo qui (arriva dalla card «sfori motivati»):
// non si passa, così il composable lo riporta com'è insieme a righe per pagina e ordinamento.
// I due capi che vanno davvero al server: con un intervallo completo sono inizio e fine, con una
// data sola dipende dal verso scelto — ed è l'unico punto in cui `versoSingola` conta.
const capiDaInviare = () => {
  const inizio = convertCalendarDateToString(dateRange.value.start) || null;
  const fine = convertCalendarDateToString(dateRange.value.end) || null;

  if (inizio && !fine && versoSingola.value === 'fino') {
    return { data_da: null, data_a: inizio };
  }
  return { data_da: inizio, data_a: fine };
};

const applyFilters = () => {
  const { data_da, data_a } = capiDaInviare();
  filtra({
    search: globalFilter.value || null,
    stato_pagamento: statoPagamento.value || null,
    data_da,
    data_a,
  })
}

watchDebounced(globalFilter, applyFilters, { debounce: 300 })
watch(statoPagamento, applyFilters)

// ⚠️ **Una sola richiesta per ogni intenzione dell'utente**, ed è una guardia contro un difetto
// misurato, non un'ottimizzazione.
//
// `vai()` in useTabellaServer **scarta** (non accoda) una richiesta se un'altra è in volo:
// `if (inCorso.value) return`, e `inCorso` torna false solo a round-trip concluso. Su un
// calendario a intervallo i due clic — inizio e fine — sono l'interazione normale e cadono
// dentro quella finestra: la seconda richiesta spariva, il pulsante mostrava «01/09 – 10/09»
// e l'indirizzo portava solo `data_da`. Il filtro dichiarava un intervallo e ne applicava
// un altro, in silenzio e in modo permanente.
//
// ⚠️ La prima diagnosi era sbagliata e vale la pena scriverlo: avevo attribuito la perdita a un
// watch non profondo e «corretto» con `deep: true`. La verifica a video era passata **solo perché
// avevo messo una pausa fra i due clic** — una pausa che un utente vero non fa. La causa era la
// guardia, non la profondità del watch.
//
// Qui si interroga il server quando la scelta è **finita**: intervallo completo, oppure svuotato.
// Un intervallo a metà è uno stato transitorio del calendario, non un filtro che qualcuno ha
// chiesto — e se l'utente chiude il pannello lasciandolo a metà, quella diventa la sua scelta e
// la manda il watch su `calendarioAperto` qui sotto (così «dal 1° settembre in poi», che i due
// campi separati permettevano, non si perde).
watch(dateRange, () => {
  const { start, end } = dateRange.value;
  if (start && !end) return;
  applyFilters();
});

watch(calendarioAperto, (aperto) => {
  if (aperto) return;
  const { start, end } = dateRange.value;
  if (start && !end) applyFilters();
});

// Se l'intervallo arrivava rovesciato dall'indirizzo, raddrizzarlo solo a video non basta:
// il server continuerebbe a rispondere con la coppia rovesciata (elenco vuoto) mentre il
// pulsante mostra un intervallo sensato — cioè di nuovo un filtro che dichiara una cosa e ne
// applica un'altra. Si riallinea una volta sola, all'apertura.
if (daRaddrizzare) applyFilters();

const isFiltered = computed(() =>
  !!(globalFilter.value || statoPagamento.value || dateRange.value.start || dateRange.value.end || page.props.filters?.stato_approvazione)
)

const formattedRange = computed(() => {
  const start = dateRange.value.start?.toDate ? dateRange.value.start.toDate(getLocalTimeZone()) : undefined;
  const end = dateRange.value.end?.toDate ? dateRange.value.end.toDate(getLocalTimeZone()) : undefined;

  // ⚠️ Il ripiego dice **quale** data si filtra, non «una data qualunque»: in questo modulo le
  // date sono tre (documento, scadenza, pagamento) e qui si filtra la data del documento —
  // informazione che prima viveva solo nel `title` dei due campi, cioè si vedeva solo col mouse
  // sopra e mai su un touch. Serve ancora di più quando questo filtro andrà sulle altre pagine,
  // dove la data filtrata è un'altra (negli incassi è la data di registrazione).
  if (start && end) return `${df.format(start)} – ${df.format(end)}`;
  if (start) return versoSingola.value === 'fino' ? `Fino al ${df.format(start)}` : `Dal ${df.format(start)}`;
  return 'Data documento';
});

// ⚠️ Il calendario tratta il primo clic **sempre** come inizio: «tutte le fatture fino al 31/12»
// — che i due campi separati permettevano, e che il server sa ancora fare — non era più
// esprimibile da nessuna sequenza di clic. Invece di rimettere due campi, si chiede il verso
// **solo nel momento in cui la domanda esiste**: quando è stata scelta una data sola.
const soloUnaData = computed(() => !!dateRange.value.start && !dateRange.value.end);

// Nessuno dei due tocca `dateRange`: cambiano il significato, non il dato del calendario.
// La richiesta la manda il watch sulla chiusura del pannello, così ne parte una sola.
const usaComeInizio = () => {
  versoSingola.value = 'dal';
  calendarioAperto.value = false;
};

const usaComeFine = () => {
  versoSingola.value = 'fino';
  calendarioAperto.value = false;
};

// ⚠️ La guardia non è pignoleria: `clearDateFilter` riassegna un oggetto **nuovo**, e il watch
// scatta sull'identità, non sul contenuto. Senza questo `return`, «Cancella» a filtro già vuoto
// faceva ripartire un giro completo di richiesta al server per non cambiare niente.
const clearDateFilter = () => {
  const { start, end } = dateRange.value;
  if (!start && !end) return;
  // Il verso torna al predefinito insieme al dato: un «fino al» dimenticato si applicherebbe
  // alla prossima data scelta, che nessuno ha chiesto.
  versoSingola.value = 'dal';
  dateRange.value = { start: undefined, end: undefined };
};

// ⚠️ L'azzeramento passa dal composable come ogni altro filtro, invece di affiancargli un
// `router.get` a mano. Prima ne partivano **tre** di richieste da un clic solo — quella scritta
// qui più quelle dei watch che scattavano sulle assegnazioni — e le ultime due ricostruivano i
// parametri da `page.props.filters`, che a quel punto è ancora quello vecchio: `stato_approvazione`
// tornava dentro. Effetto per l'amministratore: arrivato dalla card «sfori motivati», «Azzera
// filtri» non toglieva il filtro sugli sfori, e ricliccarlo rifaceva lo stesso giro.
// In `vai()` un `null` significa **togli**, quindi vanno nominati tutti, compreso
// `stato_approvazione`, che non ha un controllo in questa barra ma è comunque un filtro attivo.
const resetFilters = () => {
  globalFilter.value = ''
  statoPagamento.value = ''
  versoSingola.value = 'dal'
  dateRange.value = { start: undefined, end: undefined }

  filtra({
    search: null,
    stato_pagamento: null,
    stato_approvazione: null,
    data_da: null,
    data_a: null,
  })
}

</script>

<template>
  <div class="flex flex-wrap items-center justify-between w-full gap-2">

    <!-- ⚠️ Tre pezzi che lavorano insieme, e nessuno dei tre è decorativo — misurati
         a 1440, 1280 e 1024 px il 03/09/2026 dopo la segnalazione di Vincenzo
         («il layout del filtro è sballato»).
         1. `flex-nowrap` qui: i filtri non vanno mai a capo **fra di loro**. Era questo
            il difetto originale — appena compariva «Azzera filtri» quel pulsante
            scendeva da solo su una seconda riga mentre i tre pulsanti a destra
            restavano centrati sulla prima: uno scalino.
         2. `flex-1` + la ricerca elastica (sotto): la pressione di spazio la assorbe
            la casella di ricerca, che si stringe da 250 a 140 px prima che succeda
            altro. A 1440 basta questo e resta tutto su una riga.
         3. `min-w-min`: sotto i ~673 px di contenuto minimo la riga **non si comprime
            più**, e il `flex-wrap` del contenitore esterno fa scendere il gruppo dei
            pulsanti **in blocco** (resta a destra grazie a `ml-auto`). Senza, il
            contenuto veniva tagliato: a 1024 px si leggeva «Azz». -->
    <div class="flex flex-nowrap items-center gap-2 flex-1 min-w-min">

      <!-- Ricerca libera — l'unico elemento elastico della riga: `flex-1` con un
           minimo sotto cui non scende e un massimo oltre cui non cresce. -->
      <div class="relative flex-1 min-w-[140px] max-w-[250px]">
        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
          <Search class="h-4 w-4" />
        </div>
        <!-- ⚠️ Serve anche `md:text-xs`: la classe base dell'Input è `text-base md:text-sm`,
             e una `text-xs` liscia perde contro la variante responsive da `md` in su. Con il
             solo `text-xs` la ricerca restava a 14px mentre gli altri filtri della riga stanno
             a 12px — misurato: era una correzione che non correggeva niente sul desktop. -->
        <Input
          placeholder="Filtra per numero o fornitore..."
          v-model="globalFilter"
          class="pl-9 h-8 w-full text-xs md:text-xs"
        />
      </div>

      <!-- Stato pagamento -->
      <Select v-model="statoPagamento">
        <SelectTrigger class="h-8 w-[170px] shrink-0 text-xs style-chooser">
          <SelectValue placeholder="Stato pagamento" />
        </SelectTrigger>
        <SelectContent position="popper" :style="{ width: 'var(--reka-select-trigger-width)' }">
          <SelectItem v-for="stato in page.props.statiPagamento" :key="stato.value" :value="stato.value">
            {{ stato.label }}
          </SelectItem>
        </SelectContent>
      </Select>

      <!-- Intervallo data documento — Popover + RangeCalendar, lo stesso componente già
           usato nel toolbar di Eventi per lo stesso bisogno. -->
      <Popover v-model:open="calendarioAperto">
        <PopoverTrigger as-child>
          <Button
            variant="outline"
            class="h-8 justify-start text-left font-normal text-xs w-[210px] shrink-0"
            :class="!(dateRange.start || dateRange.end) && 'text-muted-foreground'"
          >
            <CalendarIcon class="mr-2 h-3.5 w-3.5 shrink-0" />
            {{ formattedRange }}
          </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0">
          <!-- ⚠️ Con il pulsante in basso nella pagina (elenco filtrato corto) Floating UI
               ribalta il pannello sopra la riga dei filtri, per non tagliarlo fuori dallo
               schermo: stesso comportamento del calendario di Eventi, non un difetto di qui.
               ⚠️ Da NON riscrivere come «l'altezza non dipende dal numero di mesi»: quella era
               una misura singola (settembre 2026, 370px identici a uno e due mesi) scambiata per
               una legge. `fixedWeeks` esiste in reka-ui ma questo wrapper non lo passa, quindi il
               default è `false` e un mese con sei settimane è più alto. -->
          <RangeCalendar v-model="dateRange" initial-focus :number-of-months="2" />
          <div class="p-2 border-t flex items-center justify-between gap-2">
            <!-- Compare solo quando la domanda ha senso: una data sola scelta. -->
            <div v-if="soloUnaData" class="flex items-center gap-1.5">
              <span class="text-xs text-muted-foreground">Una data sola:</span>
              <Button variant="secondary" size="sm" class="h-7 text-xs" @click="usaComeInizio">dal</Button>
              <Button variant="secondary" size="sm" class="h-7 text-xs" @click="usaComeFine">fino al</Button>
            </div>
            <span v-else></span>
            <Button variant="outline" size="sm" @click="clearDateFilter">
              Cancella
            </Button>
          </div>
        </PopoverContent>
      </Popover>

      <!-- Reset -->
      <Button
        v-if="isFiltered"
        variant="ghost"
        @click="resetFilters"
        class="h-8 px-2 lg:px-3 shrink-0 text-slate-500 hover:text-slate-700"
      >
        <X class="h-4 w-4 mr-1 lg:mr-2" />
        <span class="hidden lg:inline">Azzera filtri</span>
        <span class="inline lg:hidden">Azzera</span>
      </Button>

    </div>

    <div class="flex items-center gap-2 shrink-0 ml-auto">
      <!-- La regolazione immediata è il fratello minore della fattura (costo → banca
           senza partita fornitore): vive qui accanto, non più nella barra movimenti. -->
      <Link
        :href="route(generateRoute('gestionale.regolazioni-immediate.create'), { condominio: condominioId })"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 dark:border-slate-700 shadow-sm text-xs text-white font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-800 dark:hover:bg-slate-800 transition-colors"
      >
        <Zap class="w-3.5 h-3.5 text-amber-500" />
        Regolazione immediata
      </Link>

      <!-- Stessa rotta di «Nuova fattura», con `?modo=xml`: là il parametro non cambia
           più la pagina (la fase «scelta» non esiste più dal 03/09/2026) ma **apre subito
           la modale del lettore**.
           ⚠️ Il pulsante resta, e non è un doppione: la ragione per cui la doppia porta
           era sbagliata — Vincenzo, 02/09/2026: «la pagina a cui vengo rimandato è tale e
           quale a quella della registrazione» — era che le due porte davano la stessa
           identica schermata. Adesso danno due esiti diversi: modulo vuoto contro modulo
           col lettore già aperto. E l'elenco è il punto dove il lavoro comincia, quindi
           toglierlo costerebbe un clic in più sul percorso più frequente.
           ⚠️ **«Importa» ovunque**, deciso con Vincenzo il 03/09/2026: lo stesso gesto
           si chiamava in tre modi diversi (qui «Importa», nella fascia «Carica», nella
           modale «Carica le fatture XML»). Vince «importare» perché è la parola del
           dominio — è quella della guida e dell'importatore dati — mentre «caricare» è
           solo il gesto di trasferire il file. -->
      <Link
        :href="route(generateRoute('gestionale.fatture.create'), { condominio: condominioId, modo: 'xml' })"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 dark:border-slate-700 shadow-sm text-xs text-white font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-800 dark:hover:bg-slate-800 transition-colors"
      >
        <UploadCloud class="w-3.5 h-3.5 text-sky-400" />
        Importa XML
      </Link>

      <Link
        :href="route(generateRoute('gestionale.fatture.create'), { condominio: condominioId })"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
      >
        <Plus class="w-3.5 h-3.5 text-green-500" />
        Nuova fattura
      </Link>
    </div>

  </div>
</template>
