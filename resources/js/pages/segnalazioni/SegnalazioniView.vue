<script setup lang="ts">

import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Lock, LockOpen, User, CalendarDays, AlertTriangle, Building2, SlidersHorizontal, MessageSquare } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { Permission } from '@/enums/Permission';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Segnalazione } from '@/types/segnalazioni';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps<{
  segnalazione: Segnalazione | any;
}>();  

const { hasPermission, generateRoute } = usePermission();

// Estrazione sicura dei metadati da visualizzare nella sidebar
const priorityItem = computed(() => {
  return priorityConstants.find(p => p.value === props.segnalazione.priority);
});

const statusItem = computed(() => {
  return statoConstants.find(p => p.value === props.segnalazione.stato);
});

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
    title: trans('segnalazioni.guides.issue_title'),
    description: trans('segnalazioni.guides.issue_desc'),
    icon: AlertTriangle,
    colorVariant: 'blue' as const
  },
  {
    title: trans('segnalazioni.guides.location_title'),
    description: trans('segnalazioni.guides.location_desc'),
    icon: Building2,
    colorVariant: 'amber' as const
  },
  {
    title: trans('segnalazioni.guides.settings_title'),
    description: trans('segnalazioni.guides.settings_desc'),
    icon: SlidersHorizontal,
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
        >
            <template #actions>
                <Link 
                    as="button"
                    method="post"
                    v-if="hasPermission([Permission.EDIT_SEGNALAZIONI])"
                    :href="route(generateRoute('segnalazioni.toggleResolve'), { id: props.segnalazione.id })" 
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-sm font-medium text-slate-700 dark:text-slate-300 px-3 py-1.5 h-8 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                >
                    <LockOpen v-if="props.segnalazione.is_locked" class="w-4 h-4 text-emerald-500" />
                    <Lock v-else class="w-4 h-4 text-amber-500" />
                    <span>
                        {{ 
                            props.segnalazione.is_locked 
                            ? trans('segnalazioni.actions.unlock_ticket') 
                            : trans('segnalazioni.actions.lock_ticket') 
                        }}
                    </span>
                </Link>
            </template>
        </PageHeaderGuide>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            
            <div class="lg:col-span-2 space-y-6">
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
            </div>

            <div class="lg:col-span-1 space-y-6">
                <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                    <CardContent class="space-y-6 pt-5 p-3">
                        <div class="grid grid-cols-[1.2fr_0.8fr] gap-6 border-b border-dashed pb-4">
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-1.5">
                                    {{ trans('segnalazioni.details.current_status') }}
                                </p>
                                <div v-if="statusItem" class="flex items-center gap-2">
                                    <component :is="statusItem.icon" class="w-4 h-4 shrink-0" :class="statusItem.colorClass" />
                                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100 capitalize truncate" :title="trans(statusItem.label)">
                                        {{ trans(statusItem.label) }}
                                    </span>
                                </div>
                                <div v-else class="text-sm font-semibold text-slate-400">
                                    {{ trans('segnalazioni.label.no_status') }}
                                </div>
                            </div>
                            
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-1.5">
                                    {{ trans('segnalazioni.details.priority_level') }}
                                </p>
                                <div v-if="priorityItem" class="flex items-center gap-2">
                                    <component :is="priorityItem.icon" class="w-4 h-4 shrink-0" :class="priorityItem.colorClass" />
                                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100 capitalize truncate" :title="trans(priorityItem.label)">
                                        {{ trans(priorityItem.label) }}
                                    </span>
                                </div>
                                <div v-else class="text-sm font-semibold text-slate-400">
                                    {{ trans('segnalazioni.label.no_priority') }}
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-[1.2fr_0.8fr] gap-6 border-b border-dashed pb-4">
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-1.5">
                                    {{ trans('segnalazioni.label.building') }}
                                </p>
                                <div class="flex items-center gap-2 group cursor-help">
                                    <Building2 class="w-4 h-4 text-slate-400 shrink-0" />
                                    <span 
                                        class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate block"
                                        :title="segnalazione.condominio?.full?.nome || segnalazione.condominio?.nome"
                                    >
                                        {{ segnalazione.condominio?.full?.nome || segnalazione.condominio?.nome }}
                                    </span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-1.5">
                                    {{ trans('segnalazioni.details.visibility_status') }}
                                </p>
                                <div class="flex items-center gap-2 shrink-0">
                                    <div class="w-2 h-2 rounded-full shrink-0" :class="props.segnalazione.is_published ? 'bg-emerald-500' : 'bg-amber-500'"></div>
                                    <span class="text-sm font-semibold text-slate-900 dark:text-slate-100 whitespace-nowrap">
                                        {{ props.segnalazione.is_published ? trans('segnalazioni.visibility.public') : trans('segnalazioni.visibility.private') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-3">
                                {{ trans('segnalazioni.details.interactions') }}
                            </p>
                            <div class="grid grid-cols-2 gap-3">
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

                                <div v-if="props.segnalazione.can_comment !== undefined" 
                                    class="flex items-center gap-2 bg-white dark:bg-slate-950 p-2 rounded border border-dashed shadow-xs">
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

<style src="vue-select/dist/vue-select.css"></style>