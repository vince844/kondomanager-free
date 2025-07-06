<script setup lang="ts">

import { ref } from 'vue';
import { router, Link } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger
} from '@/components/ui/dropdown-menu';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle
} from '@/components/ui/alert-dialog';
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Categoria } from '@/types/categorie';


defineProps<{ categoria: Categoria }>()

const categoriaID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(categoria: Categoria) {
  categoriaID.value = categoria.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  categoriaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteCategoria() {
  if (categoriaID.value === null || isDeleting.value) return

  const id = categoriaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('categorie.destroy'), { id: String(id) }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'categorie'],
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
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('categorie.edit'), { id: categoria.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(categoria)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <AlertDialog v-model:open="isAlertOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di voler eliminare questa categoria?</AlertDialogTitle>
        <AlertDialogDescription>
          Questa azione non è reversibile. Eliminerà la categoria e tutti i documenti ad essa associati.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="closeModal">Annulla</AlertDialogCancel>
        <AlertDialogAction :disabled="isDeleting" @click="deleteCategoria">
          <span v-if="isDeleting">Eliminazione...</span>
          <span v-else>Continua</span>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

</template>
