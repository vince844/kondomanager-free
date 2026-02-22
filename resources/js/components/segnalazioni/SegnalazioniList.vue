<script setup lang="ts">
import { ref } from "vue";
import { Link } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert, Tags } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import type { Segnalazione } from '@/types/segnalazioni';

const props = defineProps<{
    segnalazioni: Segnalazione[];
    routeName: string;
}>();

const priorityIcons = {
    bassa: CircleArrowDown,
    media: CircleArrowRight,
    alta: CircleArrowUp,
    urgente: CircleAlert,
};

const priorityColors: Record<string, string> = {
    bassa: 'text-green-500',
    media: 'text-blue-500',
    alta: 'text-orange-500',
    urgente: 'text-red-500',
};

const { generateRoute } = usePermission();
const expandedIds = ref<Set<number>>(new Set());

const isExpanded = (id: number) => expandedIds.value.has(id);
const toggleExpanded = (id: number) => {
    if (expandedIds.value.has(id)) {
        expandedIds.value.delete(id);
    } else {
        expandedIds.value.add(id);
    }
};

const truncate = (text: string, length: number = 120) => {
    return text.length > length ? `${text.slice(0, length)}...` : text;
};
</script>

<template>
    <div class="flow-root">
        <ul role="list" class="divide-y divide-slate-100 dark:divide-slate-800">

            <Empty v-if="!segnalazioni.length" class="border border-dashed my-4">
                <EmptyHeader>
                    <EmptyMedia variant="icon" class="bg-slate-50/50 dark:bg-slate-800/50">
                        <Tags />
                    </EmptyMedia>
                    <EmptyTitle> 
                        {{ trans('segnalazioni.header.widget_tickets_title') }}
                    </EmptyTitle>
                    <EmptyDescription>
                        {{ trans('segnalazioni.dialogs.no_tickets_created') }}
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>

            <li
                v-for="segnalazione in segnalazioni"
                :key="segnalazione.id"
                class="py-3 px-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-lg transition-colors"
            >
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 shrink-0">
                        <component
                            :is="priorityIcons[segnalazione.priority]"
                            class="w-4 h-4"
                            :class="priorityColors[segnalazione.priority]"
                        />
                    </div>

                    <div class="flex-1 min-w-0">
                        <Link
                            :href="route(generateRoute(routeName), { id: segnalazione.id })"
                            class="text-sm font-bold transition-colors truncate block"
                            :class="priorityColors[segnalazione.priority]"
                        >
                            {{ segnalazione.subject }}
                        </Link>

                        <p class="text-[10px] font-medium text-slate-400 mt-0.5">
                            {{ trans('segnalazioni.visibility.sent_on_by', {
                                date: segnalazione.created_at,
                                name: segnalazione.created_by.user.name
                            }) }}
                        </p>

                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5 leading-relaxed">
                            {{ isExpanded(Number(segnalazione.id))
                                ? segnalazione.description
                                : truncate(segnalazione.description, 120) }}
                            <button
                                v-if="segnalazione.description && segnalazione.description.length > 120"
                                class="text-[10px] font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 ml-1 transition-colors"
                                @click="toggleExpanded(Number(segnalazione.id))"
                            >
                                {{ isExpanded(Number(segnalazione.id))
                                    ? trans('segnalazioni.actions.show_less')
                                    : trans('segnalazioni.actions.show_more') }}
                            </button>
                        </p>
                    </div>
                </div>
            </li>

        </ul>
    </div>
</template>