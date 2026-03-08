<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions"
import { trans } from 'laravel-vue-i18n';
import type { PianoRate } from '@/types/gestionale/piani-rate'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'

const props = defineProps<{
  pianoRate: PianoRate,
  esercizio: Esercizio
  condominio: Building
}>()

const pianoRateID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetPianoRate: PianoRate) {
  pianoRateID.value = targetPianoRate.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  pianoRateID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deletePianoRate() {
  if (pianoRateID.value === null || isDeleting.value) return

  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.esercizi.piani-rate.destroy'), { condominio: props.condominio.id, esercizio: props.esercizio.id, pianoRate: props.pianoRate.id }), {
    preserveScroll: true,
    onSuccess: () => {
      closeModal()
    },
    onError: () => {
      console.error(trans('gestionale.piani_rate.messages.delete_error'))
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
      <Button variant="ghost" class="w-8 h-8 p-0" :aria-label="trans('gestionale.piani_rate.actions.open_actions_menu')">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('gestionale.piani_rate.actions.menu_label') }}</DropdownMenuLabel>

      <!-- <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.esercizi.piani-rate.edit'), { condominio: props.condominio.id, esercizio: props.esercizio.id, pianoRate: props.pianoRate.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('gestionale.common.actions.edit') }}
        </Link>
      </DropdownMenuItem> -->

      <DropdownMenuItem
        @click="handleDelete(pianoRate)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        {{ trans('gestionale.piani_rate.actions.delete') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="trans('gestionale.piani_rate.confirm_delete_title')"
    :description="trans('gestionale.piani_rate.confirm_delete_description')"
    @confirm="deletePianoRate"
  />

</template>
