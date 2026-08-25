<script setup lang="ts">
import { computed } from 'vue';
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Megaphone, Eye, BellRing, CalendarDays, Info } from "lucide-vue-next";
import { trans } from 'laravel-vue-i18n';
import { usePermission } from "@/composables/permissions";
import { priorityConstants, publishedConstants } from '@/lib/comunicazioni/constants';
import type { BreadcrumbItem } from '@/types';
import type { Comunicazione } from "@/types/comunicazioni";

const { generateRoute } = usePermission();

const props = defineProps<{
  comunicazione: Comunicazione
}>();

// Ricava dinamicamente l'oggetto priorità dal file constants
const currentPriority = computed(() => {
    return priorityConstants.find(p => p.value === props.comunicazione.priority);
});

// Ricava dinamicamente l'oggetto stato (pubblicato/nascosto) dal file constants
const currentPublished = computed(() => {
    // !! forza la conversione in booleano puro (1 -> true, 0 -> false)
    return publishedConstants.find(p => p.value === !!props.comunicazione.is_published);
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('comunicazioni.breadcrumbs.list'), 
      href: route(generateRoute('comunicazioni.index'))
  },
  {
      title: trans('comunicazioni.breadcrumbs.view'),
      href: '#',
  }
]);

const pageGuides = computed(() => [
  {
    title: trans('comunicazioni.guides.read_title'),
    description: trans('comunicazioni.guides.read_desc'),
    icon: Megaphone,
    colorVariant: 'blue' as const
  },
  {
    title: trans('comunicazioni.guides.visibility_title'),
    description: trans('comunicazioni.guides.visibility_desc'),
    icon: Eye,
    colorVariant: 'amber' as const
  },
  {
    title: trans('comunicazioni.guides.urgency_title'),
    description: trans('comunicazioni.guides.urgency_desc'),
    icon: BellRing,
    colorVariant: 'emerald' as const
  }
]);

</script>

<template>
  <Head :title="trans('comunicazioni.header.view_communication_head')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">

        <PageHeaderGuide
            :page-title="trans('comunicazioni.header.view_communication_title')"
            :page-subtitle="trans('comunicazioni.header.view_communication_description')"
            :guides="pageGuides"
            :breadcrumbs="breadcrumbs"
            :video-url="null"
            :back-url="route(generateRoute('comunicazioni.index'))"
            :back-text="trans('comunicazioni.actions.back_to_list')"
        >
        </PageHeaderGuide>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            
            <div class="lg:col-span-3 space-y-6">
                <Card class="border-dashed shadow-sm bg-white dark:bg-slate-950">
                    <CardHeader class="pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-start justify-between gap-4">
                            <div class="space-y-1">
                                <div class="flex items-center gap-3">
                                    <component
                                        :is="currentPriority?.icon || Info"
                                        class="w-6 h-6"
                                        :class="currentPriority?.colorClass || 'text-slate-400'"
                                    />
                                    <CardTitle class="text-2xl font-bold leading-tight text-slate-900 dark:text-white">
                                        {{ comunicazione.subject }}
                                    </CardTitle>
                                </div>
                                
                                <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 pt-3">
                                    <CalendarDays class="w-4 h-4 text-slate-400" />
                                    <span>
                                        {{ 
                                            trans('comunicazioni.visibility.sent_on_by', { 
                                                date: comunicazione.created_at, 
                                                name: comunicazione.created_by?.user?.name || trans('comunicazioni.label.administrator') 
                                            }) 
                                        }}
                                    </span>
                                </div>

                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="pt-6">
                        <div class="prose prose-slate dark:prose-invert max-w-none text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
                            {{ comunicazione.description }}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="lg:col-span-1 space-y-6">
                <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          
                    <CardContent class="space-y-5 pt-5">
                        
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">
                                {{ trans('comunicazioni.label.priority') }}
                            </p>
                            <div v-if="currentPriority" class="flex items-center gap-2">
                                <component
                                    :is="currentPriority.icon"
                                    class="w-4 h-4"
                                    :class="currentPriority.colorClass"
                                />
                                <span class="text-sm font-semibold capitalize text-slate-900 dark:text-slate-100">
                                    {{ trans(currentPriority.label) }}
                                </span>
                            </div>
                            <div v-else class="text-sm font-semibold text-slate-400">
                                {{ trans('comunicazioni.label.none') }}
                            </div>
                        </div>

                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">
                                {{ trans('comunicazioni.label.visibility') }}
                            </p>
                            
                            <div v-if="currentPublished" class="flex items-center gap-2">
                                <component
                                    :is="currentPublished.icon"
                                    class="w-4 h-4"
                                    :class="currentPublished.colorClass"
                                />
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ trans(currentPublished.label) }}
                                </span>
                            </div>
                            
                            <div v-else class="text-sm font-semibold text-slate-400">
                                {{ trans('comunicazioni.label.draft_hidden') }}
                            </div>
                        </div>

                        <div v-if="comunicazione.can_comment !== undefined">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">
                                {{ trans('comunicazioni.label.interactions') }}
                            </p>
                            <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                {{ comunicazione.can_comment 
                                ? trans('comunicazioni.label.comments_enabled') 
                                : trans('comunicazioni.label.comments_disabled') }}
                            </span>
                        </div>

                    </CardContent>
                </Card>
            </div>

        </div>

    </div>
  </AppLayout>
</template>