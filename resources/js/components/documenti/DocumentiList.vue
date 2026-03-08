<script setup lang="ts">
import { ref } from "vue";
import { CloudDownload } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import type { Documento } from '@/types/documenti';

const props = defineProps<{
    documenti: Documento[];
}>();

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

const truncatedName = (name: string, length: number = 80) => {
    return name.length > length ? `${name.slice(0, length)}...` : name;
};
</script>

<template>
    <div class="flow-root">
        <ul role="list" class="divide-y divide-slate-100 dark:divide-slate-800">
            <div
                v-if="!documenti.length"
                class="flex items-center justify-center py-8 text-xs font-medium text-slate-400 uppercase tracking-widest"
            >
                {{ trans('documenti.dialogs.no_documents_created') }}
            </div>

            <li
                v-for="documento in documenti"
                :key="documento.id"
                class="py-3 px-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-lg transition-colors items-start group"
            >
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 shrink-0">
                        <CloudDownload class="w-4 h-4 text-blue-500 dark:text-blue-400" />
                    </div>

                    <div class="flex-1 min-w-0">
                        <a
                            :href="route(generateRoute('documenti.download'), { id: documento.id })"
                            class="text-sm font-bold truncate block text-blue-500 dark:text-blue-400 transition-all"
                        >
                            {{ truncatedName(documento.name, 40) }}
                        </a>

                        <p class="text-[10px] font-medium text-slate-400 mt-0.5">
                            {{ trans('documenti.visibility.sent_on_by_category', {
                                date: documento.created_at,
                                name: documento.created_by.user.name,
                                category: documento.categoria.name.toLowerCase()
                            }) }}
                        </p>

                        <p v-if="documento.description" class="text-xs text-slate-500 dark:text-slate-400 mt-1.5 leading-relaxed">
                            {{ isExpanded(Number(documento.id))
                                ? documento.description
                                : truncate(documento.description, 120) }}

                            <button
                                v-if="documento.description.length > 120"
                                class="text-[10px] font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 ml-1 transition-colors"
                                @click="toggleExpanded(Number(documento.id))"
                            >
                                {{ isExpanded(Number(documento.id))
                                    ? trans('documenti.actions.show_less')
                                    : trans('documenti.actions.show_more') }}
                            </button>
                        </p>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
