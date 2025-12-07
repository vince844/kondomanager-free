<script setup lang="ts">

import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue'
import Heading from '@/components/Heading.vue'
import type { BreadcrumbItem } from '@/types'
import { ref, computed } from 'vue'
import { Users, Settings, DatabaseBackup } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Item, ItemActions, ItemContent, ItemDescription, ItemMedia, ItemTitle } from '@/components/ui/item'

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Impostazioni',
    href: '/impostazioni',
  },
]

const apps = [
  {
    name: "Impostazioni generali",
    logo: Settings,
    desc: "Impostazioni generali di configurazione dell'applicazione",
    href: "/impostazioni/generali", 
  },
  {
    name: "Gestione utenti",
    logo: Users,
    desc: "Impostazioni di gestione degli utenti, ruoli e permessi",
    href: "/utenti",
  },
  {
    name: "Gestione backups",
    logo: DatabaseBackup,
    desc: "Impostazioni di gestione dei backups",
    href: "#",
  }
]

const searchTerm = ref("")

const filteredApps = computed(() => {
  return apps.filter((app) =>
    app.name.toLowerCase().includes(searchTerm.value.toLowerCase())
  )
})
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head title="Impostazioni" />

    <div class="px-4 py-6">
      <Heading
        title="Impostazioni applicazione"
        description="Di seguito un elenco di tutte le impostazioni configurabili per l'applicazione"
      />

      <!-- Filters -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
        <div class="flex gap-4">
          <input
            v-model="searchTerm"
            type="text"
            placeholder="Filtra impostazioni..."
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
            <ItemTitle>{{ app.name }}</ItemTitle>
            <ItemDescription>
              {{ app.desc }}
            </ItemDescription>
          </ItemContent>
          
          <ItemActions>
            <Button as-child variant="outline" size="sm">
              <Link :href="app.href">
                Gestisci
              </Link>
            </Button>
          </ItemActions>
        </Item>
      </div>
    </div>
  </AppLayout>
</template>

