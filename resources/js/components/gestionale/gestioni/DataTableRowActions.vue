<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import { usePermission } from "@/composables/permissions"
import type { Gestione } from '@/types/gestionale/gestioni'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'

const props = defineProps<{
  gestione: Gestione,
  esercizio: Esercizio
  condominio: Building
}>()

const gestioneID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetGestione: Gestione) {
  gestioneID.value = targetGestione.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  gestioneID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteGestione() {
  if (gestioneID.value === null || isDeleting.value) return

  const id = gestioneID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.esercizi.gestioni.destroy'), { condominio: props.condominio.id, esercizio: props.esercizio.id, gestione: props.gestione.id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'gestioni'],
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
          :href="route(generateRoute('gestionale.esercizi.gestioni.edit'), { condominio: props.condominio.id, esercizio: props.esercizio.id, gestione: props.gestione.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('gestionale.common.actions.edit') }}
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(gestione)"
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
    @confirm="deleteGestione"
  />

</template>
