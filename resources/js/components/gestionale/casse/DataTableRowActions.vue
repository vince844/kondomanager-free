<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Cassa } from '@/types/gestionale/casse'
import type { Building } from '@/types/buildings'

const { cassa, condominio } = defineProps<{ cassa: Cassa, condominio: Building }>()

const cassaID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetCassa: Cassa) {
  cassaID.value = targetCassa.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  cassaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteCassa() {
  if (cassaID.value === null || isDeleting.value) return

  const id = cassaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.casse.destroy'), { condominio: condominio.id, cassa: id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'casse'],
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
          :href="route(generateRoute('gestionale.casse.edit'), { condominio: condominio.id, cassa: cassa.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(cassa)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di voler eliminare questa cassa?"
    description="Questa azione non è reversibile. Eliminerà la palazzina e tutti i dati ad essa associati."
    :loading="isDeleting"
    @confirm="deleteCassa"
  />

</template>
