<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { useDocumenti } from '@/composables/useDocumenti'
import { Permission } from '@/enums/Permission'
import { trans } from 'laravel-vue-i18n'
import type { Documento } from '@/types/documenti'

defineProps<{ documento: Documento }>()

const documentoID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { removeDocumento } = useDocumenti()
const { hasPermission, generateRoute } = usePermission()

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

  router.delete(route(generateRoute('documenti.destroy'), { id: String(id) }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'stats', 'documenti'],
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
  <DropdownMenu
    v-if="hasPermission([
      Permission.EDIT_ARCHIVE_DOCUMENTS,
      Permission.DELETE_ARCHIVE_DOCUMENTS
    ])"
  >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('documenti.table.actions') }}</DropdownMenuLabel>

      <DropdownMenuItem
        v-if="hasPermission([Permission.EDIT_ARCHIVE_DOCUMENTS])"
      >
        <Link
          :href="route(generateRoute('documenti.edit'), { id: documento.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('documenti.actions.edit_document') }}
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        v-if="hasPermission([Permission.DELETE_ARCHIVE_DOCUMENTS])"
        @click="handleDelete(documento)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        {{ trans('documenti.actions.delete_document') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="trans('documenti.dialogs.delete_document_title')"
    :description="trans('documenti.dialogs.delete_document_description')"
    :loading="isDeleting"
    @confirm="deleteDocumento"
  />

</template>
