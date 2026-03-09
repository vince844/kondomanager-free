<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { UsersRound, Folders, TextSearch } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { LinkItem } from '@/types';
import type { Fornitore } from '@/types/fornitori';

const page = usePage<{
  fornitore: Fornitore;
}>();

const fornitore = computed(() => page.props.fornitore);
const { generatePath } = usePermission();

const topbarNavItems: LinkItem[] = [
  {
    type: 'link',
    icon: TextSearch,
    title: trans('fornitori.navigation.details'),
    href:  generatePath('fornitori/:fornitore', { fornitore: fornitore.value.id }),
  },
  {
    type: 'link',
    icon: UsersRound,
    title: trans('fornitori.navigation.referents'),
    href:  generatePath('fornitori/:fornitore/anagrafiche', { fornitore: fornitore.value.id }),
  },
  {   
    type: 'link',
    icon: Folders,
    title: trans('fornitori.navigation.documents'),
    href:  generatePath('fornitori/:fornitore/documenti', { fornitore: fornitore.value.id }),
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
              item.href === generatePath('fornitori/:fornitore', { fornitore: fornitore.id })
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
