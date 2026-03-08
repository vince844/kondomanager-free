<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel,  DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import { usePermission } from "@/composables/permissions"
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Building } from '@/types/buildings'

const { esercizio, condominio } = defineProps<{ esercizio: Esercizio, condominio: Building }>()

const esercizioID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetEsercizio: Esercizio) {
  esercizioID.value = targetEsercizio.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  esercizioID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteEsercizio() {
  if (esercizioID.value === null || isDeleting.value) return

  const id = esercizioID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.esercizi.destroy'), { condominio: condominio.id, esercizio: id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'esercizi'],
    onSuccess: () => {
      closeModal()
    },
    onError: () => {
      console.error(trans('gestionale.common.actions.delete_error'))
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
      <Button variant="ghost" class="w-8 h-8 p-0" :aria-label="trans('gestionale.common.actions.open_menu')">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('gestionale.common.actions.menu') }}</DropdownMenuLabel>

      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.esercizi.edit'), { condominio: condominio.id, esercizio: esercizio.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('gestionale.common.actions.edit') }}
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(esercizio)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        {{ trans('gestionale.common.actions.delete') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="trans('gestionale.common.confirm_delete_title')"
    :description="trans('gestionale.common.confirm_delete_description')"
    :loading="isDeleting"
    @confirm="deleteEsercizio"
  />

</template>
