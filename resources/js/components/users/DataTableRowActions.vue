<script setup lang="ts">
import { ref } from 'vue'
import { router } from "@inertiajs/vue3";
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

import type { User } from '@/types/users';

defineProps<{ user: User }>()

const userID = ref('');
const userEmail = ref('');

// State for AlertDialog
const isAlertOpen = ref(false)

// Reference for DropdownMenu
const isDropdownOpen = ref(false)

// Function to delete user: first close menu, then open dialog
function handleDelete(user: User) {
  userID.value = user.id;
  isDropdownOpen.value = false // Close dropdown first
  setTimeout(() => {
    isAlertOpen.value = true // Open alert after a small delay
  }, 200) // Delay helps avoid event conflicts
}

// Function to delete user: first close menu, then open dialog
function handleReinvite(user: User) {
  userEmail.value = user.email;
  isDropdownOpen.value = false // Close dropdown first
  setTimeout(() => {
    isAlertOpen.value = true // Open alert after a small delay
  }, 200) // Delay helps avoid event conflicts
}

const closeModal = () => {
  isDropdownOpen.value = false // Close dropdown first
}

const deleteUser = () => {
    router.delete(route('utenti.destroy', { id: userID.value }),{
        preserveScroll: true,
        onSuccess: () => closeModal()
    })
}

const editUser = (user: User) => {
  router.get(route('utenti.edit', { id: user.id})) 
}

const suspendUser = (user: User) => {
  router.put(route('utenti.suspend', { id: user.id})) 
}

const unsuspendUser = (user: User) => {
  router.put(route('utenti.unsuspend', { id: user.id})) 
}

const reinviteUser = () => {
  router.post(route('utenti.reinvite', { email: userEmail.value }), {
        email: userEmail.value 
    }, {
        preserveScroll: true,
        onSuccess: () => closeModal()
    });
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

      <DropdownMenuItem @click="editUser(user)" >
        Modifica utente
      </DropdownMenuItem>

      <DropdownMenuItem 
        v-if="!user.suspended_at"
        @click="suspendUser(user)"
      >
        Sospendi utente
      </DropdownMenuItem>

      <DropdownMenuItem 
        v-else
        @click="unsuspendUser(user)"
      >
        Riattiva utente
      </DropdownMenuItem>

      <DropdownMenuItem @click="handleReinvite(user)" >
        Reinvita utente
      </DropdownMenuItem>

      <DropdownMenuItem @click="handleDelete(user)" >
        Elimina utente
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

    <!-- AlertDialog for deleting action -->
   <AlertDialog v-model:open="isAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere eliminare questo utente?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà l'utente e tutti i dati ad esso associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="deleteUser()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

   <!-- AlertDialog for reinviting action -->
   <AlertDialog v-model:open="isAlertOpen" >
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di volere invitare nuovamente questo utente?</AlertDialogTitle>
        <AlertDialogDescription>
          L'utente riceverà una email con un nuovo link per la creazione di una nuova password.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
        <AlertDialogAction  @click="reinviteUser()">Continua</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>