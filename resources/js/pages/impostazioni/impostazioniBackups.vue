<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Alert from '@/components/Alert.vue'
import BackupGuide from '@/components/guides/BackupGuide.vue'
import { Separator } from '@/components/ui/separator'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'
import type { BackupItem, BackupPreflight } from '@/types/backups'
import {
  DatabaseBackup, FileArchive, ShieldAlert, ArchiveRestore, Download, Trash2,
  Copy, Check, CheckCircle2, AlertCircle, Loader2, XCircle, Play, BookOpen,
  Lock, AlertTriangle, Database, Eye, EyeOff, History, Minus, Plus, RotateCcw
} from 'lucide-vue-next'

const showGuide = ref(false)

const props = defineProps<{
  backups: any;
  runningBackup: BackupItem | null;
  preflight: BackupPreflight;
  retention_keep_last: number;
  backup_has_password: boolean;
}>()

const page = usePage()
const flashMessage = computed(() => (page.props as any).flash?.message)

// computed (non const): al primo render le traduzioni potrebbero non essere
// ancora caricate e trans() restituirebbe la chiave raw
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
    title: trans('impostazioni.label.settings') || 'Impostazioni',
    href: '/impostazioni',
  },
  {
    title: trans('impostazioni.dialogs.backups_settings_title') || 'Gestione backups',
    href: '/impostazioni/backups',
  },
])

const pageGuides = computed(() => [
  {
    title: trans('impostazioni.guides.backup_content_title'),
    description: trans('impostazioni.guides.backup_content_desc'),
    icon: FileArchive,
    colorVariant: 'blue' as const
  },
  {
    title: trans('impostazioni.guides.backup_security_title'),
    description: trans('impostazioni.guides.backup_security_desc'),
    icon: ShieldAlert,
    colorVariant: 'amber' as const
  },
  {
    title: trans('impostazioni.guides.backup_restore_title'),
    description: trans('impostazioni.guides.backup_restore_desc'),
    icon: ArchiveRestore,
    colorVariant: 'emerald' as const
  }
])

/* ------------------------------------------------------------------
 | Esecuzione a step con polling sequenziale
 | ------------------------------------------------------------------ */
const running = ref<BackupItem | null>(props.runningBackup)
const polling = ref(false)
const creating = ref(false)
const localError = ref<string | null>(null)
const localSuccess = ref<string | null>(null)
const unmounted = ref(false)

const RUNNING_STATUSES = ['pending', 'dumping_database', 'archiving_files', 'finalizing']

const isRunning = (backup: BackupItem | null) => !!backup && RUNNING_STATUSES.includes(backup.status)

const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms))

const reloadLists = () => {
  router.reload({ only: ['backups', 'runningBackup', 'preflight'] })
}

async function pump() {
  if (!running.value || polling.value) return

  polling.value = true
  localError.value = null

  try {
    let lastPercent = -1

    while (!unmounted.value && running.value && isRunning(running.value)) {
      const { data } = await axios.post(route('impostazioni.backups.step', running.value.uuid))
      running.value = data.backup

      const percent = running.value?.progress?.percent ?? 0

      // Se un altro processo sta già eseguendo lo step (lock attivo) lo stato
      // non avanza: si attende un po' di più prima di riprovare.
      await sleep(percent === lastPercent ? 2000 : 300)
      lastPercent = percent
    }

    if (unmounted.value || !running.value) return

    if (running.value.status === 'completed') {
      localSuccess.value = trans('impostazioni.backup_success_completed')
    } else if (running.value.status === 'failed') {
      localError.value = running.value.error || trans('impostazioni.backup_error_generic')
    }

    running.value = null
    reloadLists()
  } catch (error: any) {
    if (error?.response?.status === 404) {
      // Il backup è stato eliminato/annullato nel frattempo
      running.value = null
      reloadLists()
    } else {
      localError.value = error?.response?.data?.message || trans('impostazioni.backup_error_generic')
    }
  } finally {
    polling.value = false
  }
}

/* Opzioni di creazione: tipo di backup e protezione con la password salvata.
   Con una password impostata la cifratura parte attiva di default: chi la
   configura una volta si aspetta backup protetti senza altri gesti. */
const backupType = ref<'full' | 'db_only'>('full')
const protectWithPassword = ref(props.backup_has_password === true)

// La protezione è disponibile solo se il server supporta la cifratura E
// l'amministratore ha salvato una password nelle impostazioni sotto.
const hasSavedPassword = computed(() => props.backup_has_password === true)
const canProtect = computed(() => props.preflight.encryption_supported && hasSavedPassword.value)

async function createBackup() {
  if (creating.value || isRunning(running.value)) return

  creating.value = true
  localError.value = null
  localSuccess.value = null

  try {
    const { data } = await axios.post(route('impostazioni.backups.store'), {
      type: backupType.value,
      protect: protectWithPassword.value && canProtect.value,
    })
    running.value = data.backup
    pump()
  } catch (error: any) {
    localError.value = error?.response?.data?.message || trans('impostazioni.backup_error_generic')
  } finally {
    creating.value = false
  }
}

onMounted(() => {
  // Ripresa automatica di un backup rimasto in corso (es. pagina ricaricata)
  if (isRunning(running.value)) pump()
})

onBeforeUnmount(() => {
  unmounted.value = true
})

const progressPercent = computed(() => running.value?.progress?.percent ?? 0)

// Righe della tabella: il backup in corso viene mostrato come prima riga
// "live" dedicata, quindi va tolto dall'elenco se già presente (caso di
// pagina ricaricata a backup in corso)
const tableBackups = computed(() => {
  const rows = props.backups?.data ?? []
  if (!running.value) return rows
  return rows.filter((backup: BackupItem) => backup.uuid !== running.value!.uuid)
})

const phaseLabel = (status: string) => trans(`impostazioni.backup_phase.${status}`) || status

/* ------------------------------------------------------------------
 | Eliminazione / annullamento
 | ------------------------------------------------------------------ */
const isDeleteDialogOpen = ref(false)
const backupToDelete = ref<BackupItem | null>(null)

const askDelete = (backup: BackupItem) => {
  backupToDelete.value = backup
  isDeleteDialogOpen.value = true
}

const confirmDelete = () => {
  if (!backupToDelete.value) return

  const uuid = backupToDelete.value.uuid

  router.delete(route('impostazioni.backups.destroy', uuid), {
    preserveScroll: true,
    onSuccess: () => {
      if (running.value?.uuid === uuid) running.value = null
      backupToDelete.value = null
    },
  })
}

/* ------------------------------------------------------------------
 | Ripristino di un backup
 | ------------------------------------------------------------------
 | Solo backup di QUESTA installazione (quelli in lista): stessa APP_KEY,
 | .env intatto, nessun tema di dominio. Il dialog chiede la password
 | dell'account (sudo, operazione distruttiva), la password dell'archivio
 | se cifrato e se creare un backup di sicurezza. Poi un overlay mostra
 | l'avanzamento: gli step si autenticano col token, non con la sessione
 | (che l'import sovrascrive). Vedi docs/ripristino_backup_design.md.
 */
const RESTORE_RUNNING_PHASES = ['pending', 'safety_backup', 'extracting', 'verifying', 'importing_database', 'restoring_files', 'finalizing']

const isRestoreDialogOpen = ref(false)
const backupToRestore = ref<BackupItem | null>(null)
const restoreAccountPassword = ref('')
const restoreArchivePassword = ref('')
const restoreSafetyBackup = ref(true)
const showRestoreAccountPassword = ref(false)
const showRestoreArchivePassword = ref(false)
const restoreSubmitting = ref(false)
const restoreErrors = ref<Record<string, string>>({})

const restoreRunning = ref(false)
const restoreToken = ref<string | null>(null)
const restorePhase = ref<string | null>(null)
const restoreErrorMessage = ref<string | null>(null)
const restoreUuid = ref<string | null>(null)
const restoreFailedPhase = ref<string | null>(null)
const restoreFailedAt = ref<number | null>(null)
const restoreAppVersion = ref<string | null>(null)
const restoreRecovering = ref(false)
const restoreCopied = ref(false)

const askRestore = (backup: BackupItem) => {
  backupToRestore.value = backup
  restoreAccountPassword.value = ''
  restoreArchivePassword.value = ''
  restoreSafetyBackup.value = true
  showRestoreAccountPassword.value = false
  showRestoreArchivePassword.value = false
  restoreErrors.value = {}
  isRestoreDialogOpen.value = true
}

const restorePhaseLabel = computed(() =>
  restorePhase.value ? trans(`impostazioni.restore_phase.${restorePhase.value}`) : ''
)

async function submitRestore() {
  if (!backupToRestore.value || restoreSubmitting.value) return

  restoreSubmitting.value = true
  restoreErrors.value = {}

  try {
    const { data } = await axios.post(
      route('impostazioni.backups.restore.start', backupToRestore.value.uuid),
      {
        account_password: restoreAccountPassword.value,
        password: restoreArchivePassword.value || null,
        safety_backup: restoreSafetyBackup.value,
        adopt_app_key: false,
      }
    )

    restoreToken.value = data.token
    restorePhase.value = 'pending'
    restoreErrorMessage.value = null
    isRestoreDialogOpen.value = false
    restoreRunning.value = true
    pollRestore()
  } catch (error: any) {
    if (error?.response?.status === 422) {
      const errs = error.response.data?.errors ?? {}
      restoreErrors.value = Object.fromEntries(
        Object.entries(errs).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)])
      )
    } else {
      restoreErrors.value = { archive: error?.response?.data?.message || trans('impostazioni.restore_error_generic') }
    }
  } finally {
    restoreSubmitting.value = false
  }
}

async function pollRestore() {
  while (restoreRunning.value && restorePhase.value && RESTORE_RUNNING_PHASES.includes(restorePhase.value)) {
    try {
      const { data } = await axios.post(route('ripristino.step'), {}, {
        headers: { 'X-Restore-Token': restoreToken.value ?? '' },
      })

      const state = data.restore
      restorePhase.value = state?.phase ?? null
      restoreErrorMessage.value = state?.error ?? null
      restoreUuid.value = state?.uuid ?? restoreUuid.value
      restoreFailedPhase.value = state?.failed_phase ?? null
      restoreFailedAt.value = state?.failed_at ?? null
      restoreAppVersion.value = state?.app_version ?? null

      if (restorePhase.value === 'completed') {
        // Ripristino riuscito: le sessioni sono azzerate, la pagina di esito
        // è pubblica e richiede un nuovo login.
        window.location.href = route('ripristino.result')
        return
      }

      if (restorePhase.value === 'failed') {
        return // l'overlay mostra l'errore; l'app resta in modalità ripristino
      }

      await sleep(1200)
    } catch (error: any) {
      // 403 = token scaduto/non valido: interrompe il polling mostrando errore
      restoreErrorMessage.value = error?.response?.data?.message || trans('impostazioni.restore_error_generic')
      restorePhase.value = 'failed'
      return
    }
  }
}

// Log tecnico compatto da copiare e inviare all'assistenza
const restoreSupportLog = computed(() => [
  'KondoManager restore log',
  `uuid: ${restoreUuid.value ?? '—'}`,
  `version: ${restoreAppVersion.value ?? '—'}`,
  `failed_phase: ${restoreFailedPhase.value ?? restorePhase.value ?? '—'}`,
  `failed_at: ${restoreFailedAt.value ? new Date(restoreFailedAt.value * 1000).toISOString().replace('T', ' ').slice(0, 19) : '—'}`,
  `error: ${restoreErrorMessage.value ?? '—'}`,
].join('\n'))

function copyRestoreLog() {
  navigator.clipboard?.writeText(restoreSupportLog.value).then(() => {
    restoreCopied.value = true
    setTimeout(() => (restoreCopied.value = false), 2000)
  }).catch(() => {})
}

// Riprende un ripristino fallito col token ancora in memoria (l'overlay è
// aperto), poi riprende il polling degli step come un avvio normale.
async function resumeRestore() {
  if (restoreRecovering.value) return
  restoreRecovering.value = true
  try {
    const { data } = await axios.post(route('ripristino.resume'), {}, {
      headers: { 'X-Restore-Token': restoreToken.value ?? '' },
    })
    if (data.token) restoreToken.value = data.token
    restorePhase.value = data.restore?.phase ?? 'pending'
    restoreErrorMessage.value = null
    if (restorePhase.value === 'completed') {
      window.location.href = route('ripristino.result')
      return
    }
    if (restorePhase.value && RESTORE_RUNNING_PHASES.includes(restorePhase.value)) {
      pollRestore()
    }
  } catch {
    // resta sullo stato fallito, l'utente può riprovare o sbloccare
  } finally {
    restoreRecovering.value = false
  }
}

// Annulla e sblocca l'applicazione: il DB può restare parziale (la UI avvisa).
async function abortRestore() {
  if (restoreRecovering.value) return
  restoreRecovering.value = true
  try {
    await axios.post(route('ripristino.abort'), {}, {
      headers: { 'X-Restore-Token': restoreToken.value ?? '' },
    })
    window.location.href = '/'
  } catch {
    restoreRecovering.value = false
  }
}

/* ------------------------------------------------------------------
 | Impostazioni backup: retention + password di protezione in un'unica
 | card con un solo "Salva impostazioni". La password è impostata una
 | volta sola e riusata per tutti i backup cifrati; il valore reale non
 | arriva mai al client (solo il booleano backup_has_password).
 | ------------------------------------------------------------------ */
const settingsForm = useForm({
  retention_keep_last: props.retention_keep_last,
  password: '',
  password_confirmation: '',
})

const editingPassword = ref(false)
const showPassword = ref(false)
const passwordSubmitAttempted = ref(false)

const passwordError = computed<string | null>(() => {
  if (!editingPassword.value || !passwordSubmitAttempted.value) return null
  if (settingsForm.password.length < 8) return trans('impostazioni.label.backup_password_error_min')
  return settingsForm.errors.password ?? null
})

const confirmError = computed<string | null>(() => {
  if (!editingPassword.value || !passwordSubmitAttempted.value) return null
  if (settingsForm.password_confirmation !== settingsForm.password) return trans('impostazioni.label.backup_password_error_mismatch')
  return null
})

const startEditingPassword = () => {
  editingPassword.value = true
  showPassword.value = false
  passwordSubmitAttempted.value = false
  settingsForm.password = ''
  settingsForm.password_confirmation = ''
}

const cancelEditingPassword = () => {
  editingPassword.value = false
  showPassword.value = false
  passwordSubmitAttempted.value = false
  settingsForm.password = ''
  settingsForm.password_confirmation = ''
  settingsForm.clearErrors('password')
}

const saveSettings = () => {
  // Se il form password è aperto, la password è parte del salvataggio e
  // va validata inline PRIMA di inviare (una password sbagliata rende
  // irrecuperabili i backup futuri)
  if (editingPassword.value) {
    passwordSubmitAttempted.value = true
    if (settingsForm.password.length < 8 || settingsForm.password_confirmation !== settingsForm.password) return
  }

  settingsForm
    .transform(data => editingPassword.value ? data : { retention_keep_last: data.retention_keep_last })
    .post(route('impostazioni.backups.settings'), {
      preserveScroll: true,
      onSuccess: () => cancelEditingPassword(),
    })
}

const isRemovePasswordDialogOpen = ref(false)

const removePassword = () => {
  router.post(route('impostazioni.backups.password'), { remove: true }, {
    preserveScroll: true,
    onSuccess: () => {
      protectWithPassword.value = false
      cancelEditingPassword()
    },
  })
}

/* ------------------------------------------------------------------
 | Helpers presentazione
 | ------------------------------------------------------------------ */
const formatDate = (dateString: string | null) => {
  if (!dateString) return '-'
  return new Date(dateString).toLocaleString('it-IT', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  })
}

const humanSize = (bytes: number | null | undefined) => {
  if (bytes === null || bytes === undefined) return '-'
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes
  let unit = ''
  for (const candidate of units) {
    value /= 1024
    unit = candidate
    if (value < 1024) break
  }
  return `${value.toFixed(value >= 100 ? 0 : 1)} ${unit}`
}

const copiedChecksum = ref<string | null>(null)

const copyChecksum = async (checksum: string) => {
  try {
    await navigator.clipboard.writeText(checksum)
    copiedChecksum.value = checksum
    setTimeout(() => { copiedChecksum.value = null }, 2000)
  } catch {
    // clipboard non disponibile (http non sicuro): nessun feedback
  }
}

const preflightLabel = (key: string) => trans(`impostazioni.backup_preflight.${key}`) || key

const statusBadgeClass = (status: string) => {
  if (status === 'completed') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
  if (status === 'failed') return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'
  return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400'
}
</script>

<template>
  <AppLayout :breadcrumbs="[]">
    <Head :title="trans('impostazioni.dialogs.backups_settings_title') || 'Gestione backups'" />

    <div class="px-4 py-6 space-y-6">
      <PageHeaderGuide
        :page-title="trans('impostazioni.dialogs.backups_settings_title') || 'Gestione backups'"
        :page-subtitle="trans('impostazioni.header.backups_settings_description')"
        :icon="DatabaseBackup"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        back-url="/impostazioni"
        :back-text="trans('impostazioni.label.settings')"
        :video-url="null"
      >
        <template #actions>
          <Button variant="outline" size="sm" @click="showGuide = true" class="bg-white gap-2 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800 border-indigo-200">
            <BookOpen class="w-4 h-4" />
            {{ trans('impostazioni.actions.restore_guide') }}
          </Button>
        </template>
      </PageHeaderGuide>

      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>
      <div v-if="localSuccess" class="py-2">
        <Alert :message="localSuccess" type="success" />
      </div>
      <div v-if="localError" class="py-2">
        <Alert :message="localError" type="error" />
      </div>

      <!-- Requisiti di sistema (preflight) -->
      <Card>
        <CardContent class="pt-6">
          <h3 class="text-sm font-semibold mb-4 flex items-center gap-2">
            <CheckCircle2 class="w-4 h-4 text-muted-foreground" />
            {{ trans('impostazioni.label.backup_preflight_title') }}
          </h3>

          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
              v-for="check in preflight.checks"
              :key="check.key"
              class="flex items-start gap-2 rounded-lg border p-3"
              :class="check.ok ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-red-200 bg-red-50/50 dark:border-red-900 dark:bg-red-950/20'"
            >
              <CheckCircle2 v-if="check.ok" class="w-4 h-4 mt-0.5 shrink-0 text-emerald-600" />
              <AlertCircle v-else class="w-4 h-4 mt-0.5 shrink-0 text-red-600" />
              <div class="min-w-0">
                <div class="text-xs font-medium">{{ preflightLabel(check.key) }}</div>
                <div v-if="check.key === 'zip_extension'" class="text-[11px] text-muted-foreground">
                  {{ trans(check.detail.aes256_supported ? 'impostazioni.label.backup_preflight_aes_supported' : 'impostazioni.label.backup_preflight_aes_unsupported') }}
                </div>
                <div v-if="check.key === 'db_driver' && check.detail.driver" class="text-[11px] text-muted-foreground uppercase font-mono">
                  {{ check.detail.driver }}
                </div>
                <div v-if="check.key === 'dir_writable' && check.detail.path" class="text-[11px] text-muted-foreground font-mono">
                  {{ check.detail.path }}
                </div>
                <div v-if="check.key === 'disk_space'" class="text-[11px] text-muted-foreground">
                  {{ trans('impostazioni.label.backup_estimated_size') }}: ~{{ humanSize(preflight.estimated_bytes) }}
                  <template v-if="preflight.free_bytes !== null">
                    · {{ trans('impostazioni.label.backup_free_space') }}: {{ humanSize(preflight.free_bytes) }}
                  </template>
                </div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Colonna principale: creazione backup + elenco. Colonna laterale:
           impostazioni (retention + password). Il pulsante "Crea backup" vive
           sotto la tabella (non sopra le card di scelta) e resta sempre
           raggiungibile perché la tabella scorre al suo interno. -->
      <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        <div class="flex flex-col gap-6 lg:flex-1 lg:min-w-0">
          <!-- Creazione backup -->
          <Card>
            <CardContent class="pt-6">
              <div>
                <h3 class="text-sm font-semibold flex items-center gap-2">
                  <DatabaseBackup class="w-4 h-4 text-muted-foreground" />
                  {{ trans('impostazioni.label.backup_create_title') }}
                </h3>
                <p class="text-sm text-muted-foreground mt-1">
                  {{ trans('impostazioni.label.backup_create_help') }}
                </p>
              </div>

              <!-- Opzioni di creazione (nascoste durante l'esecuzione). Il tipo è una
                   scelta esclusiva, la protezione è indipendente e si combina con
                   entrambi i tipi: restano due gruppi distinti con etichetta propria,
                   allineati sulla stessa riga. -->
              <div v-if="!isRunning(running)" class="mt-5 flex flex-col lg:flex-row lg:items-stretch gap-4">
                <!-- Tipo di backup -->
                <div class="flex flex-col flex-1">
                  <div class="text-xs font-semibold text-muted-foreground mb-2">{{ trans('impostazioni.label.backup_type_title') }}</div>
                  <div class="grid gap-2 sm:grid-cols-2 flex-1">
                    <button
                      type="button"
                      class="flex items-start gap-3 rounded-lg border p-3 text-left transition-colors h-full"
                      :class="backupType === 'full' ? 'border-primary bg-muted/60' : 'border-input hover:bg-muted/40'"
                      @click="backupType = 'full'"
                    >
                      <FileArchive class="w-4 h-4 mt-0.5 shrink-0" :class="backupType === 'full' ? 'text-primary' : 'text-muted-foreground'" />
                      <span>
                        <span class="block text-sm font-medium">{{ trans('impostazioni.label.backup_type_full') }}</span>
                        <span class="block text-[11px] text-muted-foreground mt-0.5">{{ trans('impostazioni.label.backup_type_full_desc') }}</span>
                      </span>
                    </button>
                    <button
                      type="button"
                      class="flex items-start gap-3 rounded-lg border p-3 text-left transition-colors h-full"
                      :class="backupType === 'db_only' ? 'border-primary bg-muted/60' : 'border-input hover:bg-muted/40'"
                      @click="backupType = 'db_only'"
                    >
                      <Database class="w-4 h-4 mt-0.5 shrink-0" :class="backupType === 'db_only' ? 'text-primary' : 'text-muted-foreground'" />
                      <span>
                        <span class="block text-sm font-medium">{{ trans('impostazioni.label.backup_type_db') }}</span>
                        <span class="block text-[11px] text-muted-foreground mt-0.5">{{ trans('impostazioni.label.backup_type_db_desc') }}</span>
                      </span>
                    </button>
                  </div>
                </div>

                <!-- Protezione con la password salvata (solo se il server la supporta) -->
                <div v-if="preflight.encryption_supported" class="flex flex-col lg:w-72">
                  <div class="text-xs font-semibold text-muted-foreground mb-2">{{ trans('impostazioni.label.backup_protect_title') }}</div>
                  <div
                    class="flex items-start gap-3 rounded-lg border p-3 flex-1 transition-colors"
                    :class="[
                      protectWithPassword && hasSavedPassword ? 'border-primary bg-muted/60' : 'border-input',
                      hasSavedPassword ? 'cursor-pointer hover:bg-muted/40' : '',
                    ]"
                    @click="hasSavedPassword && (protectWithPassword = !protectWithPassword)"
                  >
                    <Lock class="w-4 h-4 mt-0.5 shrink-0" :class="protectWithPassword && hasSavedPassword ? 'text-primary' : 'text-muted-foreground'" />
                    <span class="flex-1">
                      <span class="block text-sm font-medium" :class="!hasSavedPassword ? 'text-muted-foreground' : ''">
                        {{ trans('impostazioni.label.backup_protect_toggle') }}
                      </span>
                      <span class="block text-[11px] text-muted-foreground mt-0.5">
                        {{ hasSavedPassword ? trans('impostazioni.label.backup_protect_card_desc') : trans('impostazioni.label.backup_protect_no_password_hint') }}
                      </span>
                    </span>
                    <Switch v-model="protectWithPassword" :disabled="!hasSavedPassword" class="mt-0.5" @click.stop />
                  </div>
                </div>
              </div>

              <!-- Barra di progresso -->
              <div v-if="isRunning(running)" class="mt-5">
                <div class="flex items-center justify-between text-xs text-muted-foreground mb-1.5">
                  <span class="flex items-center gap-2">
                    <Loader2 class="w-3.5 h-3.5 animate-spin text-muted-foreground" />
                    {{ phaseLabel(running!.status) }}
                    <span v-if="running!.progress?.detail" class="font-mono text-[11px]">({{ running!.progress.detail }})</span>
                  </span>
                  <span class="font-semibold text-foreground">{{ progressPercent }}%</span>
                </div>
                <div class="h-2 w-full rounded-full bg-muted overflow-hidden">
                  <div
                    class="h-full rounded-full bg-primary transition-all duration-500"
                    :style="{ width: `${progressPercent}%` }"
                  />
                </div>
                <p class="text-[11px] text-muted-foreground mt-2">
                  {{ trans('impostazioni.label.backup_in_progress_help') }}
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- Elenco backup: altezza limitata con scroll interno (ultimi 3-4
               visibili, gli altri con scorrimento), così il pulsante di
               creazione sotto resta sempre raggiungibile senza scroll di
               pagina qualunque sia il numero di backup conservati -->
          <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4 bg-white dark:bg-card">
            <div class="rounded-md border max-h-[280px] overflow-y-auto">
              <table class="w-full caption-bottom text-sm">
                <TableHeader class="sticky top-0 z-10 bg-background">
                  <TableRow class="bg-muted/50">
                    <TableHead class="w-[150px]">{{ trans('impostazioni.label.backup_status') }}</TableHead>
                    <TableHead>{{ trans('impostazioni.label.backup_date') }}</TableHead>
                    <TableHead class="hidden md:table-cell">{{ trans('impostazioni.label.backup_size') }}</TableHead>
                    <TableHead class="hidden xl:table-cell">SHA-256</TableHead>
                    <TableHead class="text-right">{{ trans('impostazioni.label.backup_actions') }}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
              <!-- Riga "live" del backup in corso -->
              <TableRow v-if="isRunning(running)" class="bg-muted/40 dark:bg-muted/10">
                <TableCell class="p-2">
                  <span class="inline-flex items-center gap-1.5">
                    <Badge variant="secondary" class="rounded-md whitespace-nowrap text-[10px] font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">
                      <Loader2 class="w-3 h-3 mr-1 animate-spin" />
                      {{ phaseLabel(running!.status) }}
                    </Badge>
                    <Lock
                      v-if="running!.encrypted"
                      class="w-3.5 h-3.5 shrink-0 text-muted-foreground"
                      :title="trans('impostazioni.label.backup_encrypted_badge')"
                    />
                  </span>
                </TableCell>
                <TableCell class="text-sm">
                  <div class="font-medium">{{ formatDate(running!.created_at) }}</div>
                  <div v-if="running!.created_by" class="text-[11px] text-muted-foreground">{{ running!.created_by }}</div>
                  <div v-if="running!.type === 'db_only'" class="text-[11px] text-muted-foreground flex items-center gap-1">
                    <Database class="w-3 h-3" />
                    {{ trans('impostazioni.label.backup_db_only_label') }}
                  </div>
                  <div class="mt-2 flex items-center gap-2 max-w-[260px]">
                    <div class="h-1.5 flex-1 rounded-full bg-muted overflow-hidden">
                      <div
                        class="h-full rounded-full bg-primary transition-all duration-500"
                        :style="{ width: `${progressPercent}%` }"
                      />
                    </div>
                    <span class="text-[11px] font-semibold text-foreground tabular-nums">{{ progressPercent }}%</span>
                  </div>
                </TableCell>
                <TableCell class="hidden md:table-cell text-sm text-muted-foreground">—</TableCell>
                <TableCell class="hidden xl:table-cell">
                  <span v-if="running!.progress?.detail" class="font-mono text-[11px] text-muted-foreground">{{ running!.progress.detail }}</span>
                  <span v-else class="text-muted-foreground">—</span>
                </TableCell>
                <TableCell class="text-right whitespace-nowrap">
                  <button
                    type="button"
                    class="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-red-50 dark:hover:bg-red-950/30 text-red-600"
                    :title="trans('impostazioni.actions.cancel_backup')"
                    @click="askDelete(running!)"
                  >
                    <XCircle class="w-4 h-4" />
                  </button>
                </TableCell>
              </TableRow>

              <TableRow v-for="backup in tableBackups" :key="backup.uuid" class="hover:bg-muted/50">
                <TableCell class="p-2">
                  <span class="inline-flex items-center gap-1.5">
                    <Badge variant="secondary" class="rounded-md whitespace-nowrap text-[10px] font-medium" :class="statusBadgeClass(backup.status)">
                      {{ phaseLabel(backup.status) }}
                    </Badge>
                    <Lock
                      v-if="backup.encrypted"
                      class="w-3.5 h-3.5 shrink-0 text-muted-foreground"
                      :title="trans('impostazioni.label.backup_encrypted_badge')"
                    />
                  </span>
                </TableCell>
                <TableCell class="text-sm">
                  <div class="font-medium">{{ formatDate(backup.created_at) }}</div>
                  <div v-if="backup.created_by" class="text-[11px] text-muted-foreground">{{ backup.created_by }}</div>
                  <div v-if="backup.type === 'db_only'" class="text-[11px] text-muted-foreground flex items-center gap-1">
                    <Database class="w-3 h-3" />
                    {{ trans('impostazioni.label.backup_db_only_label') }}
                  </div>
                  <div v-if="backup.status === 'failed' && backup.error" class="text-[11px] text-red-600 max-w-[300px] truncate" :title="backup.error">
                    {{ backup.error }}
                  </div>
                </TableCell>
                <TableCell class="hidden md:table-cell text-sm whitespace-nowrap">
                  {{ humanSize(backup.size) }}
                </TableCell>
                <TableCell class="hidden xl:table-cell">
                  <div v-if="backup.checksum" class="flex items-center gap-1.5">
                    <span class="font-mono text-[11px] text-muted-foreground">{{ backup.checksum.substring(0, 16) }}…</span>
                    <button
                      type="button"
                      class="text-slate-400 hover:text-primary"
                      :title="trans('impostazioni.actions.copy_checksum')"
                      @click="copyChecksum(backup.checksum)"
                    >
                      <Check v-if="copiedChecksum === backup.checksum" class="w-3.5 h-3.5 text-emerald-600" />
                      <Copy v-else class="w-3.5 h-3.5" />
                    </button>
                  </div>
                  <span v-else class="text-muted-foreground">-</span>
                </TableCell>
                <TableCell class="text-right whitespace-nowrap">
                  <button
                    v-if="backup.status === 'completed'"
                    type="button"
                    class="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-amber-50 dark:hover:bg-amber-950/30 text-amber-600 dark:text-amber-500"
                    :title="trans('impostazioni.actions.restore_backup')"
                    @click="askRestore(backup)"
                  >
                    <RotateCcw class="w-4 h-4" />
                  </button>
                  <a
                    v-if="backup.status === 'completed'"
                    :href="route('impostazioni.backups.download', backup.uuid)"
                    class="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-muted text-slate-600 dark:text-slate-300"
                    :title="trans('impostazioni.actions.download_backup')"
                  >
                    <Download class="w-4 h-4" />
                  </a>
                  <button
                    v-if="backup.status !== 'pending' || !isRunning(running) || running?.uuid !== backup.uuid"
                    type="button"
                    class="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-red-50 dark:hover:bg-red-950/30 text-red-600"
                    :title="trans('impostazioni.actions.delete_backup')"
                    @click="askDelete(backup)"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </TableCell>
              </TableRow>

              <TableRow v-if="tableBackups.length === 0 && !isRunning(running)">
                <TableCell colspan="5" class="h-32 text-center text-muted-foreground">
                  <div class="flex flex-col items-center gap-2">
                    <DatabaseBackup class="w-8 h-8 opacity-20" />
                    <span>{{ trans('impostazioni.label.backup_empty') }}</span>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
              </table>
        </div>

        <div v-if="backups.links && backups.links.length > 3" class="flex items-center justify-between pt-4">
          <div class="text-xs text-muted-foreground">
            Pag. {{ backups.current_page }} di {{ backups.last_page }}
          </div>
          <div class="flex gap-1">
            <Button
              v-for="(link, i) in backups.links" :key="i"
              :as="Link"
              :href="link.url || '#'"
              :variant="link.active ? 'default' : 'outline'"
              size="icon"
              class="h-8 w-8"
              :disabled="!link.url"
              v-html="link.label.includes('Previous') ? '&lt;' : link.label.includes('Next') ? '&gt;' : link.label"
            />
          </div>
        </div>

        <!-- Azione di creazione: sotto la tabella, sempre raggiungibile -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
          <template v-if="isRunning(running)">
            <Button v-if="!polling" variant="outline" size="sm" @click="pump">
              <Play class="w-4 h-4 mr-2" />
              {{ trans('impostazioni.actions.resume_backup') }}
            </Button>
            <Button variant="outline" size="sm" class="text-red-600 hover:text-red-700" @click="askDelete(running!)">
              <XCircle class="w-4 h-4 mr-2" />
              {{ trans('impostazioni.actions.cancel_backup') }}
            </Button>
          </template>

          <Button
            v-else
            :disabled="creating || !(backupType === 'db_only' ? preflight.ok_db_only : preflight.ok)"
            @click="createBackup"
          >
            <Loader2 v-if="creating" class="w-4 h-4 mr-2 animate-spin" />
            <DatabaseBackup v-else class="w-4 h-4 mr-2" />
            {{ trans('impostazioni.actions.create_backup') }}
          </Button>
        </div>
      </div>
        </div>

        <!-- Impostazioni: retention + password di protezione, colonna laterale.
             Altezza NATURALE (la colonna sinistra, con la tabella, è più alta:
             stirare questa lascerebbe un vuoto e staccherebbe "Salva
             impostazioni" dal contenuto). Il contenitore usa lg:items-start. -->
        <div class="lg:w-96 lg:shrink-0">
          <form @submit.prevent="saveSettings">
            <Card>
              <CardContent class="pt-6 space-y-6">
                <!-- Backup da conservare -->
                <div>
                  <h3 class="text-sm font-semibold flex items-center gap-2">
                    <History class="w-4 h-4 text-muted-foreground" />
                    {{ trans('impostazioni.label.backup_retention') }}
                  </h3>
                  <p class="text-sm text-muted-foreground mt-1">
                    {{ trans('impostazioni.label.backup_retention_help') }}
                  </p>
                  <p v-if="settingsForm.errors.retention_keep_last" class="text-sm text-red-500 mt-1">
                    {{ settingsForm.errors.retention_keep_last }}
                  </p>
                  <div class="mt-2 flex items-center gap-1.5">
                    <Button
                      type="button"
                      variant="outline"
                      size="icon-sm"
                      class="shadow-none"
                      :disabled="settingsForm.retention_keep_last <= 1"
                      @click="settingsForm.retention_keep_last = Math.max(1, (settingsForm.retention_keep_last || 1) - 1)"
                    >
                      <Minus class="w-3.5 h-3.5" />
                    </Button>
                    <Input
                      v-model.number="settingsForm.retention_keep_last"
                      type="number"
                      min="1"
                      max="50"
                      class="h-8 w-14 text-center text-sm font-semibold shadow-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="icon-sm"
                      class="shadow-none"
                      :disabled="settingsForm.retention_keep_last >= 50"
                      @click="settingsForm.retention_keep_last = Math.min(50, (settingsForm.retention_keep_last || 0) + 1)"
                    >
                      <Plus class="w-3.5 h-3.5" />
                    </Button>
                  </div>
                </div>

                <template v-if="preflight.encryption_supported">
                  <Separator />

                  <!-- Password di protezione dei backup -->
                  <div>
                    <h3 class="text-sm font-semibold flex items-center gap-2">
                      <Lock class="w-4 h-4 text-muted-foreground" />
                      {{ trans('impostazioni.label.backup_password_setting_title') }}
                    </h3>
                    <p class="text-sm text-muted-foreground mt-1">
                      {{ trans('impostazioni.label.backup_password_setting_help') }}
                    </p>

                    <!-- Stato: password impostata / non impostata -->
                    <div v-if="!editingPassword" class="mt-3">
                      <Badge
                        v-if="hasSavedPassword"
                        variant="secondary"
                        class="rounded-md whitespace-nowrap text-[10px] font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400"
                      >
                        <CheckCircle2 class="w-3 h-3 mr-1" />
                        {{ trans('impostazioni.label.backup_password_set') }}
                      </Badge>
                      <Badge
                        v-else
                        variant="secondary"
                        class="rounded-md whitespace-nowrap text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300"
                      >
                        <AlertCircle class="w-3 h-3 mr-1" />
                        {{ trans('impostazioni.label.backup_password_not_set') }}
                      </Badge>
                    </div>

                    <!-- Form impostazione / modifica (salvato dal pulsante in fondo) -->
                    <div v-if="editingPassword" class="mt-4 space-y-3">
                      <div class="space-y-3">
                        <div>
                          <label class="block text-xs font-medium text-muted-foreground mb-1">{{ trans('impostazioni.label.backup_password') }}</label>
                          <div class="relative">
                            <Input
                              v-model="settingsForm.password"
                              :type="showPassword ? 'text' : 'password'"
                              autocomplete="new-password"
                              class="pr-9"
                              :class="passwordError ? 'border-red-400 focus-visible:ring-red-400' : ''"
                              :placeholder="trans('impostazioni.placeholder.backup_password')"
                            />
                            <button
                              type="button"
                              class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground outline-none"
                              :aria-label="trans(showPassword ? 'impostazioni.label.backup_password_hide' : 'impostazioni.label.backup_password_show')"
                              @click="showPassword = !showPassword"
                            >
                              <EyeOff v-if="showPassword" class="w-4 h-4" />
                              <Eye v-else class="w-4 h-4" />
                            </button>
                          </div>
                          <p v-if="passwordError" class="text-[11px] text-red-600 mt-1">{{ passwordError }}</p>
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-muted-foreground mb-1">{{ trans('impostazioni.label.backup_password_confirm') }}</label>
                          <div class="relative">
                            <Input
                              v-model="settingsForm.password_confirmation"
                              :type="showPassword ? 'text' : 'password'"
                              autocomplete="new-password"
                              class="pr-9"
                              :class="confirmError ? 'border-red-400 focus-visible:ring-red-400' : ''"
                              :placeholder="trans('impostazioni.placeholder.backup_password')"
                            />
                            <button
                              type="button"
                              class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground outline-none"
                              :aria-label="trans(showPassword ? 'impostazioni.label.backup_password_hide' : 'impostazioni.label.backup_password_show')"
                              @click="showPassword = !showPassword"
                            >
                              <EyeOff v-if="showPassword" class="w-4 h-4" />
                              <Eye v-else class="w-4 h-4" />
                            </button>
                          </div>
                          <p v-if="confirmError" class="text-[11px] text-red-600 mt-1">{{ confirmError }}</p>
                        </div>
                      </div>
                      <div class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800/50 dark:bg-amber-900/20">
                        <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <p class="text-[12px] leading-relaxed text-amber-800 dark:text-amber-200/90">
                          {{ trans('impostazioni.label.backup_password_warning') }}
                        </p>
                      </div>
                    </div>

                    <!-- Azioni password (il salvataggio passa dal pulsante in fondo) -->
                    <div class="flex flex-wrap items-center gap-2 mt-3">
                      <Button v-if="editingPassword" type="button" variant="outline" size="sm" @click="cancelEditingPassword">
                        {{ trans('impostazioni.actions.cancel_backup') }}
                      </Button>
                      <template v-else>
                        <Button type="button" variant="outline" size="sm" @click="startEditingPassword">
                          {{ hasSavedPassword ? trans('impostazioni.actions.change_backup_password') : trans('impostazioni.actions.set_backup_password') }}
                        </Button>
                        <Button
                          v-if="hasSavedPassword"
                          type="button"
                          variant="outline"
                          size="sm"
                          class="text-red-600 hover:text-red-700"
                          @click="isRemovePasswordDialogOpen = true"
                        >
                          {{ trans('impostazioni.actions.remove_backup_password') }}
                        </Button>
                      </template>
                    </div>
                  </div>
                </template>
              </CardContent>
              <CardFooter class="flex items-center justify-end gap-4 border-t px-6 py-4 bg-slate-50/50 dark:bg-slate-900/20 rounded-b-xl mt-6">
                <Button :disabled="settingsForm.processing">
                  <span v-if="settingsForm.processing" class="animate-spin inline-block h-4 w-4 border-2 border-current rounded-full border-t-transparent mr-2" />
                  {{ trans('impostazioni.actions.save_settings') }}
                </Button>
              </CardFooter>
            </Card>
          </form>
        </div>
      </div>
    </div>

    <ConfirmDialog
      v-model:modelValue="isDeleteDialogOpen"
      :title="isRunning(backupToDelete) ? trans('impostazioni.confirmations.cancel_backup_title') : trans('impostazioni.confirmations.delete_backup_title')"
      :description="isRunning(backupToDelete) ? trans('impostazioni.confirmations.cancel_backup_description') : trans('impostazioni.confirmations.delete_backup_description')"
      @confirm="confirmDelete"
    />

    <ConfirmDialog
      v-model:modelValue="isRemovePasswordDialogOpen"
      :title="trans('impostazioni.confirmations.remove_backup_password_title')"
      :description="trans('impostazioni.confirmations.remove_backup_password_description')"
      @confirm="removePassword"
    />

    <!-- Dialog di conferma ripristino: operazione distruttiva → sudo + avvisi -->
    <Dialog v-model:open="isRestoreDialogOpen">
      <DialogContent class="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle class="flex items-center gap-2">
            <RotateCcw class="w-5 h-5 text-amber-600" />
            {{ trans('impostazioni.confirmations.restore_backup_title') }}
          </DialogTitle>
          <DialogDescription>
            {{ trans('impostazioni.confirmations.restore_backup_description') }}
          </DialogDescription>
        </DialogHeader>

        <div v-if="backupToRestore" class="space-y-4">
          <!-- Riepilogo del backup da ripristinare -->
          <div class="rounded-lg border bg-muted/40 p-3 text-sm space-y-1">
            <div class="flex items-center justify-between">
              <span class="text-muted-foreground">{{ trans('impostazioni.label.backup_date') }}</span>
              <span class="font-medium">{{ formatDate(backupToRestore.created_at) }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-muted-foreground">{{ trans('impostazioni.label.backup_type_title') }}</span>
              <span class="font-medium flex items-center gap-1">
                <Database v-if="backupToRestore.type === 'db_only'" class="w-3.5 h-3.5" />
                <FileArchive v-else class="w-3.5 h-3.5" />
                {{ backupToRestore.type === 'db_only' ? trans('impostazioni.label.backup_type_db') : trans('impostazioni.label.backup_type_full') }}
              </span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-muted-foreground">{{ trans('impostazioni.label.backup_size') }}</span>
              <span class="font-medium">{{ humanSize(backupToRestore.size) }}</span>
            </div>
          </div>

          <!-- Avviso distruttivo -->
          <div class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800/50 dark:bg-amber-900/20">
            <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" />
            <p class="text-[12px] leading-relaxed text-amber-800 dark:text-amber-200/90">
              {{ trans('impostazioni.label.restore_warning') }}
            </p>
          </div>

          <!-- Password dell'archivio (solo se cifrato) -->
          <div v-if="backupToRestore.encrypted">
            <label class="block text-xs font-medium text-muted-foreground mb-1">
              {{ trans('impostazioni.label.restore_archive_password') }}
            </label>
            <div class="relative">
              <Input
                v-model="restoreArchivePassword"
                :type="showRestoreArchivePassword ? 'text' : 'password'"
                autocomplete="off"
                class="pr-9"
                :class="restoreErrors.password ? 'border-red-400 focus-visible:ring-red-400' : ''"
                :placeholder="trans('impostazioni.placeholder.backup_password')"
              />
              <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                @click="showRestoreArchivePassword = !showRestoreArchivePassword">
                <EyeOff v-if="showRestoreArchivePassword" class="w-4 h-4" />
                <Eye v-else class="w-4 h-4" />
              </button>
            </div>
            <p v-if="restoreErrors.password" class="text-[11px] text-red-600 mt-1">{{ restoreErrors.password }}</p>
          </div>

          <!-- Backup di sicurezza -->
          <label class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer hover:bg-muted/40">
            <Switch v-model="restoreSafetyBackup" class="mt-0.5" />
            <span>
              <span class="block text-sm font-medium">{{ trans('impostazioni.label.restore_safety_backup') }}</span>
              <span class="block text-[11px] text-muted-foreground mt-0.5">{{ trans('impostazioni.label.restore_safety_backup_hint') }}</span>
            </span>
          </label>

          <!-- Password account (sudo) -->
          <div>
            <label class="block text-xs font-medium text-muted-foreground mb-1">
              {{ trans('impostazioni.label.restore_account_password') }}
            </label>
            <div class="relative">
              <Input
                v-model="restoreAccountPassword"
                :type="showRestoreAccountPassword ? 'text' : 'password'"
                autocomplete="current-password"
                class="pr-9"
                :class="restoreErrors.account_password ? 'border-red-400 focus-visible:ring-red-400' : ''"
                :placeholder="trans('impostazioni.placeholder.restore_account_password')"
              />
              <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                @click="showRestoreAccountPassword = !showRestoreAccountPassword">
                <EyeOff v-if="showRestoreAccountPassword" class="w-4 h-4" />
                <Eye v-else class="w-4 h-4" />
              </button>
            </div>
            <p v-if="restoreErrors.account_password" class="text-[11px] text-red-600 mt-1">{{ restoreErrors.account_password }}</p>
          </div>

          <p v-if="restoreErrors.archive" class="text-[12px] text-red-600">{{ restoreErrors.archive }}</p>
        </div>

        <DialogFooter class="gap-2">
          <Button type="button" variant="outline" @click="isRestoreDialogOpen = false">
            {{ trans('impostazioni.actions.cancel_backup') }}
          </Button>
          <Button type="button" class="bg-amber-600 hover:bg-amber-700 text-white" :disabled="restoreSubmitting || !restoreAccountPassword" @click="submitRestore">
            <Loader2 v-if="restoreSubmitting" class="w-4 h-4 mr-2 animate-spin" />
            <RotateCcw v-else class="w-4 h-4 mr-2" />
            {{ trans('impostazioni.actions.confirm_restore') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Overlay a pieno schermo durante il ripristino -->
    <div v-if="restoreRunning" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/90 p-4">
      <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-8 text-center shadow-2xl">
        <template v-if="restorePhase !== 'failed'">
          <Loader2 class="w-10 h-10 mx-auto mb-4 animate-spin text-amber-600" />
          <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ trans('impostazioni.label.restore_in_progress') }}</h2>
          <p class="mt-2 text-sm text-muted-foreground">{{ restorePhaseLabel }}</p>
          <p class="mt-4 text-[12px] text-muted-foreground">{{ trans('impostazioni.label.restore_do_not_close') }}</p>
        </template>
        <template v-else>
          <XCircle class="w-10 h-10 mx-auto mb-4 text-red-600" />
          <h2 class="text-lg font-bold text-red-700 dark:text-red-400">{{ trans('impostazioni.label.restore_failed') }}</h2>
          <p class="mt-2 text-sm text-muted-foreground">{{ trans('impostazioni.restore_recovery.failed_body') }}</p>

          <!-- Dettagli tecnici + log copiabile per l'assistenza -->
          <details class="mt-4 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-3 text-left">
            <summary class="cursor-pointer select-none text-[12px] font-medium text-muted-foreground">{{ trans('impostazioni.restore_recovery.details') }}</summary>
            <pre class="mt-2 max-h-32 overflow-auto whitespace-pre-wrap break-words rounded border border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-900 p-2 font-mono text-[10px] leading-relaxed text-muted-foreground">{{ restoreSupportLog }}</pre>
            <p class="mt-1.5 text-[11px] text-muted-foreground">{{ trans('impostazioni.restore_recovery.log_intro') }}</p>
            <button type="button" @click="copyRestoreLog" class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-2.5 py-1 text-[12px] font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700">
              <component :is="restoreCopied ? Check : Copy" class="h-3.5 w-3.5" />
              {{ restoreCopied ? trans('impostazioni.restore_recovery.copied') : trans('impostazioni.restore_recovery.copy_log') }}
            </button>
          </details>

          <!-- Azioni di recupero: token ancora in memoria (overlay aperto) -->
          <div class="mt-5 flex flex-col gap-2">
            <Button type="button" class="bg-slate-900 hover:bg-slate-800 text-white dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white" :disabled="restoreRecovering" @click="resumeRestore">
              <Loader2 v-if="restoreRecovering" class="w-4 h-4 mr-2 animate-spin" />
              {{ trans('impostazioni.restore_recovery.resume') }}
            </Button>
            <Button type="button" variant="outline" class="border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400" :disabled="restoreRecovering" @click="abortRestore">
              {{ trans('impostazioni.restore_recovery.unlock') }}
            </Button>
            <p class="text-[11px] text-muted-foreground">{{ trans('impostazioni.restore_recovery.unlock_note') }}</p>
          </div>
        </template>
      </div>
    </div>

    <BackupGuide v-model:open="showGuide" />
  </AppLayout>
</template>
