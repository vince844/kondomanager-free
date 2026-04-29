<script setup lang="ts">

import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import GestionaleLayout from '@/layouts/GestionaleLayout.vue'
import { usePermission } from '@/composables/permissions'
import { Button } from '@/components/ui/button'
import { Plus, LoaderCircle, List, AlertTriangle, Wallet, FolderTree, Settings2, Calculator, Lock } from 'lucide-vue-next'
import Alert from "@/components/Alert.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ModalNuovoConto from '@/components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue'
import ModalModificaConto from '@/components/gestionale/pianiDeiConti/conti/ModalModificaConto.vue'
import AlberoDeiConti from '@/components/gestionale/pianiDeiConti/conti/AlberoDeiConti.vue'
import DettaglioConto from '@/components/gestionale/pianiDeiConti/conti/DettaglioConto.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ModalAssociaTabella from '@/components/gestionale/pianiDeiConti/conti/ModalAssociaTabella.vue'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Conto } from '@/types/gestionale/conti'
import type { Flash } from '@/types/flash';

// Tipo locale per la tabella da modificare
interface TabellaAssociata {
  id: number
  nome: string
  coefficiente: number
  ripartizioni: Array<{ soggetto: string; percentuale: number }>
}

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  esercizi?: Esercizio[]
  condomini?: Building[]
  pianoConti: PianoDeiConti
  conti: Conto[]
  fornitori: Array<{ id: number, ragione_sociale: string }>
  tabelle: Array<{ id: number, nome: string }>
  totalePreventivo: number
  totaleSopravvenienze: number
}>()

const { generatePath } = usePermission()
const showModalNew              = ref(false)
const showModalEdit             = ref(false)
const showModalDelete           = ref(false)
const contoSelezionato          = ref<Conto | null>(null)
const contoDaEliminare          = ref<Conto | null>(null)
const tabellaDaRimuovere        = ref<number | null>(null)
const showModalAssociaTabella   = ref(false)
const showModalRimuoviTabella   = ref(false)

// Stato per la modifica di una tabella esistente
const tabellaDaModificare       = ref<TabellaAssociata | null>(null)

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);
const { euro } = useCurrencyFormatter()

const headerBreadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Piani dei conti', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: props.pianoConti.nome, href: '#' }
])

const pageGuides = [
  {
    title: 'Struttura ad Albero',
    description: 'Organizza le spese in Mastro, Conto e Sottoconto per una maggiore granularità e precisione nel bilancio.',
    icon: FolderTree,
    colorVariant: 'blue' as const
  },
  {
    title: 'Associazione Tabelle',
    description: 'Collega direttamente le tabelle millesimali ai conti per automatizzare i futuri riparti di spesa.',
    icon: Settings2,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Monitoraggio Preventivo',
    description: 'Tieni d\'occhio il totale preventivato per assicurarti che il budget assegnato sia sempre sotto controllo.',
    icon: Calculator,
    colorVariant: 'amber' as const
  }
];

function trovaContoInArray(conti: Conto[], id: number): Conto | null {
  for (const conto of conti) {
    if (conto.id === id) return conto;
    if (conto.sottoconti && conto.sottoconti.length) {
      const sottoconto = trovaContoInArray(conto.sottoconti, id);
      if (sottoconto) return sottoconto;
    }
  }
  return null;
}

watch(
  () => props.conti,
  (newConti) => {
    if (contoSelezionato.value) {
      const contoAggiornato = trovaContoInArray(newConti, contoSelezionato.value.id);
      if (contoAggiornato) {
        contoSelezionato.value = contoAggiornato;
      } else {
        contoSelezionato.value = null;
      }
    }
  },
  { deep: true }
);

const selezionaConto    = (conto: Conto) => { contoSelezionato.value = conto }
const modificaConto     = (conto: Conto) => { contoSelezionato.value = conto; showModalEdit.value = true }
const confermaEliminazione = (conto: Conto) => { contoDaEliminare.value = conto; showModalDelete.value = true }

const confermaRimozioneTabella = (payload: { conto: Conto, tabellaId: number }) => {
  contoSelezionato.value   = payload.conto
  tabellaDaRimuovere.value = payload.tabellaId
  showModalRimuoviTabella.value = true
}

// Handler per l'edit della tabella dal DettaglioConto
const onModificaTabella = (payload: { conto: Conto, tabella: TabellaAssociata }) => {
  contoSelezionato.value   = payload.conto
  tabellaDaModificare.value = payload.tabella
  showModalAssociaTabella.value = true
}

// Handler per aprire la modale in modalità CREA
const onAggiungiTabella = (conto: Conto) => {
  contoSelezionato.value   = conto
  tabellaDaModificare.value = null   // nessuna tabella = modalità creazione
  showModalAssociaTabella.value = true
}

// Chiusura modale: pulisce sempre la tabellaDaModificare
const onChiudiModalAssociaTabella = (val: boolean) => {
  showModalAssociaTabella.value = val
  if (!val) tabellaDaModificare.value = null
}

const contiPreventivo = computed(() => props.conti.filter(c => !c.is_tecnico))
const contiTecnici    = computed(() => props.conti.filter(c => c.is_tecnico))

// Residuo disponibile per il conto selezionato (esclude la tabella in modifica se in edit mode)
const residuoDisponibile = computed(() => {
  if (!contoSelezionato.value?.tabelle_millesimali) return 100
  const somma = contoSelezionato.value.tabelle_millesimali.reduce(
    (acc, tm) => acc + (tm.coefficiente ?? 0), 0
  )
  // In edit mode il residuo "grezzo" esclude già la tabella corrente perché
  // maxCoefficiente nella modale aggiunge il coefficiente attuale della tabella
  return Math.max(0, 100 - somma)
})

const eliminaConto = () => {
  if (!contoDaEliminare.value) return
  router.delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    pianoConto: props.pianoConti.id,
    conto: contoDaEliminare.value.id
  }), {
    preserveScroll: true,
    onSuccess: () => { contoSelezionato.value = null; contoDaEliminare.value = null; showModalDelete.value = false },
    onError:   () => { showModalDelete.value = false }
  })
}

const annullaEliminazione    = () => { contoDaEliminare.value = null; showModalDelete.value = false }
const annullaRimozioneTabella = () => { tabellaDaRimuovere.value = null; showModalRimuoviTabella.value = false }
const onModificaSuccess      = () => { showModalEdit.value = false }

/**
 * Callback unica per la ModalAssociaTabella.
 * In base a _isEdit chiama POST (crea) o PUT (aggiorna).
 */
const gestisciTabella = (dati: any) => {
  if (!contoSelezionato.value) return

  if (dati._isEdit && dati._tabellaId) {
    // === MODIFICA ===
    router.put(
      route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella', {
        condominio: props.condominio.id,
        esercizio:  props.esercizio.id,
        pianoConto: props.pianoConti.id,
        conto:      contoSelezionato.value.id,
        tabella:    dati._tabellaId,
      }),
      {
        coefficiente:              dati.coefficiente,
        percentuale_proprietario:  dati.percentuale_proprietario,
        percentuale_inquilino:     dati.percentuale_inquilino,
        percentuale_usufruttuario: dati.percentuale_usufruttuario,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          showModalAssociaTabella.value = false
          tabellaDaModificare.value     = null
        },
      }
    )
  } else {
    // === CREAZIONE ===
    router.post(
      route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', {
        condominio: props.condominio.id,
        esercizio:  props.esercizio.id,
        pianoConto: props.pianoConti.id,
        conto:      contoSelezionato.value.id,
      }),
      {
        tabella_millesimale_id:    dati.tabella_millesimale_id,
        coefficiente:              dati.coefficiente,
        percentuale_proprietario:  dati.percentuale_proprietario,
        percentuale_inquilino:     dati.percentuale_inquilino,
        percentuale_usufruttuario: dati.percentuale_usufruttuario,
      },
      {
        preserveScroll: true,
        onSuccess: () => { showModalAssociaTabella.value = false },
      }
    )
  }
}

const rimuoviTabella = () => {
  if (!contoSelezionato.value || !tabellaDaRimuovere.value) return
  router.delete(route('admin.gestionale.esercizi.piani-conti.conti.dissocia-tabella', {
    condominio: props.condominio.id,
    esercizio:  props.esercizio.id,
    pianoConto: props.pianoConti.id,
    conto:      contoSelezionato.value.id,
    tabella:    tabellaDaRimuovere.value
  }), {
    preserveScroll: true,
    onSuccess: () => { showModalRimuoviTabella.value = false; tabellaDaRimuovere.value = null }
  })
}
</script>

<template>
  <Head title="Gestione conto" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Gestione spese"
        :page-subtitle="props.pianoConti.nome"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :back-url="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
        back-text="Piani dei conti"
      >
        <template #actions>
          <div class="flex items-center gap-2">
            <button
              @click="showModalNew = true"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md shadow px-4 bg-primary text-[10px] font-bold uppercase tracking-widest text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <Plus class="w-3.5 h-3.5" />
              <span>Aggiungi Voce</span>
            </button>
          </div>
        </template>
      </PageHeaderGuide>

      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">

            <!-- ELENCO CONTI -->
            <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden">
              <div class="p-4 border-b border-gray-200 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-900/50">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Elenco conti e sottoconti</h3>
                <div class="flex items-center gap-2">
                  <span v-if="contiTecnici.length > 0"
                        class="bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-bold px-2 py-1 rounded-md flex items-center gap-1 shadow-sm">
                    <AlertTriangle class="w-3 h-3" />
                    Sopravvenienze: {{ euro(props.totaleSopravvenienze) }}
                  </span>
                  <span class="bg-indigo-50 text-indigo-700 border border-indigo-200 text-[10px] font-bold px-2 py-1 rounded-md flex items-center gap-1 shadow-sm">
                    <Wallet class="w-3 h-3" />
                    Preventivo: {{ euro(props.totalePreventivo) }}
                  </span>
                </div>
              </div>

              <div class="pl-2 pt-4 pr-2 max-h-[600px] overflow-y-auto">
                <div class="mb-2">
                  <div class="flex items-center gap-2 px-3 py-2">
                    <Lock class="w-3.5 h-3.5 text-indigo-500" />
                    <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-600">Preventivo deliberato</span>
                  </div>
                  <div class="bg-indigo-50/30 rounded-lg mx-1 mb-2">
                    <AlberoDeiConti
                      :conti="contiPreventivo"
                      :selected-id="contoSelezionato?.id"
                      @seleziona="selezionaConto"
                    />
                  </div>
                </div>

                <div v-if="contiTecnici.length > 0" class="mt-4 pt-3 border-t border-dashed border-amber-200">
                  <div class="flex items-center gap-2 px-3 py-2 mb-1">
                    <AlertTriangle class="w-3.5 h-3.5 text-amber-500" />
                    <span class="text-[10px] font-bold uppercase tracking-widest text-amber-600">Sopravvenienze e imprevisti</span>
                    <span class="text-[9px] text-amber-500 font-medium">(fuori preventivo)</span>
                  </div>
                  <div class="bg-amber-50/30 rounded-lg mx-1 mb-2">
                    <AlberoDeiConti
                      :conti="contiTecnici"
                      :selected-id="contoSelezionato?.id"
                      @seleziona="selezionaConto"
                    />
                  </div>
                </div>
              </div>
            </div>

            <!-- DETTAGLIO CONTO -->
            <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden">
              <div class="p-4 border-b border-gray-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Dettagli voce selezionata</h3>
              </div>
              <div class="p-2">
                <DettaglioConto
                  :conto="contoSelezionato"
                  :condominio-id="props.condominio.id"
                  :esercizio-id="props.esercizio.id"
                  @elimina="confermaEliminazione"
                  @modifica="modificaConto"
                  @aggiungi-tabella="onAggiungiTabella"
                  @modifica-tabella="onModificaTabella"
                  @rimuovi-tabella="confermaRimozioneTabella"
                />
              </div>
            </div>

          </div>
        </section>
      </div>
    </div>

    <ModalNuovoConto
      :show="showModalNew"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
      :piano-conto-id="props.pianoConti.id"
      :fornitori="props.fornitori"
      @update:show="showModalNew = $event"
    />

    <!-- Modale unica per CREA e MODIFICA associazione -->
    <ModalAssociaTabella
      :show="showModalAssociaTabella"
      :conto="contoSelezionato"
      :condominio-id="props.condominio.id"
      :tabella-esistente="tabellaDaModificare"
      :residuo-disponibile="residuoDisponibile"
      @update:show="onChiudiModalAssociaTabella"
      @success="gestisciTabella"
    />

    <ModalModificaConto
      :show="showModalEdit"
      :conto="contoSelezionato"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
      :piano-conto-id="props.pianoConti.id"
      :tabelle="props.tabelle"
      :fornitori="props.fornitori"
      @update:show="showModalEdit = $event"
      @success="onModificaSuccess"
    />

    <ConfirmDialog
      v-model:modelValue="showModalRimuoviTabella"
      title="Rimuovi tabella associata"
      description="Sei sicuro di voler rimuovere questa tabella millesimale dal conto?"
      confirm-text="Rimuovi"
      cancel-text="Annulla"
      variant="destructive"
      @confirm="rimuoviTabella"
      @cancel="annullaRimozioneTabella"
    />

    <ConfirmDialog
      v-model:modelValue="showModalDelete"
      title="Sei sicuro di voler eliminare"
      description="Questa azione non è reversibile. Il conto verrà eliminato permanentemente."
      confirm-text="Elimina"
      cancel-text="Annulla"
      variant="destructive"
      @confirm="eliminaConto"
      @cancel="annullaEliminazione"
    />

  </GestionaleLayout>
</template>