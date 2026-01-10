<script setup lang="ts">

import { computed, watch, onMounted, ref, nextTick } from 'vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Settings } from 'lucide-vue-next'
import Heading from '@/components/Heading.vue'
import Alert from '@/components/Alert.vue'
import { trans, wTrans, isLoaded, loadLanguageAsync } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'
import type { GeneralSettings } from '@/types/GeneralSettings'

/* -------------------------------------------------
 * Types
 * ------------------------------------------------- */
type SupportedLanguage = 'it' | 'en' | 'pt'

/* -------------------------------------------------
 * Page / State
 * ------------------------------------------------- */
const page = usePage<GeneralSettings>()
const flashMessage = computed(() => page.props.flash?.message)
const translationsLoaded = ref(false)

/* -------------------------------------------------
 * Breadcrumbs
 * ------------------------------------------------- */
const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'Generali', href: '/impostazioni/generali' },
]

/* -------------------------------------------------
 * i18n labels
 * ------------------------------------------------- */
const languageLabels = computed<
  Record<SupportedLanguage, ReturnType<typeof wTrans>>
>(() => ({
  it: wTrans('impostazioni.placeholder.language.it'),
  en: wTrans('impostazioni.placeholder.language.en'),
  pt: wTrans('impostazioni.placeholder.language.pt'),
}))

/* -------------------------------------------------
 * Props da Inertia
 * ------------------------------------------------- */
const {
  can_register,
  language,
  open_condominio_on_login,
  default_condominio_id,
  condomini,
} = page.props

/* -------------------------------------------------
 * Form
 * ------------------------------------------------- */
const form = useForm({
  user_frontend_registration: Boolean(can_register),
  language: (language || 'it') as SupportedLanguage,
  open_condominio_on_login: Boolean(Number(open_condominio_on_login)),
  default_condominio_id: default_condominio_id
    ? String(default_condominio_id)
    : '',
})

/* -------------------------------------------------
 * Selected language label (SOLUZIONE)
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
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head :title="trans('impostazioni.header.general_settings_title')" />

    <div class="px-4 py-6">
      <Heading
        :title="trans('impostazioni.header.general_settings_title')"
        :description="
          trans('impostazioni.header.general_settings_description')
        "
      />

      <div v-if="flashMessage" class="py-2">
        <Alert
          :message="flashMessage.message"
          :type="flashMessage.type"
        />
      </div>

      <form @submit.prevent="submit">
        <Card class="border shadow-none p-4">
          <div
            class="flex flex-col w-full sm:flex-row sm:justify-end mb-4"
          >
            <Link
              as="button"
              href="/impostazioni"
              class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90"
            >
              <Settings class="w-4 h-4" />
              <span>{{ trans('impostazioni.label.settings') }}</span>
            </Link>
          </div>

          <CardContent class="space-y-4 p-0">
            <!-- LANGUAGE -->
            <div
              class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4"
            >
              <div class="flex-1">
                <label
                  class="block text-sm font-medium leading-none mb-1"
                >
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
                      <SelectItem value="it">
                        {{ languageLabels.it.value }}
                      </SelectItem>
                      <SelectItem value="en">
                        {{ languageLabels.en.value }}
                      </SelectItem>
                      <SelectItem value="pt">
                        {{ languageLabels.pt.value }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div
                  v-else
                  class="w-full h-10 bg-muted/50 rounded-md animate-pulse"
                />
              </div>
            </div>

            <!-- OPEN CONDOMINIO -->
            <div
              class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4"
            >
              <div class="flex-1">
                <label
                  class="block text-sm font-medium leading-none mb-1"
                >
                  {{ trans('impostazioni.dialogs.default_building_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.default_building_description') }}
                </p>
              </div>

              <Switch v-model="form.open_condominio_on_login" />
            </div>

            <!-- DEFAULT CONDOMINIO -->
            <div
              v-if="form.open_condominio_on_login"
              class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4"
            >
              <div class="flex-1">
                <label
                  class="block text-sm font-medium leading-none mb-1"
                >
                  {{ trans('impostazioni.dialogs.select_building_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.select_building_description') }}
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <Select v-model="form.default_condominio_id">
                  <SelectTrigger
                    class="w-full"
                    :class="{
                      'border-red-500':
                        form.errors.default_condominio_id,
                    }"
                  >
                    <SelectValue
                      :placeholder="trans('impostazioni.placeholder.select_building')"
                    />
                  </SelectTrigger>

                  <SelectContent>
                    <SelectItem
                      v-for="c in condomini"
                      :key="c.id"
                      :value="String(c.id)"
                    >
                      {{ c.nome }}
                    </SelectItem>
                  </SelectContent>
                </Select>

                <p
                  v-if="form.errors.default_condominio_id"
                  class="text-sm text-red-500 mt-1"
                >
                  {{ form.errors.default_condominio_id }}
                </p>
              </div>
            </div>

            <!-- REGISTRATION -->
            <div
              class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4"
            >
              <div class="flex-1">
                <label
                  class="block text-sm font-medium leading-none mb-1"
                >
                  {{ trans('impostazioni.dialogs.user_registration_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.user_registration_description') }}
                </p>
              </div>

              <Switch
                v-model="form.user_frontend_registration"
              />
            </div>
          </CardContent>

          <CardFooter
            class="px-0 pt-6 flex items-center gap-4"
          >
            <Button :disabled="form.processing">
              <span
                v-if="form.processing"
                class="animate-spin inline-block h-4 w-4 border-2 border-current rounded-full border-t-transparent mr-2"
              />
              {{ trans('impostazioni.actions.save_settings') }}
            </Button>
          </CardFooter>
        </Card>
      </form>
    </div>
  </AppLayout>
</template>
