<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Alert from '@/components/Alert.vue'
import BackupGuide from '@/components/guides/BackupGuide.vue'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'
import type { BackupItem, BackupPreflight } from '@/types/backups'
import {
  DatabaseBackup, FileArchive, ShieldAlert, ArchiveRestore, Download, Trash2,
  Copy, Check, CheckCircle2, AlertCircle, Loader2, XCircle, Play, BookOpen
} from 'lucide-vue-next'

const showGuide = ref(false)

const props = defineProps<{
  backups: any;
  runningBackup: BackupItem | null;
  preflight: BackupPreflight;
  retention_keep_last: number;
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

async function createBackup() {
  if (creating.value || isRunning(running.value)) return

  creating.value = true
  localError.value = null
  localSuccess.value = null

  try {
    const { data } = await axios.post(route('impostazioni.backups.store'))
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
 | Retention
 | ------------------------------------------------------------------ */
const retentionForm = useForm({
  retention_keep_last: props.retention_keep_last,
})

const saveRetention = () => {
  retentionForm.post(route('impostazioni.backups.settings'), { preserveScroll: true })
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
            Guida al ripristino
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
                <div v-if="check.key === 'db_driver' && check.detail.driver" class="text-[11px] text-muted-foreground uppercase font-mono">
                  {{ check.detail.driver }}
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

      <!-- Creazione backup -->
      <Card>
        <CardContent class="pt-6">
          <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
              <h3 class="text-sm font-semibold">{{ trans('impostazioni.label.backup_create_title') }}</h3>
              <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                {{ trans('impostazioni.label.backup_create_help') }}
              </p>
            </div>

            <div class="flex items-center gap-2 shrink-0">
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
                :disabled="creating || !preflight.ok"
                @click="createBackup"
              >
                <Loader2 v-if="creating" class="w-4 h-4 mr-2 animate-spin" />
                <DatabaseBackup v-else class="w-4 h-4 mr-2" />
                {{ trans('impostazioni.actions.create_backup') }}
              </Button>
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

      <!-- Elenco backup -->
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4 bg-white dark:bg-card">
        <div class="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow class="bg-muted/50">
                <TableHead class="w-[150px]">{{ trans('impostazioni.label.backup_status') }}</TableHead>
                <TableHead>{{ trans('impostazioni.label.backup_date') }}</TableHead>
                <TableHead class="hidden md:table-cell">{{ trans('impostazioni.label.backup_size') }}</TableHead>
                <TableHead class="hidden lg:table-cell">SHA-256</TableHead>
                <TableHead class="text-right">{{ trans('impostazioni.label.backup_actions') }}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <!-- Riga "live" del backup in corso -->
              <TableRow v-if="isRunning(running)" class="bg-muted/40 dark:bg-muted/10">
                <TableCell class="p-2">
                  <Badge variant="secondary" class="rounded-md whitespace-nowrap text-[10px] font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">
                    <Loader2 class="w-3 h-3 mr-1 animate-spin" />
                    {{ phaseLabel(running!.status) }}
                  </Badge>
                </TableCell>
                <TableCell class="text-sm">
                  <div class="font-medium">{{ formatDate(running!.created_at) }}</div>
                  <div v-if="running!.created_by" class="text-[11px] text-muted-foreground">{{ running!.created_by }}</div>
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
                <TableCell class="hidden lg:table-cell">
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
                  <Badge variant="secondary" class="rounded-md whitespace-nowrap text-[10px] font-medium" :class="statusBadgeClass(backup.status)">
                    {{ phaseLabel(backup.status) }}
                  </Badge>
                </TableCell>
                <TableCell class="text-sm">
                  <div class="font-medium">{{ formatDate(backup.created_at) }}</div>
                  <div v-if="backup.created_by" class="text-[11px] text-muted-foreground">{{ backup.created_by }}</div>
                  <div v-if="backup.status === 'failed' && backup.error" class="text-[11px] text-red-600 max-w-[300px] truncate" :title="backup.error">
                    {{ backup.error }}
                  </div>
                </TableCell>
                <TableCell class="hidden md:table-cell text-sm whitespace-nowrap">
                  {{ humanSize(backup.size) }}
                </TableCell>
                <TableCell class="hidden lg:table-cell">
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
          </Table>
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
      </div>

      <!-- Conservazione -->
      <form @submit.prevent="saveRetention">
        <Card>
          <CardContent class="pt-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div>
                <h3 class="text-sm font-semibold">{{ trans('impostazioni.label.backup_retention') }}</h3>
                <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                  {{ trans('impostazioni.label.backup_retention_help') }}
                </p>
                <p v-if="retentionForm.errors.retention_keep_last" class="text-sm text-red-500 mt-1">
                  {{ retentionForm.errors.retention_keep_last }}
                </p>
              </div>
              <div class="w-28 shrink-0">
                <Input
                  v-model.number="retentionForm.retention_keep_last"
                  type="number"
                  min="1"
                  max="50"
                />
              </div>
            </div>
          </CardContent>
          <CardFooter class="flex items-center justify-end gap-4 border-t px-6 py-4 bg-slate-50/50 dark:bg-slate-900/20 rounded-b-xl mt-6">
            <Button :disabled="retentionForm.processing">
              <span v-if="retentionForm.processing" class="animate-spin inline-block h-4 w-4 border-2 border-current rounded-full border-t-transparent mr-2" />
              {{ trans('impostazioni.actions.save_settings') }}
            </Button>
          </CardFooter>
        </Card>
      </form>
    </div>

    <ConfirmDialog
      v-model:modelValue="isDeleteDialogOpen"
      :title="isRunning(backupToDelete) ? trans('impostazioni.confirmations.cancel_backup_title') : trans('impostazioni.confirmations.delete_backup_title')"
      :description="isRunning(backupToDelete) ? trans('impostazioni.confirmations.cancel_backup_description') : trans('impostazioni.confirmations.delete_backup_description')"
      @confirm="confirmDelete"
    />

    <BackupGuide v-model:open="showGuide" />
  </AppLayout>
</template>
