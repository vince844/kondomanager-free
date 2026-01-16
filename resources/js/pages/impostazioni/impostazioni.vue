<script setup lang="ts">

import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue'
import Heading from '@/components/Heading.vue'
import { ref, computed } from 'vue'
import { Users, Settings, DatabaseBackup } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Item, ItemActions, ItemContent, ItemDescription, ItemMedia, ItemTitle } from '@/components/ui/item'
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types'

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Impostazioni',
    href: '/impostazioni',
  },
]

const apps = [
  {
    name: 'impostazioni.dialogs.general_settings_title',
    logo: Settings,
    desc: 'impostazioni.dialogs.general_settings_description',
    href: "/impostazioni/generali", 
  },
  {
    name: 'impostazioni.dialogs.users_settings_title',
    logo: Users,
    desc: 'impostazioni.dialogs.users_settings_description',
    href: "/utenti",
  },
  {
    name: 'impostazioni.dialogs.backups_settings_title',
    logo: DatabaseBackup,
    desc: 'impostazioni.dialogs.backups_settings_description',
    href: "#",
  }
]

const searchTerm = ref("")

const normalize = (value: string) =>value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')

const filteredApps = computed(() => {
  const term = normalize(searchTerm.value)

  return apps.filter(app => {
    const name = normalize(trans(app.name))
    const desc = normalize(trans(app.desc))

    return name.includes(term) || desc.includes(term)
  })
})

</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head :title="trans('impostazioni.header.settings_head')" />

    <div class="px-4 py-6">
      <Heading
        :title="trans('impostazioni.header.settings_title')" 
        :description="trans('impostazioni.header.settings_description')" 
      />

      <!-- Filters -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
        <div class="flex gap-4">
          <input
            v-model="searchTerm"
            type="text"
            :placeholder="trans('impostazioni.placeholder.search_settings')"
            class="h-9 w-40 lg:w-64 rounded border px-2"
          />
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <Item
          v-for="app in filteredApps"
          :key="app.name"
          variant="outline"
        >
          <ItemMedia variant="icon">
            <div class="flex h-8 w-13 items-center justify-center rounded-lg bg-gray-100">
              <component :is="app.logo" class="h-5 w-5 text-gray-700" />
            </div>
          </ItemMedia>
          
          <ItemContent>
            <ItemTitle>{{ trans(app.name) }}</ItemTitle>
            <ItemDescription>
              {{ trans(app.desc) }}
            </ItemDescription>
          </ItemContent>
          
          <ItemActions>
            <Button as-child variant="outline" size="sm">
              <Link :href="app.href">
                {{ trans('impostazioni.label.manage') }}
              </Link>
            </Button>
          </ItemActions>
        </Item>
      </div>
    </div>
  </AppLayout>
</template>

