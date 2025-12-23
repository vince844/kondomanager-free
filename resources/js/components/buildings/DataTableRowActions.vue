<script setup lang="ts">

import { ref } from 'vue'
import { router } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { MoreHorizontal, Trash2, FilePenLine } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { Permission } from "@/enums/Permission"
import { trans } from 'laravel-vue-i18n';
import type { Building } from '@/types/buildings';

defineProps<{ building: Building }>()

const { hasPermission } = usePermission()

const buildingID = ref('');
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)

// Function to delete building first close menu, then open dialog
function handleDelete(building: Building) {
  buildingID.value = building.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true 
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false 
}

const deleteBuilding = () => {
    router.delete(route('condomini.destroy', { id: buildingID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

const editBuilding = (building: Building) => {
  router.get(route('condomini.edit', { id: building.id }))
}

</script>

<template>

  <DropdownMenu
    v-if="hasPermission([
      Permission.EDIT_CONDOMINI,
      Permission.DELETE_CONDOMINI
    ])"
  >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">{{ trans('condomini.table.actions') }}</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('condomini.table.actions') }}</DropdownMenuLabel>

      <DropdownMenuItem 
        v-if="hasPermission([Permission.EDIT_CONDOMINI])"
        @click="editBuilding(building)" 
      >
        <FilePenLine class="w-4 h-4 text-xs" />
         {{ trans('condomini.actions.edit_building') }}
      </DropdownMenuItem>

      <DropdownMenuItem 
        v-if="hasPermission([Permission.DELETE_CONDOMINI])"
        @click="handleDelete(building)" 
      >
        <Trash2 class="w-4 h-4 text-xs" />
        {{ trans('condomini.actions.delete_building') }}
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di volere eliminare questo condominio?"
    description="Questa azione non è reversibile. Eliminerà il condominio e tutti i dati ad esso associati."
    @confirm="deleteBuilding"
  />

</template>