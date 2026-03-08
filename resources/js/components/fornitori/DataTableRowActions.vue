<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n';
import type { Fornitore } from '@/types/fornitori';

defineProps<{ fornitore: Fornitore }>()

const fornitoreID = ref('');
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)

function handleDelete(fornitore: Fornitore) {
  fornitoreID.value = fornitore.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false 
}

const deleteFornitore = () => {
    router.delete(route('admin.fornitori.destroy', { id: fornitoreID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">{{ trans('fornitori.table.actions') }}</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('fornitori.table.actions') }}</DropdownMenuLabel>

       <DropdownMenuItem>
        <Link
          :href="route('admin.fornitori.edit', { id: fornitore.id })"
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('fornitori.actions.edit_fornitore') }}
        </Link>
      </DropdownMenuItem> 

      <DropdownMenuItem @click="handleDelete(fornitore)" >
        <Trash2 class="w-4 h-4 text-xs" />
        {{ trans('fornitori.actions.delete_fornitore') }}
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="trans('fornitori.dialogs.delete_supplier_title')"
    :description="trans('fornitori.dialogs.delete_supplier_description')"
    @confirm="deleteFornitore"
  />

</template>
