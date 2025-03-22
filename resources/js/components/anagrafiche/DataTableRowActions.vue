<script setup lang="ts">
import { ref } from 'vue'
import { router } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { 
  DropdownMenu, 
  DropdownMenuContent,
  DropdownMenuItem, 
  DropdownMenuLabel, 
  DropdownMenuTrigger 
} from '@/components/ui/dropdown-menu'
import { MoreHorizontal } from 'lucide-vue-next'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'

import type { Anagrafica } from '@/types/anagrafiche';

defineProps<{ anagrafica: Anagrafica }>()

const anagraficaID = ref('');

// State for AlertDialog
const isAlertOpen = ref(false)

// Reference for DropdownMenu
const isDropdownOpen = ref(false)

// Function to delete user: first close menu, then open dialog
function handleDelete(anagrafica: Anagrafica) {
  anagraficaID.value = anagrafica.id;
  isDropdownOpen.value = false // Close dropdown first
  setTimeout(() => {
    isAlertOpen.value = true // Open alert after a small delay
  }, 200) // Delay helps avoid event conflicts
}

const closeModal = () => {
  isDropdownOpen.value = false // Close dropdown first
}

const deleteAnagrafica = () => {
    router.delete(route('anagrafiche.destroy', { id: anagraficaID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

const editAnagrafica = (anagrafica: Anagrafica) => {
  router.get(route('utenti.edit', { id: anagrafica.id})) 
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
        Elimina anagrafica
      </DropdownMenuItem>

      <DropdownMenuItem @click="editAnagrafica(anagrafica)" >
        Modifica anagrafica
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

   <!-- AlertDialog moved outside DropdownMenu -->
   <AlertDialog v-model:open="isAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere eliminare questa anagrafica?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà l'anagrafica e tutti i dati ad essa associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="deleteAnagrafica()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>