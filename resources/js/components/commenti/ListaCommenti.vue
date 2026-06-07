<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { MessageSquare, MessageSquareOff, Clock, ToggleLeft, ToggleRight } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import Commento, { type CommentoData } from '@/components/commenti/Commento.vue';
import FormCommento from '@/components/commenti/FormCommento.vue';

const props = defineProps<{
  segnalazioneId: number;
  commenti: CommentoData[];
  commentiInAttesa?: CommentoData[];
  commentiConfig: {
    can_comment: boolean;
    can_create: boolean;
    can_moderate: boolean;
    can_publish: boolean;
  };
  currentUserId: number;
}>();

const { hasPermission, generateRoute } = usePermission();

// Tutti i commenti visibili: prima quelli in attesa (moderatori), poi i pubblicati
const commentiDaMostrare = computed(() => {
  if (props.commentiConfig.can_moderate && props.commentiInAttesa?.length) {
    return [...(props.commentiInAttesa ?? []), ...props.commenti];
  }
  return props.commenti;
});

const totaleCommenti = computed(() => props.commenti.length);
const inAttesa = computed(() => props.commentiInAttesa?.length ?? 0);
</script>

<template>
  <div class="space-y-0">
    <!-- Header sezione commenti -->
    <div class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-slate-800 mb-4">
      <div class="flex items-center gap-2.5">
        <MessageSquare class="w-4 h-4 text-slate-400" />
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">
          {{ trans('commenti.titolo') }}
        </h3>
        <span
          v-if="totaleCommenti > 0"
          class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400"
        >
          {{ totaleCommenti }}
        </span>
        <!-- Badge in attesa (solo moderatori) -->
        <span
          v-if="commentiConfig.can_moderate && inAttesa > 0"
          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400"
        >
          <Clock class="w-3 h-3" />
          {{ inAttesa }} {{ trans('commenti.in_attesa') }}
        </span>
      </div>

      <!-- Toggle commenti (solo admin/moderatori) -->
      <Link
        v-if="commentiConfig.can_moderate"
        as="button"
        method="patch"
        :href="route(generateRoute('segnalazioni.commenti.toggle'), { segnalazione: segnalazioneId })"
        preserve-scroll
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium transition-colors"
        :class="commentiConfig.can_comment
          ? 'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 border border-emerald-200 dark:border-emerald-800/50'
          : 'text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800/50 hover:bg-slate-200 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700'"
      >
        <ToggleRight v-if="commentiConfig.can_comment" class="w-4 h-4" />
        <ToggleLeft v-else class="w-4 h-4" />
        {{ commentiConfig.can_comment ? trans('commenti.disabilita') : trans('commenti.abilita') }}
      </Link>
    </div>

    <!-- Lista commenti vuota -->
    <div
      v-if="commentiDaMostrare.length === 0"
      class="flex flex-col items-center justify-center py-8 text-center"
    >
      <MessageSquareOff class="w-8 h-8 text-slate-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-slate-400 dark:text-slate-500">
        {{ trans('commenti.nessun_commento') }}
      </p>
    </div>

    <!-- Lista commenti -->
    <div v-else class="space-y-5">
      <Commento
        v-for="commento in commentiDaMostrare"
        :key="commento.id"
        :commento="commento"
        :can-moderate="commentiConfig.can_moderate"
        :current-user-id="currentUserId"
      />
    </div>

    <!-- Form nuovo commento -->
    <FormCommento
      :segnalazione-id="segnalazioneId"
      :can-comment="commentiConfig.can_comment"
      :can-create="commentiConfig.can_create"
      class="mt-6"
    />
  </div>
</template>
