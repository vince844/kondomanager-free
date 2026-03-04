<script setup lang="ts">

import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { useCurrencyFormatter } from "@/composables/useCurrencyFormatter";
import { Pencil, Trash2, Lock, Plus, Users, Coins, TrendingUp, TrendingDown, ChevronDown, Building2, User } from "lucide-vue-next";
import MoneyInput from '@/components/MoneyInput.vue';
import { unformat } from 'v-money3';
import vSelect from "vue-select";
import type { ImmobileConSaldi } from "@/types/gestionale/saldi";
import type { Building } from "@/types/buildings";

const props = defineProps<{
  immobile: ImmobileConSaldi;
  gestioni: any[];
  esercizio: any;
  condominio: Building;
  valoriInCentesimi?: boolean;
}>();

// Opzioni per l'input valuta (Standard Italiano)
const moneyOptions = ref({
  prefix: '',              
  suffix: '',              
  thousands: '.',          
  decimal: ',',          
  precision: 2, 
  disableNegative: true, 
  allowBlank: false,
  masked: true 
});

// Opzioni per l'editing inline
const moneyOptionsInline = ref({
  ...moneyOptions.value,
  disableNegative: false
});

const { euro, format: formatSigned } = useCurrencyFormatter({ 
  fromCents: props.valoriInCentesimi ?? false,
  showSpaceAfterSign: true 
});

const openGestioni = ref<Set<number>>(new Set());
const showLockedInfoModal = ref(false); // Flag per la modale di spiegazione lucchetto

function toggleGestione(id: number) {
  if (openGestioni.value.has(id)) openGestioni.value.delete(id);
  else                             openGestioni.value.add(id);
}

const editingSaldoId = ref<number | null>(null);
const editingAmount  = ref<string>('');

// Helper sicuro per le iniziali
function initials(nome?: string, cognome?: string): string {
  const n = nome ? nome.charAt(0) : '';
  const c = cognome ? cognome.charAt(0) : '';
  return (n + c).toUpperCase() || '?';
}

interface SaldoItem { saldo: any; anagrafica: any; isSolidale: boolean; }
interface GestioneGroup {
  gestione: any;
  crediti: SaldoItem[];
  debiti:  SaldoItem[];
  totaleCrediti: number;
  totaleDebiti:  number;
  netto: number;
  isGestioneBloccata: boolean; 
}

const gestioniGroups = computed<GestioneGroup[]>(() =>
  props.gestioni.map(g => {
    const crediti: SaldoItem[] = [];
    const debiti:  SaldoItem[] = [];

    if (props.immobile && props.immobile.saldi) {
      props.immobile.saldi
        .filter((s: any) => s.gestione_id == g.id)
        .forEach((saldo: any) => {
          
          const isSolidale = saldo.anagrafica_id == null;
          const anagrafica = saldo.anagrafica || { nome: 'Intero Immobile' };
          const importo = saldo.saldo_iniziale ?? 0;
          
          if (importo > 0) {
            debiti.push({ saldo, anagrafica, isSolidale });
          } else {
            crediti.push({ saldo, anagrafica, isSolidale });
          }
        });
    }

    const totaleCrediti = Math.abs(crediti.reduce((s, c) => s + (c.saldo.saldo_iniziale ?? 0), 0));
    const totaleDebiti  = debiti.reduce( (s, d) => s + (d.saldo.saldo_iniziale ?? 0), 0);
    const netto = totaleDebiti - totaleCrediti;

    const isGestioneBloccata = g.saldo_applicato === 1 || g.saldo_applicato === true;

    return { gestione: g, crediti, debiti, totaleCrediti, totaleDebiti, netto, isGestioneBloccata }; 
  })
);

const totaleCrediti = computed(() => gestioniGroups.value.reduce((s, g) => s + g.totaleCrediti, 0));
const totaleDebiti  = computed(() => gestioniGroups.value.reduce((s, g) => s + g.totaleDebiti,  0));
const netto         = computed(() => totaleDebiti.value - totaleCrediti.value);

// ── Actions Modifica Inline ────────────────────────────────────────────────
function startEdit(saldo: any) {
  editingSaldoId.value = saldo.id;
  const importoInEuro = Math.abs(saldo.saldo_iniziale ?? 0) / 100;
  editingAmount.value  = importoInEuro.toFixed(2);
}

function saveEdit(saldo: any, tipo: 'credito' | 'debito') {
  const rawValue = unformat(editingAmount.value, moneyOptionsInline.value);
  let cents = Math.round(Math.abs(Number(rawValue)) * 100);

  if (tipo === 'credito') {
    cents = -cents; 
  }

  router.patch(
    route('admin.gestionale.saldi.update', { condominio: props.condominio.id, saldo: saldo.id }),
    { saldo_iniziale: cents, gestione_id: saldo.gestione_id },
    { preserveScroll: true, onSuccess: () => { editingSaldoId.value = null; } }
  );
}

function cancelEdit() { editingSaldoId.value = null; }

function deleteSaldo(saldo: any) {
  if (!confirm('Rimuovere questo saldo dal wallet?')) return;
  router.delete(
    route('admin.gestionale.saldi.destroy', { condominio: props.condominio.id, saldo: saldo.id }),
    { preserveScroll: true }
  );
}

const showAddModal = ref(false);
const modalForm = ref({
  gestioneId: null as number | null,
  tipo: 'debito' as 'credito' | 'debito',
  targetType: 'solidale' as 'solidale' | 'personale',
  anagraficaId: null as number | null,
  importo: ''
});

function openAddModal(gestioneId: number, tipo: 'credito' | 'debito') {
  const idAssegnati = props.immobile.saldi
    .filter((s: any) => s.gestione_id == gestioneId && s.anagrafica_id != null)
    .map((s: any) => s.anagrafica_id);
    
  const liberi = props.immobile.anagrafiche?.filter(a => !idAssegnati.includes(a.id)) || [];

  modalForm.value = {
    gestioneId,
    tipo,
    targetType: 'solidale',
    anagraficaId: liberi.length === 1 ? liberi[0].id : null,
    importo: ''
  };
  showAddModal.value = true;
}

const anagraficheDisponibili = computed(() => {
  if (!modalForm.value.gestioneId || !props.immobile.anagrafiche) return [];

  const idAssegnati = props.immobile.saldi
    .filter((s: any) => s.gestione_id == modalForm.value.gestioneId && s.anagrafica_id != null)
    .map((s: any) => s.anagrafica_id);

  return props.immobile.anagrafiche.filter(a => !idAssegnati.includes(a.id));
});

function closeAddModal() {
  showAddModal.value = false;
}

function submitAddModal() {
  if (!modalForm.value.importo || !modalForm.value.gestioneId) return;
  
  if (modalForm.value.targetType === 'personale' && !modalForm.value.anagraficaId) {
      alert('Seleziona un soggetto per il debito/credito personale.');
      return;
  }

  const rawValue = unformat(modalForm.value.importo, moneyOptions.value);
  
  let cents = Math.round(Number(rawValue) * 100);
  if (isNaN(cents)) cents = 0;
  
  const finalCents = modalForm.value.tipo === 'credito' ? -cents : cents;

  router.post(
    route('admin.gestionale.saldi.store', { condominio: props.condominio.id }),
    {
      anagrafica_id:  modalForm.value.targetType === 'solidale' ? null : modalForm.value.anagraficaId,
      immobile_id:    props.immobile.id,
      gestione_id:    modalForm.value.gestioneId,
      saldo_iniziale: finalCents,
    },
    { preserveScroll: true, onSuccess: () => closeAddModal() }
  );
}

</script>

<template>
  <div class="p-6 space-y-6 relative">

    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">
          {{ immobile.nome }}
          <span class="font-normal text-slate-400 dark:text-slate-500 text-base"> · Int. {{ immobile.interno }}</span>
        </h2>
        <p class="text-xs text-slate-400 mt-0.5">
          <span v-if="immobile.palazzina">Palazzina {{ immobile.palazzina.name }}</span>
          <span v-if="immobile.scala"> · Scala {{ immobile.scala.name }}</span>
        </p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <div class="flex flex-col items-end px-3 py-2 rounded-lg border border-emerald-100 bg-emerald-50 dark:bg-emerald-900/20 dark:border-emerald-800/40 min-w-[100px]">
          <span class="text-[10px] font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
            <TrendingUp class="w-3 h-3" /> Crediti
          </span>
          <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-300 mt-0.5">
            {{ euro(totaleCrediti) }}
          </span>
        </div>

        <div class="flex flex-col items-end px-3 py-2 rounded-lg border border-red-100 bg-red-50 dark:bg-red-900/20 dark:border-red-800/40 min-w-[100px]">
          <span class="text-[10px] font-bold uppercase tracking-widest text-red-500 dark:text-red-400 flex items-center gap-1">
            <TrendingDown class="w-3 h-3" /> Debiti
          </span>
          <span class="text-sm font-semibold text-red-700 dark:text-red-300 mt-0.5">
            {{ euro(totaleDebiti) }}
          </span>
        </div>

        <div class="flex flex-col items-end px-3 py-2 rounded-lg border min-w-[100px]"
          :class="{
            'bg-red-50 border-red-200 dark:bg-red-900/20 dark:border-red-800/40': netto > 0,
            'bg-emerald-50 border-emerald-200 dark:bg-emerald-900/20 dark:border-emerald-800/40': netto < 0,
            'bg-slate-50 border-slate-200 dark:bg-slate-800 dark:border-slate-700': netto === 0,
          }">
          <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Saldo netto</span>
          <span class="text-sm font-semibold mt-0.5"
            :class="{
              'text-red-700 dark:text-red-300': netto > 0,
              'text-emerald-700 dark:text-emerald-300': netto < 0,
              'text-slate-500': netto === 0,
            }">
            {{ formatSigned(netto, { forcePlus: true }) }}
          </span>
        </div>
      </div>
    </div>

    <div>
      <div class="flex items-center gap-2 mb-3">
        <Users class="w-3.5 h-3.5 text-slate-400" />
        <span class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Soggetti associati</span>
        <div class="flex-1 h-px bg-slate-100 dark:bg-slate-800" />
      </div>
      <div class="flex flex-wrap gap-2">
        <div v-for="a in immobile.anagrafiche" :key="a.id" class="flex items-center gap-2 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 shadow-sm">
          <div class="w-7 h-7 rounded-full bg-indigo-50 dark:bg-indigo-900/40 flex items-center justify-center text-[11px] font-bold text-indigo-600 dark:text-indigo-300 shrink-0">
            {{ initials(a.nome, a.cognome) }}
          </div>
          <div>
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-200 leading-tight">{{ a.nome }}</p>
            <p class="text-[10px] uppercase tracking-wider text-slate-400 leading-tight">{{ a.pivot?.tipologia }}</p>
          </div>
        </div>
        <p v-if="!immobile.anagrafiche?.length" class="text-sm text-slate-400 italic">Nessun soggetto associato</p>
      </div>
    </div>

    <div>
      <div class="flex items-center gap-2 mb-3">
        <Coins class="w-3.5 h-3.5 text-slate-400" />
        <span class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Saldi per gestione</span>
        <div class="flex-1 h-px bg-slate-100 dark:bg-slate-800" />
      </div>

      <div class="space-y-3">
        <div v-for="group in gestioniGroups" :key="group.gestione.id" class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden shadow-sm">

          <button type="button" @click="toggleGestione(group.gestione.id)"
            class="w-full flex items-center justify-between px-4 py-3 bg-slate-50 dark:bg-slate-800/60 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
            :class="{ 'border-b border-slate-200 dark:border-slate-700': openGestioni.has(group.gestione.id) }">
            <div class="flex items-center gap-2.5 min-w-0">
              <ChevronDown class="w-3.5 h-3.5 text-slate-400 shrink-0 transition-transform duration-200" :class="{ '-rotate-90': !openGestioni.has(group.gestione.id) }" />
              <span class="w-2 h-2 rounded-full shrink-0" :class="group.gestione.tipo === 'straordinaria' ? 'bg-amber-400' : 'bg-indigo-500'" />
              <span class="text-sm font-semibold text-slate-800 dark:text-slate-200 truncate">{{ group.gestione.nome }}</span>
              <span class="text-[10px] font-bold uppercase tracking-widest px-1.5 py-0.5 rounded shrink-0" :class="group.gestione.tipo === 'straordinaria' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300'">
                {{ group.gestione.tipo }}
              </span>
              <span class="text-[11px] text-slate-400 shrink-0 hidden sm:inline">{{ group.crediti.length + group.debiti.length }} voci</span>
            </div>
            <span class="text-sm font-semibold shrink-0 ml-3"
              :class="{
                'text-red-600 dark:text-red-400': group.netto > 0,
                'text-emerald-600 dark:text-emerald-400': group.netto < 0,
                'text-slate-400': group.netto === 0,
              }">
              {{ formatSigned(group.netto, { forcePlus: true }) }}
            </span>
          </button>

          <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition-all duration-150 ease-in" leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-1">
            <div v-show="openGestioni.has(group.gestione.id)" class="grid grid-cols-2">

              <div class="p-4 border-r border-slate-200 dark:border-slate-700">
                <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400 mb-3 flex items-center gap-1.5">
                  <TrendingUp class="w-3 h-3" /> Crediti
                </p>

                <div class="space-y-1.5">
                  <div v-for="item in group.crediti" :key="item.saldo.id" class="flex items-center gap-2 px-2.5 py-2 rounded-md border transition-colors" :class="(item.saldo.is_applicato || group.isGestioneBloccata) ? 'border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/40 hover:border-slate-300'">
                    
                    <span class="flex-1 text-xs text-slate-600 dark:text-slate-400 truncate flex items-center gap-1.5">
                      <Building2 v-if="item.isSolidale" class="w-3 h-3 text-indigo-500 shrink-0" />
                      {{ item.anagrafica.nome }} {{ item.anagrafica.cognome }}
                      <span v-if="item.isSolidale" class="text-[9px] bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 px-1 rounded uppercase tracking-widest font-bold ml-1">Art. 63</span>
                    </span>

                    <template v-if="editingSaldoId === item.saldo.id">
                      <MoneyInput
                        v-model="editingAmount"
                        :money-options="moneyOptionsInline"
                        class="w-20 text-xs text-right rounded border border-indigo-300 dark:border-indigo-600 bg-white dark:bg-slate-800 px-1 py-0.5 outline-none focus:ring-1 focus:ring-indigo-400"
                        @keyup.enter="saveEdit(item.saldo, 'credito')" 
                        @keyup.escape="cancelEdit"
                      />
                      <button 
                        @click="saveEdit(item.saldo, 'credito')" 
                        class="text-emerald-600 hover:text-emerald-700 transition-colors">
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                          <path d="M20 6 9 17l-5-5"/>
                        </svg>
                      </button>
                      <button 
                        @click="cancelEdit" 
                        class="text-slate-400 hover:text-slate-600 transition-colors">
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                          <path d="M18 6 6 18M6 6l12 12"/>
                        </svg>
                      </button>
                    </template>

                    <template v-else>
                      <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 shrink-0">
                        {{ euro(Math.abs(item.saldo.saldo_iniziale)) }}
                      </span>
                      <button v-if="item.saldo.is_applicato || group.isGestioneBloccata" 
                              @click.prevent="showLockedInfoModal = true" 
                              class="text-slate-400 hover:text-amber-500 transition-colors shrink-0" 
                              title="Saldo Bloccato">
                        <Lock class="w-3 h-3" />
                      </button>
                      <template v-else>
                        <button @click="startEdit(item.saldo)" class="text-slate-300 hover:text-indigo-500 transition-colors shrink-0"><Pencil class="w-3 h-3" /></button>
                        <button @click="deleteSaldo(item.saldo)" class="text-slate-300 hover:text-red-500 transition-colors shrink-0"><Trash2 class="w-3 h-3" /></button>
                      </template>
                    </template>
                  </div>

                  <p v-if="group.crediti.length === 0" class="text-[11px] text-slate-400 italic px-2">Nessun credito registrato</p>

                  <button v-if="!group.isGestioneBloccata" @click="openAddModal(group.gestione.id, 'credito')" class="w-full mt-1 py-1.5 border border-dashed border-slate-200 dark:border-slate-700 rounded-md text-[11px] text-slate-400 flex items-center justify-center gap-1.5 hover:border-emerald-400 hover:text-emerald-600 hover:bg-emerald-50/50 dark:hover:bg-emerald-900/10 transition-all">
                    <Plus class="w-3 h-3" /> Aggiungi credito
                  </button>
                </div>

                <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800 text-right">
                  <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                    Totale: {{ euro(group.totaleCrediti) }}
                  </span>
                </div>
              </div>

              <div class="p-4">
                <p class="text-[10px] font-bold uppercase tracking-widest text-red-500 dark:text-red-400 mb-3 flex items-center gap-1.5">
                  <TrendingDown class="w-3 h-3" /> Debiti
                </p>

                <div class="space-y-1.5">
                  <div v-for="item in group.debiti" :key="item.saldo.id" class="flex items-center gap-2 px-2.5 py-2 rounded-md border transition-colors" :class="(item.saldo.is_applicato || group.isGestioneBloccata) ? 'border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/40 hover:border-slate-300'">
                    
                    <span class="flex-1 text-xs text-slate-600 dark:text-slate-400 truncate flex items-center gap-1.5">
                      <Building2 v-if="item.isSolidale" class="w-3 h-3 text-indigo-500 shrink-0" />
                      {{ item.anagrafica.nome }} {{ item.anagrafica.cognome }}
                      <span v-if="item.isSolidale" class="text-[9px] bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 px-1 rounded uppercase tracking-widest font-bold ml-1">Art. 63</span>
                    </span>

                    <template v-if="editingSaldoId === item.saldo.id">
                      <MoneyInput
                        v-model="editingAmount"
                        :money-options="moneyOptionsInline"
                        class="w-20 text-xs text-right rounded border border-indigo-300 dark:border-indigo-600 bg-white dark:bg-slate-800 px-1 py-0.5 outline-none focus:ring-1 focus:ring-indigo-400"
                        @keyup.enter="saveEdit(item.saldo, 'debito')" 
                        @keyup.escape="cancelEdit"
                      />
                      <button 
                        @click="saveEdit(item.saldo, 'debito')" 
                        class="text-emerald-600 hover:text-emerald-700 transition-colors">
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                          <path d="M20 6 9 17l-5-5"/>
                        </svg>
                      </button>
                      <button 
                        @click="cancelEdit" 
                        class="text-slate-400 hover:text-slate-600 transition-colors">
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                          <path d="M18 6 6 18M6 6l12 12"/>
                        </svg>
                      </button>
                    </template>

                    <template v-else>
                      <span class="text-xs font-semibold text-red-600 dark:text-red-400 shrink-0">
                        {{ euro(item.saldo.saldo_iniziale) }}
                      </span>
                      <button v-if="item.saldo.is_applicato || group.isGestioneBloccata" 
                              @click.prevent="showLockedInfoModal = true" 
                              class="text-slate-400 hover:text-amber-500 transition-colors shrink-0" 
                              title="Saldo Bloccato">
                        <Lock class="w-3 h-3" />
                      </button>
                      <template v-else>
                        <button @click="startEdit(item.saldo)" class="text-slate-300 hover:text-indigo-500 transition-colors shrink-0"><Pencil class="w-3 h-3" /></button>
                        <button @click="deleteSaldo(item.saldo)" class="text-slate-300 hover:text-red-500 transition-colors shrink-0"><Trash2 class="w-3 h-3" /></button>
                      </template>
                    </template>
                  </div>

                  <p v-if="group.debiti.length === 0" class="text-[11px] text-slate-400 italic px-2">Nessun debito registrato</p>

                  <button v-if="!group.isGestioneBloccata" @click="openAddModal(group.gestione.id, 'debito')" class="w-full mt-1 py-1.5 border border-dashed border-slate-200 dark:border-slate-700 rounded-md text-[11px] text-slate-400 flex items-center justify-center gap-1.5 hover:border-red-400 hover:text-red-500 hover:bg-red-50/50 dark:hover:bg-red-900/10 transition-all">
                    <Plus class="w-3 h-3" /> Aggiungi debito
                  </button>
                </div>

                <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800 text-right">
                  <span class="text-xs font-semibold text-red-600 dark:text-red-400">
                    Totale: {{ euro(group.totaleDebiti) }}
                  </span>
                </div>
              </div>

            </div>
          </Transition>

        </div>

        <p v-if="!gestioniGroups.length" class="text-center py-8 text-sm text-slate-400 italic">
          Nessuna gestione attiva disponibile
        </p>
      </div>
    </div>

    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0" enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100" leave-to-class="opacity-0"
    >
      <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-0">
        <div class="fixed inset-0 bg-slate-900/60 dark:bg-black/60 backdrop-blur-sm" @click="closeAddModal"></div>
        
        <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-lg overflow-hidden flex flex-col border dark:border-slate-700">
          
          <div class="px-6 py-4 border-b flex items-center justify-between"
               :class="modalForm.tipo === 'credito' ? 'bg-emerald-50 border-emerald-100 dark:bg-emerald-900/20 dark:border-emerald-800' : 'bg-red-50 border-red-100 dark:bg-red-900/20 dark:border-red-800'">
            <div>
              <h3 class="text-lg font-bold" :class="modalForm.tipo === 'credito' ? 'text-emerald-900 dark:text-emerald-300' : 'text-red-900 dark:text-red-300'">
                Aggiungi {{ modalForm.tipo === 'credito' ? 'Credito' : 'Debito' }}
              </h3>
              <p class="text-xs font-medium opacity-80" :class="modalForm.tipo === 'credito' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
                Int. {{ immobile.interno }}
              </p>
            </div>
            <button @click="closeAddModal" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 bg-white/50 dark:bg-slate-800/50 p-1.5 rounded-full"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
          </div>

          <div class="p-6 space-y-6">
            <div class="space-y-3">
              <label class="text-xs font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">A chi è assegnato?</label>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                
                <label class="cursor-pointer">
                  <input type="radio" v-model="modalForm.targetType" value="solidale" class="peer sr-only" />
                  <div class="h-full rounded-xl border-2 dark:border-slate-700 p-4 transition-all peer-checked:border-indigo-500 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-900/20 hover:bg-slate-50 dark:hover:bg-slate-800 relative">
                    <div class="flex items-center gap-2 mb-2 text-indigo-700 dark:text-indigo-400">
                      <Building2 class="w-5 h-5" />
                      <span class="font-bold text-sm">Intero immobile</span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                      Solidale. Verrà ripartito in automatico sui proprietari al momento dell'emissione rate (Art. 63).
                    </p>
                  </div>
                </label>

                <label class="cursor-pointer">
                  <input type="radio" v-model="modalForm.targetType" value="personale" class="peer sr-only" />
                  <div class="h-full rounded-xl border-2 dark:border-slate-700 p-4 transition-all peer-checked:border-indigo-500 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-900/20 hover:bg-slate-50 dark:hover:bg-slate-800 relative">
                    <div class="flex items-center gap-2 mb-2 text-slate-700 dark:text-slate-300">
                      <User class="w-5 h-5" />
                      <span class="font-bold text-sm">Soggetto specifico</span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                      Personale. La Rata Zero sarà intestata esclusivamente a lui.
                    </p>
                  </div>
                </label>
              </div>

              <div v-if="modalForm.targetType === 'personale'" class="mt-3">
  
                <div v-if="anagraficheDisponibili.length > 0">
                  <v-select 
                    class="w-full bg-white dark:bg-slate-800 text-sm rounded-lg"
                    :options="anagraficheDisponibili" 
                    v-model="modalForm.anagraficaId"
                    :reduce="(a: any) => a.id"
                    placeholder="Cerca o seleziona l'anagrafica..."
                  >
                    <template #option="{ nome, cognome, pivot }">
                      <div class="flex flex-col py-1">
                        <span class="font-medium text-sm">{{ nome }} {{ cognome }}</span>
                        <span class="text-xs text-slate-400 capitalize">{{ pivot?.tipologia }}</span>
                      </div>
                    </template>

                    <template #selected-option="{ nome, cognome, pivot }">
                      <div class="flex items-center gap-1.5 text-sm">
                        <span class="font-medium">{{ nome }} {{ cognome }}</span>
                        <span class="text-xs text-slate-400">({{ pivot?.tipologia }})</span>
                      </div>
                    </template>
                  </v-select>
                </div>

                <div v-else class="p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg text-xs text-amber-700 dark:text-amber-400">
                  Tutti i soggetti associati hanno già un saldo per questa gestione. <br>
                  <strong>Suggerimento:</strong> modifica l'importo della riga già esistente.
                </div>

              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-xs font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Inserisci l'importo</label>
              <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-sm z-10">€</span>
                
                <MoneyInput
                  v-model="modalForm.importo"
                  :money-options="moneyOptions"
                  placeholder="0,00"
                  class="w-full text-sm font-semibold bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 dark:text-white rounded-lg pl-8 pr-3 py-2 outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                  @keyup.enter="submitAddModal"
                />
              </div>
            </div>

          </div>

          <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 border-t dark:border-slate-700 flex justify-end gap-3">
            <button @click="closeAddModal" class="px-5 py-2.5 rounded-lg text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
              Annulla
            </button>
            <button @click="submitAddModal" 
                    class="px-5 py-2.5 rounded-lg text-sm font-bold text-white transition-all shadow-sm flex items-center gap-2"
                    :class="modalForm.tipo === 'credito' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-red-600 hover:bg-red-700'"
                    :disabled="!modalForm.importo || (modalForm.targetType === 'personale' && !modalForm.anagraficaId)">
              <svg v-if="modalForm.tipo === 'credito'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
              <TrendingDown v-else class="w-4 h-4" />
              Salva {{ modalForm.tipo === 'credito' ? 'Credito' : 'Debito' }}
            </button>
          </div>

        </div>
      </div>
    </Transition>

      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100" leave-to-class="opacity-0"
      >
      <div v-if="showLockedInfoModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6">
        <div class="fixed inset-0 bg-slate-900/60 dark:bg-black/60 backdrop-blur-sm" @click="showLockedInfoModal = false"></div>
        
        <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden flex flex-col border dark:border-slate-700">
          <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-amber-50 dark:bg-amber-900/20">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-900/50 flex items-center justify-center">
                <Lock class="w-5 h-5 text-amber-600 dark:text-amber-400" />
              </div>
              <div>
                <h3 class="text-lg font-bold text-amber-900 dark:text-amber-300">Saldo bloccato dal sistema</h3>
                <p class="text-xs text-amber-700/70 dark:text-amber-400/60 font-medium">Integrazione piano rate attiva</p>
              </div>
            </div>
            <button @click="showLockedInfoModal = false" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 bg-white/50 dark:bg-slate-800/50 p-1.5 rounded-full transition-colors">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          
          <div class="p-8 space-y-6 text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
            <p class="text-base">
              Non è possibile modificare o eliminare questo saldo iniziale perché <strong>è già stato integrato in un piano rate</strong> e sono state generate le relative quote di pagamento.
            </p>
            
            <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-6 border border-slate-100 dark:border-slate-700 space-y-4">
              <h4 class="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <div class="w-1 h-4 bg-amber-400 rounded-full"></div>
                Come procedere per la correzione?
              </h4>
              
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                  <p class="font-bold text-xs uppercase tracking-wider text-indigo-400">Opzione A: Eliminazione</p>
                  <p class="text-xs leading-normal">
                    <strong>Nessun incasso registrato?</strong> <br>
                    Vai nella sezione <em>Piani Rate</em>, individua il piano associato a questa gestione ed eliminalo. Il sistema rimuoverà i "lucchetti" e renderà i saldi nuovamente editabili.
                  </p>
                </div>
                
                <div class="space-y-2">
                  <p class="font-bold text-xs uppercase tracking-wider text-indigo-400">Opzione B: Rettifica</p>
                  <p class="text-xs leading-normal">
                    <strong>Incassi già registrati?</strong> <br>
                    Per garantire l'integrità del Libro Giornale, non puoi eliminare il passato. Registra un <strong>Movimento di Storno</strong> manuale per compensare l'errore (nuovo debito o credito).
                  </p>
                </div>
              </div>
            </div>
          </div>
          
          <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 border-t dark:border-slate-700 flex justify-end gap-3">
             <button @click="showLockedInfoModal = false" class="px-6 py-2.5 rounded-lg text-sm font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-md transition-all active:scale-95">
              Ho capito
            </button>
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<style src="vue-select/dist/vue-select.css"></style>