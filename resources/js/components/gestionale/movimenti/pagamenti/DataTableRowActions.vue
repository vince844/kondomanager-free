<script setup lang="ts">
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Button } from '@/components/ui/button'
import { MoreHorizontal, Eye, RotateCcw, Download } from 'lucide-vue-next'
import { router } from '@inertiajs/vue3'
import { usePermission } from '@/composables/permissions'

const props = defineProps<{
    pagamento: any,
    condominioId: number
}>()

const { generateRoute } = usePermission()

const viewScrittura = () => {
    // Navigazione al dettaglio della scrittura contabile associata
    if (props.pagamento.scrittura?.id) {
        // Mock navigation for now, or point to the correct route if it exists
        // router.get(route(generateRoute('gestionale.movimenti.show'), { condominio: props.condominioId, movimento: props.pagamento.scrittura.id }));
    }
}

const showStornoModal = () => {
    // Al momento mascherato come richiesto
    alert("La funzionalità di storno dei pagamenti è in fase di sviluppo.");
}

const downloadDistinta = () => {
    // Al momento mascherato come richiesto
    alert("La generazione della Distinta di Pagamento in PDF sarà disponibile prossimamente.");
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="h-8 w-8 p-0">
        <span class="sr-only">Apri menu</span>
        <MoreHorizontal class="h-4 w-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-[200px]">
      
      <DropdownMenuItem @click="viewScrittura">
        <Eye class="mr-2 h-4 w-4 text-slate-500" />
        Dettaglio Scrittura
      </DropdownMenuItem>

      <DropdownMenuItem @click="downloadDistinta" :disabled="true" title="In arrivo">
        <Download class="mr-2 h-4 w-4 text-slate-400" />
        <span class="text-slate-500">Scarica Distinta</span>
      </DropdownMenuItem>
      
      <DropdownMenuSeparator v-if="pagamento.stato === 'confermato'" />
      
      <DropdownMenuItem 
        v-if="pagamento.stato === 'confermato'" 
        @click="showStornoModal" 
        class="text-rose-600 focus:text-rose-700"
      >
        <RotateCcw class="mr-2 h-4 w-4" />
        Storna Pagamento
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>
</template>
