<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import InputError from '@/components/InputError.vue'
import { useCapitoliConti, type CapitoloDropdown } from '@/composables/useCapitoliConti'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import vSelect from 'vue-select'
import MoneyInput from '@/components/MoneyInput.vue'
import { Info, Lock } from 'lucide-vue-next'
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
})

watch(
  () => props.show,
  (newVal) => {
    if (newVal && props.conto) {
      fetchCapitoliConti(props.condominioId, props.pianoContoId)
    }
  },
)

const extractNumericValue = (formatted: string): number => {
  if (!formatted) return 0
  return Number.parseFloat(formatted.replace('€', '').replace(/\s/g, '').replace(/\./g, '').replace(',', '.')) || 0
}

const isContoCapitolo = computed(() => {
  if (!props.conto) return false
  const importoNumerico = extractNumericValue(props.conto.importo)
  const hasZeroImporto = importoNumerico === 0
  const hasSottoconti = (props.conto.sottoconti?.length ?? 0) > 0
  return (hasZeroImporto && hasSottoconti) || (hasZeroImporto && !props.conto.parent_id)
})

const isImportoLocked = computed(() => props.conto?.has_rate_emesse === true)
const hasSottoconti = computed(() => (props.conto?.sottoconti?.length ?? 0) > 0)

const getPercentualeBySoggetto = (conto: Conto, soggetto: 'proprietario' | 'inquilino' | 'usufruttuario') => {
  const ripartizioni = conto.tabelle_millesimali?.[0]?.ripartizioni || []
  return Number(ripartizioni.find((r) => r.soggetto === soggetto)?.percentuale || 0)
}

watch(
  () => props.conto,
  (newConto) => {
    if (!newConto) return

    form.nome = newConto.nome
    form.codice = newConto.codice || ''
    form.default_fornitore_id = newConto.default_fornitore_id || null
    form.tipo_spesa = newConto.tipo_spesa || 'standard'
    form.tipo = newConto.tipo
    form.descrizione = newConto.descrizione || ''
    form.note = newConto.note || ''
    form.parent_id = newConto.parent_id

    form.tabella_millesimale_id = newConto.tabelle_millesimali?.[0]?.tabella_id ?? null
    form.percentuale_proprietario = getPercentualeBySoggetto(newConto, 'proprietario') ?? 100
    form.percentuale_inquilino = getPercentualeBySoggetto(newConto, 'inquilino')
    form.percentuale_usufruttuario = getPercentualeBySoggetto(newConto, 'usufruttuario')

    isCapitolo.value = isContoCapitolo.value
    isSottoConto.value = !!newConto.parent_id
    form.isCapitolo = isContoCapitolo.value
    form.isSottoConto = !!newConto.parent_id

    form.importo = !isContoCapitolo.value ? newConto.importo : ''
  },
  { immediate: true },
)

watch(isCapitolo, (val) => {
  if (val) {
    isSottoConto.value = false
    form.parent_id = null
    form.importo = ''
    form.tabella_millesimale_id = null
    form.percentuale_proprietario = 100
    form.percentuale_inquilino = 0
    form.percentuale_usufruttuario = 0
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
  form.percentuale_proprietario = 100
  form.percentuale_inquilino = 0
  form.percentuale_usufruttuario = 0
}

const onDropdownCapitoliOpen = () => {
  if (capitoli.value.length === 0) {
    fetchCapitoliConti(props.condominioId, props.pianoContoId)
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

          <div v-if="!isCapitolo" class="space-y-2">
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

          <div v-if="!isCapitolo" class="grid grid-cols-3 gap-4 bg-gray-50 p-3 rounded-md">
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
