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
import { Trash2,FilePenLine, Send, MonitorCheck, MonitorX } from 'lucide-vue-next'
import type { Invito } from '@/types/inviti';

defineProps<{ invito: Invito }>()

const invitoID = ref('');

// State for AlertDialog
const isDeleteAlertOpen = ref(false)

// Reference for DropdownMenu
const isDropdownOpen = ref(false)

function handleDelete(invito: Invito) {
  invitoID.value = invito.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isDeleteAlertOpen.value = true
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false 
}

const deleteInvito = () => {
    router.delete(route('inviti.destroy', { id: invitoID.value }),{
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

      <DropdownMenuItem @click="handleDelete(invito)" >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina invito
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

    <!-- AlertDialog for deleting action -->
   <AlertDialog v-model:open="isDeleteAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere eliminare questo invito?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà l'invito e l'untente non potrà più registrarsi fino all'invio di un nuovo invito.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isDeleteAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="deleteInvito()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
  
</template>