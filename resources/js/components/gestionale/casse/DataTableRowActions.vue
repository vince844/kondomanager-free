<script setup lang="ts">

import { ref } from 'vue'
import { router, Link, usePage } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal, BookOpen } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Cassa } from '@/types/gestionale/casse'
import type { Building } from '@/types/buildings'

const { cassa, condominio } = defineProps<{ cassa: Cassa, condominio: Building }>()

const cassaID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute, generatePath } = usePermission()

// La porta verso il mastrino (D21.7): «tutti i movimenti della banca» per chi pensa alla
// cassa e non al conto 1010.01. Serve l'esercizio corrente, che la pagina riceve dal
// controller; senza un esercizio aperto — o senza un conto contabile — la voce non compare.
const pagina = usePage<{ esercizio?: { id: number } | null }>()
const hrefMovimenti = (cassa: Cassa): string | null => {
  const esercizio = pagina.props.esercizio
  if (!esercizio || !cassa.conto_contabile_id) return null
  return generatePath('gestionale/:condominio/esercizi/:esercizio/conti/:contoContabile/movimenti', {
    condominio: condominio.id, esercizio: esercizio.id, contoContabile: cassa.conto_contabile_id,
  }) + '?da=casse' // così il pulsante «indietro» del mastrino riporta qui
}

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

      <DropdownMenuItem v-if="hrefMovimenti(cassa)">
        <Link :href="hrefMovimenti(cassa)!" class="flex items-center gap-2">
          <BookOpen class="w-4 h-4 text-xs" />
          Movimenti
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
