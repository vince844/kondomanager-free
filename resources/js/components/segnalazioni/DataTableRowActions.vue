<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, } from '@/components/ui/alert-dialog'
import type { Segnalazione } from '@/types/segnalazioni';
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";

const { hasPermission } = usePermission();

defineProps<{ segnalazione: Segnalazione }>()

const segnalazioneID = ref('');

// State for AlertDialog
const isAlertOpen = ref(false)

// Reference for DropdownMenu
const isDropdownOpen = ref(false)

// Function to delete user: first close menu, then open dialog
function handleDelete(segnalazione: Segnalazione) {
  segnalazioneID.value = segnalazione.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true 
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false
}

const deleteSegnalazione = () => {
    router.delete(route('admin.segnalazioni.destroy', { id: segnalazioneID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

</script>

<template>
  <DropdownMenu v-if="hasPermission(['Modifica segnalazioni']) || hasPermission(['Elimina segnalazioni'])" >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">Azioni</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem v-if="hasPermission(['Modifica segnalazioni'])">
        <Link
          :href="route('admin.segnalazioni.edit', { id: segnalazione.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>
  
      <DropdownMenuItem v-if="hasPermission(['Elimina segnalazioni'])" @click="handleDelete(segnalazione)" >
        <Trash2 class="w-4 h-4 text-xs" />
         Elimina 
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

   <!-- AlertDialog moved outside DropdownMenu -->
   <AlertDialog v-model:open="isAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere eliminare questa segnalazione?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà la segnalazione e tutti i dati ad essa associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="deleteSegnalazione()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>