<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { useDocumenti } from '@/composables/useDocumenti'
import type { Documento } from '@/types/documenti'
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'

const props = defineProps<{
  documento: Documento 
  immobile: Immobile
  condominio: Building
}>()

const documentoID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { removeDocumento } = useDocumenti()
const { generateRoute } = usePermission()

function handleDelete(documento: Documento) {
  documentoID.value = documento.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  documentoID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteDocumento() {
  if (documentoID.value === null || isDeleting.value) return

  const id = documentoID.value
  isDeleting.value = true

   router.delete(route(generateRoute('gestionale.immobili.documenti.destroy'), 
  { 
    condominio: props.condominio.id, 
    immobile: props.immobile.id,
    documento: id
  }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'documenti'],
    onSuccess: () => {
      removeDocumento(id)
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
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.immobili.documenti.edit'), 
          { condominio: condominio.id, 
            immobile: immobile.id,
            documento: documento.id
          })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(documento)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <AlertDialog v-model:open="isAlertOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di voler eliminare questo documento?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà il documento e tutti i dati associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="closeModal">Annulla</AlertDialogCancel>
        <AlertDialogAction :disabled="isDeleting" @click="deleteDocumento">
          <span v-if="isDeleting">Eliminazione...</span>
          <span v-else>Continua</span>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
