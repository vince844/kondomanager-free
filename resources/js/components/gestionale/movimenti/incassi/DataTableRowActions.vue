<script setup lang="ts">
import { ref } from 'vue'
import { router } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions";
import { RotateCcw, MoreHorizontal, FileText,  Eye, Printer } from 'lucide-vue-next'

const props = defineProps<{
  incasso: any,
  condominioId: number
}>()

const { generateRoute } = usePermission();
const isAlertOpen = ref(false)
const isStorning = ref(false)

function handleStorno() {
  isAlertOpen.value = true
}

function confirmStorno() {
  if (isStorning.value) return
  isStorning.value = true

  router.post(route(generateRoute('gestionale.movimenti-rate.storno'), { 
      condominio: props.condominioId, 
      scrittura: props.incasso.id 
  }), {}, {
    preserveScroll: true,
    onSuccess: () => isAlertOpen.value = false,
    onFinish: () => isStorning.value = false
  })
}

const printRicevuta = () => {
    // Apri una nuova finestra per la stampa (da implementare rotta backend)
    // window.open(route(generateRoute('gestionale.incassi.stampa'), { ... }), '_blank');
    alert("Funzionalità stampa ricevuta in arrivo!");
}
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
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Azioni su Prot. {{ incasso.numero_protocollo }}</DropdownMenuLabel>
      
      <DropdownMenuItem @click="router.visit(route(generateRoute('gestionale.movimenti-rate.show'), { condominio: condominioId, scrittura: incasso.id }))">
        <Eye class="w-4 h-4 mr-2" /> Dettagli
      </DropdownMenuItem>
      
      <DropdownMenuItem @click="printRicevuta">
        <Printer class="w-4 h-4 mr-2" /> Stampa Ricevuta
      </DropdownMenuItem>

      <DropdownMenuSeparator />
      
      <DropdownMenuItem 
        v-if="incasso.stato !== 'annullata'" 
        @click="handleStorno" 
        class="text-red-600 focus:text-red-600 focus:bg-red-50"
      >
        <RotateCcw class="w-4 h-4 mr-2" /> Storna
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Storno Movimento"
    :description="`Stai per annullare l'incasso protocollo #${incasso.numero_protocollo} di € ${incasso.importo_totale}. Le rate torneranno 'da pagare'. Continuare?`"
    confirmText="Sì, Procedi"
    variant="destructive"
    :loading="isStorning"
    @confirm="confirmStorno"
  />
</template>