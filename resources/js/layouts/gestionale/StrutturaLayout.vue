<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { Building2, ArrowUpNarrowWide, TextSearch, Wallet, Coins } from 'lucide-vue-next';
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
    title: trans('gestionale.struttura.nav.details'),
    href:  generatePath('gestionale/:condominio/struttura', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Building2,
    title: trans('gestionale.struttura.nav.buildings'),
    href: generatePath('gestionale/:condominio/palazzine', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: ArrowUpNarrowWide,
    title: trans('gestionale.struttura.nav.stairs'),
    href: generatePath('gestionale/:condominio/scale', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Wallet,
    title: trans('gestionale.struttura.nav.resources'),
    href: generatePath('gestionale/:condominio/casse', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Coins,
    title: 'Saldi Iniziali',
    href: generatePath('gestionale/:condominio/saldi', { condominio: condominio.value.id }),
  }
];

const currentPath = window.location.pathname;

</script>

<template>
  <div>
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
    <div class="w-full">
      <section class="w-full">
        <slot />
      </section>
    </div>
  </div>
</template>
