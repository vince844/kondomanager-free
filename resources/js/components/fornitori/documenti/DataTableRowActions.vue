<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { useDocumenti } from '@/composables/useDocumenti'
import type { Documento } from '@/types/documenti'
import type { Fornitore } from '@/types/fornitori'

const props = defineProps<{
  documento: Documento 
  fornitore: Fornitore
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

   router.delete(route(generateRoute('fornitori.documenti.destroy'), 
  { 
    fornitore: props.fornitore.id,
    documento: id
  }), {
    preserveScroll: true,
    preserveState: true,
    only: ['documenti'],
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
          :href="route(generateRoute('fornitori.documenti.edit'), 
          { 
            fornitore: props.fornitore.id,
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

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di voler eliminare questo documento?"
    description="Questa azione non è reversibile. Eliminerà il documento e tutti i dati associati."
    :loading="isDeleting"
    @confirm="deleteDocumento"
  />

</template>
