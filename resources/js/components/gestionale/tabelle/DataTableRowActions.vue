<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal, Percent } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Tabella } from '@/types/gestionale/tabelle'
import type { Building } from '@/types/buildings'

const { tabella, condominio } = defineProps<{ tabella: Tabella, condominio: Building }>()

const tabellaID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleDelete(targetTabella: Tabella) {
  tabellaID.value = targetTabella.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  tabellaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteTabella() {
  if (tabellaID.value === null || isDeleting.value) return

  const id = tabellaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.tabelle.destroy'), { condominio: condominio.id, tabella: id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'tabelle'],
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
            :href="route(generateRoute('gestionale.tabelle.quote.index'), { condominio: condominio.id, tabella: tabella.id })"
            preserve-state
            class="flex items-center gap-2"
          >
            <Percent class="w-4 h-4 text-xs" />
            Millesimi
          </Link>
        </DropdownMenuItem>


      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.tabelle.edit'), { condominio: condominio.id, tabella: tabella.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(tabella)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di voler eliminare questa tabella?"
    description="Questa azione non è reversibile. Eliminerà la tabella e tutti i dati ad essa associati."
    :loading="isDeleting"
    @confirm="deleteTabella"
  />

</template>
