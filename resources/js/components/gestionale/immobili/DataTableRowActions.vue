<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger
} from '@/components/ui/dropdown-menu'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Trash2, FilePenLine, MoreHorizontal, UserPlus } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'

const { immobile, condominio } = defineProps<{ immobile: Immobile, condominio: Building }>()

const immobileID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetImmobile: Immobile) {
  immobileID.value = targetImmobile.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  immobileID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteImmobile() {
  if (immobileID.value === null || isDeleting.value) return

  const id = immobileID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.immobili.destroy'), { condominio: condominio.id, immobile: id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'immobili'],
    onSuccess: () => {
      closeModal()
    },
    onError: () => {
      console.error('Errore durante la cancellazione.')
    },
    onFinish: () => {
      isDeleting.value = false
    }
  })
}
</script>


<template>
  <DropdownMenu

  >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.immobili.edit'), { condominio: condominio.id, immobile: immobile.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.immobili.anagrafiche.index'), { condominio: condominio.id, immobile: immobile.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <UserPlus class="w-4 h-4 text-xs" />
          Anagrafiche
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(immobile)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <AlertDialog v-model:open="isAlertOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di voler eliminare questo immobile?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà l'immobile e tutti i dati ad esso associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="closeModal">Annulla</AlertDialogCancel>
        <AlertDialogAction :disabled="isDeleting" @click="deleteImmobile">
          <span v-if="isDeleting">Eliminazione...</span>
          <span v-else>Continua</span>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
