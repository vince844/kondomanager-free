<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import type { NavItem } from '@/types';
import type { Building } from '@/types/buildings';

const page = usePage<{
  condominio: Building;
}>();

const condominio = computed(() => page.props.condominio);

const { generatePath } = usePermission();

const topbarNavItems: NavItem[] = [
    {
        title: 'Struttura',
        href:  generatePath('gestionale/:condominioId/struttura', { condominioId: condominio.value.id }),
    },
    {
        title: 'Palazzine',
        href: generatePath('gestionale/:condominioId/palazzine', { condominioId: condominio.value.id }),
    },
];

const currentPath = window.location.pathname;

</script>

<template>
  <div class="px-4 py-6">
    <!-- Topbar -->
    <nav class="flex items-center space-x-2 shadow ring-1 ring-black/5 md:rounded-lg p-2 mb-4">
      <Button
        v-for="item in topbarNavItems"
        :key="item.href"
        variant="ghost"
        :class="['justify-start', { 'bg-muted': currentPath.startsWith(item.href) }]"
        as-child
      >
        <Link :href="item.href">
          {{ item.title }}
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

