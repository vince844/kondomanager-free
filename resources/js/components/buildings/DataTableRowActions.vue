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
import { MoreHorizontal, Trash2, FilePenLine } from 'lucide-vue-next'
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
import { usePermission } from "@/composables/permissions"
import { Permission } from "@/enums/Permission"
import type { Building } from '@/types/buildings';

defineProps<{ building: Building }>()

const { hasPermission, generateRoute } = usePermission()

const buildingID = ref('');

// State for AlertDialog
const isAlertOpen = ref(false)

// Reference for DropdownMenu
const isDropdownOpen = ref(false)

// Function to delete user: first close menu, then open dialog
function handleDelete(building: Building) {
  buildingID.value = building.id;
  isDropdownOpen.value = false // Close dropdown first
  setTimeout(() => {
    isAlertOpen.value = true // Open alert after a small delay
  }, 200) // Delay helps avoid event conflicts
}

const closeModal = () => {
  isDropdownOpen.value = false // Close dropdown first
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
        <span class="sr-only">Azioni</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem 
        v-if="hasPermission([Permission.EDIT_CONDOMINI])"
        @click="editBuilding(building)" 
      >
        <FilePenLine class="w-4 h-4 text-xs" />
        Modifica
      </DropdownMenuItem>

      <DropdownMenuItem 
        v-if="hasPermission([Permission.DELETE_CONDOMINI])"
        @click="handleDelete(building)" 
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

   <!-- AlertDialog moved outside DropdownMenu -->
   <AlertDialog v-model:open="isAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere eliminare questo condominio?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà il condominio e tutti i dati ad esso associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="deleteBuilding()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>