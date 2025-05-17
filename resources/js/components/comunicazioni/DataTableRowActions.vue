<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, } from '@/components/ui/alert-dialog'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { Comunicazione } from '@/types/comunicazioni';
import { useComunicazioni } from '@/composables/useComunicazioni';

defineProps<{ 
  comunicazione: Comunicazione 
}>()

const comunicazioneID = ref('');
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const { removeComunicazione } = useComunicazioni();
const { hasPermission, generateRoute } = usePermission();

function handleDelete(comunicazione: Comunicazione) {
  comunicazioneID.value = comunicazione.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true 
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false
}

const deleteComunicazione = () => {
  
  const id = comunicazioneID.value;

  router.delete(route('admin.comunicazioni.destroy', { id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['stats'],
    onSuccess: () => {
      removeComunicazione(id);
      closeModal();
    },
    onError: () => {
      console.error('Errore durante la cancellazione.');
    }
  });
};

</script>

<template>
  <DropdownMenu v-if="hasPermission(['Modifica comunicazioni', 'Modifica proprie comunicazioni', 'Elimina comunicazioni'])" >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">Azioni</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem  v-if="hasPermission(['Modifica comunicazioni', 'Modifica proprie comunicazioni'])">
        <Link
          :href="route(generateRoute('comunicazioni.edit'), { id: comunicazione.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>
  
      <DropdownMenuItem 
        v-if="hasPermission(['Elimina comunicazioni'])" 
        @click="handleDelete(comunicazione)" 
      >
        <Trash2 class="w-4 h-4 text-xs" />
         Elimina 
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

   <!-- AlertDialog moved outside DropdownMenu -->
   <AlertDialog v-model:open="isAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere eliminare questa comunicazione?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà la comunicazione e tutti i dati ad essa associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="deleteComunicazione()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>