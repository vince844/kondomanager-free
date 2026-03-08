<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import { usePermission } from "@/composables/permissions"
import type { Scala } from '@/types/gestionale/scale'
import type { Building } from '@/types/buildings'

const { scala, condominio } = defineProps<{ scala: Scala, condominio: Building }>()

const scalaID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetScala: Scala) {
  scalaID.value = targetScala.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  scalaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteScala() {
  if (scalaID.value === null || isDeleting.value) return

  const id = scalaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.scale.destroy'), { condominio: condominio.id, scala: id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'scale'],
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
          :href="route(generateRoute('gestionale.scale.edit'), { condominio: condominio.id, scala: scala.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('gestionale.common.actions.edit') }}
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(scala)"
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
    @confirm="deleteScala"
  />

</template>
