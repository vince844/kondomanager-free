<script setup lang="ts">
import { ref } from 'vue'
import { router } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions"; // Importante
import { RotateCcw, MoreHorizontal, FileText, Eye } from 'lucide-vue-next'

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

  // Usa generateRoute per lo storno
  router.post(route(generateRoute('gestionale.scritture.storno'), { 
      condominio: props.condominioId, 
      scrittura: props.incasso.id 
  }), {}, {
    preserveScroll: true,
    onSuccess: () => isAlertOpen.value = false,
    onFinish: () => isStorning.value = false
  })
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>
      
      <DropdownMenuItem>
        <Eye class="w-4 h-4 mr-2" /> Dettaglio
      </DropdownMenuItem>
      
      <DropdownMenuItem>
        <FileText class="w-4 h-4 mr-2" /> Ricevuta PDF
      </DropdownMenuItem>

      <DropdownMenuSeparator v-if="incasso.stato !== 'annullata'" />
      
      <DropdownMenuItem v-if="incasso.stato !== 'annullata'" @click="handleStorno" class="text-red-600 focus:text-red-600">
        <RotateCcw class="w-4 h-4 mr-2" /> Storna Movimento
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Confermi lo storno?"
    description="Verrà creata una scrittura di rettifica e le rate collegate torneranno 'da pagare'. Operazione irreversibile."
    confirmText="Sì, Storna"
    variant="destructive"
    :loading="isStorning"
    @confirm="confirmStorno"
  />
</template>