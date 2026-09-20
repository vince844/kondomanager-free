<script setup lang="ts">

import { ref, computed } from 'vue'
import { router, Link, usePage } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Unplug, FilePenLine, MoreHorizontal, ArrowRightLeft } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'
import type { AnagraficaWithPivot } from '@/types/anagrafiche'

const props = defineProps<{
  anagrafica: AnagraficaWithPivot
  immobile: Immobile
  condominio: Building
}>()

const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)
/** Il rifiuto del server (decisione 13): si mostra nel dialogo, al posto della conferma. */
const rifiuto = ref<string | null>(null)

/**
 * ⚠️ **La conferma diceva «questa azione non è reversibile» e taceva la cosa che conta.**
 *
 * Se il soggetto ha già quote emesse su questa unità, il `detach()` lo toglie dalla pivot ma le
 * sue righe restano in `rate_quote`. Da quel momento i due documenti di riparto raccontano storie
 * diverse — è il difetto A6, chiuso nella beta.30 — e l'estratto conto resta intestato a chi la
 * scheda dell'unità dichiara non più associato.
 *
 * Dalla 1.11.0-beta.31 il rimedio giusto esiste: **«Registra passaggio»** chiude il periodo e
 * conserva la storia. «Dissocia» resta per la riga scritta per sbaglio; su una riga con storia il
 * server rifiuta (422, decisione 13) e il rifiuto compare qui.
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
    ? "Dissociandolo, le sue quote restano nel piano rate ma lui sparisce dalla scheda dell'unità: i documenti di riparto continueranno a mostrarlo, l'elenco dei titolari no. Se è cambiato il titolare, chiudi qui e usa «Registra passaggio»: chiude il periodo alla data giusta e conserva la storia."
    : "Cancella questa riga di titolarità: la persona sparisce dall'unità come se non ci fosse mai stata, senza date e senza storico. Va bene per un'associazione scritta per sbaglio. Se invece il titolare è cambiato, usa «Registra passaggio»."
)

const { generateRoute } = usePermission()

/**
 * La voce «Registra passaggio» di una riga apre il tipo che fa uscire **quel** ruolo, con la riga già
 * scelta (`?riga=`): l'inquilino esce con «Fine locazione», l'usufruttuario con «Usufrutto → estinzione»,
 * il proprietario e il nudo proprietario con «Vendita o donazione».
 */
function tipoPassaggioPerRuolo(ruolo: string): string {
  if (ruolo === 'inquilino') return 'fine_locazione'
  if (ruolo === 'usufruttuario') return 'usufrutto'
  return 'vendita'
}

function handleDelete() {
  rifiuto.value = null
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  isAlertOpen.value = false
  isDropdownOpen.value = false
  rifiuto.value = null
}

function deleteAnagrafica() {
  if (isDeleting.value) return
  isDeleting.value = true

  // Per **riga** (`titolarita: pivot.id`), non per persona: la stessa persona può avere due periodi
  // sulla stessa unità e «quale dei due» lo dice solo la riga (decisione 13).
  router.delete(route(generateRoute('gestionale.immobili.anagrafiche.destroy'),
  {
    condominio: props.condominio.id,
    immobile: props.immobile.id,
    titolarita: props.anagrafica.pivot.id
  }), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      closeModal()
    },
    onError: (errors) => {
      // Decisione 13: la riga ha una storia e il server non la cancella. Il messaggio resta nel
      // dialogo, dove l'amministratore ha appena letto la conferma.
      rifiuto.value = (errors as Record<string, string>).titolarita ?? Object.values(errors as Record<string, string>)[0] ?? 'Il server ha rifiutato la cancellazione.'
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
    <DropdownMenuContent align="end" class="w-72">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem as-child>
        <Link
          :href="route(generateRoute('gestionale.immobili.passaggi.create'), { condominio: condominio.id, immobile: immobile.id, tipo: tipoPassaggioPerRuolo(anagrafica.pivot.tipologia), riga: anagrafica.pivot.id })"
          class="flex items-start gap-2 cursor-pointer"
        >
          <ArrowRightLeft class="w-4 h-4 mt-0.5 shrink-0" />
          <span class="flex flex-col">
            <span>Registra passaggio</span>
            <span class="text-[11px] text-slate-500">Il titolare cambia: il periodo si chiude, la storia resta</span>
          </span>
        </Link>
      </DropdownMenuItem>

      <DropdownMenuSeparator />

      <DropdownMenuItem as-child>
        <Link
          :href="route(generateRoute('gestionale.immobili.anagrafiche.edit'), { condominio: condominio.id, immobile: immobile.id, titolarita: anagrafica.pivot.id })"
          preserve-state
          class="flex items-start gap-2 cursor-pointer"
        >
          <FilePenLine class="w-4 h-4 mt-0.5 shrink-0" />
          <span class="flex flex-col">
            <span>Modifica associazione</span>
            <span class="text-[11px] text-slate-500">Correggi un dato sbagliato, senza cambiare il titolare</span>
          </span>
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem @click="handleDelete" class="flex items-start gap-2">
        <Unplug class="w-4 h-4 mt-0.5 shrink-0" />
        <span class="flex flex-col">
          <span>Dissocia</span>
          <span class="text-[11px] text-slate-500">Cancella la riga: solo per un'associazione scritta per sbaglio</span>
        </span>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="rifiuto ? 'Questa riga non si cancella' : haQuoteEmesse ? 'Questo soggetto ha già quote emesse su questa unità' : 'Dissociare ' + anagrafica.nome + ' da questa unità?'"
    :description="rifiuto ?? descrizioneDissocia"
    :loading="isDeleting"
    :disabled="rifiuto !== null"
    :variant="rifiuto ? 'warning' : 'destructive'"
    confirm-text="Dissocia"
    @confirm="deleteAnagrafica"
  />

</template>
