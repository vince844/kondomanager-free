<script setup lang="ts">
import { ref } from "vue";
import { Link } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { Comunicazione } from '@/types/comunicazioni';

const props = defineProps<{
    comunicazioni: Comunicazione[];
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

            <div
                v-if="!comunicazioni.length"
                class="flex items-center justify-center py-8 text-xs font-medium text-slate-400 uppercase tracking-widest"
            >
                {{ trans('comunicazioni.dialogs.no_communications_created') }}
            </div>

            <li
                v-for="comunicazione in comunicazioni"
                :key="comunicazione.id"
                class="py-3 px-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-lg transition-colors"
            >
                <div class="flex items-start gap-3">
                    <!-- Priority icon -->
                    <div class="mt-0.5 shrink-0">
                        <component
                            :is="priorityIcons[comunicazione.priority]"
                            class="w-4 h-4"
                            :class="priorityColors[comunicazione.priority]"
                        />
                    </div>

                    <div class="flex-1 min-w-0">
                        <!-- Subject -->
                        <Link
                            :href="route(generateRoute(routeName), { id: comunicazione.id })"
                            class="text-sm font-bold transition-colors truncate block"
                            :class="priorityColors[comunicazione.priority]"
                        >
                            {{ comunicazione.subject }}
                        </Link>

                        <!-- Meta -->
                        <p class="text-[10px] font-medium text-slate-400 mt-0.5">
                            {{ trans('comunicazioni.visibility.sent_on_by', {
                                date: comunicazione.created_at,
                                name: comunicazione.created_by.user.name
                            }) }}
                        </p>

                        <!-- Description -->
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5 leading-relaxed">
                            {{ isExpanded(Number(comunicazione.id))
                                ? comunicazione.description
                                : truncate(comunicazione.description, 120) }}
                            <button
                                v-if="comunicazione.description && comunicazione.description.length > 120"
                                class="text-[10px] font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 ml-1 transition-colors"
                                @click="toggleExpanded(Number(comunicazione.id))"
                            >
                                {{ isExpanded(Number(comunicazione.id))
                                    ? trans('comunicazioni.actions.show_less')
                                    : trans('comunicazioni.actions.show_more') }}
                            </button>
                        </p>
                    </div>
                </div>
            </li>

        </ul>
    </div>
</template>