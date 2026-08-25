<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { Building2, ArrowUpNarrowWide, TextSearch, Wallet, Coins, HandCoins } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import type { Building } from '@/types/buildings';
import NavSezione from '@/components/NavSezione.vue';

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
  },
  {
    type: 'link',
    icon: Coins,
    title: 'Saldi Iniziali',
    href: generatePath('gestionale/:condominio/saldi', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: HandCoins,
    title: 'Già versato',
    href: generatePath('gestionale/:condominio/contributi', { condominio: condominio.value.id }),
  }
];

const currentPath = window.location.pathname;

</script>

<template>
  <div>
    <NavSezione :items="topbarNavItems" class="mb-4" />

    <!-- Main content -->
    <div class="w-full">
      <section class="w-full">
        <slot />
      </section>
    </div>
  </div>
</template>

