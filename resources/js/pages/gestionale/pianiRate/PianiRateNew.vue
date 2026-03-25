<script setup lang="ts">

import { computed, ref, watch } from 'vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import vSelect from 'vue-select';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import MoneyInput from '@/components/MoneyInput.vue'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Plus, LoaderCircle, List, AlertTriangle, CheckCircle, Wallet, Ban, Info, Trash2, Building2, User, CalendarDays } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Gestione } from '@/types/gestionale/gestioni';
import type { BreadcrumbItem } from '@/types';

interface Capitolo {
  id: number;
  nome: string;
  disabled: boolean;
  importo_totale: number;
  residuo: number;
  note?: string;
}

interface CapitoloDettaglio {
  id: number;
  nome: string;
  importo_totale: number;
  residuo: number;
  importo_da_usare: number | undefined;
  note: string;
}

interface SaldoDettaglio {
  id: number;
  tipo: 'nominale' | 'solidale';
  anagrafica_id?: number;
  soggetto_nome: string;
  immobile_nome: string;
  ruolo: string;
  importo: number;
  is_debito: boolean;
  immobile_id?: number;
  ripartizione_mode: 'automatica' | 'manuale';
  ripartizioni_custom: { anagrafica_id: number; importo: number }[];
}

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  gestioni: Gestione[]
  saldoInfo: {
    saldo: number
    applicabile: boolean
    motivo: string
    gestione_utilizzatrice?: any
    is_primo_anno?: boolean
    has_movimenti?: boolean
  }
  anagraficheDisponibili: { id: number, nome: string }[]
}>()

const { generateRoute, generatePath } = usePermission()

// --- BREADCRUMBS & GUIDES ---
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Piani Rate', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: 'Nuovo Piano Rate', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Informazioni Base',
    description: "Definisci il nome del piano e la gestione contabile di competenza.",
    icon: List,
    colorVariant: 'blue' as const
  },
  {
    title: 'Saldi Pregressi',
    description: "Scegli se creare una Rata 0 per i debiti precedenti o spalmarli sul nuovo piano.",
    icon: Wallet,
    colorVariant: 'amber' as const
  },
  {
    title: 'Scadenze',
    description: "Imposta numero di rate, giorni di scadenza e genera il piano automaticamente.",
    icon: CalendarDays,
    colorVariant: 'emerald' as const
  }
]);

const moneyOptions = ref({
  prefix: '',              
  suffix: '',              
  thousands: '.',          
  decimal: ',',          
  precision: 2,            
  allowBlank: true, 
  masked: true,
  disableNegative: true
})

// --- STATO REATTIVO ---
const showRecurrence = ref(false)
const capitoliDisponibili = ref<Capitolo[]>([]);
const isLoadingCapitoli = ref(false);
const capitoliDettaglio = ref<CapitoloDettaglio[]>([]);
const saldiDettaglio = ref<SaldoDettaglio[]>([]);
const isLoadingSaldi = ref(false);
const isModaleSaldiAperta = ref(false);
const { euro } = useCurrencyFormatter({ fromCents: false });

const frequencies = [
  { label: 'Mensile', value: 'MONTHLY' },
  { label: 'Settimanale', value: 'WEEKLY' },
  { label: 'Annuale', value: 'YEARLY' }
]

const weekdays = [
  { label: 'Lunedì', value: 'MO' },
  { label: 'Martedì', value: 'TU' },
  { label: 'Mercoledì', value: 'WE' },
  { label: 'Giovedì', value: 'TH' },
  { label: 'Venerdì', value: 'FR' },
  { label: 'Sabato', value: 'SA' },
  { label: 'Domenica', value: 'SU' }
]

const form = useForm({
  gestione_id: '',
  nome: '',
  descrizione: '',
  metodo_distribuzione: 'rata_zero',
  saldi_config: [] as any[],
  numero_rate: 12,
  giorno_scadenza: 5,
  note: '',
  genera_subito: true,
  recurrence_enabled: false,
  recurrence_frequency: 'MONTHLY',
  recurrence_interval: 1,
  recurrence_by_day: [] as string[],
  capitoli_ids: [] as number[],
  capitoli_config: [] as any[],
})

// Helper fondamentale per i calcoli con MoneyInput (risolve il problema NaN)
const parseMoney = (val: any): number => {
  if (!val) return 0;
  if (typeof val === 'number') return val;
  // Trasforma "1.250,50" in 1250.50
  return Number(val.toString().replace(/\./g, '').replace(',', '.'));
};

const rimuoviCapitolo = (id: number) => {
  form.capitoli_ids = form.capitoli_ids.filter(cid => cid !== id);
}

const totaleSelezionatoFormatted = computed(() => {
  const tot = capitoliDettaglio.value.reduce((acc, curr) => {
    return acc + parseMoney(curr.importo_da_usare);
  }, 0);
  return euro(tot); // Sostituito formatMoney con euro
});

const mostraDistribuzioneSaldo = computed(() => {
  if (props.saldoInfo.has_movimenti !== undefined) {
    return props.saldoInfo.applicabile && props.saldoInfo.has_movimenti;
  }
  return props.saldoInfo.applicabile && props.saldoInfo.saldo !== 0;
});

// ── Aggregato dinamico per la gestione correntemente selezionata.
// Usa saldiDettaglio (già caricati per gestione dal watch) invece di
// saldoInfo.motivo che è un aggregato globale dell'intero esercizio.
const saldoGestioneCorrente = computed(() => {
  const vuoto = { totale: 0, hasSaldi: false, countNominali: 0, countSolidali: 0 };
  if (!form.gestione_id || isLoadingSaldi.value) return vuoto;
  return {
    totale:        saldiDettaglio.value.reduce((acc, s) => acc + s.importo, 0),
    hasSaldi:      saldiDettaglio.value.some(s => s.importo !== 0),
    countNominali: saldiDettaglio.value.filter(s => s.tipo === 'nominale').length,
    countSolidali: saldiDettaglio.value.filter(s => s.tipo === 'solidale').length,
  };
});

// --- WATCHERS ---
watch(() => form.gestione_id, async (newId) => {
  saldiDettaglio.value = [];
  if (!newId) return;

  isLoadingCapitoli.value = true;
  isLoadingSaldi.value = true;

  try {
    router.get(
      window.location.pathname, 
      { gestione_id: newId },   
      {
        only: ['saldoInfo'],    
        preserveState: true,    
        preserveScroll: true,   
        onSuccess: (page: any) => {
           // CHIAMIAMO SEMPRE caricaDettagliGestione! 
           // Passiamo un flag per dirgli se scaricare anche i saldi
           const saldiApplicabili = page.props.saldoInfo?.applicabile || false;
           caricaDettagliGestione(newId, saldiApplicabili);
        },
        onError: () => {
           isLoadingCapitoli.value = false;
           isLoadingSaldi.value = false;
        }
      }
    );
  } catch (e) {
    console.error("Errore ricaricamento saldoInfo", e);
    isLoadingCapitoli.value = false;
    isLoadingSaldi.value = false;
  }
});

// Helper aggiornato
const caricaDettagliGestione = async (idGestione: string | number, caricaAncheSaldi: boolean) => {
  try {
    // 1. CARICHIAMO SEMPRE I CAPITOLI DI SPESA (Anche se i saldi sono bloccati)
    const resCap = await axios.get(route('admin.gestionale.fetch-capitoli-gestione', {
      condominio: props.condominio.id
    }), { params: { gestione_id: idGestione } });
    
    capitoliDisponibili.value = resCap.data;

    // 2. CARICHIAMO I SALDI SOLO SE SERVONO
    if (caricaAncheSaldi) {
        const resSaldi = await axios.get(route('admin.gestionale.fetch-saldi-analitici', {
          condominio: props.condominio.id,
          esercizio: props.esercizio.id,
          gestione: idGestione
        }));

        saldiDettaglio.value = resSaldi.data.map((s: any) => ({
          ...s,
          ripartizione_mode: 'automatica',
          ripartizioni_custom: []
        }));
    } else {
        saldiDettaglio.value = [];
    }

  } catch (e) {
    console.error("Errore nel caricamento dati gestione", e);
  } finally {
    isLoadingCapitoli.value = false;
    isLoadingSaldi.value = false;
  }
}

const saldiRaggruppati = computed(() => {
  const groups: Record<string, any> = {};

  saldiDettaglio.value.forEach(s => {
    const key = s.tipo === 'nominale'
      ? `nominale-${s.anagrafica_id}`
      : `solidale-${s.id}`;

    if (!groups[key]) {
      groups[key] = { nome: s.soggetto_nome, tipo: s.tipo, totale_soggetto: 0, voci: [] };
    }
    groups[key].voci.push(s);
    groups[key].totale_soggetto += s.importo; 
  });

  return groups;
});

watch(showRecurrence, (enabled) => {
  form.recurrence_enabled = enabled
  if (!enabled) form.recurrence_by_day = []
})

const usingByDay = computed(() =>
  showRecurrence.value && Array.isArray(form.recurrence_by_day) && form.recurrence_by_day.length > 0
)

const toggleDay = (dayValue: string) => {
  const index = form.recurrence_by_day.indexOf(dayValue);
  if (index > -1) form.recurrence_by_day.splice(index, 1);
  else form.recurrence_by_day.push(dayValue);
}

const apriConfigurazioneSaldi = () => {
  if (!form.gestione_id || isLoadingSaldi.value) return;
  isModaleSaldiAperta.value = true;
}

const aggiungiRigaRiparto = (saldo: SaldoDettaglio) => {
  saldo.ripartizioni_custom.push({ anagrafica_id: 0, importo: 0 });
}

watch(() => form.capitoli_ids, (newIds) => {
  capitoliDettaglio.value = capitoliDettaglio.value.filter(c => newIds.includes(c.id));
  newIds.forEach(id => {
    if (!capitoliDettaglio.value.find(c => c.id === id)) {
      const capOriginale = capitoliDisponibili.value.find(c => c.id === id);
      if (capOriginale) {
        capitoliDettaglio.value.push({
          id: id,
          nome: capOriginale.nome,
          importo_totale: capOriginale.importo_totale ?? 0,
          residuo: capOriginale.residuo ?? 0,
          importo_da_usare: (capOriginale.residuo ?? 0) > 0 ? capOriginale.residuo : 0,
          note: ''
        });
      }
    }
  });
});

const submit = () => {
  form.capitoli_config = capitoliDettaglio.value.map(c => ({
    id: c.id, importo: c.importo_da_usare, note: c.note
  }));
  
  form.saldi_config = saldiDettaglio.value
    .filter(s => s.tipo === 'solidale' && s.ripartizione_mode === 'manuale')
    .map(s => ({ saldo_id: s.id, ripartizioni: s.ripartizioni_custom }));

  form.post(route(...generateRoute(
    'gestionale.esercizi.piani-rate.store',
    { condominio: props.condominio.id, esercizio: props.esercizio.id }
  )), {
    preserveScroll: true,
    onSuccess: () => {
      router.flushAll()
    },
  })
}

</script>

<template>
  <Head title="Crea nuovo piano rate" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Nuovo piano rate"
        :page-subtitle="`Generazione scadenze per l'esercizio: ${props.esercizio.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
        back-text="Torna all'elenco"
      />

      <form id="pianoRateForm" @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Configurazione iniziale</CardTitle>
            <CardDescription>Definisci il nome e la gestione di riferimento del piano rate.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="space-y-2">
                <Label for="nome">Nome identificativo</Label>
                <Input 
                    id="nome" 
                    v-model="form.nome" 
                    placeholder="es. Ordinario 2026" 
                    class="mt-1 bg-white dark:bg-slate-950" 
                    v-on:focus="form.clearErrors('nome')"
                />
                <InputError :message="form.errors.nome" />
              </div>

              <div class="space-y-2">
                <Label for="gestione_id">Gestione di riferimento</Label>
                <v-select
                  class="mt-1 bg-white dark:bg-slate-950 text-sm"
                  id="gestione_id"
                  :options="gestioni"
                  label="nome"
                  v-model="form.gestione_id"
                  :reduce="g => g.id"
                  placeholder="Seleziona gestione..."
                  @update:modelValue="form.clearErrors('gestione_id')"
                />
                <InputError :message="form.errors.gestione_id" />
              </div>
            </div>

            <div class="space-y-2">
              <Label for="descrizione">Descrizione / Note esterne</Label>
              <Textarea
                id="descrizione"
                class="mt-1 w-full min-h-[80px] resize-none bg-white dark:bg-slate-950"
                v-model="form.descrizione"
                placeholder="Dettagli visibili nei documenti generati..."
              />
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Saldi pregressi</CardTitle>
            <CardDescription>Gestisci eventuali debiti o crediti dell'anno precedente.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">

            <!-- ── BANNER DINAMICO per gestione ─────────────────────────────── -->
            <div v-if="!saldoInfo.is_primo_anno">

              <!-- Bloccato: un'altra gestione ha già consumato il saldo -->
              <div v-if="!saldoInfo.applicabile"
                   class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 flex items-start gap-3">
                <AlertTriangle class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div>
                  <h4 class="font-bold text-amber-900 dark:text-amber-100 text-sm">Saldi non disponibili</h4>
                  <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">{{ saldoInfo.motivo }}</p>
                </div>
              </div>

              <template v-else>

                <!-- Nessuna gestione ancora selezionata -->
                <div v-if="!form.gestione_id"
                     class="bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 rounded-lg p-4 flex items-start gap-3">
                  <Info class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-bold text-slate-600 dark:text-slate-300 text-sm">Saldi pregressi disponibili</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                      Seleziona una gestione per visualizzare i saldi specifici e il totale da riportare.
                    </p>
                  </div>
                </div>

                <!-- Gestione selezionata, fetch in corso -->
                <div v-else-if="isLoadingSaldi"
                     class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex items-center gap-3">
                  <LoaderCircle class="w-5 h-5 text-blue-500 animate-spin shrink-0" />
                  <p class="text-xs text-blue-700 dark:text-blue-300 font-medium">
                    Caricamento saldi per la gestione selezionata...
                  </p>
                </div>

                <!-- Gestione con saldi → totale dinamico -->
                <div v-else-if="saldoGestioneCorrente.hasSaldi"
                     class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex items-start gap-3">
                  <CheckCircle class="w-5 h-5 text-blue-600 shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-bold text-blue-900 dark:text-blue-100 text-sm">Saldi iniziali rilevati per questa gestione</h4>
                    <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                      <template v-if="saldoGestioneCorrente.countNominali > 0 && saldoGestioneCorrente.countSolidali > 0">
                        {{ saldoGestioneCorrente.countNominali }} nominali · {{ saldoGestioneCorrente.countSolidali }} solidali (Art. 63) —
                      </template>
                      <template v-else-if="saldoGestioneCorrente.countNominali > 0">
                        {{ saldoGestioneCorrente.countNominali }} saldi nominali —
                      </template>
                      <template v-else>
                        {{ saldoGestioneCorrente.countSolidali }} saldi solidali (Art. 63) —
                      </template>
                      Totale netto:
                      <strong :class="saldoGestioneCorrente.totale > 0 ? 'text-red-700' : 'text-emerald-700'">
                        {{ saldoGestioneCorrente.totale > 0 ? '' : '+ ' }}{{ euro(Math.abs(saldoGestioneCorrente.totale) / 100) }}
                      </strong>
                    </p>
                  </div>
                </div>

                <!-- Gestione selezionata ma senza saldi -->
                <div v-else
                     class="bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 rounded-lg p-4 flex items-start gap-3">
                  <CheckCircle class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-bold text-slate-600 dark:text-slate-300 text-sm">Nessun saldo pregresso per questa gestione</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                      Non ci sono debiti o crediti da riportare per la gestione selezionata.
                    </p>
                  </div>
                </div>

              </template>
            </div>
            <!-- ── FINE BANNER ────────────────────────────────────────────── -->

            <div v-if="mostraDistribuzioneSaldo" class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
                
                <div>
                  <div class="flex items-center gap-2 mb-2">
                    <Label class="text-sm font-bold text-slate-700 dark:text-slate-300">Metodo di distribuzione</Label>
                    <HoverCard>
                      <HoverCardTrigger as-child>
                        <button type="button" class="cursor-pointer">
                          <Info class="w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" />
                        </button>
                      </HoverCardTrigger>
                      <HoverCardContent class="w-96 z-50 p-4 shadow-xl border-blue-100">
                        <div class="space-y-3 text-sm">
                          <h4 class="font-bold text-slate-800 flex items-center gap-2 border-b pb-2">
                            <Wallet class="w-4 h-4 text-blue-600" /> Gestione saldi pregressi
                          </h4>
                          <div class="space-y-2">
                            <p class="text-xs text-slate-600">
                              <strong class="text-emerald-700">Crea rata separata (Saldi iniziali):</strong><br>
                              <span class="opacity-90">Opzione consigliata. Crea una rata iniziale dedicata solo ai debiti/crediti dell'anno precedente. Fondamentale per la massima trasparenza e per gestire correttamente i subentri.</span>
                            </p>
                            <p class="text-xs text-slate-600">
                              <strong class="text-slate-700">Aggiungi alla prima rata:</strong><br>
                              <span class="opacity-90">Somma i vecchi debiti alla Rata 1 del nuovo preventivo. Metodo tradizionale, ma rende difficile distinguere la competenza.</span>
                            </p>
                            <p class="text-xs text-slate-600">
                              <strong class="text-slate-700">Spalma su tutte le rate:</strong><br>
                              <span class="opacity-90">Divide il debito/credito in parti uguali su tutte le rate dell'anno. Sconsigliato perché maschera l'importo reale della gestione corrente.</span>
                            </p>
                          </div>
                        </div>
                      </HoverCardContent>
                    </HoverCard>
                  </div>

                  <v-select
                    :options="[
                      { label: 'Crea rata separata (Rata 0 - Consigliata)', value: 'rata_zero' },
                      { label: 'Somma alla prima rata ordinaria', value: 'prima_rata' },
                      { label: 'Spalma matematicamente su tutte le rate', value: 'tutte_rate' }
                    ]"
                    class="bg-white dark:bg-slate-950 text-sm w-full v-select-custom"
                    v-model="form.metodo_distribuzione"
                    :reduce="opt => opt.value"
                    :clearable="false"
                    @update:modelValue="form.clearErrors('metodo_distribuzione')"
                  />
                  <InputError :message="form.errors.metodo_distribuzione" class="mt-1" />
                </div>

                <div class="flex flex-col justify-center h-full border-t md:border-t-0 md:border-l border-slate-100 dark:border-slate-800 pt-5 md:pt-0 md:pl-8">
                  
                  <div class="space-y-3 mb-4">
                    <h5 class="text-[11px] font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200">
                      Gestione saldi pregressi (Art. 63)
                    </h5>
                    <ul class="text-xs text-slate-600 dark:text-slate-400 space-y-2 leading-relaxed">
                      <li class="flex items-start gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-slate-300 mt-1.5 shrink-0"></div>
                        <span><strong>Saldi Nominali:</strong> Vengono emessi automaticamente in base all'intestazione. Non richiedono configurazione.</span>
                      </li>
                      <li class="flex items-start gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-indigo-400 mt-1.5 shrink-0"></div>
                        <span><strong>Saldi Solidali:</strong> Legati all'immobile, il sistema li ripartirà <strong>in automatico</strong> usando i coefficienti di quota di proprietà. Usa il pulsante qui sotto se vuoi forzare un riparto manuale per le anagrafiche.</span>
                      </li>
                    </ul>
                  </div>
                  
                  <div class="relative group/btn w-full mt-auto">
                    <Button 
                      type="button" 
                      variant="outline" 
                      class="w-full h-10 border-indigo-200 text-indigo-700 bg-indigo-50/30 hover:bg-indigo-50 hover:text-indigo-800 transition-colors font-bold text-xs uppercase tracking-widest shadow-sm"
                      :disabled="!form.gestione_id || isLoadingSaldi || !saldoGestioneCorrente.hasSaldi"
                      @click="apriConfigurazioneSaldi"
                    >
                      <LoaderCircle v-if="isLoadingSaldi" class="w-4 h-4 mr-2 animate-spin" />
                      <Wallet v-else class="w-4 h-4 mr-2" />
                      Configura riparto saldi
                    </Button>
                    
                    <!-- Tooltip contestuale in base allo stato -->
                    <div v-if="!form.gestione_id"
                         class="absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap text-[10px] font-bold tracking-wider uppercase bg-slate-800 text-white px-3 py-1.5 rounded opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none z-10">
                      Seleziona prima la gestione
                    </div>
                    <div v-else-if="!saldoGestioneCorrente.hasSaldi && !isLoadingSaldi"
                         class="absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap text-[10px] font-bold tracking-wider uppercase bg-slate-800 text-white px-3 py-1.5 rounded opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none z-10">
                      Nessun saldo da configurare
                    </div>
                  </div>
                </div>

              </div>
            </div>

          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Budget e capitoli di spesa</CardTitle>
            <CardDescription>Scegli quali voci di spesa includere nel piano rate e con quali importi.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            
            <div class="bg-blue-50/60 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 rounded-xl p-4 flex gap-3">
              <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
              <div class="text-sm text-blue-900 dark:text-blue-100 space-y-2 leading-relaxed">
                <p>
                  <strong class="font-bold text-blue-950 dark:text-white">Emissione globale (Default):</strong> 
                  Se lasci il campo sottostante vuoto, il sistema includerà automaticamente <strong>tutte le voci di spesa</strong> previste dal bilancio per questa gestione.
                </p>
                <p>
                  <strong class="font-bold text-blue-950 dark:text-white">Emissione parziale:</strong> 
                  Seleziona specifiche voci dal menu per creare un piano rate mirato (es. solo Riscaldamento). Una volta selezionate, potrai anche <strong>richiedere un importo parziale</strong> per ogni voce rispetto al budget totale.
                </p>
              </div>
            </div>

            <div>
              <Label :class="{ 'opacity-50': !form.gestione_id }" class="mb-1.5 block">Voci di bilancio incluse (Lascia vuoto per includere tutto)</Label>
              <v-select
                multiple
                v-model="form.capitoli_ids"
                :options="capitoliDisponibili"
                label="nome"
                :reduce="c => c.id"
                :disabled="!form.gestione_id || isLoadingCapitoli"
                :selectable="(option: Capitolo) => !option.disabled"
                :placeholder="!form.gestione_id ? 'Seleziona prima una gestione...' : 'Ricerca e seleziona capitoli (opzionale)...'"
                class="bg-white dark:bg-slate-950 text-sm"
              >
                <template #spinner>
                  <LoaderCircle v-if="isLoadingCapitoli" class="w-4 h-4 animate-spin mr-2" />
                </template>
                <template #option="option: Capitolo">
                  <div :class="{ 'opacity-50 grayscale': option.disabled }" class="flex justify-between w-full items-center py-1">
                    <div class="flex flex-col">
                      <span :class="{ 'font-bold text-slate-800': option.nome.startsWith('[') }">{{ option.nome }}</span>
                      <span class="text-[10px] text-slate-500">Totale: {{ euro(option.importo_totale || 0) }}</span>
                    </div>
                    <div class="flex items-center">
                      <span v-if="option.disabled" class="flex items-center gap-1 text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold uppercase ml-2 border border-red-200"><Ban class="w-3 h-3" /> Esaurito</span>
                      <span v-else class="flex items-center gap-1 text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-bold ml-2 border border-green-200"><Wallet class="w-3 h-3" /> Disp: {{ euro(option.residuo || 0) }}</span>
                    </div>
                  </div>
                </template>
              </v-select>
            </div>

            <div v-if="capitoliDettaglio.length > 0" class="border border-slate-200 rounded-lg overflow-hidden bg-white shadow-sm">
              <div class="bg-slate-50 px-4 py-3 border-b border-slate-200 flex justify-between items-center">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2"><List class="w-4 h-4" /> Ripartizione manuale voci</span>
                <span class="text-sm font-bold text-primary bg-white px-3 py-1 rounded border shadow-sm">Totale richiesto: {{ totaleSelezionatoFormatted }}</span>
              </div>
              <div class="divide-y divide-slate-100 max-h-[350px] overflow-y-auto">
                <div v-for="(cap) in capitoliDettaglio" :key="cap.id" class="p-3 hover:bg-slate-50 transition-colors group">
                  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium text-sm text-slate-900 truncate">{{ cap.nome }}</div>
                      <div class="text-xs text-slate-500 mt-1 flex items-center gap-2">
                        <span class="bg-slate-100 px-1.5 py-0.5 rounded text-slate-600">Budget totale: {{ euro(cap.importo_totale) }}</span>
                        <span class="text-slate-300">|</span>
                        <span :class="{ 'text-green-700 bg-green-50': (cap.residuo - parseMoney(cap.importo_da_usare)) >= 0, 'text-red-600 bg-red-50': (cap.residuo - parseMoney(cap.importo_da_usare)) < 0 }" class="px-1.5 py-0.5 rounded font-medium transition-colors">
                            Residuo: {{ euro(cap.residuo - parseMoney(cap.importo_da_usare)) }}
                        </span>
                      </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                      <div class="w-32 relative">
                        <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">€</span>
                        <MoneyInput 
                          v-model="cap.importo_da_usare" 
                          :money-options="moneyOptions"
                          :lazy="true"
                          class="pl-6 h-9 text-right text-sm font-bold" 
                          :class="{ 'border-red-500 bg-red-50 text-red-700': parseMoney(cap.importo_da_usare) > cap.residuo }" 
                        />
                      </div>
                      <div class="w-40 sm:w-56"><Input v-model="cap.note" placeholder="Note (es. Acconto invernale)..." class="h-9 text-xs" /></div>
                      <button @click="rimuoviCapitolo(cap.id)" type="button" class="text-slate-400 hover:text-red-500 p-2"><Trash2 class="w-4 h-4" /></button>
                    </div>
                  </div>
                  <div v-if="parseMoney(cap.importo_da_usare) > cap.residuo" class="text-[11px] text-red-600 mt-2 flex items-center gap-1 font-medium bg-red-50 p-1.5 rounded">
                    <AlertTriangle class="w-3 h-3" /> Attenzione: L'importo richiesto supera il residuo disponibile a bilancio.
                  </div>
                </div>
              </div>
            </div>

          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Piano scadenze</CardTitle>
            <CardDescription>Genera il calendario per la riscossione delle quote.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
              <div>
                <Label for="numero_rate">Numero Rate *</Label>
                <Input id="numero_rate" v-model.number="form.numero_rate" class="mt-1 bg-white dark:bg-slate-950" />
                <InputError :message="form.errors.numero_rate" />
              </div>
              <div v-if="!usingByDay">
                <Label for="giorno_scadenza">Giorno del mese</Label>
                <Input id="giorno_scadenza" v-model.number="form.giorno_scadenza" class="mt-1 bg-white dark:bg-slate-950" />
              </div>
              <div class="flex items-center pt-6">
                 <div class="flex items-center gap-2">
                  <Checkbox id="genera_subito" v-model="form.genera_subito" />
                  <Label for="genera_subito" class="cursor-pointer font-medium text-sm">Genera calcolo scadenze subito</Label>
                </div>
              </div>
            </div>

            <Separator />

            <div>
              <div class="flex items-center gap-2 mb-4">
                <Checkbox id="recurrenceToggle" v-model="showRecurrence" />
                <Label for="recurrenceToggle" class="cursor-pointer font-medium text-sm text-primary">Abilita regole di ricorrenza avanzate (Opzionale)</Label>
              </div>

              <div v-if="showRecurrence" class="bg-blue-50/40 border border-blue-100 p-4 rounded-lg space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <Label>Frequenza</Label>
                    <v-select :options="frequencies" v-model="form.recurrence_frequency" :reduce="o => o.value" class="mt-1 bg-white text-sm" />
                  </div>
                  <div>
                    <Label>Intervallo (es. ogni 2 mesi)</Label>
                    <Input v-model="form.recurrence_interval" class="mt-1 bg-white" />
                  </div>
                </div>
                <div>
                  <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 block">Forza in giorni specifici</Label>
                  <div class="flex flex-wrap gap-2">
                    <div v-for="day in weekdays" :key="day.value" @click="toggleDay(day.value)" class="flex items-center gap-2 bg-white px-3 py-1.5 rounded border cursor-pointer hover:border-primary/50">
                      <Checkbox :checked="form.recurrence_by_day.includes(day.value)" @update:checked="toggleDay(day.value)" />
                      <label class="text-xs font-medium cursor-pointer">{{ day.label }}</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3 pt-2">
          <Link
            :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
            class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-6 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
          >
            Annulla
          </Link>

          <Button type="submit" :disabled="form.processing" class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            <Plus v-else class="h-4 w-4" />
            Salva piano rate
          </Button>
        </div>

      </form>
    </div>

    <Dialog :open="isModaleSaldiAperta" @update:open="isModaleSaldiAperta = $event">
      <DialogContent class="!max-w-6xl w-[95vw] max-h-[88vh] flex flex-col p-0 overflow-hidden">
        
        <DialogHeader class="px-7 py-5 border-b bg-white">
          <DialogTitle class="flex items-center gap-3 text-xl font-bold">
            <div class="w-9 h-9 rounded-xl bg-indigo-100 flex items-center justify-center shrink-0">
              <Wallet class="w-5 h-5 text-indigo-600" />
            </div>
            Riparto saldi iniziali
          </DialogTitle>
          <DialogDescription class="text-sm text-slate-500 mt-1">
            Configura come verranno distribuiti i saldi pregressi al momento della generazione delle rate.
          </DialogDescription>
        </DialogHeader>

        <div class="flex-1 overflow-y-auto px-7 py-6 space-y-5 bg-slate-50/60">
          
          <div v-if="isLoadingSaldi" class="flex justify-center py-16 text-slate-400 gap-3">
            <LoaderCircle class="w-5 h-5 animate-spin" /><span>Caricamento saldi in corso...</span>
          </div>
          <div v-else-if="!form.gestione_id" class="flex flex-col items-center justify-center py-16 gap-3 text-slate-400">
            <Wallet class="w-12 h-12 opacity-20" /><p class="text-sm font-semibold">Nessuna gestione selezionata.</p>
          </div>
          <div v-else-if="Object.keys(saldiRaggruppati).length === 0" class="flex flex-col items-center justify-center py-16 gap-3 text-slate-400">
            <CheckCircle class="w-12 h-12 opacity-20" /><p class="text-sm font-semibold">Nessun saldo iniziale per questa gestione.</p>
          </div>

          <template v-else>
            <div v-for="(gruppo, gKey) in saldiRaggruppati" :key="gKey" class="bg-white border rounded-xl shadow-sm overflow-hidden">
              <div class="bg-slate-50 px-5 py-3.5 border-b flex justify-between items-center gap-4">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-8 h-8 rounded-full bg-white border-2 flex items-center justify-center shrink-0" :class="gruppo.tipo === 'solidale' ? 'border-indigo-200' : 'border-slate-200'">
                    <Building2 v-if="gruppo.tipo === 'solidale'" class="w-4 h-4 text-indigo-500" />
                    <User v-else class="w-4 h-4 text-slate-500" />
                  </div>
                  <div class="min-w-0 flex items-center gap-2 flex-wrap">
                    <span class="font-bold text-slate-900 text-sm truncate">{{ gruppo.nome }}</span>
                    <span v-if="gruppo.tipo === 'solidale'" class="text-[9px] px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 font-bold uppercase border border-indigo-200">Solidale · Art. 63</span>
                    <span v-else class="text-[9px] px-2 py-0.5 rounded-full bg-slate-200 text-slate-600 font-bold uppercase">Nominale</span>
                  </div>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                  <span class="text-xs text-slate-400 font-semibold">Totale</span>
                  <span class="font-bold text-sm px-2.5 py-1 rounded-lg" :class="gruppo.totale_soggetto > 0 ? 'text-red-700 bg-red-50 border-red-100' : 'text-emerald-700 bg-emerald-50 border-emerald-100'">
                    {{ gruppo.totale_soggetto > 0 ? '' : '+ ' }}{{ euro(Math.abs(gruppo.totale_soggetto) / 100) }}
                  </span>
                </div>
              </div>

              <div class="divide-y divide-slate-100">
                <div v-for="saldo in gruppo.voci" :key="saldo.id" class="px-5 py-4 space-y-3">
                  <div class="flex justify-between items-start gap-4">
                    <div class="space-y-1.5 flex-1 min-w-0">
                      <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-semibold text-slate-800">{{ saldo.immobile_nome }}</span>
                        <span class="text-[10px] px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 font-bold uppercase border">{{ saldo.ruolo }}</span>
                      </div>
                      <span :class="saldo.is_debito ? 'text-red-600 bg-red-50 border-red-100' : 'text-emerald-600 bg-emerald-50 border-emerald-100'" class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border uppercase">
                        {{ saldo.is_debito ? 'Debito pregresso' : 'Credito a favore' }}
                      </span>
                    </div>
                    <div class="text-right shrink-0">
                      <div class="font-bold text-base" :class="saldo.is_debito ? 'text-red-700' : 'text-emerald-700'">
                        {{ saldo.is_debito ? '' : '+ ' }}{{ euro(Math.abs(saldo.importo) / 100) }}
                      </div>
                    </div>
                  </div>

                  <div v-if="saldo.tipo === 'nominale'" class="flex items-center gap-2 text-[11px] text-slate-500 bg-slate-50 border rounded-lg px-3 py-2">
                    <User class="w-3.5 h-3.5 text-slate-400" /> Già intestato a <strong class="text-slate-700">{{ saldo.soggetto_nome }}</strong>.
                  </div>

                  <div v-if="saldo.tipo === 'solidale'" class="space-y-3">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                      <span class="text-[11px] text-slate-500 font-medium flex items-center gap-1.5"><Building2 class="w-3.5 h-3.5 text-indigo-400" /> Modalità riparto subentro:</span>
                      <v-select :options="[{ label: 'Auto — Pro-quota proprietari', value: 'automatica' }, { label: 'Manuale — Split', value: 'manuale' }]" v-model="saldo.ripartizione_mode" :reduce="(o: any) => o.value" :clearable="false" class="w-64 text-xs bg-white" />
                    </div>

                    <div v-if="saldo.ripartizione_mode === 'manuale'" class="bg-indigo-50/40 border-2 border-dashed border-indigo-200 rounded-xl p-4 space-y-3">
                      <p class="text-[11px] text-indigo-600 font-bold uppercase tracking-wide">Da distribuire: {{ euro(Math.abs(saldo.importo) / 100) }}</p>
                      <div v-for="(rip, rIndex) in saldo.ripartizioni_custom" :key="rIndex" class="flex items-center gap-3">
                        <v-select :options="anagraficheDisponibili" label="nome" v-model="rip.anagrafica_id" :reduce="(a: any) => a.id" class="flex-1 text-xs bg-white" placeholder="Seleziona soggetto..." />
                        <div class="relative w-32 shrink-0">
                          <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">€</span>
                          <MoneyInput 
                              v-model="rip.importo" 
                              :money-options="moneyOptions"
                              :lazy="true"
                              class="h-8 pl-7 text-right text-sm" 
                          />
                        </div>
                        <Button type="button" variant="ghost" size="icon" class="h-8 w-8 text-slate-400 hover:text-red-500" @click="saldo.ripartizioni_custom.splice(rIndex, 1)"><Trash2 class="w-4 h-4" /></Button>
                      </div>

                      <div v-if="saldo.ripartizioni_custom.length > 0">
                        <div v-if="Math.abs(saldo.ripartizioni_custom.reduce((acc: number, r: any) => acc + parseMoney(r.importo), 0) - Math.abs(saldo.importo) / 100) > 0.01" class="flex items-center gap-1.5 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                          <AlertTriangle class="w-3.5 h-3.5" /> La somma ripartita ({{ euro(saldo.ripartizioni_custom.reduce((acc: number, r: any) => acc + parseMoney(r.importo), 0)) }}) non combacia.
                        </div>
                      </div>

                      <Button type="button" variant="outline" size="sm" @click="aggiungiRigaRiparto(saldo)" class="h-7 text-[11px] font-bold border-indigo-200 text-indigo-600 bg-white"><Plus class="w-3 h-3 mr-1.5" /> Aggiungi soggetto</Button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <div class="px-7 py-5 border-t bg-white flex flex-col sm:flex-row justify-between gap-5">
          <div class="text-[11px] text-slate-500 space-y-1 bg-slate-50 p-3 rounded-lg border flex-1">
            <span class="font-bold text-slate-700 block mb-1">Guida rapida:</span>
            • <b>Nominali:</b> Vengono emessi automaticamente sulla persona indicata.<br>
            • <b>Solidali:</b> Se lasci su "Auto", il sistema usa le quote millesimali degli attuali proprietari.
          </div>
          <div class="flex items-end shrink-0">
            <Button type="button" class="h-10 px-8 shadow-md text-xs font-bold uppercase tracking-widest" @click="isModaleSaldiAperta = false">Chiudi Modale</Button>
          </div>
        </div>

      </DialogContent>
    </Dialog>

  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>