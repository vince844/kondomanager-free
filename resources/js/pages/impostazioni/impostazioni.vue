<script setup lang="ts">

import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue'
import Heading from '@/components/Heading.vue'
import type { BreadcrumbItem } from '@/types'
import { ref, computed } from 'vue'
import { Users, Settings, DatabaseBackup } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'

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
    desc: "Impostazioni generali di Kondomanager",
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
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex gap-4">
          <input
            v-model="searchTerm"
            type="text"
            placeholder="Filtra impostazioni..."
            class="h-9 w-40 lg:w-64 rounded border px-2"
          />
        </div>
      </div>

      <!-- App Grid -->
      <ul class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 pt-4">
        <li
          v-for="app in filteredApps"
          :key="app.name"
          class="rounded-lg border p-4 hover:shadow-md"
        >
          <div class="mb-6 flex items-center justify-between">
            <div class="bg-gray-100 flex size-10 items-center justify-center rounded-lg p-2">
              <component :is="app.logo" class="text-black w-5 h-5" />
            </div>

            <Button as-child variant="outline" size="sm">
              <Link :href="app.href">
                Gestisci
              </Link>
            </Button>
          </div>

          <div>
            <h2 class="font-semibold mb-1">{{ app.name }}</h2>
            <p class="text-gray-500 text-sm">{{ app.desc }}</p>
          </div>
        </li>
      </ul>
    </div>
  </AppLayout>
</template>

