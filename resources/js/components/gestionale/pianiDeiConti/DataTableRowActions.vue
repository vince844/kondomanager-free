<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import { usePermission } from "@/composables/permissions"
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const props = defineProps<{
  pianoDeiConti: PianoDeiConti,
  esercizio: Esercizio
  condominio: Building
}>()

const pianoDeiContiID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetPianoDeiConti: PianoDeiConti) {
  pianoDeiContiID.value = targetPianoDeiConti.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  pianoDeiContiID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deletePianoDeiConti() {
  if (pianoDeiContiID.value === null || isDeleting.value) return

  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.esercizi.piani-conti.destroy'), { condominio: props.condominio.id, esercizio: props.esercizio.id, pianoConto: props.pianoDeiConti.id }), {
    preserveScroll: true,
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
          :href="route(generateRoute('gestionale.esercizi.piani-conti.edit'), { condominio: props.condominio.id, esercizio: props.esercizio.id, pianoConto: props.pianoDeiConti.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('gestionale.common.actions.edit') }}
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(pianoDeiConti)"
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
    @confirm="deletePianoDeiConti"
  />

</template>
