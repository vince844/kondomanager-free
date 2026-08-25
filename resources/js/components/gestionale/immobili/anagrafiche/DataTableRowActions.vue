<script setup lang="ts">

import { ref, computed } from 'vue'
import { router, Link, usePage } from "@inertiajs/vue3"
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

/**
 * ⚠️ **La conferma diceva «questa azione non è reversibile» e taceva la cosa che conta.**
 *
 * Se il soggetto ha già quote emesse su questa unità, il `detach()` lo toglie dalla pivot ma le
 * sue righe restano in `rate_quote`. Da quel momento i due documenti di riparto raccontano storie
 * diverse — è il difetto A6, chiuso in questa beta — e l'estratto conto resta intestato a chi la
 * scheda dell'unità dichiara non più associato.
 *
 * Non è un caso limite: è **il rimedio che gli amministratori usano oggi per il subentro**,
 * perché il motore non legge le date di competenza. Finché il subentro vero non esiste (blocco B2,
 * 1.11), la strada resta praticata: tanto vale dire cosa comporta.
 *
 * Il flag arriva dalle props di pagina invece che da `createColumns()`: la catena è
 * pagina → colonne → tabella → azioni, e infilarci un parametro solo per un avviso avrebbe
 * cambiato la firma di tre file per un dato che appartiene alla pagina.
 */
const haQuoteEmesse = computed(() => {
  const conQuote = (usePage().props.anagraficheConQuoteEmesse ?? []) as Array<number | string>
  return conQuote.some((id) => String(id) === String(props.anagrafica.id))
})

const descrizioneDissocia = computed(() =>
  haQuoteEmesse.value
    // Il titolo dice già il fatto: qui si dice la conseguenza, senza ripeterlo.
    ? "Dissociandolo, le quote restano a suo carico nel piano rate ma lui sparisce dalla scheda dell'unità: i documenti di riparto continueranno a mostrarlo, l'elenco dei titolari no. Se stai registrando un passaggio di proprietà, tieni presente che il riparto non tiene ancora conto delle date di competenza — la quota va corretta a mano."
    : "Questa azione non è reversibile e dissocierà l'anagrafica dall'immobile."
)

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
    :title="haQuoteEmesse ? 'Questo soggetto ha già quote emesse su questa unità' : 'Sei sicuro di voler dissociare questa anagrafica?'"
    :description="descrizioneDissocia"
    :loading="isDeleting"
    @confirm="deleteAnagrafica"
  />

</template>
