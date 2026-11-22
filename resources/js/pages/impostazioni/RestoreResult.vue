<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
// Pagina di esito del ripristino. Volutamente autonoma (niente layout
// autenticato): dopo il ripristino le sessioni sono azzerate e tutti devono
// rifare l'accesso. Legge lo stato su file passato dal controller.
// Senza `dettaglio` (chi non ha l'accesso, o non gestisce i backup) il server
// manda solo la fase e che cosa riconfigurare: niente log tecnico, e la pagina
// dice che i dettagli si vedono qui dopo l'accesso (Coda 343).
import { Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { trans, transChoice } from 'laravel-vue-i18n'
import { CheckCircle2, XCircle, AlertTriangle, ShieldAlert, Copy, ClipboardCheck } from 'lucide-vue-next'

const props = defineProps<{
  restore: {
    phase: string | null
    outcome: Record<string, any>
    aborted?: boolean
    uuid?: string | null
    manifest?: Record<string, any> | null
    error?: string | null
    failed_phase?: string | null
    failed_at?: number | null
    app_version?: string | null
  } | null
  dettaglio?: boolean
}>()

const sourceVersion = computed(() => props.restore?.manifest?.app?.version ?? null)

// Voci "cosa verificare/riconfigurare" derivate dall'esito della finalizzazione
const reconfigure = computed(() => {
  const o = props.restore?.outcome ?? {}
  const items: string[] = []
  const cleanup = o.cleanup ?? {}
  if (cleanup.two_factor_reset_users) {
    items.push(trans('impostazioni.restore_result.reconfigure_2fa', { count: cleanup.two_factor_reset_users }))
  }
  if (cleanup.smtp_password_cleared) {
    items.push(trans('impostazioni.restore_result.reconfigure_smtp'))
  }
  if (o.app_key_adopted) {
    items.push(trans('impostazioni.restore_result.reconfigure_backup_password'))
  }
  return items
})

// 1.12.0-beta.13 (decisione 546): che cosa è successo alle righe del registro delle modifiche. Le manda il server solo con il dettaglio
// (`outcome.registro_modifiche`): righe rimesse e annullate, la copia senza registro, il PDF con il suo nome, o l'errore.
const cifra = (n: number) => String(Math.trunc(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
const righeRegistro = computed(() => {
  const r = props.restore?.outcome?.registro_modifiche
  if (!r || typeof r !== 'object') return [] as string[]
  const righe: string[] = []
  if (r.errore) {
    righe.push(trans('impostazioni.restore_result.registry_error', { errore: String(r.errore) }))
    if (r.nome_pdf) righe.push(trans('impostazioni.restore_result.registry_error_pdf', { nome: String(r.nome_pdf) }))
    else if (r.file_rimasto) righe.push(trans('impostazioni.restore_result.registry_error_file', { file: String(r.file_rimasto) }))
    return righe
  }
  if (r.nome_pdf) {
    righe.push(trans('impostazioni.restore_result.registry_pdf', { righe: cifra(Number(r.righe_nel_pdf ?? 0)), nome: String(r.nome_pdf) }))
    return righe
  }
  const rimesse = Number(r.righe_rimesse ?? 0)
  if (r.tutte) righe.push(transChoice('impostazioni.restore_result.registry_all_back', rimesse, { righe: cifra(rimesse) }))
  else if (rimesse > 0) righe.push(transChoice('impostazioni.restore_result.registry_kept', rimesse, { righe: cifra(rimesse) }))
  else if (r.righe_rimesse !== undefined) righe.push(trans('impostazioni.restore_result.registry_none_after'))
  const oscuramenti = Number(r.oscuramenti_riapplicati ?? 0)
  if (oscuramenti > 0) righe.push(transChoice('impostazioni.restore_result.registry_obscured', oscuramenti, { righe: cifra(oscuramenti) }))
  return righe
})

// Log tecnico compatto da copiare e inviare all'assistenza
const copied = ref(false)
const supportLog = computed(() => {
  const r = props.restore
  const at = r?.failed_at ? new Date(r.failed_at * 1000).toISOString().replace('T', ' ').slice(0, 19) : '—'
  return [
    'KondoManager restore log',
    `uuid: ${r?.uuid ?? '—'}`,
    `version: ${r?.app_version ?? '—'}`,
    `failed_phase: ${r?.failed_phase ?? r?.phase ?? '—'}`,
    `failed_at: ${at}`,
    `error: ${r?.error ?? '—'}`,
  ].join('\n')
})
function copyLog() {
  navigator.clipboard?.writeText(supportLog.value).then(() => {
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  }).catch(() => {})
}
</script>

<template>
  <Head :title="trans('impostazioni.restore_result.page_title')" />

  <div class="min-h-screen flex items-center justify-center bg-slate-950 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-8 shadow-xl">
      <!-- COMPLETATO -->
      <template v-if="restore?.phase === 'completed'">
        <div class="text-center">
          <CheckCircle2 class="mx-auto mb-3 h-12 w-12 text-emerald-600" />
          <h1 class="text-xl font-bold text-slate-900">{{ trans('impostazioni.restore_result.title_completed') }}</h1>
          <p class="mt-2 text-sm text-slate-500">
            <template v-if="sourceVersion">
              {{ trans('impostazioni.restore_result.subtitle_completed_versioned', { version: sourceVersion }) }}
            </template>
            <template v-else>
              {{ trans('impostazioni.restore_result.subtitle_completed') }}
            </template>
          </p>
        </div>

        <!-- Cosa verificare/riconfigurare -->
        <div v-if="reconfigure.length" class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
          <h2 class="flex items-center gap-2 text-sm font-semibold text-amber-800">
            <AlertTriangle class="h-4 w-4 shrink-0" />
            {{ trans('impostazioni.restore_result.reconfigure_heading') }}
          </h2>
          <ul class="mt-2 space-y-1.5 pl-6 text-[13px] text-amber-800 list-disc">
            <li v-for="(item, i) in reconfigure" :key="i">{{ item }}</li>
          </ul>
        </div>

        <!-- Il registro delle modifiche: le righe venute dopo la copia, o il PDF (decisione 546) -->
        <div v-if="righeRegistro.length" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4" data-testid="registro-modifiche">
          <h2 class="text-sm font-semibold text-slate-800">{{ trans('impostazioni.restore_result.registry_heading') }}</h2>
          <ul class="mt-2 space-y-1.5 text-[13px] text-slate-700">
            <li v-for="(riga, i) in righeRegistro" :key="i">{{ riga }}</li>
          </ul>
        </div>

        <p v-if="!dettaglio" class="mt-5 text-center text-[12px] text-slate-400" data-testid="dettagli-dopo-accesso">
          {{ trans('impostazioni.restore_result.details_after_login') }}
        </p>
      </template>

      <!-- NON RIUSCITO -->
      <template v-else-if="restore?.phase === 'failed'">
        <div class="text-center">
          <XCircle class="mx-auto mb-3 h-12 w-12 text-red-600" />
          <h1 class="text-xl font-bold text-red-700">{{ trans('impostazioni.restore_result.title_failed') }}</h1>
          <p class="mt-2 text-sm text-slate-600">{{ trans('impostazioni.restore_recovery.failed_body') }}</p>
        </div>

        <!-- Dettagli tecnici + log copiabile per l'assistenza: solo a chi vede il dettaglio -->
        <p v-if="!dettaglio" class="mt-5 text-center text-[12px] text-slate-400" data-testid="dettagli-dopo-accesso">
          {{ trans('impostazioni.restore_result.details_after_login') }}
        </p>
        <details v-else class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-left">
          <summary class="cursor-pointer select-none text-[13px] font-medium text-slate-600">
            {{ trans('impostazioni.restore_recovery.details') }}
          </summary>
          <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded border border-slate-100 bg-white p-2 font-mono text-[11px] leading-relaxed text-slate-600">{{ supportLog }}</pre>
          <p class="mt-2 text-[12px] text-slate-400">{{ trans('impostazioni.restore_recovery.log_intro') }}</p>
          <button type="button" @click="copyLog"
                  class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[12px] font-semibold text-slate-700 hover:bg-slate-100">
            <component :is="copied ? ClipboardCheck : Copy" class="h-3.5 w-3.5" />
            {{ copied ? trans('impostazioni.restore_recovery.copied') : trans('impostazioni.restore_recovery.copy_log') }}
          </button>
        </details>

        <div class="mt-5 flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
          <ShieldAlert class="h-4 w-4 shrink-0 mt-0.5 text-slate-500" />
          <p class="text-[13px] leading-relaxed text-slate-600">
            {{ trans('impostazioni.restore_result.failed_guidance') }}
          </p>
        </div>
      </template>

      <!-- NESSUN RIPRISTINO -->
      <template v-else>
        <div class="text-center">
          <h1 class="text-xl font-bold text-slate-900">{{ trans('impostazioni.restore_result.title_none') }}</h1>
          <p class="mt-2 text-sm text-slate-500">{{ trans('impostazioni.restore_result.subtitle_none') }}</p>
        </div>
      </template>

      <div class="mt-8 text-center">
        <a href="/login" class="inline-flex rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">
          {{ trans('impostazioni.restore_result.go_to_login') }}
        </a>
      </div>
    </div>
  </div>
</template>
