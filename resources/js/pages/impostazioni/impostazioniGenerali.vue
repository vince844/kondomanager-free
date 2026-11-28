<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, watch, onMounted, ref, nextTick } from 'vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import { router } from '@inertiajs/core'
import AppLayout from '@/layouts/AppLayout.vue'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import vSelect from 'vue-select'
import 'vue-select/dist/vue-select.css'
import { Input } from '@/components/ui/input'
import { Globe, ShieldCheck, MessagesSquare } from 'lucide-vue-next'
import ImpostazioniGeneraliGuide from '@/components/guides/ImpostazioniGeneraliGuide.vue'
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
  { title: trans('impostazioni.guides.program_access_title'), description: trans('impostazioni.guides.program_access_desc'), icon: Globe, colorVariant: 'blue' as const },
  { title: trans('impostazioni.guides.reports_communications_title'), description: trans('impostazioni.guides.reports_communications_desc'), icon: MessagesSquare, colorVariant: 'emerald' as const },
  { title: trans('impostazioni.guides.security_title'), description: trans('impostazioni.guides.security_desc'), icon: ShieldCheck, colorVariant: 'amber' as const }
])

const mostraGuida = ref(false)

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
  default_per_page,
  per_page_disponibili,
  ruolo_registrazione_modificabile,
} = page.props

/**
 * Il ruolo predefinito dei nuovi utenti lo cambia solo un amministratore (Coda 258, decisione 134.1): a chi
 * non lo è la tendina si mostra senza farsi cambiare, con la nota che dice perché, e il modulo lo rimanda
 * com'era.
 */
const ruoloRegistrazioneBloccato = ruolo_registrazione_modificabile !== true

/* -------------------------------------------------
 * Form
 * ------------------------------------------------- */
const form = useForm({
  user_frontend_registration: Boolean(can_register),
  language: (language || 'it') as SupportedLanguage,
  app_name: app_name || 'KondoManager',
  open_condominio_on_login: Boolean(Number(open_condominio_on_login)),
  default_condominio_id: default_condominio_id ? String(default_condominio_id) : '',
  default_user_role: default_user_role || 'utenti', 
  commenti_segnalazioni_moderati_di_serie: page.props.commenti_segnalazioni_moderati_di_serie !== undefined
    ? Boolean(page.props.commenti_segnalazioni_moderati_di_serie)
    : false,
  commenti_comunicazioni_moderati_di_serie: page.props.commenti_comunicazioni_moderati_di_serie !== undefined
    ? Boolean(page.props.commenti_comunicazioni_moderati_di_serie)
    : true,
  allegati_moderati_di_serie: page.props.allegati_moderati_di_serie !== undefined
    ? Boolean(page.props.allegati_moderati_di_serie)
    : true,
  allegati_massimo_per_elemento: (page.props as Record<string, unknown>).allegati_massimo_per_elemento !== undefined
    ? Number((page.props as Record<string, unknown>).allegati_massimo_per_elemento)
    : 5,
  segnalazioni_assegnazione_visibile_nel_portale: (page.props as Record<string, unknown>).segnalazioni_assegnazione_visibile_nel_portale !== undefined
    ? Boolean(Number((page.props as Record<string, unknown>).segnalazioni_assegnazione_visibile_nel_portale))
    : false,
  segnalazioni_assegnazione_porta_in_lavorazione: (page.props as Record<string, unknown>).segnalazioni_assegnazione_porta_in_lavorazione !== undefined
    ? Boolean(Number((page.props as Record<string, unknown>).segnalazioni_assegnazione_porta_in_lavorazione))
    : false,
  comunicazioni_registra_aperture: Boolean(
    typeof (page.props as Record<string, unknown>).comunicazioni_registra_aperture === 'string'
      ? (page.props as Record<string, unknown>).comunicazioni_registra_aperture === '1' || (page.props as Record<string, unknown>).comunicazioni_registra_aperture === 'true'
      : (page.props as Record<string, unknown>).comunicazioni_registra_aperture
  ),
  // Coda 788: acceso di serie; senza il valore resta acceso.
  errori_avviso_dalla_pagina: (page.props as Record<string, unknown>).errori_avviso_dalla_pagina === undefined
    ? true
    : Boolean(
      typeof (page.props as Record<string, unknown>).errori_avviso_dalla_pagina === 'string'
        ? (page.props as Record<string, unknown>).errori_avviso_dalla_pagina === '1' || (page.props as Record<string, unknown>).errori_avviso_dalla_pagina === 'true'
        : (page.props as Record<string, unknown>).errori_avviso_dalla_pagina
    ),
  // Spenta di serie: senza il valore resta spenta.
  due_fattori_obbligatoria_pannello: Boolean(
    typeof (page.props as Record<string, unknown>).due_fattori_obbligatoria_pannello === 'string'
      ? (page.props as Record<string, unknown>).due_fattori_obbligatoria_pannello === '1' || (page.props as Record<string, unknown>).due_fattori_obbligatoria_pannello === 'true'
      : (page.props as Record<string, unknown>).due_fattori_obbligatoria_pannello
  ),
  default_per_page: Number(default_per_page) || 10,
})

const confermaCancellazioneAperture = ref(false)
const countAperture = computed(() => Number((page.props as Record<string, unknown>).aperture_registrate ?? 0))

function chiediConfermaCancellazione() {
  confermaCancellazioneAperture.value = true
}

function annullaCancellazione() {
  confermaCancellazioneAperture.value = false
}

function eseguiCancellazioneAperture() {
  router.delete(route('impostazioni.generali.aperture.destroy'), {
    preserveScroll: true,
    onSuccess: () => {
      confermaCancellazioneAperture.value = false
    },
  })
}

/* -------------------------------------------------
 * Le scelte dei menu
 * ------------------------------------------------- */
const opzioniLingua = computed(() => (['it', 'en', 'pt', 'es'] as SupportedLanguage[]).map((valore) => ({ valore, nome: languageLabels.value[valore].value })))
const opzioniCondominio = computed(() => (condomini ?? []).map((c) => ({ id: String(c.id), nome: c.nome })))
const opzioniRuolo = computed(() => (roles ?? []).map((r) => ({ nome: r.name })))

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
        :storia="{ tipo: 'impostazioni_generali' }"
        :page-title="trans('impostazioni.header.general_settings_title')"
        :page-subtitle="trans('impostazioni.header.general_settings_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        back-url="/impostazioni"
        :back-text="trans('impostazioni.label.settings')"
        :video-url="null"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="mostraGuida = true"
      />
      <ImpostazioniGeneraliGuide v-model:open="mostraGuida" />

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
                <div v-if="translationsLoaded" class="menu-campo menu-impostazioni">
                  <v-select v-model="form.language" :options="opzioniLingua" label="nome" :reduce="(o: { valore: SupportedLanguage }) => o.valore"
                    :clearable="false" :searchable="false" class="w-full bg-white text-sm dark:bg-slate-950" />
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
                <div class="menu-campo menu-impostazioni" :class="{ 'menu-errore': form.errors.default_condominio_id }">
                  <v-select v-model="form.default_condominio_id" :options="opzioniCondominio" label="nome" :reduce="(o: { id: string }) => o.id"
                    :clearable="false" :placeholder="trans('impostazioni.placeholder.select_building')" class="w-full bg-white text-sm dark:bg-slate-950">
                    <template #no-options>{{ trans('impostazioni.placeholder.no_building') }}</template>
                  </v-select>
                </div>
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
                <div class="menu-campo menu-impostazioni" :class="{ 'menu-errore': form.errors.default_user_role }">
                  <v-select v-model="form.default_user_role" :options="opzioniRuolo" label="nome" :reduce="(o: { nome: string }) => o.nome"
                    :disabled="ruoloRegistrazioneBloccato" :clearable="false" :searchable="false"
                    :placeholder="trans('impostazioni.placeholder.select_role')" class="w-full bg-white text-sm dark:bg-slate-950">
                    <!-- Nel menu l'iniziale maiuscola; il valore scelto si legge com'è scritto. -->
                    <template #option="o"><span class="capitalize">{{ o.nome }}</span></template>
                  </v-select>
                </div>
                <p v-if="form.errors.default_user_role" class="text-sm text-red-500 mt-1">
                  {{ form.errors.default_user_role }}
                </p>
                <p v-else-if="ruoloRegistrazioneBloccato" data-test="ruolo-registrazione-bloccato" class="text-xs text-muted-foreground mt-1">
                  {{ trans('impostazioni.ruolo_registrazione.solo_amministratore') }}
                </p>
              </div>
            </div>

            <!-- RIGHE PER PAGINA -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.default_per_page_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.default_per_page_description') }}
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <div class="menu-campo menu-impostazioni" :class="{ 'menu-errore': form.errors.default_per_page }">
                  <v-select v-model="form.default_per_page" :options="per_page_disponibili" :clearable="false" :searchable="false"
                    :placeholder="`${form.default_per_page}`" class="w-full bg-white text-sm tabular-nums dark:bg-slate-950" />
                </div>
                <p v-if="form.errors.default_per_page" class="text-sm text-red-500 mt-1">
                  {{ form.errors.default_per_page }}
                </p>
              </div>
            </div>

            <!-- MODERAZIONE COMMENTI SEGNALAZIONI DI SERIE -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.commenti_segnalazioni_moderati_di_serie_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.commenti_segnalazioni_moderati_di_serie_description') }}
                </p>
              </div>
              <Switch v-model="form.commenti_segnalazioni_moderati_di_serie" />
            </div>

            <!-- MODERAZIONE COMMENTI COMUNICAZIONI DI SERIE -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.commenti_comunicazioni_moderati_di_serie_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.commenti_comunicazioni_moderati_di_serie_description') }}
                </p>
              </div>
              <Switch v-model="form.commenti_comunicazioni_moderati_di_serie" />
            </div>

            <!-- MODERAZIONE ALLEGATI DI SERIE -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.allegati_moderati_di_serie_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.allegati_moderati_di_serie_description') }}
                </p>
              </div>
              <Switch v-model="form.allegati_moderati_di_serie" />
            </div>

            <!-- NUMERO MASSIMO ALLEGATI PER ELEMENTO -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.allegati_massimo_per_elemento_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.allegati_massimo_per_elemento_description') }}
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <Input
                  v-model.number="form.allegati_massimo_per_elemento"
                  type="number"
                  min="1"
                  max="50"
                  class="w-full text-sm tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                />
                <InputError :message="form.errors.allegati_massimo_per_elemento" />
              </div>
            </div>

            <!-- VISIBILITA ASSEGNAZIONE SEGNALAZIONI NEL PORTALE -->
            <div data-impostazione="segnalazioni_assegnazione_visibile_nel_portale" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.segnalazioni_assegnazione_visibile_nel_portale_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.segnalazioni_assegnazione_visibile_nel_portale_description') }}
                </p>
              </div>
              <Switch v-model="form.segnalazioni_assegnazione_visibile_nel_portale" />
            </div>

            <!-- ASSEGNAZIONE SEGNALAZIONE PORTA IN LAVORAZIONE -->
            <div data-impostazione="segnalazioni_assegnazione_porta_in_lavorazione" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.segnalazioni_assegnazione_porta_in_lavorazione_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.segnalazioni_assegnazione_porta_in_lavorazione_description') }}
                </p>
              </div>
              <Switch v-model="form.segnalazioni_assegnazione_porta_in_lavorazione" />
            </div>

            <!-- REGISTRAZIONE APERTURE COMUNICAZIONI NEL PORTALE -->
            <div data-impostazione="comunicazioni_registra_aperture" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1 space-y-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.comunicazioni_registra_aperture_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.comunicazioni_registra_aperture_description') }}
                </p>
                <p data-nota="privacy-aperture" class="text-xs text-amber-700 dark:text-amber-400 pt-1">
                  {{ trans('impostazioni.dialogs.comunicazioni_registra_aperture_privacy') }}
                </p>
              </div>
              <Switch v-model="form.comunicazioni_registra_aperture" />
            </div>

            <!-- APERTURE REGISTRATE E CANCELLAZIONE -->
            <div data-riquadro="aperture-registrate" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1 space-y-1">
                <div class="text-sm font-medium leading-none tabular-nums">
                  {{ trans('impostazioni.dialogs.comunicazioni_aperture_registrate', { count: countAperture }) }}
                </div>
                <p v-if="confermaCancellazioneAperture" class="text-xs text-red-600 dark:text-red-400 pt-1">
                  {{ trans('impostazioni.dialogs.comunicazioni_cancella_aperture_conferma') }}
                </p>
              </div>

              <div class="flex items-center gap-2">
                <template v-if="!confermaCancellazioneAperture">
                  <Button
                    type="button"
                    variant="outline"
                    data-azione="cancella-aperture"
                    :disabled="countAperture === 0"
                    @click="chiediConfermaCancellazione"
                    class="min-h-10 text-xs"
                  >
                    {{ trans('impostazioni.dialogs.comunicazioni_cancella_aperture') }}
                  </Button>
                </template>
                <template v-else>
                  <Button
                    type="button"
                    variant="destructive"
                    data-azione="conferma-cancella-aperture"
                    @click="eseguiCancellazioneAperture"
                    class="min-h-10 text-xs"
                  >
                    {{ trans('impostazioni.dialogs.comunicazioni_cancella_aperture_si') }}
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    data-azione="annulla-cancella-aperture"
                    @click="annullaCancellazione"
                    class="min-h-10 text-xs"
                  >
                    {{ trans('impostazioni.dialogs.comunicazioni_cancella_aperture_no') }}
                  </Button>
                </template>
              </div>
            </div>

            <!-- AVVISO DALLA PAGINA D'ERRORE (Coda 788, decisione 713) -->
            <div data-impostazione="errori_avviso_dalla_pagina" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1 space-y-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.errori_avviso_dalla_pagina_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.errori_avviso_dalla_pagina_description') }}
                </p>
              </div>
              <Switch v-model="form.errori_avviso_dalla_pagina" />
            </div>

            <!-- VERIFICA IN DUE PASSAGGI OBBLIGATORIA PER IL PANNELLO -->
            <div data-impostazione="due_fattori_obbligatoria_pannello" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1 space-y-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  {{ trans('impostazioni.dialogs.due_fattori_obbligatoria_pannello_title') }}
                </label>
                <p class="text-sm text-muted-foreground">
                  {{ trans('impostazioni.dialogs.due_fattori_obbligatoria_pannello_description') }}
                </p>
              </div>
              <Switch v-model="form.due_fattori_obbligatoria_pannello" />
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

<style scoped>
/* I menu alti come i campi accanto (come in quelli della posta), con il bordo rosso se il campo ha un errore. */
.menu-impostazioni :deep(.vs__dropdown-toggle) {
  min-height: 2.25rem;
}
.menu-errore :deep(.vs__dropdown-toggle) {
  border-color: rgb(239 68 68);
}
</style>
