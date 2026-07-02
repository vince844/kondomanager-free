<script setup lang="ts">
import { computed, watch, onMounted, ref, nextTick } from 'vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Settings, Globe, Building2, ShieldCheck } from 'lucide-vue-next'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import Alert from '@/components/Alert.vue'
import InputError from '@/components/InputError.vue'
import { trans, wTrans, isLoaded, loadLanguageAsync } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'
import type { GeneralSettings } from '@/types/GeneralSettings'

/* -------------------------------------------------
 * Types
 * ------------------------------------------------- */
type SupportedLanguage = 'it' | 'en' | 'pt' | 'es'

/* -------------------------------------------------
 * Page / State
 * ------------------------------------------------- */
const page = usePage<GeneralSettings>()
const flashMessage = computed(() => page.props.flash?.message)
const translationsLoaded = ref(false)

/* -------------------------------------------------
 * Breadcrumbs & Guides
 * ------------------------------------------------- */
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
  { title: trans('impostazioni.header.general_settings_title'), href: '/impostazioni/generali' },
])

const pageGuides = computed(() => [
  {
    title: trans('impostazioni.guides.language_title'),
    description: trans('impostazioni.guides.language_desc'),
    icon: Globe,
    colorVariant: 'blue' as const
  },
  {
    title: trans('impostazioni.guides.access_title'),
    description: trans('impostazioni.guides.access_desc'),
    icon: Building2,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('impostazioni.guides.security_title'),
    description: trans('impostazioni.guides.security_desc'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const
  }
])

/* -------------------------------------------------
 * i18n labels
 * ------------------------------------------------- */
const languageLabels = computed<
  Record<SupportedLanguage, ReturnType<typeof wTrans>>
>(() => ({
  it: wTrans('impostazioni.placeholder.language.it'),
  en: wTrans('impostazioni.placeholder.language.en'),
  pt: wTrans('impostazioni.placeholder.language.pt'),
  es: wTrans('impostazioni.placeholder.language.es'),
}))

/* -------------------------------------------------
 * Props da Inertia
 * ------------------------------------------------- */
const {
  can_register,
  language,
  app_name,
  open_condominio_on_login,
  default_condominio_id,
  condomini,
  default_user_role,
  roles,
} = page.props

/* -------------------------------------------------
 * Form
 * ------------------------------------------------- */
const form = useForm({
  user_frontend_registration: Boolean(can_register),
  language: (language || 'it') as SupportedLanguage,
  app_name: app_name || 'Kondomanager',
  open_condominio_on_login: Boolean(Number(open_condominio_on_login)),
  default_condominio_id: default_condominio_id ? String(default_condominio_id) : '',
  default_user_role: default_user_role || 'utenti', 
  force_comment_moderation: Boolean(page.props.force_comment_moderation),
})

/* -------------------------------------------------
 * Selected language label
 * ------------------------------------------------- */
const selectedLanguageLabel = computed(() => {
  return languageLabels.value[form.language].value
})

/* -------------------------------------------------
 * Lifecycle
 * ------------------------------------------------- */
onMounted(async () => {
  if (!isLoaded()) {
    await loadLanguageAsync(form.language)
  }
  await nextTick()
  translationsLoaded.value = true
})

/* -------------------------------------------------
 * Watchers
 * ------------------------------------------------- */
watch(
  () => form.default_condominio_id,
  (newValue) => {
    if (newValue) {
      form.clearErrors('default_condominio_id')
    }
  }
)

/* -------------------------------------------------
 * Submit
 * ------------------------------------------------- */
const submit = () => {
  form.post(route('impostazioni.generali.store'), {
    preserveScroll: true,
  })
}
</script>

<template>
  <AppLayout :breadcrumbs="[]">
    <Head :title="trans('impostazioni.header.general_settings_title')" />

    <div class="px-4 py-6 space-y-6">
      <PageHeaderGuide
        :page-title="trans('impostazioni.header.general_settings_title')"
        :page-subtitle="trans('impostazioni.header.general_settings_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        back-url="/impostazioni"
        :back-text="trans('impostazioni.label.settings')"
        :video-url="null"
      />

      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <form @submit.prevent="submit">
        <Card class="border shadow-none p-4 mt-6">
          <CardContent class="space-y-4 p-0">
            <!-- LANGUAGE -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.language_settings_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.language_settings_description') }}
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <div v-if="translationsLoaded">
                  <Select v-model="form.language">
                    <SelectTrigger class="w-full">
                      <SelectValue>
                        {{ selectedLanguageLabel }}
                      </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="it">{{ languageLabels.it.value }}</SelectItem>
                      <SelectItem value="en">{{ languageLabels.en.value }}</SelectItem>
                      <SelectItem value="pt">{{ languageLabels.pt.value }}</SelectItem>
                      <SelectItem value="es">{{ languageLabels.es.value }}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div v-else class="w-full h-10 bg-muted/50 rounded-md animate-pulse" />
              </div>
            </div>

            <!-- APP NAME -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.app_name_settings_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.app_name_settings_description') }}
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <Input v-model="form.app_name" type="text" maxlength="255"
                  :placeholder="trans('impostazioni.placeholder.app_name')"
                  class="w-full text-sm" />
                <InputError :message="form.errors.app_name" />
              </div>
            </div>

            <!-- OPEN CONDOMINIO -->
            <div class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.default_building_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.default_building_description') }}
                </p>
              </div>
              <Switch v-model="form.open_condominio_on_login" />
            </div>

            <!-- DEFAULT CONDOMINIO -->
            <div v-if="form.open_condominio_on_login" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.select_building_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.select_building_description') }}
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <Select v-model="form.default_condominio_id">
                  <SelectTrigger class="w-full" :class="{ 'border-red-500': form.errors.default_condominio_id }">
                    <SelectValue :placeholder="trans('impostazioni.placeholder.select_building')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="c in condomini" :key="c.id" :value="String(c.id)">
                      {{ c.nome }}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p v-if="form.errors.default_condominio_id" class="text-sm text-red-500 mt-1">
                  {{ form.errors.default_condominio_id }}
                </p>
              </div>
            </div>

            <!-- REGISTRATION -->
            <div class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.user_registration_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.user_registration_description') }}
                </p>
              </div>
              <Switch v-model="form.user_frontend_registration" />
            </div>

            <!-- DEFAULT USER ROLE (NUOVO BLOCCO) -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.default_role_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.default_role_description') }}
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <Select v-model="form.default_user_role">
                  <SelectTrigger class="w-full" :class="{ 'border-red-500': form.errors.default_user_role }">
                    <SelectValue :placeholder="trans('impostazioni.placeholder.select_role')" />
                  </SelectTrigger>
                  <SelectContent>
                    <!-- Itera sui ruoli passati dal backend -->
                    <SelectItem v-for="role in roles" :key="role.id" :value="role.name">
                      <!-- Trasforma la prima lettera in maiuscolo o traduci se preferisci -->
                      <span class="capitalize">{{ role.name }}</span>
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p v-if="form.errors.default_user_role" class="text-sm text-red-500 mt-1">
                  {{ form.errors.default_user_role }}
                </p>
              </div>
            </div>

            <!-- MODERAZIONE COMMENTI -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.force_comment_moderation_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.force_comment_moderation_description') }}
                </p>
              </div>
              <Switch v-model="form.force_comment_moderation" />
            </div>

          </CardContent>

          <CardFooter class="flex items-center justify-end gap-4 border-t px-6 py-4 bg-slate-50/50 dark:bg-slate-900/20 rounded-b-xl mt-6">
            <Button :disabled="form.processing">
              <span v-if="form.processing" class="animate-spin inline-block h-4 w-4 border-2 border-current rounded-full border-t-transparent mr-2" />
              {{ trans('impostazioni.actions.save_settings') }}
            </Button>
          </CardFooter>
        </Card>
      </form>
    </div>
  </AppLayout>
</template>