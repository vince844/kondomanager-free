<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { UsersRound, Folders, TextSearch } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';

const page = usePage<{
  condominio: Building;
  immobile: Immobile
}>();

const condominio = computed(() => page.props.condominio);
const immobile = computed(() => page.props.immobile);

const { generatePath } = usePermission();

const topbarNavItems: LinkItem[] = [
  {
    type: 'link',
    icon: TextSearch,
    title: 'Dettagli',
    href:  generatePath('gestionale/:condominio/immobili/:immobile', { condominio: condominio.value.id, immobile: immobile.value.id }),
  },
  {
    type: 'link',
    icon: UsersRound,
    title: 'Anagrafiche',
    href: generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: condominio.value.id, immobile: immobile.value.id }),
  },
  {   
    type: 'link',
    icon: Folders,
    title: 'Documenti',
    href: generatePath('gestionale/:condominio/immobili/:immobile/documenti', { condominio: condominio.value.id, immobile: immobile.value.id }),
  },
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
        :class="[
          'justify-start',
          {
            'bg-muted':
              item.href === generatePath('gestionale/:condominio/immobili/:immobile', { condominio: condominio.id, immobile: immobile.id })
                ? currentPath === item.href
                : currentPath === item.href || currentPath.startsWith(item.href + '/')
          }
        ]"
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

