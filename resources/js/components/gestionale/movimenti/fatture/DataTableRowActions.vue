<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions";
import { MoreHorizontal, Eye, CreditCard, Trash2, RotateCcw, CheckCircle2, AlertTriangle, Download, ShieldCheck, Edit, Ban } from 'lucide-vue-next'

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
  !props.fattura.dati_extra?.is_stornata
);

// Lo storno è ammesso solo su una fattura senza pagamenti vivi: il denaro già
// uscito va rimesso a posto per primo, altrimenti resterebbe un'uscita di cassa
// senza un debito che la giustifichi. Stessa regola della guardia server, esposta
// qui perché l'utente la veda PRIMA di aprire la modale.
const puoStornare = computed(() =>
  !props.fattura.dati_extra?.is_stornata &&
  props.fattura.netto_a_pagare > 0 &&
  props.fattura.stato_pagamento === 'aperta'
);

const stornoBloccatoDaPagamenti = computed(() =>
  !props.fattura.dati_extra?.is_stornata &&
  props.fattura.netto_a_pagare > 0 &&
  ['pagata', 'parziale'].includes(props.fattura.stato_pagamento)
);

// Il perché del divieto sull'Elimina lo calcola il server, con tutti e sette i
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

// Esecuzione Download PDF
const downloadPdf = () => {
    if (props.fattura.documenti && props.fattura.documenti.length > 0) {
        const documentoId = props.fattura.documenti[0].id;
        
        // Usiamo window.location.href per i file binari, aggirando le chiamate XHR di Inertia
        window.location.href = route(generateRoute('gestionale.fatture.download'), {
            condominio: props.condominioId,
            fattura: props.fattura.id,
            documento: documentoId
        });
    }
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
        v-if="fattura.documenti && fattura.documenti.length > 0"
        @click="downloadPdf" 
        class="cursor-pointer"
      >
        <Download class="w-4 h-4 mr-2" /> Scarica documento
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
           che divergono. -->
      <DropdownMenuItem
          v-else
          disabled
          class="opacity-60 cursor-not-allowed"
          :title="motivoEliminaBloccato"
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
          disabled
          class="opacity-60 cursor-not-allowed"
          :title="motivoStornoBloccato"
      >
          <Ban class="w-4 h-4 mr-2" /> Storna — prima i pagamenti
      </DropdownMenuItem>

      <DropdownMenuItem v-if="fattura.dati_extra?.is_stornata" disabled class="opacity-50">
          <CheckCircle2 class="w-4 h-4 mr-2" /> Già stornata
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <Teleport to="body">
      <ConfirmDialog 
          v-model="isDeleteModalOpen"
          title="Elimina Fattura"
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
          title="Storno Contabile"
          confirm-text="Genera Nota di Credito"
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
                  Il sistema non eliminerà il documento originale, ma genererà automaticamente una <strong>Nota di Credito a pareggio</strong> per neutralizzare i costi nel Libro Giornale e ripristinare il budget nei capitoli di spesa.
              </p>
          </div>
      </ConfirmDialog>

      <!-- Modale Ratifica Assembleare sforo_motivato → approvata -->
      <ConfirmDialog
          v-model="isApprovaSforoModalOpen"
          title="Ratifica Assembleare — Sforo Motivato"
          confirm-text="Conferma Ratifica"
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
          title="Approva Fattura"
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
                      <h3 class="text-lg font-semibold">Storno non consentito</h3>
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