<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import type { Fornitore } from '@/types/fornitori';

defineProps<{ fornitore: Fornitore }>()

// Ogni comando chiede il permesso che `FornitorePolicy` chiede alla sua azione, con i permessi delle anagrafiche (Coda 402):
// «Modifica» apre il modulo di modifica, che vuole «Modifica anagrafiche»; «Elimina» vuole «Elimina anagrafiche». Il
// collaboratore di serie non ha «Elimina anagrafiche», come per le anagrafiche; chi ha la sola consultazione non ha comandi,
// e il menù non compare.
const { hasPermission } = usePermission()

const fornitoreID = ref('');
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)

function handleDelete(fornitore: Fornitore) {
  fornitoreID.value = fornitore.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false 
}

const deleteFornitore = () => {
    router.delete(route('admin.fornitori.destroy', { id: fornitoreID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

</script>

<template>
  <DropdownMenu
    v-if="hasPermission([
      Permission.EDIT_ANAGRAFICHE,
      Permission.DELETE_ANAGRAFICHE
    ])"
  >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">Azioni</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

       <DropdownMenuItem v-if="hasPermission([Permission.EDIT_ANAGRAFICHE])">
        <Link
          :href="route('admin.fornitori.edit', { id: fornitore.id })"
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem> 

      <DropdownMenuItem v-if="hasPermission([Permission.DELETE_ANAGRAFICHE])" @click="handleDelete(fornitore)" >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di volere eliminare questo fornitore?"
    description="Questa azione non è reversibile. Eliminerà il fornitore e tutti i dati ad esso associati."
    @confirm="deleteFornitore"
  />

</template>