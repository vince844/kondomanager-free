<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { Building2, ArrowUpNarrowWide, TextSearch, Wallet } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import type { Building } from '@/types/buildings';

const page = usePage<{
  condominio: Building;
}>();

const condominio = computed(() => page.props.condominio);

const { generatePath } = usePermission();

const topbarNavItems: LinkItem[] = [
  { 
    type: 'link',
    icon: TextSearch,
    title: 'Dettagli',
    href:  generatePath('gestionale/:condominio/struttura', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Building2,
    title: 'Palazzine',
    href: generatePath('gestionale/:condominio/palazzine', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: ArrowUpNarrowWide,
    title: 'Scale',
    href: generatePath('gestionale/:condominio/scale', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Wallet,
    title: 'Risorse e fondi',
    href: generatePath('gestionale/:condominio/casse', { condominio: condominio.value.id }),
  }
];

const currentPath = window.location.pathname;

</script>

<template>
  <div class="px-4 py-6">
    <!-- Topbar -->
    <nav class="inline-flex items-center space-x-2 shadow ring-1 ring-black/5 md:rounded-lg p-2 mb-4">
      <Button
        v-for="item in topbarNavItems"
        :key="item.href"
        variant="ghost"
        :class="['justify-start', { 'bg-muted': currentPath.startsWith(item.href) }]"
        as-child
      >
        <Link :href="item.href">
          <component v-if="item.icon" :is="item.icon" class="mr-1 h-4 w-4" />
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

