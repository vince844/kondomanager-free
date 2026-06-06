<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { MessageSquarePlus, Send, Lock } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

const props = defineProps<{
  segnalazioneId: number;
  canComment: boolean;
  canCreate: boolean;
}>();

const { generateRoute } = usePermission();

const isExpanded = ref(false);

const form = useForm({
  corpo: '',
});

const charCount = computed(() => form.corpo.length);
const MAX_CHARS = 5000;
const isOverLimit = computed(() => charCount.value > MAX_CHARS);

function submit() {
  if (!form.corpo.trim() || isOverLimit.value) return;

  form.post(route('admin.segnalazioni.commenti.store', { segnalazione: props.segnalazioneId }), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset('corpo');
      isExpanded.value = false;
    },
  });
}
</script>

<template>
  <div class="mt-2">
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
        @click="isExpanded = true"
        class="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-dashed border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm text-slate-400 dark:text-slate-500 hover:border-blue-300 dark:hover:border-blue-700 hover:text-slate-600 dark:hover:text-slate-300 transition-all cursor-text"
      >
        <MessageSquarePlus class="w-4 h-4 shrink-0" />
        <span>{{ trans('commenti.scrivi_commento') }}</span>
      </button>

      <!-- Form espanso -->
      <div
        v-else
        class="rounded-lg border border-blue-200 dark:border-blue-800/50 bg-white dark:bg-slate-950 shadow-sm overflow-hidden transition-all"
      >
        <textarea
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
              @click="isExpanded = false; form.reset('corpo')"
              class="px-3 py-1.5 text-xs font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded transition-colors"
            >
              {{ trans('commenti.cancel') }}
            </button>
            <button
              type="button"
              @click="submit"
              :disabled="form.processing || !form.corpo.trim() || isOverLimit"
              class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-md text-xs font-semibold bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              <Send class="w-3.5 h-3.5" />
              {{ form.processing ? trans('commenti.invio_in_corso') : trans('commenti.invia') }}
            </button>
          </div>
        </div>

        <p v-if="form.errors.corpo" class="px-4 pb-2 text-xs text-red-500">
          {{ form.errors.corpo }}
        </p>
      </div>
    </div>
  </div>
</template>
