<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions";
import { MoreHorizontal, Eye, CreditCard, Trash2, RotateCcw, CheckCircle2, AlertTriangle, Download, ShieldCheck, Edit, Ban, Link2, Unlink } from 'lucide-vue-next'
import { useFattureRettificabili } from '@/composables/useFattureRettificabili'
import { etichettaCandidata, statoScelta } from '@/lib/gestionale/fatture/fatturaRettificata'

const props = defineProps<{
  fattura: any,
  condominioId: number
}>()

const { generateRoute } = usePermission();

const isModificabile = computed(() =>
  props.fattura.stato_pagamento === 'aperta' &&
  !props.fattura.dati_extra?.is_stornata &&
  !props.fattura.is_pregresso &&
  props.fattura.stato_approvazione !== 'sforo_motivato'
);

// Solo una fattura APPROVATA è pagabile: PagamentoFornitoreService (riga ~1060)
// respinge con FatturaNonApprovataException qualunque altro stato di approvazione.
// Non riguarda quindi solo lo sforo da ratificare — vale anche per "da approvare" e
// "contestata". Senza questo filtro l'azione portava a un vicolo cieco: il form si
// apriva ma la fattura vi risultava non selezionabile, e l'utente non capiva perché.
// Il menu offre già l'azione giusta per lo sforo ("Ratifica assembleare"), quindi
// nascondere quella sbagliata non lascia l'utente senza strada.
//
// Il gate è una scelta di flusso deliberata, non un blocco da aggirare:
// vedi docs/note_tecniche_e_decisioni.md — «Bug 5 — sforo_motivato "blocca" il
// pagamento (art. 1135 c.c.)».
const isPagabile = computed(() =>
  props.fattura.stato_approvazione === 'approvata' &&
  props.fattura.stato_pagamento !== 'pagata' &&
  props.fattura.stato_pagamento !== 'stornata' &&
  !props.fattura.dati_extra?.is_stornata &&
  // ⚠️ Coda 124, revisione avversariale del 05/09/2026: `is_stornata` sta sull'ORIGINALE,
  // non sulla nota che lo storno genera — quella resta `stato_pagamento='aperta'` e passava
  // tutti i controlli sopra. Da quando la nota non è più compensabile automaticamente
  // (Coda 124), offrirle «Registra pagamento» porta a un form che non la trova più nelle
  // pendenze: un vicolo cieco muto, dove prima «funzionava» (ed era proprio il difetto).
  // ⚠️ Coda 133: si guarda il flag calcolato dal server, non la sola chiave `nota_storno`.
  // Le note generate prima della Coda 124 quella chiave non ce l'hanno, e questo menù
  // continuava a offrire loro «Registra pagamento» — riaprendo per tutte le note storiche
  // esattamente il vicolo cieco muto che il commento qui sopra dichiara chiuso.
  // ⚠️ **Due criteri, e servono entrambi.** `e_nata_da_storno` lo calcola il server e copre anche
  // le note generate prima della Coda 124, che la chiave non ce l'hanno (Coda 133). Ma questo
  // componente è montato da più elenchi, e uno che non passi quel flag tornerebbe a offrire
  // «Registra pagamento» **in silenzio**: la chiave resta come rete, perché il costo di tenerla è
  // zero e il costo di sbagliarsi è un pagamento su un documento che il fornitore non ha emesso.
  !props.fattura.e_nata_da_storno &&
  !props.fattura.dati_extra?.nota_storno
);

// Il perché del divieto sullo storno lo calcola il server (`FatturaPassiva::motivoBloccoStorno()`), come quello
// sull'Elimina: dalla 1.11.0-beta.35 guarda anche i piani rate che contengono la fattura, e la riga non li conosce
// (R1 della Fase 1-bis — una fattura stornata restava nel suo piano, e le rate la chiedevano ancora).
const motivoStornoDalServer = computed<string | null>(() => props.fattura.motivo_blocco_storno ?? null);

// Lo storno è ammesso solo su una fattura senza pagamenti vivi: il denaro già
// uscito va rimesso a posto per primo, altrimenti resterebbe un'uscita di cassa
// senza un debito che la giustifichi. Stessa regola della guardia server, esposta
// qui perché l'utente la veda PRIMA di aprire la modale.
const puoStornare = computed(() =>
  !props.fattura.dati_extra?.is_stornata &&
  props.fattura.netto_a_pagare > 0 &&
  props.fattura.stato_pagamento === 'aperta' &&
  !motivoStornoDalServer.value
);

// Aperta, ma lo storno è rifiutato (un piano rate che non ha incassato, un fondo già confermato): la voce resta cliccabile
// e apre la finestra con il motivo del server e la via d'uscita. ⚠️ Non `disabled` con un `title`: la voce disattivata ha
// `pointer-events: none`, il tooltip non compare mai e la tastiera la salta — il motivo non si leggeva da nessuna parte
// (verifica delle correzioni della Fase 1-bis, 1.11.0-beta.35, confermata con Chromium sul CSS compilato).
const stornoBloccatoDalServer = computed(() =>
  !props.fattura.dati_extra?.is_stornata &&
  props.fattura.netto_a_pagare > 0 &&
  props.fattura.stato_pagamento === 'aperta' &&
  !!motivoStornoDalServer.value
);

const stornoBloccatoDaPagamenti = computed(() =>
  !props.fattura.dati_extra?.is_stornata &&
  props.fattura.netto_a_pagare > 0 &&
  ['pagata', 'parziale'].includes(props.fattura.stato_pagamento)
);

// Il perché del divieto sull'Elimina lo calcola il server, con tutti gli otto
// motivi: `null` significa eliminabile. Il frontend non lo ricostruisce — quando
// ci provava ne conosceva due, e sbagliava in entrambi i versi (voce nascosta
// senza spiegazione, oppure mostrata e poi rifiutata dalla destroy).
const motivoEliminaBloccato = computed<string | null>(
  () => props.fattura.motivo_blocco_eliminazione ?? null
);

const motivoStornoBloccato = computed(() =>
  props.fattura.stato_pagamento === 'pagata'
    ? 'La fattura è già stata pagata: storna prima il pagamento dalla sezione Pagamenti fornitori, poi la fattura.'
    : 'La fattura ha un pagamento parziale: storna prima il pagamento dalla sezione Pagamenti fornitori, poi la fattura.'
);

// Messaggio della guardia server, se dovesse scattare comunque (difesa in profondità:
// il blocco lato UI si basa sui dati della riga, che potrebbero essere obsoleti).
const erroreStorno = ref<string | null>(null);
// Il titolo della stessa finestra: la usano anche le voci «non consentito» dell'eliminazione e dello storno, che la aprono
// al clic (Coda 162, 1.11.0-beta.35).
const erroreTitolo = ref('Storno non consentito');
const mostraDivieto = (titolo: string, motivo: string | null) => {
  erroreTitolo.value = titolo;
  erroreStorno.value = motivo;
};

// Coda 165 (1.11.0-beta.36): la nota di credito del fornitore e la fattura che rettifica. Il collegamento si fa qui per le
// note già registrate — tutte quelle di prima di questa versione — e si toglie qui. Le regole le decide il server
// (`motivo_blocco_collegamento` sulla nota, `motivo_blocco_nota` e `avviso_nota` su ogni fattura candidata): il menu le
// legge. La nota nata da uno storno ha già il suo legame, e il menu non le offre niente.
const eNotaDelFornitore = computed(() =>
  props.fattura.tipo_documento === 'nota_credito' &&
  !props.fattura.e_nata_da_storno &&
  !props.fattura.dati_extra?.nota_storno
);
const eCollegata = computed(() => eNotaDelFornitore.value && !!props.fattura.fattura_rettificata_id);
const puoCollegare = computed(() => eNotaDelFornitore.value && !eCollegata.value && !props.fattura.motivo_blocco_collegamento);
const collegamentoBloccato = computed(() => eNotaDelFornitore.value && !eCollegata.value && !!props.fattura.motivo_blocco_collegamento);
const numeroRettificata = computed(() => props.fattura.fattura_rettificata?.numero_documento ?? `#${props.fattura.fattura_rettificata_id}`);
// Una nota contestata non conta nel netto: collegarla o scollegarla non cambia carrello e cruscotto (W8 del terzo giro).
const notaContestata = computed(() => props.fattura.stato_approvazione === 'contestata');

const isCollegaModalOpen = ref(false);
const isScollegaModalOpen = ref(false);
const fatturaScelta = ref<number | null>(null);
const rettificabili = useFattureRettificabili();
const scelta = computed(() => statoScelta(rettificabili.candidate.value, fatturaScelta.value));

const apriCollega = () => {
  fatturaScelta.value = null;
  isCollegaModalOpen.value = true;
  // La nota è già registrata: righe e importo li legge il server, e motivi e avvisi dicono dove riduce (R4 della 1-bis).
  rettificabili.carica({
    condominioId: props.condominioId,
    fornitoreId: props.fattura.fornitore_id,
    notaId: props.fattura.id,
    perCollegare: true,
  });
};

const esitoCollegamento = {
  preserveScroll: true,
  onSuccess: () => { isCollegaModalOpen.value = false; isScollegaModalOpen.value = false; },
  onError: (errors: Record<string, string>) => {
    isCollegaModalOpen.value = false;
    isScollegaModalOpen.value = false;
    if (errors.avviso_nota) {
      // Il server ha trovato un avviso che la finestra non mostrava (le candidate sono cambiate nel frattempo).
      mostraDivieto('Serve la conferma', `${errors.avviso_nota} Riapri «Collega a una fattura» per confermare.`);
      return;
    }
    mostraDivieto('Collegamento non consentito', errors.collega_vietato ?? errors.fattura_rettificata_id ?? Object.values(errors)[0] ?? 'Operazione non consentita.');
  },
};

const executeCollega = () => {
  if (fatturaScelta.value === null || scelta.value.motivo) return;
  router.post(route(generateRoute('gestionale.fatture.collega-fattura'), {
    condominio: props.condominioId,
    fattura: props.fattura.id,
  }), {
    fattura_rettificata_id: fatturaScelta.value,
    // L'avviso di un piano che ha incassato si conferma cliccando «Collega»: il server lo ricontrolla (R3).
    conferma_avviso_nota: !!scelta.value.avviso,
  }, esitoCollegamento);
};

const executeScollega = () => {
  router.post(route(generateRoute('gestionale.fatture.scollega-fattura'), {
    condominio: props.condominioId,
    fattura: props.fattura.id,
  }), {}, esitoCollegamento);
};

// Stato dei Modali
const isDeleteModalOpen = ref(false);
const isStornoModalOpen = ref(false);
const isApprovaSforoModalOpen = ref(false);
const isApprovaBaseModalOpen = ref(false);
const noteApprovazioneRatifica = ref('');

const confirmDeleteFattura = () => isDeleteModalOpen.value = true;
const confirmStornoFattura = () => isStornoModalOpen.value = true;
const confirmApprovaBase = () => isApprovaBaseModalOpen.value = true;
const apriModaleApprovazione = () => {
    noteApprovazioneRatifica.value = '';
    isApprovaSforoModalOpen.value = true;
};

// Esecuzione Eliminazione Fisica (Errore Immediato)
const executeDelete = () => {
    router.delete(route(generateRoute('gestionale.fatture.destroy'), {
        condominio: props.condominioId,
        fattura: props.fattura.id 
    }), {
        preserveScroll: true,
        onSuccess: () => isDeleteModalOpen.value = false
    });
};

// Esecuzione Storno Contabile (Errore Consolidato)
const executeStorno = () => {
    erroreStorno.value = null;
    erroreTitolo.value = 'Storno non consentito';

    router.post(route(generateRoute('gestionale.fatture.storno'), {
        condominio: props.condominioId,
        fattura: props.fattura.id
    }), {}, {
        preserveScroll: true,
        onSuccess: () => isStornoModalOpen.value = false,
        // Le guardie di dominio rispondono con withErrors: il canale del flash non
        // sopravvive al redirect di back() in una visita Inertia, e l'operazione
        // veniva rifiutata in silenzio.
        onError: (errors: Record<string, string>) => {
            isStornoModalOpen.value = false;
            erroreStorno.value = errors.storno_vietato
                ?? Object.values(errors)[0]
                ?? 'Operazione non consentita.';
        },
    });
};

// Esecuzione Ratifica Assembleare (sforo_motivato → approvata)
const executeApprovaSforo = () => {
    router.post(route(generateRoute('gestionale.fatture.approva-sforo'), {
        condominio: props.condominioId,
        fattura: props.fattura.id
    }), {
        note: noteApprovazioneRatifica.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => isApprovaSforoModalOpen.value = false
    });
};

// Esecuzione Approvazione Base (da_approvare → approvata)
const executeApprovaBase = () => {
    router.post(route(generateRoute('gestionale.fatture.approva'), {
        condominio: props.condominioId,
        fattura: props.fattura.id
    }), {}, {
        preserveScroll: true,
        onSuccess: () => isApprovaBaseModalOpen.value = false
    });
};

// ⚠️ **Fino alla 1.11.0-beta.11 una fattura aveva al massimo un allegato**
// (aggiornaFattura() cancellava il precedente prima di salvarne uno), quindi
// `documenti[0]` era «il documento», non «il primo di N». Dalla beta.12
// (Coda 102) gli allegati si accumulano: prendere ciecamente [0] sceglieva
// un file a caso senza dirlo — trovato dalla revisione avversariale.
// Con un solo allegato si scarica ancora direttamente, com'era; con più di
// uno si va al Dettaglio, dove FatturaShow.vue li elenca tutti — non si
// sceglie per l'amministratore, si mostra dove sono tutti.
const haUnicoAllegato = computed(() => (props.fattura.documenti?.length ?? 0) === 1);
const haPiuAllegati = computed(() => (props.fattura.documenti?.length ?? 0) > 1);

const downloadPdf = () => {
    if (!haUnicoAllegato.value) return;

    const documentoId = props.fattura.documenti[0].id;

    // Usiamo window.location.href per i file binari, aggirando le chiamate XHR di Inertia
    window.location.href = route(generateRoute('gestionale.fatture.download'), {
        condominio: props.condominioId,
        fattura: props.fattura.id,
        documento: documentoId
    });
};

const vaiAgliAllegati = () => {
    router.visit(route(generateRoute('gestionale.fatture.show'), { condominio: props.condominioId, fattura: props.fattura.id }));
};
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="h-8 w-8 p-0 data-[state=open]:bg-muted">
        <span class="sr-only">Apri menu</span>
        <MoreHorizontal class="h-4 w-4 text-muted-foreground" />
      </Button>
    </DropdownMenuTrigger>
    <!-- 210px non bastavano: le voci che spiegano un divieto («Elimina — non
         consentito», «Storna — prima i pagamenti») andavano a capo, e il
         contenitore ha `overflow-hidden`, quindi nowrap le avrebbe tagliate.
         Si allarga il menu invece di accorciare l'etichetta: è l'etichetta che
         fa il lavoro di dire che l'operazione è bloccata. -->
    <DropdownMenuContent align="end" class="w-[250px]">
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Fattura n. {{ fattura.numero_documento }}</DropdownMenuLabel>
      
      <DropdownMenuItem @click="router.visit(route(generateRoute('gestionale.fatture.show'), { condominio: condominioId, fattura: fattura.id }))" class="cursor-pointer">
        <Eye class="w-4 h-4 mr-2" /> Dettagli
      </DropdownMenuItem>

      <DropdownMenuItem
        v-if="haUnicoAllegato"
        @click="downloadPdf"
        class="cursor-pointer"
      >
        <Download class="w-4 h-4 mr-2" /> Scarica documento
      </DropdownMenuItem>

      <DropdownMenuItem
        v-if="haPiuAllegati"
        @click="vaiAgliAllegati"
        class="cursor-pointer"
      >
        <Download class="w-4 h-4 mr-2" /> Vedi allegati ({{ fattura.documenti.length }})
      </DropdownMenuItem>
      
      <DropdownMenuItem 
        v-if="isModificabile"
        @click="router.visit(route(generateRoute('gestionale.fatture.edit'), { condominio: condominioId, fattura: fattura.id }))" 
        class="cursor-pointer"
      >
        <Edit class="w-4 h-4 mr-2" /> Modifica
      </DropdownMenuItem>
      
      <!-- Scorciatoia al pagamento con la fattura già scelta: il controller legge
           `fattura_id`, la preseleziona e ne ricava il fornitore, quindi il form si
           apre pronto invece di far ricercare a mano la fattura appena vista.
           Era già scritta e commentata, ma puntava a `gestionale.pagamenti.create`,
           rotta che non esiste (il nome vero è `gestionale.pagamenti-fornitori.create`):
           riattivarla così com'era avrebbe dato errore.
           "Registra pagamento" e non "Paga": il programma annota un pagamento, non lo
           esegue — la banca resta fuori. -->
      <DropdownMenuItem
        v-if="isPagabile"
        @click="router.visit(route(generateRoute('gestionale.pagamenti-fornitori.create'), { condominio: condominioId, fattura_id: fattura.id }))"
        class="text-blue-600 focus:text-blue-700 focus:bg-blue-50 font-medium cursor-pointer"
      >
        <CreditCard class="w-4 h-4 mr-2" /> Registra pagamento
      </DropdownMenuItem>
      <!-- Ratifica Assembleare: visibile solo per fatture in sforo_motivato -->
      <DropdownMenuItem
        v-if="fattura.stato_approvazione === 'sforo_motivato'"
        @click="apriModaleApprovazione"
        class="text-orange-600 focus:text-orange-700 focus:bg-orange-50 font-medium cursor-pointer"
      >
        <ShieldCheck class="w-4 h-4 mr-2" /> Ratifica assembleare
      </DropdownMenuItem>

      <!-- Approvazione Base: visibile solo per fatture in da_approvare -->
      <DropdownMenuItem
        v-if="fattura.stato_approvazione === 'da_approvare'"
        @click="confirmApprovaBase"
        class="text-emerald-600 focus:text-emerald-700 focus:bg-emerald-50 font-medium cursor-pointer"
      >
        <CheckCircle2 class="w-4 h-4 mr-2" /> Segna come approvata
      </DropdownMenuItem>

      <DropdownMenuItem
        v-if="puoCollegare"
        @click="apriCollega"
        class="cursor-pointer"
      >
        <Link2 class="w-4 h-4 mr-2" /> Collega a una fattura
      </DropdownMenuItem>

      <DropdownMenuItem
        v-if="collegamentoBloccato"
        @click="mostraDivieto('Collegamento non consentito', fattura.motivo_blocco_collegamento)"
        class="text-slate-500 cursor-pointer"
      >
        <Ban class="w-4 h-4 mr-2" /> Collega — non consentito
      </DropdownMenuItem>

      <DropdownMenuItem
        v-if="eCollegata"
        @click="isScollegaModalOpen = true"
        class="cursor-pointer"
      >
        <!-- Il numero non va a capo a metà («FT-» / «36-B»): visto a video il 27/09/2026. -->
        <Unlink class="w-4 h-4 mr-2 shrink-0" /> <span>Scollega dalla fattura <span class="whitespace-nowrap">n. {{ numeroRettificata }}</span></span>
      </DropdownMenuItem>

      <DropdownMenuSeparator />
      
      <DropdownMenuItem
          v-if="!motivoEliminaBloccato"
          @click="confirmDeleteFattura"
          class="text-red-600 focus:text-red-700 focus:bg-red-50 cursor-pointer"
      >
          <Trash2 class="w-4 h-4 mr-2" /> Elimina
      </DropdownMenuItem>

      <!-- Stesso principio già applicato a «Storna» qui sotto: il divieto va detto
           QUI. Prima la voce spariva e basta, e l'amministratore non aveva modo di
           sapere quale dei sette motivi lo riguardasse — né cosa fare per uscirne.
           Il motivo arriva dal server (`motivo_blocco_eliminazione`), quindi è
           esattamente quello che applicherebbe la destroy(): niente due guardie
           che divergono. ⚠️ Al clic, non in un `title`: una voce disattivata ha
           `pointer-events: none` e il suggerimento non compariva mai (Coda 162). -->
      <DropdownMenuItem
          v-else
          @click="mostraDivieto('Eliminazione non consentita', motivoEliminaBloccato)"
          class="text-slate-500 cursor-pointer"
      >
          <Ban class="w-4 h-4 mr-2" /> Elimina — non consentito
      </DropdownMenuItem>

      <DropdownMenuItem
          v-if="puoStornare"
          @click="confirmStornoFattura"
          class="text-amber-600 focus:text-amber-700 focus:bg-amber-50 cursor-pointer"
      >
          <RotateCcw class="w-4 h-4 mr-2" /> Storna
      </DropdownMenuItem>

      <!-- Con pagamenti registrati lo storno non è ammesso: va detto QUI, non dopo
           aver aperto una modale che promette un'operazione poi rifiutata. -->
      <DropdownMenuItem
          v-else-if="stornoBloccatoDaPagamenti"
          @click="mostraDivieto('Storno non consentito', motivoStornoBloccato)"
          class="text-slate-500 cursor-pointer"
      >
          <Ban class="w-4 h-4 mr-2" /> Storna — prima i pagamenti
      </DropdownMenuItem>

      <DropdownMenuItem
          v-else-if="stornoBloccatoDalServer"
          @click="mostraDivieto('Storno non consentito', motivoStornoDalServer)"
          class="text-slate-500 cursor-pointer"
      >
          <Ban class="w-4 h-4 mr-2" /> Storna — non consentito
      </DropdownMenuItem>

      <DropdownMenuItem v-if="fattura.dati_extra?.is_stornata" disabled class="opacity-50">
          <CheckCircle2 class="w-4 h-4 mr-2" /> Già stornata
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <Teleport to="body">
      <ConfirmDialog 
          v-model="isDeleteModalOpen"
          title="Elimina fattura"
          confirm-text="Elimina fisicamente"
          variant="destructive"
          @confirm="executeDelete"
      >
          <div class="space-y-3 text-sm text-slate-600">
              <p>
                  Stai per eliminare la fattura <strong>{{ fattura.numero_documento }}</strong>.
              </p>
              <p>
                  Questa azione cancellerà il documento dal database e rimuoverà le scritture contabili associate. L'operazione è <strong>irreversibile</strong>.
              </p>
          </div>
      </ConfirmDialog>

      <ConfirmDialog
          v-model="isStornoModalOpen"
          title="Storno contabile"
          confirm-text="Genera nota di credito"
          variant="warning"
          @confirm="executeStorno"
      >
          <div class="space-y-3 text-sm text-slate-600">
              <div class="bg-amber-50 border border-amber-200 text-amber-800 p-3 rounded flex gap-3 items-start">
                  <AlertTriangle class="w-5 h-5 shrink-0 mt-0.5" />
                  <div>
                      <p class="font-bold">Azione contabile avanzata</p>
                      <p class="text-xs mt-1">Stai per stornare una fattura già processata dal sistema.</p>
                  </div>
              </div>
              <p>
                  Il sistema non eliminerà il documento originale, ma genererà automaticamente una <strong>nota di credito a pareggio</strong> per neutralizzare i costi nel libro giornale e ripristinare il budget nei capitoli di spesa.
              </p>
              <!-- R1 della Fase 1-bis (1.11.0-beta.35): con un piano che ha già incassato lo storno resta possibile, ma il
                   piano non si rettifica da solo. Il testo lo compone il server (`FatturaPassiva::avvisoStorno()`). -->
              <div v-if="fattura.avviso_storno" class="bg-red-50 border border-red-200 text-red-800 p-3 rounded flex gap-3 items-start">
                  <AlertTriangle class="w-5 h-5 shrink-0 mt-0.5" />
                  <div>
                      <p class="font-bold">Il piano rate resta com'è</p>
                      <p class="text-xs mt-1">{{ fattura.avviso_storno }}</p>
                  </div>
              </div>
          </div>
      </ConfirmDialog>

      <!-- Modale Ratifica Assembleare sforo_motivato → approvata -->
      <ConfirmDialog
          v-model="isApprovaSforoModalOpen"
          title="Ratifica assembleare — sforo motivato"
          confirm-text="Conferma ratifica"
          variant="default"
          :disabled="noteApprovazioneRatifica.trim().length < 10"
          @confirm="executeApprovaSforo"
      >
          <div class="space-y-4 text-sm text-slate-600">

              <!-- Contesto legale -->
              <div class="bg-orange-50 border border-orange-200 text-orange-800 p-3 rounded-lg flex gap-3 items-start">
                  <ShieldCheck class="w-5 h-5 shrink-0 mt-0.5 text-orange-600" />
                  <div>
                      <p class="font-bold text-orange-900">Ratifica assembleare obbligatoria (Art. 1135 c.c.)</p>
                      <p class="text-xs mt-1 leading-relaxed">
                          Questa fattura è stata registrata con sforo motivato: la spesa supera il budget approvato dall'assemblea.
                          La ratifica è obbligatoria per legge prima del pagamento.
                          Confermando dichiari che l'assemblea ha deliberato l'approvazione di questa spesa.
                      </p>
                  </div>
              </div>

              <!-- Campo note -->
              <div class="space-y-1.5">
                  <label class="text-xs font-bold uppercase tracking-wider text-slate-500 flex justify-between">
                      <span>Riferimento verbale / Note <span class="text-rose-500">*</span></span>
                      <span class="font-normal text-slate-400 normal-case tracking-normal ml-1" :class="{'text-rose-500 font-bold': noteApprovazioneRatifica.trim().length < 10}">
                          {{ noteApprovazioneRatifica.trim().length < 10 ? `(minimo 10 caratteri, attuali: ${noteApprovazioneRatifica.trim().length})` : '(obbligatorio)' }}
                      </span>
                  </label>
                  <textarea
                      v-model="noteApprovazioneRatifica"
                      rows="3"
                      placeholder="Es: Delibera assembleare del 15/05/2025 – Verbale n. 3/2025 – Ratifica spesa urgente manutenzione ascensore..."
                      class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-orange-400/30 focus:border-orange-400 resize-none"
                  />
                  <p class="text-[10px] text-slate-400 leading-relaxed">
                      Il sistema registrerà automaticamente data e autore dell'approvazione nell'audit trail della fattura.
                  </p>
              </div>
          </div>
      </ConfirmDialog>

      <!-- Modale Approvazione Base da_approvare → approvata -->
      <ConfirmDialog
          v-model="isApprovaBaseModalOpen"
          title="Approva fattura"
          confirm-text="Approva"
          variant="default"
          @confirm="executeApprovaBase"
      >
          <div class="space-y-3 text-sm text-slate-600">
              <p>
                  Stai per approvare la fattura <strong>{{ fattura.numero_documento }}</strong>.
              </p>
              <p>
                  Una volta approvata, la fattura diventerà visibile nel registro pagamenti per poter essere saldata.
              </p>
          </div>
      </ConfirmDialog>

      <!-- Coda 165: la fattura che questa nota del fornitore rettifica. L'elenco e i motivi vengono dal server. -->
      <ConfirmDialog
          v-model="isCollegaModalOpen"
          title="Collega a una fattura"
          confirm-text="Collega"
          variant="default"
          :disabled="fatturaScelta === null || !!scelta.motivo"
          @confirm="executeCollega"
      >
          <div class="space-y-3 text-sm text-slate-600">
              <p>
                  Quale fattura rettifica la nota di credito <strong>n. {{ fattura.numero_documento }}</strong>?
                  <template v-if="notaContestata">La nota è contestata: finché resta tale, collegarla non cambia né il carrello dei piani rate né il cruscotto.</template>
                  <template v-else>Collegandola, il carrello dei piani rate e il cruscotto conteranno la fattura al netto della nota.</template>
              </p>
              <p v-if="rettificabili.isLoading.value" class="text-xs text-slate-400">Cerco le fatture di questo fornitore…</p>
              <p v-else-if="rettificabili.errore.value" class="text-xs text-rose-600">Non sono riuscito a caricare le fatture di questo fornitore. Riprova tra poco.</p>
              <p v-else-if="rettificabili.candidate.value.length === 0" class="text-xs text-slate-500">
                  Questo fornitore non ha fatture registrate in questo condominio che la nota possa rettificare.
              </p>
              <select
                  v-else
                  v-model="fatturaScelta"
                  aria-label="Fattura che la nota rettifica"
                  class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 dark:bg-slate-900 dark:text-slate-200"
              >
                  <option :value="null" disabled>Scegli la fattura</option>
                  <option v-for="c in rettificabili.candidate.value" :key="c.id" :value="c.id">{{ etichettaCandidata(c) }}</option>
              </select>
              <div v-if="scelta.motivo" class="bg-rose-50 border border-rose-200 text-rose-800 p-3 rounded flex gap-3 items-start">
                  <Ban class="w-5 h-5 shrink-0 mt-0.5" />
                  <p class="text-xs">{{ scelta.motivo }}</p>
              </div>
              <div v-else-if="scelta.avviso" class="bg-red-50 border border-red-200 text-red-800 p-3 rounded flex gap-3 items-start">
                  <AlertTriangle class="w-5 h-5 shrink-0 mt-0.5" />
                  <div>
                      <p class="font-bold">Il piano rate resta com'è</p>
                      <p class="text-xs mt-1">{{ scelta.avviso }}</p>
                  </div>
              </div>
          </div>
      </ConfirmDialog>

      <ConfirmDialog
          v-model="isScollegaModalOpen"
          title="Scollega dalla fattura"
          confirm-text="Scollega"
          variant="default"
          @confirm="executeScollega"
      >
          <div class="space-y-3 text-sm text-slate-600">
              <p>
                  La nota di credito <strong>n. {{ fattura.numero_documento }}</strong> non rettificherà più la fattura
                  <strong>n. {{ numeroRettificata }}</strong>.
                  <template v-if="notaContestata">È contestata, quindi il carrello dei piani rate e il cruscotto non cambiano.</template>
                  <template v-else>Il carrello dei piani rate e il cruscotto torneranno a contare la fattura senza la nota.</template>
                  Le rate già calcolate non cambiano, e la contabilità nemmeno.
              </p>
          </div>
      </ConfirmDialog>

      <!-- Esito della guardia server: mostra SEMPRE il motivo del rifiuto.
           Prima l'operazione veniva negata senza alcun riscontro a schermo. -->
      <div
          v-if="erroreStorno"
          class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
          @click.self="erroreStorno = null"
      >
          <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
              <div class="flex items-start gap-3">
                  <Ban class="mt-0.5 h-5 w-5 shrink-0 text-rose-600" />
                  <div>
                      <h3 class="text-lg font-semibold">{{ erroreTitolo }}</h3>
                      <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ erroreStorno }}</p>
                  </div>
              </div>
              <div class="mt-5 flex justify-end">
                  <Button @click="erroreStorno = null">Ho capito</Button>
              </div>
          </div>
      </div>
  </Teleport>
</template>