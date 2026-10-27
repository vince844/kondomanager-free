<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import type { Anagrafica } from '@/types/anagrafiche';

defineProps<{ anagrafica: Anagrafica }>()

// Ogni comando chiede il permesso che `AnagraficaPolicy` chiede alla sua azione (decisioni 137 e 138.1): «Modifica»
// apre il modulo di modifica, «Elimina» elimina. Il collaboratore di serie non ha «Elimina anagrafiche»; chi ha la sola
// consultazione non ha comandi, e il menù non compare.
const { hasPermission } = usePermission()

const anagraficaID = ref('');
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)

function handleDelete(anagrafica: Anagrafica) {
  anagraficaID.value = anagrafica.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false 
}

const deleteAnagrafica = () => {
    router.delete(route('admin.anagrafiche.destroy', { id: anagraficaID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

</script>

<template>
  <DropdownMenu
    v-if="hasPermission([
      Permission.EDIT_ANAGRAFICHE,
      Permission.DELETE_ANAGRAFICHE
    ])"
  >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">{{ trans('anagrafiche.table.actions') }}</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('anagrafiche.table.actions') }}</DropdownMenuLabel>

       <DropdownMenuItem v-if="hasPermission([Permission.EDIT_ANAGRAFICHE])">
        <Link
          :href="route('admin.anagrafiche.edit', { id: anagrafica.id })"
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          {{ trans('anagrafiche.actions.edit_resident') }}
        </Link>
      </DropdownMenuItem> 

      <DropdownMenuItem
        v-if="hasPermission([Permission.DELETE_ANAGRAFICHE])"
        @click="handleDelete(anagrafica)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
         {{ trans('anagrafiche.actions.delete_resident') }}
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="trans('anagrafiche.dialogs.delete_resident_title')"
    :description="trans('anagrafiche.dialogs.delete_resident_description')"
    @confirm="deleteAnagrafica"
  />

</template>