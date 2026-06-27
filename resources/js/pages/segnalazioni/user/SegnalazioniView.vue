<script setup lang="ts">

import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Lock, LockOpen, CalendarDays, User, Building2, MessageSquare, ChevronLeft, AlertTriangle } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Segnalazione } from '@/types/segnalazioni';
import ListaCommenti from '@/components/commenti/ListaCommenti.vue';

const props = defineProps<{
  segnalazione: Segnalazione | any;
  commenti_config: {
    can_comment: boolean;
    can_create: boolean;
    can_moderate: boolean;
    can_publish: boolean;
  };
}>();

const { generateRoute } = usePermission();

const page = usePage();
const currentUserId = computed(() => (page.props.auth as any).user.id);

const priorityItem = computed(() =>
  priorityConstants.find(p => p.value === props.segnalazione.priority)
);

const statusItem = computed(() =>
  statoConstants.find(p => p.value === props.segnalazione.stato)
);

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: trans('segnalazioni.breadcrumbs.list'),
    href: route(generateRoute('segnalazioni.index'))
  },
  {
    title: trans('segnalazioni.breadcrumbs.view'),
    href: '#',
  }
];

const pageGuides = computed(() => [
  {
    title: 'Monitoraggio Guasto',
    description: 'Controlla lo stato di avanzamento e se ci sono novità dal tuo amministratore.',
    icon: AlertTriangle,
    colorVariant: 'blue' as const
  },
  {
    title: 'Comunicazione Diretta',
    description: 'Usa la sezione in basso per chiedere informazioni o fornire ulteriori dettagli utili.',
    icon: MessageSquare,
    colorVariant: 'amber' as const
  },
  {
    title: 'Privacy e Sicurezza',
    description: 'Questa conversazione è privata e visibile solo a te e all\'amministratore.',
    icon: Lock,
    colorVariant: 'emerald' as const
  }
]);
</script>

<template>
  <Head :title="trans('segnalazioni.header.view_ticket_head')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        :page-title="trans('segnalazioni.header.view_ticket_title')"
        :page-subtitle="trans('segnalazioni.header.view_ticket_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="route(generateRoute('segnalazioni.index'))"
        :back-text="trans('segnalazioni.actions.back_to_list')"
      />

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        <!-- Colonna principale -->
        <div class="lg:col-span-2 space-y-6">

          <!-- Corpo della segnalazione -->
          <Card class="border-dashed shadow-sm bg-white dark:bg-slate-950">
            <CardHeader class="pb-4 border-b border-slate-100 dark:border-slate-800">
              <div class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                  <div class="flex items-center gap-3">
                    <AlertTriangle class="w-6 h-6 text-slate-400" />
                    <CardTitle class="text-2xl font-bold leading-tight text-slate-900 dark:text-white">
                      {{ props.segnalazione.subject }}
                    </CardTitle>
                  </div>
                  <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-slate-500 dark:text-slate-400 pt-3">
                    <div class="flex items-center gap-2">
                      <User class="w-4 h-4 text-slate-400" />
                      <span class="font-medium">
                        {{ props.segnalazione.created_by?.user?.name || trans('segnalazioni.details.admin_sender') }}
                      </span>
                    </div>
                    <div class="flex items-center gap-2">
                      <CalendarDays class="w-4 h-4 text-slate-400" />
                      <span>
                        {{
                          trans('segnalazioni.visibility.sent_on_by', {
                            date: props.segnalazione.created_at,
                            name: props.segnalazione.created_by?.user?.name || trans('segnalazioni.details.admin_sender')
                          })
                        }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </CardHeader>
            <CardContent class="pt-6">
              <div class="prose prose-slate dark:prose-invert max-w-none text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
                {{ props.segnalazione.description }}
              </div>
            </CardContent>
          </Card>

          <!-- Sezione Commenti -->
          <Card class="border-dashed shadow-sm bg-white dark:bg-slate-950">
            <CardContent class="pt-6">
              <ListaCommenti
                :segnalazione-id="props.segnalazione.id"
                :commenti="props.segnalazione.commenti || []"
                :commenti-in-attesa="props.segnalazione.commentiInAttesa || []"
                :commenti-config="props.commenti_config"
                :current-user-id="currentUserId"
              />
            </CardContent>
          </Card>

        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-1 space-y-4">

          <!-- Stato e priorità -->
          <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardContent class="space-y-5 pt-5 p-4">

              <div class="grid grid-cols-2 gap-4 border-b border-dashed pb-4">
                <!-- Stato -->
                <div class="min-w-0">
                  <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-1.5">
                    {{ trans('segnalazioni.details.current_status') }}
                  </p>
                  <div v-if="statusItem" class="flex items-center gap-2">
                    <component :is="statusItem.icon" class="w-4 h-4 shrink-0" :class="statusItem.colorClass" />
                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100 capitalize truncate">
                      {{ trans(statusItem.label) }}
                    </span>
                  </div>
                  <div v-else class="text-sm font-semibold text-slate-400">
                    {{ trans('segnalazioni.label.no_status') }}
                  </div>
                </div>

                <!-- Priorità -->
                <div class="min-w-0">
                  <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-1.5">
                    {{ trans('segnalazioni.details.priority_level') }}
                  </p>
                  <div v-if="priorityItem" class="flex items-center gap-2">
                    <component :is="priorityItem.icon" class="w-4 h-4 shrink-0" :class="priorityItem.colorClass" />
                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100 capitalize truncate">
                      {{ trans(priorityItem.label) }}
                    </span>
                  </div>
                  <div v-else class="text-sm font-semibold text-slate-400">
                    {{ trans('segnalazioni.label.no_priority') }}
                  </div>
                </div>
              </div>

              <!-- Condominio -->
              <div class="border-b border-dashed pb-4">
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-1.5">
                  {{ trans('segnalazioni.label.building') }}
                </p>
                <div class="flex items-center gap-2">
                  <Building2 class="w-4 h-4 text-slate-400 shrink-0" />
                  <span class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">
                    {{ props.segnalazione.condominio?.full?.nome || props.segnalazione.condominio?.nome || '—' }}
                  </span>
                </div>
              </div>

              <!-- Interazioni -->
              <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-3">
                  {{ trans('segnalazioni.details.interactions') }}
                </p>
                <div class="grid grid-cols-2 gap-2">
                  <div class="flex items-center gap-2 bg-white dark:bg-slate-950 p-2 rounded border border-dashed shadow-xs">
                    <component
                      :is="props.segnalazione.is_locked ? Lock : LockOpen"
                      class="w-3.5 h-3.5 shrink-0"
                      :class="props.segnalazione.is_locked ? 'text-amber-500' : 'text-emerald-500'"
                    />
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300 truncate">
                      {{ props.segnalazione.is_locked ? trans('segnalazioni.details.locked') : trans('segnalazioni.details.unlocked') }}
                    </span>
                  </div>

                  <div class="flex items-center gap-2 bg-white dark:bg-slate-950 p-2 rounded border border-dashed shadow-xs">
                    <MessageSquare
                      class="w-3.5 h-3.5 shrink-0"
                      :class="props.segnalazione.can_comment ? 'text-emerald-500' : 'text-slate-400'"
                    />
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300 truncate">
                      {{ props.segnalazione.can_comment ? trans('segnalazioni.details.comments_enabled') : trans('segnalazioni.details.comments_disabled') }}
                    </span>
                  </div>
                </div>
              </div>

            </CardContent>
          </Card>

        </div>
      </div>

    </div>
  </AppLayout>
</template>
