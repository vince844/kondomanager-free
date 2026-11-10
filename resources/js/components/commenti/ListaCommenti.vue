<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { MessageSquare, Clock, ToggleLeft, ToggleRight } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
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

// Tutti i commenti visibili: prima quelli in attesa, poi i pubblicati
const commentiDaMostrare = computed(() => {
  if (props.commentiInAttesa?.length) {
    return [...(props.commentiInAttesa ?? []), ...props.commenti];
  }
  return props.commenti;
});

const totaleCommenti = computed(() => props.commenti.length);
const inAttesa = computed(() => props.commentiInAttesa?.length ?? 0);
</script>

<template>
  <div class="space-y-4">
    <!-- Header sezione commenti (allineato allo stile di CardTitle di Allegati) -->
    <div class="flex flex-wrap items-center justify-between gap-2 pb-1">
      <div class="flex items-center gap-2">
        <div class="text-base font-semibold flex items-center gap-2">
          <MessageSquare class="h-4 w-4 text-muted-foreground" />
          <span>{{ trans('commenti.titolo') }}</span>
          <Badge variant="secondary" class="tabular-nums">
            {{ totaleCommenti }}
          </Badge>
        </div>

        <!-- Badge in attesa (solo moderatori) -->
        <Badge
          v-if="commentiConfig.can_moderate && inAttesa > 0"
          variant="secondary"
          class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs font-normal whitespace-nowrap gap-1"
        >
          <Clock class="w-3 h-3" />
          <span>{{ inAttesa }} {{ trans('commenti.in_attesa') }}</span>
        </Badge>
      </div>

      <!-- Toggle commenti (solo admin/moderatori) -->
      <Link
        v-if="commentiConfig.can_moderate"
        as="button"
        method="patch"
        :href="route(generateRoute('segnalazioni.commenti.toggle'), { segnalazione: segnalazioneId })"
        preserve-scroll
        class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-md text-xs font-medium transition-colors border"
        :class="commentiConfig.can_comment
          ? 'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 border-emerald-200 dark:border-emerald-800/50'
          : 'text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800/50 hover:bg-slate-200 dark:hover:bg-slate-700 border-slate-200 dark:border-slate-700'"
      >
        <ToggleRight v-if="commentiConfig.can_comment" class="w-3.5 h-3.5" />
        <ToggleLeft v-else class="w-3.5 h-3.5" />
        <span>{{ commentiConfig.can_comment ? trans('commenti.disabilita') : trans('commenti.abilita') }}</span>
      </Link>
    </div>

    <!-- Lista commenti vuota (stesso stile della card degli allegati) -->
    <Empty v-if="commentiDaMostrare.length === 0" class="border border-dashed py-8">
      <EmptyHeader>
        <EmptyMedia variant="icon" class="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500">
          <MessageSquare class="w-6 h-6" />
        </EmptyMedia>
        <EmptyTitle>{{ trans('commenti.nessun_commento') }}</EmptyTitle>
        <EmptyDescription>{{ trans('commenti.nessun_commento_descrizione') }}</EmptyDescription>
      </EmptyHeader>
    </Empty>

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
      class="mt-4"
    />
  </div>
</template>
