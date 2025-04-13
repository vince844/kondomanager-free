<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { 
  DropdownMenu, 
  DropdownMenuContent,
  DropdownMenuItem, 
  DropdownMenuLabel, 
  DropdownMenuTrigger 
} from '@/components/ui/dropdown-menu'
import { MoreHorizontal } from 'lucide-vue-next'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import type { Role } from '@/types/roles';
import { Trash2, FilePenLine} from 'lucide-vue-next'

defineProps<{ role: Role }>()

const roleID = ref('');

// State for AlertDialog
const isAlertOpen = ref(false)

// Reference for DropdownMenu
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

   <!-- AlertDialog moved outside DropdownMenu -->
   <AlertDialog v-model:open="isAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere eliminare questo ruolo?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà il ruolo e tutti i dati ad esso associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="deleteRole()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>