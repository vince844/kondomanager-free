<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { Anagrafica } from '@/types/anagrafiche';
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'

defineProps<{ anagrafica: Anagrafica }>()

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
    router.delete(route('admin.anagrafiche.destroy', { id: anagraficaID.value }),{
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

       <DropdownMenuItem>
        <Link
          :href="route('admin.anagrafiche.edit', { id: anagrafica.id })"
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem> 

      <DropdownMenuItem @click="handleDelete(anagrafica)" >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di volere eliminare questa anagrafica?"
    description="Questa azione non è reversibile. Eliminerà l'anagrafica e tutti i dati ad essa associati."
    @confirm="deleteAnagrafica"
  />

</template>