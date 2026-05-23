<script setup lang="ts">
import { ref } from 'vue';
import { router } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions";
import { MoreHorizontal, Eye, CreditCard, Trash2, RotateCcw, CheckCircle2, AlertTriangle, Download, ShieldCheck } from 'lucide-vue-next'

const props = defineProps<{
  fattura: any,
  condominioId: number
}>()

const { generateRoute } = usePermission();

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
    router.post(route(generateRoute('gestionale.fatture.storno'), {
        condominio: props.condominioId,
        fattura: props.fattura.id 
    }), {}, {
        preserveScroll: true,
        onSuccess: () => isStornoModalOpen.value = false
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
    <DropdownMenuContent align="end" class="w-[190px]">
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Fattura n. {{ fattura.numero_documento }}</DropdownMenuLabel>
      
      <DropdownMenuItem @click="router.visit(route(generateRoute('gestionale.fatture.show'), { condominio: condominioId, fattura: fattura.id }))" class="cursor-pointer">
        <Eye class="w-4 h-4 mr-2" /> Dettagli
      </DropdownMenuItem>

      <DropdownMenuItem 
        v-if="fattura.documenti && fattura.documenti.length > 0"
        @click="downloadPdf" 
        class="cursor-pointer"
      >
        <Download class="w-4 h-4 mr-2" /> Scarica PDF
      </DropdownMenuItem>
      
      <DropdownMenuItem 
        v-if="fattura.stato_pagamento !== 'pagata' && fattura.stato_pagamento !== 'stornata' && !fattura.dati_extra?.is_stornata"
        @click="router.visit(route(generateRoute('gestionale.pagamenti.create'), { condominio: condominioId, fattura_id: fattura.id }))"
        class="text-blue-600 focus:text-blue-700 focus:bg-blue-50 font-medium cursor-pointer"
      >
        <CreditCard class="w-4 h-4 mr-2" /> Ordina bonifico
      </DropdownMenuItem>

      <!-- Ratifica Assembleare: visibile solo per fatture in sforo_motivato -->
      <DropdownMenuItem
        v-if="fattura.stato_approvazione === 'sforo_motivato'"
        @click="apriModaleApprovazione"
        class="text-orange-600 focus:text-orange-700 focus:bg-orange-50 font-medium cursor-pointer"
      >
        <ShieldCheck class="w-4 h-4 mr-2" /> Ratifica Assembleare
      </DropdownMenuItem>

      <!-- Approvazione Base: visibile solo per fatture in da_approvare -->
      <DropdownMenuItem
        v-if="fattura.stato_approvazione === 'da_approvare'"
        @click="confirmApprovaBase"
        class="text-emerald-600 focus:text-emerald-700 focus:bg-emerald-50 font-medium cursor-pointer"
      >
        <CheckCircle2 class="w-4 h-4 mr-2" /> Segna come Approvata
      </DropdownMenuItem>

      <DropdownMenuSeparator />
      
      <DropdownMenuItem 
          v-if="fattura.stato_pagamento === 'aperta' && !fattura.dati_extra?.is_stornata"
          @click="confirmDeleteFattura" 
          class="text-red-600 focus:text-red-700 focus:bg-red-50 cursor-pointer"
      >
          <Trash2 class="w-4 h-4 mr-2" /> Elimina
      </DropdownMenuItem>

      <DropdownMenuItem 
          v-if="!fattura.dati_extra?.is_stornata && fattura.netto_a_pagare > 0"
          @click="confirmStornoFattura" 
          class="text-amber-600 focus:text-amber-700 focus:bg-amber-50 cursor-pointer"
      >
          <RotateCcw class="w-4 h-4 mr-2" /> Storna
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
  </Teleport>
</template>