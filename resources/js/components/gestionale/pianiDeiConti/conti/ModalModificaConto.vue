<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import InputError from '@/components/InputError.vue'
import { useCapitoliConti, type CapitoloDropdown } from '@/composables/useCapitoliConti'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import vSelect from 'vue-select'
import MoneyInput from '@/components/MoneyInput.vue'
import { Info, Lock, TriangleAlert } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import type { Conto } from '@/types/gestionale/conti'
import type { TabellaDropdown } from '@/types/gestionale/tabelle'

interface Emits {
  (e: 'update:show', value: boolean): void
  (e: 'success'): void
}

interface Props {
  show: boolean
  conto: Conto | null
  condominioId: number
  esercizioId: number
  pianoContoId: number
  tabelle?: Array<{ id: number; nome: string }>
  fornitori?: Array<{ id: number; ragione_sociale: string }>
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const isCapitolo = ref(false)
const isSottoConto = ref(false)
const richiedeGiaVersato = ref(false)
// Confermata esplicitamente dall'admin quando converte in capitolo una voce
// che ha già una tabella millesimale reale (vedi confirm() nel watch su isCapitolo).
// Il backend rifiuta la conversione distruttiva senza questo flag.
const confermaConversioneCapitolo = ref(false)

const { capitoli, isLoading: isLoadingCapitoli, fetchCapitoliConti, reset: resetCapitoli } = useCapitoliConti()
const { euro } = useCurrencyFormatter()

const moneyOptions = ref({
  prefix: '',
  suffix: '',
  thousands: '.',
  decimal: ',',
  precision: 2,
  allowBlank: false,
  masked: true,
})

/**
 * Quante tabelle millesimali sono collegate alla voce aperta.
 *
 * ⚠️ **Questa scheda ne sa rappresentare una sola** — legge `tabelle_millesimali[0]` — e fino alla
 * beta.67 salvarla ne cancellava tutte le altre, con le loro ripartizioni. Il caso non è esotico:
 * è l'art. 1126 c.c., il lastrico solare diviso fra chi ne ha l'uso esclusivo e tutti gli altri.
 *
 * Dalla beta.68 il salvataggio non le tocca più. Ma tacere non basta: senza questo conteggio la
 * scheda mostrerebbe **una** tabella su due come se fossero tutte, e chi legge crederebbe di
 * vedere la ripartizione completa.
 */
const tabelleCollegate = computed(() => props.conto?.tabelle_millesimali?.length ?? 0);
const suPiuTabelle = computed(() => tabelleCollegate.value > 1);

const form = useForm({
  nome: '',
  codice: '',
  default_fornitore_id: null as number | null,
  tipo_spesa: 'standard',
  tipo: 'spesa' as 'spesa' | 'entrata',
  importo: '',
  descrizione: '',
  note: '',
  parent_id: null as number | null,
  tabella_millesimale_id: null as number | null,
  percentuale_proprietario: 100,
  percentuale_inquilino: 0,
  percentuale_usufruttuario: 0,
  isCapitolo: false,
  isSottoConto: false,
  richiedeGiaVersato: false,
})

watch(
  () => props.show,
  (newVal) => {
    if (newVal && props.conto) {
      fetchCapitoliConti(props.condominioId, props.pianoContoId, props.conto.id)
      // Re-idrata il form ogni volta che il modale si apre, anche se lo stesso
      // conto è già selezionato (stessa reference, il watch su conto non scatterebbe)
      populateFormFromConto(props.conto)
    }
  },
)

const isImportoLocked = computed(() => props.conto?.has_rate_emesse === true)
const hasSottoconti = computed(() => (props.conto?.sottoconti?.length ?? 0) > 0)
// Ha già una tabella millesimale reale collegata: se true, trasformare questo
// conto in capitolo cancellerebbe dati veri (tabella + ripartizioni), non un
// record vuoto — serve conferma esplicita, mai un'eliminazione silenziosa.
const hasTabellaReale = computed(() => (props.conto?.tabelle_millesimali?.length ?? 0) > 0)

const getPercentualeBySoggetto = (conto: Conto, soggetto: 'proprietario' | 'inquilino' | 'usufruttuario') => {
  const ripartizioni = conto.tabelle_millesimali?.[0]?.ripartizioni || []
  return Number(ripartizioni.find((r) => r.soggetto === soggetto)?.percentuale || 0)
}

const populateFormFromConto = (newConto: Conto) => {
  form.nome = newConto.nome
  form.codice = newConto.codice || ''
  form.default_fornitore_id = newConto.default_fornitore_id || null
  form.tipo_spesa = newConto.tipo_spesa || 'standard'
  form.tipo = newConto.tipo
  form.descrizione = newConto.descrizione || ''
  form.note = newConto.note || ''
  form.parent_id = newConto.parent_id

  form.tabella_millesimale_id = newConto.tabelle_millesimali?.[0]?.tabella_id ?? null

  // Usa ?? null per distinguere il 0 salvato legittimamente dal "nessuna ripartizione trovata".
  // getPercentualeBySoggetto restituisce 0 anche se non esiste la ripartizione, quindi
  // usiamo un check sull'esistenza della riga per evitare di forzare 100 su conti senza tabella.
  const ripartizioni = newConto.tabelle_millesimali?.[0]?.ripartizioni || []
  const hasPropRipartizione = ripartizioni.some((r) => r.soggetto === 'proprietario')

  form.percentuale_proprietario = hasPropRipartizione
    ? getPercentualeBySoggetto(newConto, 'proprietario')
    : (ripartizioni.length === 0 ? 100 : 0)
  form.percentuale_inquilino = getPercentualeBySoggetto(newConto, 'inquilino')
  form.percentuale_usufruttuario = getPercentualeBySoggetto(newConto, 'usufruttuario')

  // FIX bug "voce a zero perde la tabella millesimale": is_capitolo è un
  // fatto persistito e scelto esplicitamente in creazione — mai più
  // indovinato da importo/parent_id. Una voce non ancora budgettizzata
  // (importo=0) con una tabella millesimale reale non deve mai essere
  // scambiata per un capitolo solo perché il budget è a zero.
  isCapitolo.value = !!newConto.is_capitolo
  isSottoConto.value = !!newConto.parent_id
  form.isCapitolo = !!newConto.is_capitolo
  form.isSottoConto = !!newConto.parent_id
  richiedeGiaVersato.value = !!newConto.richiede_gia_versato
  form.richiedeGiaVersato = !!newConto.richiede_gia_versato
  confermaConversioneCapitolo.value = false

  form.importo = !newConto.is_capitolo ? newConto.importo : ''
}

watch(
  () => props.conto,
  (newConto) => {
    if (!newConto) return
    populateFormFromConto(newConto)
  },
  { immediate: true },
)

watch(isCapitolo, (val, oldVal) => {
  // Toggle manuale ON (via Switch) su una voce che ha già una tabella
  // millesimale reale: è una conversione distruttiva legittima, ma va
  // confermata esplicitamente — mai silenziosa. Se l'admin annulla, il
  // toggle torna indietro e non viene inviata alcuna conferma al backend.
  if (val && !oldVal && hasTabellaReale.value) {
    const confermato = window.confirm(
      trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.confirm.convert_to_capitolo_deletes_table'),
    )
    if (!confermato) {
      isCapitolo.value = false
      return
    }
    confermaConversioneCapitolo.value = true
  } else if (!val) {
    confermaConversioneCapitolo.value = false
  }

  if (val) {
    isSottoConto.value = false
    form.parent_id = null
    form.importo = ''
    form.tabella_millesimale_id = null
    form.percentuale_proprietario = 100
    form.percentuale_inquilino = 0
    form.percentuale_usufruttuario = 0
    richiedeGiaVersato.value = false
    form.richiedeGiaVersato = false
  }
  form.isCapitolo = val
})

watch(isSottoConto, (val) => {
  if (val) {
    isCapitolo.value = false
    form.isCapitolo = false
  }
  form.isSottoConto = val
})

watch(richiedeGiaVersato, (val) => {
  form.richiedeGiaVersato = val
})

watch(() => form.tabella_millesimale_id, () => {
  form.clearErrors('tabella_millesimale_id')
})

watch(() => form.parent_id, () => {
  form.clearErrors('parent_id')
})

const closeModal = () => emit('update:show', false)

const resetForm = () => {
  form.reset()
  isCapitolo.value = false
  isSottoConto.value = false
  richiedeGiaVersato.value = false
  confermaConversioneCapitolo.value = false
  form.percentuale_proprietario = 100
  form.percentuale_inquilino = 0
  form.percentuale_usufruttuario = 0
}

const onDropdownCapitoliOpen = () => {
  if (capitoli.value.length === 0) {
    fetchCapitoliConti(props.condominioId, props.pianoContoId, props.conto?.id)
  }
}

const submit = () => {
  if (!props.conto) return

  const routeParams = {
    condominio: props.condominioId,
    esercizio: props.esercizioId,
    pianoConto: props.pianoContoId,
    conto: props.conto.id,
  }

  form
    .transform((data) => ({
      ...data,
      importo: isCapitolo.value ? 0 : data.importo,
      parent_id: isSottoConto.value ? data.parent_id : null,
      tabella_millesimale_id: isCapitolo.value ? null : data.tabella_millesimale_id,
      percentuale_proprietario: isCapitolo.value ? null : data.percentuale_proprietario,
      percentuale_inquilino: isCapitolo.value ? null : data.percentuale_inquilino,
      percentuale_usufruttuario: isCapitolo.value ? null : data.percentuale_usufruttuario,
      confermaConversioneCapitolo: confermaConversioneCapitolo.value,
    }))
    .put(route('admin.gestionale.esercizi.piani-conti.conti.update', routeParams), {
      preserveScroll: true,
      onSuccess: () => {
        resetForm()
        resetCapitoli()
        emit('success')
        closeModal()
      },
      onError: (errors) => {
        console.error('Errore nella modifica:', errors)
      },
    })
}
</script>

<template>
  <Dialog :open="props.show" @update:open="(val) => !val && closeModal()">
    <DialogContent class="sm:max-w-[750px]" @openAutoFocus="(e: Event) => e.preventDefault()">
      <DialogHeader>
        <DialogTitle>{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.title') }}</DialogTitle>
      </DialogHeader>

      <div class="grid gap-4 py-4 overflow-y-auto px-6 max-h-[70vh]">
        <form v-if="props.conto" class="space-y-4 mt-4" @submit.prevent="submit">
          <input v-model="form.isCapitolo" type="hidden" />
          <input v-model="form.isSottoConto" type="hidden" />
          <input v-model="form.richiedeGiaVersato" type="hidden" />

          <div
            v-if="!isCapitolo && (isImportoLocked || (props.conto?.impegnato ?? 0) > 0)"
            class="bg-blue-50/80 border border-blue-200 rounded-lg p-4 space-y-3 mb-6"
          >
            <div class="flex items-start gap-3">
              <div class="bg-blue-100 p-1.5 rounded-full shrink-0 mt-0.5">
                <Info class="w-4 h-4 text-blue-700" />
              </div>

              <div class="text-sm text-blue-900">
                <strong>{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.locked_notice.title') }}</strong>

                <span v-if="isImportoLocked" class="block mt-1 text-blue-800/80">
                  {{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.locked_notice.locked_by_installments_text') }}
                  <br /><br />
                  <strong>{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.locked_notice.how_to_fix') }}</strong>
                  {{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.locked_notice.locked_by_installments_how_to_fix') }}
                </span>

                <span v-else-if="(props.conto?.impegnato ?? 0) > 0" class="block mt-1 text-blue-800/80">
                  {{
                    trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.locked_notice.committed_already_text', {
                      amount: euro(props.conto?.impegnato ?? 0),
                    })
                  }}
                  <br /><br />
                  <strong>{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.locked_notice.how_to_fix') }}</strong>
                  {{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.locked_notice.committed_how_to_fix') }}
                </span>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-4 gap-4">
            <div class="col-span-1">
              <Label for="codice">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.code') }}</Label>
              <Input
                id="codice"
                v-model="form.codice"
                :placeholder="trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.placeholders.code')"
                class="mt-1"
              />
            </div>
            <div class="col-span-3">
              <Label for="nome">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.entry_name') }}</Label>
              <Input
                id="nome"
                v-model="form.nome"
                :placeholder="trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.placeholders.entry_name')"
                class="mt-1"
                required
              />
              <InputError :message="form.errors.nome" />
            </div>
          </div>

          <div>
            <Label for="descrizione">{{ trans('gestionale.form_common.labels.description') }}</Label>
            <Textarea
              id="descrizione"
              v-model="form.descrizione"
              :placeholder="trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.placeholders.description')"
            />
          </div>

          <div v-if="!isCapitolo" class="flex items-center gap-6 pb-2">
            <Label class="font-medium">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.movement_type') }}</Label>
            <div class="flex items-center gap-2">
              <input id="spesa" v-model="form.tipo" type="radio" value="spesa" />
              <Label for="spesa">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.options.expense_outgoing') }}</Label>
            </div>
            <div class="flex items-center gap-2">
              <input id="entrata" v-model="form.tipo" type="radio" value="entrata" />
              <Label for="entrata">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.options.income') }}</Label>
            </div>
          </div>

          <div class="flex flex-col gap-3 border-y border-gray-100 py-3">
            <div class="flex items-center justify-between">
              <Label for="editIsCapitolo" class="cursor-pointer">
                {{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.is_expense_chapter') }}
              </Label>
              <Switch id="editIsCapitolo" v-model="isCapitolo" :disabled="isSottoConto || hasSottoconti" />
            </div>
            <div class="flex items-center justify-between">
              <Label for="editIsSottoConto" class="cursor-pointer">
                {{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.is_expense_subaccount') }}
              </Label>
              <Switch id="editIsSottoConto" v-model="isSottoConto" :disabled="isCapitolo" />
            </div>
            <div v-if="!isCapitolo" class="flex items-center justify-between">
              <div class="flex items-center gap-1.5">
                <Label for="editRichiedeGiaVersato" class="cursor-pointer">Voce di spesa da esercizio precedente</Label>
                <TooltipProvider :delay-duration="300">
                  <Tooltip>
                    <TooltipTrigger as-child>
                      <Info class="w-3.5 h-3.5 text-slate-400 cursor-default" />
                    </TooltipTrigger>
                    <TooltipContent side="top" class="text-xs max-w-[280px]">
                      Attiva questa opzione se la voce riporta una spesa già in parte
                      raccolta prima di passare a Kondomanager. Comparirà nell'elenco
                      "Già versato", dove potrai registrare quanto ogni unità ha già
                      versato: il riparto chiederà poi solo il residuo.
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
              <Switch id="editRichiedeGiaVersato" v-model="richiedeGiaVersato" />
            </div>
          </div>

          <div v-if="isSottoConto">
            <Label>{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.parent_chapter') }}</Label>
            <v-select
              v-model="form.parent_id"
              :options="capitoli"
              label="nome"
              :placeholder="trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.placeholders.parent_chapter')"
              :reduce="(c: CapitoloDropdown) => c.id"
              :loading="isLoadingCapitoli"
              :clearable="true"
              @open="onDropdownCapitoliOpen"
            />
            <InputError :message="form.errors.parent_id" />
          </div>

          <!--
            ⚠️ Su una voce ripartita su più tabelle la tendina **non si mostra**, e non è una
            limitazione nascosta: mostrarne una su due sarebbe peggio, perché darebbe da leggere
            una ripartizione che non è quella vera. Il salvataggio non le tocca (beta.68), e qui si
            dice dove si modificano.
          -->
          <div v-if="!isCapitolo && suPiuTabelle" class="space-y-2">
            <Label>Ripartizione</Label>
            <div class="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-800/50 dark:bg-amber-900/20">
              <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
              <div class="space-y-1 text-xs text-amber-900 dark:text-amber-200">
                <p class="font-semibold">
                  Questa voce è ripartita su {{ tabelleCollegate }} tabelle millesimali.
                </p>
                <p>
                  Qui puoi cambiare nome, importo e note: la ripartizione resta com'è. Per
                  modificarla — i pesi, le tabelle, i soggetti — usa la sezione delle tabelle
                  collegate sulla riga della voce.
                </p>
              </div>
            </div>
          </div>

          <div v-else-if="!isCapitolo" class="space-y-2">
            <Label>{{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.allocation_table') }}</Label>
            <v-select
              v-model="form.tabella_millesimale_id"
              :options="props.tabelle || []"
              label="nome"
              :placeholder="trans('gestionale.list_pages.piani_conti.show.new_entry_modal.placeholders.allocation_table')"
              :reduce="(t: TabellaDropdown) => t.id"
              :clearable="true"
            />
            <InputError :message="form.errors.tabella_millesimale_id" />
          </div>

          <div v-if="!isCapitolo && !suPiuTabelle" class="grid grid-cols-3 gap-4 bg-gray-50 p-3 rounded-md">
            <div>
              <Label class="text-xs">{{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.owner_percent') }}</Label>
              <Input v-model="form.percentuale_proprietario" class="h-8 mt-1" placeholder="100" />
            </div>
            <div>
              <Label class="text-xs">{{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.tenant_percent') }}</Label>
              <Input v-model="form.percentuale_inquilino" class="h-8 mt-1" placeholder="0" />
            </div>
            <div>
              <Label class="text-xs">{{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.usufructuary_percent') }}</Label>
              <Input v-model="form.percentuale_usufruttuario" class="h-8 mt-1" placeholder="0" />
            </div>
            <InputError :message="form.errors.percentuale_proprietario" />
          </div>

          <div v-if="!isCapitolo" class="bg-slate-50 p-4 rounded-md border border-slate-200 grid grid-cols-2 gap-4">
            <div>
              <Label for="fornitore" class="text-xs font-semibold uppercase text-slate-500">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.suggested_supplier') }}</Label>
              <select
                id="fornitore"
                v-model="form.default_fornitore_id"
                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm mt-1 focus:ring-2 focus:ring-ring"
              >
                <option :value="null">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.options.none') }}</option>
                <option v-for="f in props.fornitori || []" :key="f.id" :value="f.id">{{ f.ragione_sociale }}</option>
              </select>
              <p class="text-[10px] text-slate-500 mt-1">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.help.supplier_prefill') }}</p>
            </div>

            <div>
              <Label for="tipo_spesa" class="text-xs font-semibold uppercase text-slate-500">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.fiscal_expense_nature') }}</Label>
              <select
                id="tipo_spesa"
                v-model="form.tipo_spesa"
                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm mt-1 focus:ring-2 focus:ring-ring"
              >
                <option value="standard">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.expense_types.standard') }}</option>
                <option value="professionista">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.expense_types.professional') }}</option>
                <option value="lavori">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.expense_types.works') }}</option>
                <option value="utenza">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.expense_types.utility') }}</option>
              </select>
            </div>
          </div>

          <div v-if="!isCapitolo">
            <div class="flex justify-between items-center mb-1">
              <Label for="importo">{{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.planned_amount') }}</Label>
              <div v-if="isImportoLocked" class="flex items-center text-xs text-amber-600 bg-amber-50 px-2 py-1 rounded border border-amber-200">
                <Lock class="w-3 h-3 mr-1" />
                {{ trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.labels.locked_badge') }}
              </div>
            </div>

            <MoneyInput
              id="importo"
              v-model="form.importo"
              :money-options="moneyOptions"
              :lazy="true"
              :placeholder="trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.placeholders.amount')"
              :disabled="isImportoLocked"
              :class="{ 'opacity-60 bg-gray-100 cursor-not-allowed': isImportoLocked }"
              @focus="form.clearErrors('importo')"
            />
            <InputError :message="form.errors.importo" />
          </div>

          <div>
            <Label for="note">{{ trans('gestionale.form_common.labels.notes') }}</Label>
            <Textarea id="note" v-model="form.note" :placeholder="trans('gestionale.form_common.placeholders.insert_note')" />
          </div>

          <DialogFooter class="flex justify-end space-x-2 mt-6">
            <Button type="button" variant="outline" @click="closeModal">{{ trans('gestionale.form_common.actions.cancel') }}</Button>
            <Button :disabled="form.processing" type="submit">
              {{
                form.processing
                  ? trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.actions.saving')
                  : trans('gestionale.list_pages.piani_conti.show.edit_entry_modal.actions.save_changes')
              }}
            </Button>
          </DialogFooter>
        </form>
      </div>
    </DialogContent>
  </Dialog>
</template>

<style src="vue-select/dist/vue-select.css"></style>
