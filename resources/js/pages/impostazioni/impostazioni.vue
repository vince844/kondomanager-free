<script setup lang="ts">

import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue'
import Heading from '@/components/Heading.vue'
import { ref, computed } from 'vue'
import { Users, Settings, DatabaseBackup, RefreshCw, Timer, Mail, Activity, Printer } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Item, ItemActions, ItemContent, ItemDescription, ItemMedia, ItemTitle } from '@/components/ui/item'
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types'

interface SystemUpdateData {
  available?: boolean;
  new_version?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Impostazioni',
    href: '/impostazioni',
  },
]

const page = usePage()

const updateAvailable = computed(() => {
  const updateInfo = page.props.system_update as SystemUpdateData | undefined;
  return updateInfo?.available || false;
})

const newVersion = computed(() => {
  const updateInfo = page.props.system_update as SystemUpdateData | undefined;
  return updateInfo?.new_version || '';
})

const searchTerm = ref("")

const normalize = (value: string) => value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')

const filteredApps = computed(() => {
  const term = normalize(searchTerm.value)

  return apps.value.filter(app => {
    const name = normalize(trans(app.name))
    const desc = app.highlight ? normalize(app.desc) : normalize(trans(app.desc)) 

    return name.includes(term) || desc.includes(term)
  })
})

const apps = computed(() => [
  {
    name: 'impostazioni.dialogs.general_settings_title',
    logo: Settings,
    desc: 'impostazioni.dialogs.general_settings_description',
    href: "/impostazioni/generali", 
  },
  {
    name: 'impostazioni.dialogs.print_settings_title',
    logo: Printer,
    desc: 'impostazioni.dialogs.print_settings_description',
    href: "/impostazioni/stampe",
  },
  {
    name: 'impostazioni.dialogs.users_settings_title',
    logo: Users,
    desc: 'impostazioni.dialogs.users_settings_description',
    href: "/utenti",
  },
  {
    name: 'impostazioni.dialogs.mail_settings_title', 
    logo: Mail,
    desc: 'impostazioni.dialogs.mail_settings_description',
    href: "/impostazioni/mail", 
  },
  {
    name: 'impostazioni.dialogs.cron_settings_title', 
    logo: Timer,
    desc: 'impostazioni.dialogs.cron_settings_description',
    href: "/impostazioni/cron", 
  },
  {
    name: 'impostazioni.dialogs.logs_settings_title',
    logo: Activity,
    desc: 'impostazioni.dialogs.logs_settings_description',
    href: "/logs", 
  },
  {
    name: 'impostazioni.dialogs.backups_settings_title',
    logo: DatabaseBackup,
    desc: 'impostazioni.dialogs.backups_settings_description',
    href: "#",
    comingSoon: true, 
  },
  {
    name: 'impostazioni.dialogs.updates_title',
    logo: RefreshCw,
    desc: updateAvailable.value 
        ? trans('impostazioni.dialogs.updates_desc_available', { version: newVersion.value })
        : trans('impostazioni.dialogs.updates_desc_latest'),
    href: '/system/upgrade',
    highlight: updateAvailable.value, 
  }
])

</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head :title="trans('impostazioni.header.settings_head')" />

    <div class="px-4 py-6">
      <Heading
        :title="trans('impostazioni.header.settings_title')" 
        :description="trans('impostazioni.header.settings_description')" 
      />

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
          :class="{ 'border-orange-400 bg-orange-50/50 dark:bg-orange-950/20': app.highlight }"
        >
          <ItemMedia variant="icon">
            <div 
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg" 
              :class="app.highlight ? 'bg-orange-100 text-orange-600 dark:bg-orange-900 dark:text-orange-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'"
            >
              <component 
                :is="app.logo" 
                class="h-5 w-5" 
                :class="{ 'animate-spin-slow': app.highlight }"
              />
            </div>
          </ItemMedia>
          
          <ItemContent>
            <ItemTitle class="flex items-center gap-2">
              {{ trans(app.name) }}
              <span 
                v-if="app.comingSoon" 
                class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30"
              >
                In arrivo
              </span>
            </ItemTitle>
            <ItemDescription class="text-[13px] leading-tight mt-0.5">
              {{ app.highlight ? app.desc : trans(app.desc) }}
            </ItemDescription>
          </ItemContent>
          
          <ItemActions>
            <Button 
              v-if="app.comingSoon"
              disabled
              variant="outline" 
              size="sm"
              class="h-8 text-xs font-semibold opacity-50 cursor-not-allowed"
            >
              Prossimamente
            </Button>

            <Button 
              v-else
              as-child 
              :variant="app.highlight ? 'default' : 'outline'" 
              size="sm"
              :class="[
                'h-8 text-xs font-semibold transition-colors duration-200 relative z-20',
                app.highlight 
                  ? 'bg-orange-600 hover:bg-orange-700 text-white border-transparent' 
                  : 'hover:bg-slate-900 hover:text-white dark:hover:bg-slate-100 dark:hover:text-slate-900'
              ]"
            >
              <Link :href="app.href">
                {{ app.highlight ? trans('impostazioni.label.update_now') : trans('impostazioni.label.manage') }}
              </Link>
            </Button>
          </ItemActions>
        </Item>
      </div>
    </div>
  </AppLayout>
</template>

<style scoped>
.animate-spin-slow { 
    animation: spin 3s linear infinite; 
}

@keyframes spin { 
    from { transform: rotate(0deg); } 
    to { transform: rotate(360deg); } 
}
</style>