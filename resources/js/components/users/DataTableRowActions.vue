<script setup lang="ts">

import { ref } from 'vue'
import { router } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { MoreHorizontal } from 'lucide-vue-next'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { trans } from 'laravel-vue-i18n'
import { Trash2, FilePenLine, Send, MonitorCheck, MonitorX } from 'lucide-vue-next'
import type { User } from '@/types/users';

defineProps<{ user: User }>()

const userID = ref('');
const userEmail = ref('');

// State for AlertDialog
const isDeleteAlertOpen = ref(false)
const isReinviteAlertOpen = ref(false)

// Reference for DropdownMenu
const isDropdownOpen = ref(false)

// Function to delete user: first close menu, then open dialog
function handleDelete(user: User) {
  userID.value = user.id;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isDeleteAlertOpen.value = true 
  }, 200) 
}

// Function to delete user: first close menu, then open dialog
function handleReinvite(user: User) {
  userEmail.value = user.email;
  isDropdownOpen.value = false 
  setTimeout(() => {
    isReinviteAlertOpen.value = true 
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false 
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
        <span class="sr-only">{{ trans('comunicazioni.table.actions') }}</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('comunicazioni.table.actions') }}</DropdownMenuLabel>

      <DropdownMenuItem @click="editUser(user)" >
        <FilePenLine class="w-4 h-4 text-xs" />
        {{ trans('users.actions.edit_user') }}
      </DropdownMenuItem>

      <DropdownMenuItem  v-if="!user.suspended_at" @click="suspendUser(user)">
        <MonitorX class="w-4 h-4 text-xs" />
        {{ trans('users.actions.suspend_user') }} 
      </DropdownMenuItem>

      <DropdownMenuItem v-else @click="unsuspendUser(user)" >
        <MonitorCheck class="w-4 h-4 text-xs" />
        {{ trans('users.actions.activate_user') }} 
      </DropdownMenuItem>

      <DropdownMenuItem @click="handleReinvite(user)" >
        <Send class="w-4 h-4 text-xs" />
        {{ trans('users.actions.invite_user') }} 
      </DropdownMenuItem>

      <DropdownMenuItem @click="handleDelete(user)" >
        <Trash2 class="w-4 h-4 text-xs" />
        {{ trans('users.actions.delete_user') }}
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isDeleteAlertOpen"
    :title="trans('users.dialogs.delete_user_title')"
    :description="trans('users.dialogs.delete_user_description')"
    @confirm="deleteUser"
  />

  <ConfirmDialog
    v-model:modelValue="isReinviteAlertOpen"
    :title="trans('users.dialogs.invite_user_title')"
    :description="trans('users.dialogs.invite_user_description')"
    @confirm="reinviteUser"
  />

</template>