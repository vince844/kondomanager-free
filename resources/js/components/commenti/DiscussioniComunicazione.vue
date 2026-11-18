<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref, nextTick } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { MessageSquare, User as UserIcon } from 'lucide-vue-next';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import FormCommento from '@/components/commenti/FormCommento.vue';
import Commento, { type CommentoData } from '@/components/commenti/Commento.vue';

// Una comunicazione ai palazzi, uno o più, ha la discussione unica (`tutti`, decisione 458), senza titolo; una comunicazione a
// persone, o privata, una discussione per persona con lo studio.
export interface DiscussioneItem {
  chiave: string;
  tipo?: 'tutti' | 'persona';
  titolo: string;
  puo_scrivere: boolean;
  commenti: CommentoData[];
}

export interface CommentiConfig {
  can_comment: boolean;
  commenti_moderati: boolean;
  puo_impostare: boolean;
  // «Commenti in moderazione»: la cambia solo chi modera (Fase 1-bis della 1.12.0-beta.11).
  puo_impostare_moderazione?: boolean;
  puo_moderare: boolean;
}

const props = defineProps<{
  comunicazioneId: number;
  porta: 'admin' | 'user';
  discussioni: DiscussioneItem[];
  config: CommentiConfig;
  // Standard 16: le proprietà che il server rimanda dopo un'azione sui commenti (una visita parziale).
  ricarica?: string[];
}>();

const aperteManualmente = ref<Record<string, boolean>>({});

const mostraTitolo = computed(() => props.discussioni.length > 1);

// Le «più di tre discussioni chiuse» sono quelle per persona: la discussione unica dei palazzi è sempre sola, e aperta.
function isChiusa(discussione: DiscussioneItem): boolean {
  if (props.porta !== 'admin' || props.discussioni.length <= 3 || discussione.tipo === 'tutti') {
    return false;
  }
  if (discussione.commenti.length > 0) {
    return false;
  }
  return !aperteManualmente.value[discussione.chiave];
}

// Fra due righe chiuse di fila non resta lo spazio delle discussioni aperte. `space-y-8` mette lo spazio sotto ogni
// discussione tranne l'ultima (margine in fondo), quindi lo si toglie alla riga chiusa che ha sotto un'altra riga chiusa.
// Il numero fra parentesi conta i pubblicati e gli in attesa, come il contatore dell'elenco, non i nascosti che chi modera vede in grigio (decisione 457).
function contati(discussione: DiscussioneItem): number {
  return discussione.commenti.filter((c) => c.stato !== 'nascosto').length;
}

function primaDiUnaChiusa(indice: number): boolean {
  return indice < props.discussioni.length - 1 && isChiusa(props.discussioni[indice]) && isChiusa(props.discussioni[indice + 1]);
}

function apriDiscussione(chiave: string) {
  aperteManualmente.value[chiave] = true;
  nextTick(() => {
    const el = document.querySelector<HTMLTextAreaElement>(`[data-discussione="${chiave}"] textarea`);
    el?.focus();
  });
}
</script>

<template>
  <div class="space-y-8">
    <Empty v-if="discussioni.length === 0 && porta === 'admin'" class="border border-dashed py-8">
      <EmptyHeader>
        <EmptyMedia variant="icon" class="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500">
          <MessageSquare class="w-6 h-6" />
        </EmptyMedia>
        <EmptyTitle>{{ trans('commenti.nessuna_discussione') }}</EmptyTitle>
        <EmptyDescription>{{ trans('commenti.nessuna_discussione_descrizione') }}</EmptyDescription>
      </EmptyHeader>
    </Empty>

    <div
      v-for="(discussione, indice) in discussioni"
      :key="discussione.chiave"
      class="space-y-4"
      :class="{ '!mb-0': primaDiUnaChiusa(indice) }"
    >
      <!-- Discussione chiusa (pannello, >3 discussioni, 0 commenti e non ancora aperta a mano) -->
      <div
        v-if="isChiusa(discussione)"
        class="flex items-center justify-between py-2 border-b border-slate-200 dark:border-slate-800"
      >
        <div class="flex items-center gap-2">
          <UserIcon class="w-4 h-4 text-slate-500" />
          <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
            {{ discussione.titolo }}
          </h4>
        </div>

        <button
          v-if="discussione.puo_scrivere"
          type="button"
          :data-apri-discussione="discussione.chiave"
          @click="apriDiscussione(discussione.chiave)"
          class="inline-flex items-center justify-center min-h-10 min-w-10 px-3 -my-2 -mr-3 text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors"
        >
          {{ trans('commenti.scrivi') }}
        </button>
        <span
          v-else
          class="text-xs text-slate-400 italic"
        >
          {{ trans('commenti.nessun_commento') }}
        </span>
      </div>

      <!-- Discussione aperta -->
      <template v-else>
        <!-- Titolo della discussione con una persona, se ce ne sono più di una o se si è nel pannello; la discussione unica non ne ha -->
        <div
          v-if="discussione.tipo !== 'tutti' && (mostraTitolo || (porta === 'admin' && discussione.tipo === 'persona'))"
          class="pb-2 border-b border-slate-200 dark:border-slate-800"
        >
          <div class="flex items-center gap-2">
            <UserIcon class="w-4 h-4 text-slate-500" />
            <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
              {{ discussione.titolo }}
            </h4>
            <span
              v-if="contati(discussione) > 0"
              data-numero-commenti
              class="text-xs text-slate-400 tabular-nums"
            >
              ({{ contati(discussione) }})
            </span>
          </div>
          <p
            v-if="porta === 'admin' && discussione.tipo === 'persona'"
            class="text-xs text-slate-500 dark:text-slate-400 mt-0.5"
          >
            {{ trans('commenti.discussione_privata_pannello') }}
          </p>
        </div>

        <!-- Elenco commenti della discussione -->
        <div v-if="discussione.commenti.length > 0" class="space-y-4">
          <Commento
            v-for="commento in discussione.commenti"
            :key="commento.id"
            :commento="commento"
            :porta="porta"
            :can-moderate="config.puo_moderare"
            :ricarica="ricarica"
          />
        </div>

        <!-- Una discussione sola senza commenti: il riquadro vuoto delle segnalazioni e degli allegati (decisione 468) -->
        <Empty v-else-if="discussioni.length === 1" data-discussione-vuota class="border border-dashed py-8">
          <EmptyHeader>
            <EmptyMedia variant="icon" class="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500">
              <MessageSquare class="w-6 h-6" />
            </EmptyMedia>
            <EmptyTitle>{{ trans('commenti.nessun_commento') }}</EmptyTitle>
            <EmptyDescription>
              {{ discussione.puo_scrivere ? trans('commenti.comunicazione_primo_commento') : trans('commenti.comunicazione_commenti_chiusi') }}
            </EmptyDescription>
          </EmptyHeader>
        </Empty>

        <div
          v-else-if="!discussione.puo_scrivere"
          class="text-xs text-slate-400 italic py-2"
        >
          {{ trans('commenti.nessun_commento') }}
        </div>

        <!-- Modulo di scrittura: lo stesso delle segnalazioni. Chiuso di serie; aperto, con il fuoco nel campo, dopo «Scrivi». -->
        <FormCommento
          v-if="discussione.puo_scrivere"
          :url="route(`${porta}.comunicazioni.commenti.store`, comunicazioneId)"
          :dati="{ discussione: discussione.chiave }"
          :chiave="discussione.chiave"
          :aperto-di-serie="!!aperteManualmente[discussione.chiave]"
          :can-comment="true"
          :can-create="true"
          :ricarica="ricarica"
          class="mt-4"
        />
      </template>
    </div>
  </div>
</template>

