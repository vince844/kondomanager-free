<script setup lang="ts">

import { ref } from 'vue'
import { router } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Unplug, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Anagrafica } from '@/types/anagrafiche';
import type { Fornitore } from '@/types/fornitori';

const props = defineProps<{
  fornitore: Fornitore
  anagrafica: Anagrafica
}>()

const { generateRoute } = usePermission()

const anagraficaID = ref('');
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)

function handleDelete(anagrafica: Anagrafica) {
  anagraficaID.value = anagrafica.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false 
}

const deleteAnagrafica = () => {
    router.delete(route(generateRoute('fornitori.anagrafiche.destroy'), 
    { 
      fornitore: props.fornitore.id, 
      anagrafica: props.anagrafica.id 
    }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">Azioni</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem @click="handleDelete(anagrafica)" >
        <Unplug class="w-4 h-4 text-xs" />
        Dissocia
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di volere dissociare questa anagrafica dal fornitore?"
    description="Questa azione non è reversibile. L'anagrafica verrà dissociata e non potrà più visualizzare i dati del fornitore."
    @confirm="deleteAnagrafica"
  />

</template>