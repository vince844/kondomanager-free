<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { usePermission } from "@/composables/permissions";
import { LogIn, LogOut, Wallet, Repeat2, FileText } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import type { Building } from '@/types/buildings';

const page = usePage<{ condominio: Building }>();
const condominio = computed(() => page.props.condominio);
const { generatePath } = usePermission();

const topbarNavItems: (LinkItem & { badge?: string })[] = [
    {
        type:  'link',
        icon:  LogIn,
        title: 'Incassi rate',
        href:  generatePath('gestionale/:condominio/movimenti-rate', { condominio: condominio.value.id }),
    },
    {
        type:  'link',
        icon:  FileText,
        title: 'Fatture passive',
        href:  generatePath('gestionale/:condominio/fatture', { condominio: condominio.value.id }),
    },
    {
        type:  'link',
        icon:  LogOut,
        title: 'Pagamenti fornitori',
        href:  '#',
        badge: 'In sviluppo',
    },
    {
        type:  'link',
        icon:  Repeat2,
        title: 'Giroconti',
        href:  '#',
        badge: 'In sviluppo',
    },
    {
        type:  'link',
        icon:  Wallet,
        title: 'Registro contabilità (prima nota)',
        href:  '#',
        badge: 'In sviluppo',
    },
];

const currentPath = window.location.pathname;
</script>

<template>
    <div class="">
        <!-- Topbar -->
        <nav class="inline-flex items-center space-x-2 shadow ring-1 ring-black/5 md:rounded-lg p-2 mb-4">
            <Button
                v-for="item in topbarNavItems"
                :key="item.href"
                variant="ghost"
                :class="['justify-start', { 'bg-muted': currentPath.startsWith(item.href) && item.href !== '#' }]"
                :disabled="item.href === '#'"
                as-child
            >
                <Link :href="item.href">
                    <component v-if="item.icon" :is="item.icon" class="mr-1 h-4 w-4" />
                    {{ item.title }}
                    <Badge 
                        v-if="item.badge" 
                        class="ml-2 text-[10px] px-1.5 py-0 bg-blue-100 text-blue-700 rounded-md border-blue-200 hover:bg-blue-100"
                    >
                        {{ item.badge }}
                    </Badge>
                </Link>
            </Button>
        </nav>

        <!-- Main content -->
        <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
            <section class="w-full">
                <slot />
            </section>
        </div>
    </div>
</template>