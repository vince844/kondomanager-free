<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { MoreHorizontal } from 'lucide-vue-next'
import type { Role } from '@/types/roles';
import { Trash2, FilePenLine} from 'lucide-vue-next'

defineProps<{ role: Role }>()

const roleID = ref('');
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)

// Function to delete user: first close menu, then open dialog
function handleDelete(role: Role) {
  roleID.value = role.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isAlertOpen.value = true 
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false
}

const deleteRole = () => {
    router.delete(route('ruoli.destroy', { id: roleID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

const editRole = (role: Role) => {
  router.get(route('ruoli.edit', { id: role.id}))
}

</script>

<template>
  
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">Azioni</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>
      
      <DropdownMenuItem @click="editRole(role)" >
        <FilePenLine class="w-4 h-4 text-xs" />
        Modifica 
      </DropdownMenuItem>

      <DropdownMenuItem @click="handleDelete(role)" >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina 
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di volere eliminare questo ruolo?"
    description="Questa azione non è reversibile. Eliminerà il ruolo e tutti i dati ad esso associati."
    @confirm="deleteRole"
  />

</template>