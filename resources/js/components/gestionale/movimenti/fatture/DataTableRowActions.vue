<script setup lang="ts">
import { ref } from 'vue';
import { router } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions";
// Importata l'icona Download
import { MoreHorizontal, Eye, CreditCard, Trash2, RotateCcw, CheckCircle2, AlertTriangle, Download } from 'lucide-vue-next'

const props = defineProps<{
  fattura: any,
  condominioId: number
}>()

const { generateRoute } = usePermission();

// Stato dei Modali
const isDeleteModalOpen = ref(false);
const isStornoModalOpen = ref(false);

const confirmDeleteFattura = () => isDeleteModalOpen.value = true;
const confirmStornoFattura = () => isStornoModalOpen.value = true;

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
    <DropdownMenuContent align="end" class="w-[160px]">
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
  </Teleport>
</template>