<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { ref, computed, nextTick } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { MessageSquarePlus, Send, Lock } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

// Per una segnalazione basta `segnalazioneId`. Per la discussione di una comunicazione si passano `url` (la rotta
// che riceve il commento), `dati` (i campi in più, per esempio `{ discussione }`) e `chiave` (che finisce nell'attributo
// `data-discussione` del modulo); `apertoDiSerie` lo apre già aperto, con il fuoco nel campo.
const props = defineProps<{
  segnalazioneId?: number;
  canComment: boolean;
  canCreate: boolean;
  url?: string;
  dati?: Record<string, unknown>;
  chiave?: string;
  apertoDiSerie?: boolean;
  // Le proprietà che il server rimanda dopo l'invio (una visita parziale di Inertia): senza, rimanda la pagina intera.
  ricarica?: string[];
}>();

// `inviato` a invio riuscito e `annullato` a «Annulla»: chi mette il modulo in una finestra (la pagina «Commenti») la chiude.
const emit = defineEmits<{
  (e: 'inviato'): void;
  (e: 'annullato'): void;
}>();

const { generateRoute } = usePermission();

const isExpanded = ref(props.apertoDiSerie ?? false);
const campo = ref<HTMLTextAreaElement | null>(null);

function espandi() {
  isExpanded.value = true;
  nextTick(() => campo.value?.focus());
}

const form = useForm({
  corpo: '',
});

const charCount = computed(() => form.corpo.length);
const MAX_CHARS = 5000;
const isOverLimit = computed(() => charCount.value > MAX_CHARS);

function submit() {
  if (!form.corpo.trim() || isOverLimit.value) return;

  const indirizzo = props.url ?? route(generateRoute('segnalazioni.commenti.store'), { segnalazione: props.segnalazioneId });

  form.transform((dati) => ({ ...dati, ...(props.dati ?? {}) })).post(indirizzo, {
    preserveScroll: true,
    ...(props.ricarica ? { preserveState: true, only: props.ricarica, showProgress: false } : {}),
    onSuccess: () => {
      form.reset('corpo');
      isExpanded.value = false;
      emit('inviato');
    },
  });
}
</script>

<template>
  <!-- Il margine lo decide chi mette il modulo (la discussione, la scheda della segnalazione): qui nessuno, che si sommerebbe al suo. -->
  <div :data-discussione="chiave">
    <!-- Thread chiuso -->
    <div
      v-if="!canComment"
      class="flex items-center gap-2 px-4 py-3 rounded-lg bg-slate-50 dark:bg-slate-900/40 border border-dashed border-slate-200 dark:border-slate-700 text-sm text-slate-500 dark:text-slate-400"
    >
      <Lock class="w-4 h-4 shrink-0" />
      <span>{{ trans('commenti.thread_chiuso') }}</span>
    </div>

    <!-- Nessun permesso -->
    <div
      v-else-if="!canCreate"
      class="flex items-center gap-2 px-4 py-3 rounded-lg bg-slate-50 dark:bg-slate-900/40 border border-dashed border-slate-200 dark:border-slate-700 text-sm text-slate-500 dark:text-slate-400"
    >
      <Lock class="w-4 h-4 shrink-0" />
      <span>{{ trans('commenti.no_permesso_commentare') }}</span>
    </div>

    <!-- Form commento -->
    <div v-else>
      <!-- Trigger collassato -->
      <button
        v-if="!isExpanded"
        @click="espandi"
        class="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-dashed border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm text-slate-400 dark:text-slate-500 hover:border-slate-300 dark:hover:border-slate-600 hover:text-slate-600 dark:hover:text-slate-300 transition-all cursor-text"
      >
        <MessageSquarePlus class="w-4 h-4 shrink-0" />
        <span>{{ trans('commenti.scrivi_commento') }}</span>
      </button>

      <!-- Form espanso -->
      <form
        v-else
        @submit.prevent="submit"
        class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 shadow-sm overflow-hidden transition-all focus-within:border-slate-300 dark:focus-within:border-slate-600"
      >
        <textarea
          ref="campo"
          name="corpo"
          v-model="form.corpo"
          :placeholder="trans('commenti.placeholder')"
          rows="4"
          autofocus
          class="w-full resize-none px-4 pt-3 pb-2 text-sm text-slate-800 dark:text-slate-200 bg-transparent border-0 outline-none placeholder:text-slate-400 dark:placeholder:text-slate-500"
          @keydown.ctrl.enter="submit"
          @keydown.meta.enter="submit"
        />

        <!-- Footer form -->
        <div class="flex items-center justify-between px-4 py-2 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30">
          <span
            class="text-xs tabular-nums transition-colors"
            :class="isOverLimit ? 'text-red-500 font-semibold' : 'text-slate-400'"
          >
            {{ charCount }}/{{ MAX_CHARS }}
          </span>

          <div class="flex items-center gap-2">
            <button
              type="button"
              @click="isExpanded = false; form.reset('corpo'); emit('annullato')"
              class="px-3 py-1.5 text-xs font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded transition-colors"
            >
              {{ trans('commenti.cancel') }}
            </button>
            <button
              type="submit"
              :disabled="form.processing || !form.corpo.trim() || isOverLimit"
              class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-md text-xs font-semibold bg-slate-900 text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-xs"
            >
              <Send class="w-3.5 h-3.5" />
              {{ form.processing ? trans('commenti.invio_in_corso') : trans('commenti.invia') }}
            </button>
          </div>
        </div>

        <p v-if="form.errors.corpo" class="px-4 pb-2 text-xs text-red-500">
          {{ form.errors.corpo }}
        </p>
      </form>
    </div>
  </div>
</template>
