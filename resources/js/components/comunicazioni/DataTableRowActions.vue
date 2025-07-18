<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger
} from '@/components/ui/dropdown-menu'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { useComunicazioni } from '@/composables/useComunicazioni'
import { Permission } from "@/enums/Permission"
import type { Comunicazione } from '@/types/comunicazioni'

defineProps<{ comunicazione: Comunicazione }>()

const comunicazioneID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { removeComunicazione } = useComunicazioni()
const { hasPermission, generateRoute } = usePermission()

function handleDelete(comunicazione: Comunicazione) {
  comunicazioneID.value = comunicazione.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  comunicazioneID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteComunicazione() {
  if (comunicazioneID.value === null || isDeleting.value) return

  const id = comunicazioneID.value
  isDeleting.value = true

  router.delete(route(generateRoute('comunicazioni.destroy'), { id: String(id) }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'stats', 'comunicazioni'],
    onSuccess: () => {
      removeComunicazione(id)
      closeModal()
    },
    onError: () => {
      console.error('Errore durante la cancellazione.')
    },
    onFinish: () => {
      isDeleting.value = false
    }
  })
}
</script>

<template>
  <DropdownMenu
    v-if="hasPermission([
      Permission.EDIT_COMUNICAZIONI,
      Permission.EDIT_OWN_COMUNICAZIONI,
      Permission.DELETE_COMUNICAZIONI
    ])"
  >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem
        v-if="hasPermission([Permission.EDIT_COMUNICAZIONI, Permission.EDIT_OWN_COMUNICAZIONI])"
      >
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
        v-if="hasPermission([Permission.DELETE_COMUNICAZIONI])"
        @click="handleDelete(comunicazione)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <AlertDialog v-model:open="isAlertOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di voler eliminare questa comunicazione?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà la comunicazione e tutti i dati ad essa associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="closeModal">Annulla</AlertDialogCancel>
        <AlertDialogAction :disabled="isDeleting" @click="deleteComunicazione">
          <span v-if="isDeleting">Eliminazione...</span>
          <span v-else>Continua</span>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
