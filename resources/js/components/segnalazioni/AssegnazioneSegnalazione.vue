<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/core';
import { trans } from 'laravel-vue-i18n';
import vSelect from 'vue-select';
import { rigaAssegnazione } from '@/lib/segnalazioni/assegnazione';

interface AssegnatarioItem {
  id: number;
  name: string;
}

interface AssegnataAProps {
  id: number;
  name: string;
  attivo: boolean;
  da?: string | null;
  il?: string | null;
  alle?: string | null;
}

interface AssegnazioneProps {
  puo_assegnare: boolean;
  io: number;
  assegnabili: AssegnatarioItem[];
}

const props = defineProps<{
  segnalazioneId: number;
  assegnataA: AssegnataAProps | null;
  assegnazione: AssegnazioneProps;
}>();

const errore = ref<string | null>(null);

const opzioni = computed(() => {
  const lista = [...(props.assegnazione?.assegnabili ?? [])];
  if (props.assegnataA && props.assegnataA.id) {
    const presente = lista.some((item) => item.id === props.assegnataA!.id);
    if (!presente) {
      lista.push({
        id: props.assegnataA.id,
        name: props.assegnataA.name,
      });
    }
  }
  return lista;
});

const riga = computed(() => rigaAssegnazione(props.assegnataA));

function assegna(nuovoId: number | null | undefined): void {
  const targetId = nuovoId ?? null;
  const correnteId = props.assegnataA?.id ?? null;
  if (targetId === correnteId) {
    return;
  }
  errore.value = null;
  router.patch(
    route('admin.segnalazioni.assegna', { segnalazione: props.segnalazioneId }),
    { assigned_to: targetId },
    {
      // P7 della lente P (Fase 1-bis della beta.12): una visita parziale (standard 16). L'assegnazione cambia la segnalazione (anche lo stato, con
      // «Porta in lavorazione»), il numero di «Le mie» e il messaggio; il resto della scheda resta com'è.
      only: ['segnalazione', 'inbox_count', 'flash'],
      preserveState: true,
      preserveScroll: true,
      onError: (errs) => {
        if (errs.assigned_to) {
          errore.value = errs.assigned_to;
        }
      },
    }
  );
}

function assegnaAMe(): void {
  const targetId = props.assegnazione.io;
  const correnteId = props.assegnataA?.id ?? null;
  if (targetId === correnteId) {
    return;
  }
  errore.value = null;
  router.patch(
    route('admin.segnalazioni.assegna', { segnalazione: props.segnalazioneId }),
    { assigned_to: targetId },
    {
      // P7 della lente P (Fase 1-bis della beta.12): una visita parziale (standard 16). L'assegnazione cambia la segnalazione (anche lo stato, con
      // «Porta in lavorazione»), il numero di «Le mie» e il messaggio; il resto della scheda resta com'è.
      only: ['segnalazione', 'inbox_count', 'flash'],
      preserveState: true,
      preserveScroll: true,
      onError: (errs) => {
        if (errs.assigned_to) {
          errore.value = errs.assigned_to;
        }
      },
    }
  );
}
</script>

<template>
  <div data-campo="assegnata-a" class="min-w-0">
    <!-- L'etichetta come le altre della card dei parametri (decisione 464 (2), Coda 671): 12 px. -->
    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
      {{ trans('segnalazioni.assignment.label') }}
    </p>

    <template v-if="assegnazione.puo_assegnare">
      <div class="space-y-2">
        <v-select
          id="assegnatario"
          class="w-full premium-select bg-white dark:bg-slate-950"
          :options="opzioni"
          label="name"
          :reduce="(item: any) => item.id"
          :placeholder="trans('segnalazioni.assignment.placeholder')"
          :model-value="props.assegnataA?.id ?? null"
          @update:model-value="assegna"
        />

        <button
          v-if="props.assegnataA?.id !== props.assegnazione.io"
          type="button"
          data-azione="assegna-a-me"
          class="min-h-[40px] px-3 py-2 text-xs font-semibold rounded-md border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors w-full flex items-center justify-center cursor-pointer"
          @click="assegnaAMe"
        >
          {{ trans('segnalazioni.assignment.assign_to_me') }}
        </button>
      </div>
    </template>

    <template v-else>
      <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">
        {{ props.assegnataA?.name ?? trans('segnalazioni.assignment.none') }}
      </span>
    </template>

    <p
      v-if="errore"
      data-errore="assegnazione"
      class="text-xs text-red-600 dark:text-red-400 mt-1.5 font-medium"
    >
      {{ errore }}
    </p>

    <p
      v-if="props.assegnataA && props.assegnataA.attivo === false"
      data-avviso="assegnatario-non-attivo"
      class="text-xs text-amber-700 dark:text-amber-400 mt-1.5 font-medium"
    >
      {{ trans('segnalazioni.assignment.inactive', { name: props.assegnataA.name }) }}
    </p>

    <p
      v-if="riga"
      data-campo="assegnata-da"
      class="text-xs text-slate-500 mt-1.5"
    >
      {{ trans(riga.chiave, riga.argomenti) }}
    </p>
  </div>
</template>

<style src="vue-select/dist/vue-select.css"></style>
