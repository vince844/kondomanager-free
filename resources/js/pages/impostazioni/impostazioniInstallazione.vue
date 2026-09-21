<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Building2, HardDrive, ToggleLeft, ExternalLink } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'

/*
 * «Questa installazione» (1.11.0-beta.33): i fatti dell'installazione in tre righe. I numeri
 * arrivano dal server già decisi: la riga dello spazio compare solo se c'è un tetto, il
 * collegamento «Gestisci il tuo piano» solo se chi ospita ha dato un indirizzo. Le traduzioni
 * si leggono nel template, mai in una costante al livello del modulo (TraduzioniNonSiCongelano).
 */
interface Props {
  versione: string
  condomini: number
  condomini_dimostrativi: number
  limite_condomini: number
  spazio_usato_byte: number | null
  limite_spazio_byte: number | null
  funzioni: Record<string, boolean>
  documenti_stato: 'esterni' | 'su_volume' | 'effimeri'
  gestione_piano_url: string | null
}

const props = defineProps<Props>()
const page = usePage()
const locale = computed(() => (page.props.locale as string) || 'it')

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
  { title: trans('impostazioni.header.installazione_title'), href: '/impostazioni/installazione' },
])

const formattaByte = (byte: number): string => {
  const gb = byte / (1024 * 1024 * 1024)
  if (gb >= 1) return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(gb) + ' GB'
  const mb = byte / (1024 * 1024)
  return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(mb) + ' MB'
}

const percentuale = (usato: number, limite: number): number => Math.min(100, Math.round((usato / limite) * 100))

const funzioniOrdinate = computed(() => [
  'aggiornamenti_in_app',
  'pianificatore_esterno',
  'backup',
  'archiviazione_esterna',
].map((chiave) => ({ chiave, attiva: props.funzioni[chiave] === true })))
</script>

<template>
  <AppLayout :breadcrumbs="[]">
    <Head :title="trans('impostazioni.header.installazione_title')" />

    <div class="px-4 py-6 space-y-6">
      <PageHeaderGuide
        :page-title="trans('impostazioni.header.installazione_title')"
        :page-subtitle="trans('impostazioni.header.installazione_description')"
        :guides="[]"
        :breadcrumbs="breadcrumbs"
        back-url="/impostazioni"
        :back-text="trans('impostazioni.label.settings')"
        :video-url="null"
      />

      <Card class="border shadow-none p-4">
        <CardContent class="space-y-4 p-0">
          <!-- CONDOMINI -->
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
            <div class="flex items-start gap-3">
              <Building2 class="h-5 w-5 mt-0.5 text-muted-foreground" />
              <div>
                <p class="text-sm font-medium leading-none mb-1">{{ trans('impostazioni.installazione.condomini') }}</p>
                <p class="text-sm text-muted-foreground">
                  {{ condomini_dimostrativi > 0 ? trans('impostazioni.installazione.condomini_dimostrativi', { n: String(condomini_dimostrativi) }) : trans('impostazioni.installazione.condomini_desc') }}
                </p>
              </div>
            </div>
            <div class="text-sm tabular-nums text-right">
              <span class="text-lg font-semibold">{{ condomini }}</span>
              <span class="text-muted-foreground"> / {{ limite_condomini > 0 ? limite_condomini : trans('impostazioni.installazione.illimitato') }}</span>
            </div>
          </div>

          <!-- SPAZIO: solo con un tetto -->
          <div v-if="limite_spazio_byte !== null && spazio_usato_byte !== null" class="border rounded-lg p-4 space-y-2">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-start gap-3">
                <HardDrive class="h-5 w-5 mt-0.5 text-muted-foreground" />
                <div>
                  <p class="text-sm font-medium leading-none mb-1">{{ trans('impostazioni.installazione.spazio') }}</p>
                  <p class="text-sm text-muted-foreground">{{ trans('impostazioni.installazione.spazio_desc') }}</p>
                </div>
              </div>
              <div class="text-sm tabular-nums text-right">
                <span class="text-lg font-semibold">{{ formattaByte(spazio_usato_byte) }}</span>
                <span class="text-muted-foreground"> / {{ formattaByte(limite_spazio_byte) }}</span>
              </div>
            </div>
            <div class="h-2 w-full rounded bg-muted overflow-hidden">
              <div class="h-2 rounded bg-primary" :style="{ width: percentuale(spazio_usato_byte, limite_spazio_byte) + '%' }" />
            </div>
          </div>

          <!-- FUNZIONI -->
          <div class="border rounded-lg p-4">
            <div class="flex items-start gap-3 mb-3">
              <ToggleLeft class="h-5 w-5 mt-0.5 text-muted-foreground" />
              <div>
                <p class="text-sm font-medium leading-none mb-1">{{ trans('impostazioni.installazione.funzioni') }}</p>
                <p class="text-sm text-muted-foreground">{{ trans('impostazioni.installazione.funzioni_desc') }}</p>
              </div>
            </div>
            <dl class="grid gap-2 sm:grid-cols-2">
              <div v-for="f in funzioniOrdinate" :key="f.chiave" class="flex items-center justify-between rounded border px-3 py-2 text-sm">
                <dt>{{ trans('impostazioni.installazione.funzione_' + f.chiave) }}</dt>
                <dd :class="f.attiva ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">
                  {{ f.attiva ? trans('impostazioni.installazione.si') : trans('impostazioni.installazione.no') }}
                </dd>
              </div>
              <div class="flex items-center justify-between rounded border px-3 py-2 text-sm sm:col-span-2">
                <dt>{{ trans('impostazioni.installazione.documenti') }}</dt>
                <dd class="text-muted-foreground">{{ trans('impostazioni.installazione.documenti_' + documenti_stato) }}</dd>
              </div>
            </dl>
          </div>

          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-2">
            <p class="text-xs text-muted-foreground">{{ trans('impostazioni.installazione.versione') }} {{ versione }}</p>
            <Button v-if="gestione_piano_url" as-child variant="outline" size="sm">
              <a :href="gestione_piano_url" target="_blank" rel="noopener">
                {{ trans('impostazioni.installazione.gestione_piano') }}
                <ExternalLink class="ml-2 h-4 w-4" />
              </a>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
