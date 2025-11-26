<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Unplug, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'
import type { AnagraficaWithPivot } from '@/types/anagrafiche'

const props = defineProps<{
  anagrafica: AnagraficaWithPivot
  immobile: Immobile
  condominio: Building
}>()

const anagraficaID = ref<string | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetAnagrafica: AnagraficaWithPivot) {
  anagraficaID.value = targetAnagrafica.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  anagraficaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteAnagrafica() {
  if (anagraficaID.value === null || isDeleting.value) return

  const id = anagraficaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.immobili.anagrafiche.destroy'), 
  { 
    condominio: props.condominio.id, 
    immobile: props.immobile.id,
    anagrafica: id
  }), {
    preserveScroll: true,
    preserveState: true,
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
          :href="route(generateRoute('gestionale.immobili.anagrafiche.edit'), { condominio: condominio.id, immobile: immobile.id, anagrafica: anagrafica.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(anagrafica)"
      >
        <Unplug class="w-4 h-4 text-xs" />
        Dissocia
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di voler dissociare questa anagrafica?"
    description="Questa azione non è reversibile e dissocierà l'anagrafica dall'immobile."
    :loading="isDeleting"
    @confirm="deleteAnagrafica"
  />

</template>
