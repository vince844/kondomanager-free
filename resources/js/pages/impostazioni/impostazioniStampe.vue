<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { ref, computed } from 'vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { Printer, Trash, Upload } from 'lucide-vue-next'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'
import Alert from '@/components/Alert.vue'

import { FileText, PenTool, LayoutTemplate, Info } from 'lucide-vue-next'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import PrintSetupGuide from '@/components/guides/PrintSetupGuide.vue'

const page = usePage()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
    title: trans('impostazioni.label.settings'),
    href: '/impostazioni',
  },
  {
    title: trans('impostazioni.dialogs.print_settings_title'),
    href: '/impostazioni/stampe',
  },
])

const {
  nota_legale_stampe,
  firma_stampe_url,
  /** Limite di caricamento già scritto per l'utente («2 MB»), calcolato dal server. */
  limiteFirma,
} = page.props

const flashMessage = computed(() => (page.props as any).flash?.message)

const pageGuides = computed(() => [
  {
    title: trans('impostazioni.guides.print_footer_title'),
    description: trans('impostazioni.guides.print_footer_desc'),
    icon: FileText,
    colorVariant: 'blue' as const
  },
  {
    title: trans('impostazioni.guides.print_signature_title'),
    description: trans('impostazioni.guides.print_signature_desc'),
    icon: PenTool,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('impostazioni.guides.print_layout_title'),
    description: trans('impostazioni.guides.print_layout_desc'),
    icon: LayoutTemplate,
    colorVariant: 'amber' as const
  }
])

const form = useForm({
  nota_legale_stampe: (nota_legale_stampe as string) || '',
  firma_stampe: null as File | null,
  delete_firma_stampe: false,
})

const showGuide = ref(false)

const signaturePreviewUrl = ref<string | null>((firma_stampe_url as string) || null)

const handleSignatureUpload = (e: Event) => {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    const file = target.files[0]
    form.firma_stampe = file
    form.delete_firma_stampe = false
    signaturePreviewUrl.value = URL.createObjectURL(file)
  }
}

const removeSignature = () => {
  form.firma_stampe = null
  form.delete_firma_stampe = true
  signaturePreviewUrl.value = null
  const input = document.getElementById('firma-stampe-upload') as HTMLInputElement
  if (input) input.value = ''
}

const submit = () => {
  form.post(route('impostazioni.stampe.store'), {
    preserveScroll: true,
  })
}
</script>

<template>
  <AppLayout :breadcrumbs="[]">
    <Head :title="trans('impostazioni.dialogs.print_settings_title')" />

    <div class="px-4 py-6 space-y-6">
      <PageHeaderGuide
        :page-title="trans('impostazioni.dialogs.print_settings_title')"
        :page-description="trans('impostazioni.dialogs.print_settings_description')"
        :icon="Printer"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        back-url="/impostazioni"
        :back-text="trans('impostazioni.label.settings')"
        :video-url="null"
      >
        <template #actions>
          <Button variant="outline" size="sm" @click="showGuide = true" class="bg-white gap-2 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800 border-indigo-200">
            <FileText class="w-4 h-4" />
            Guida impaginazione
          </Button>
        </template>
      </PageHeaderGuide>

      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <form @submit.prevent="submit">
        <Card>
          <CardContent class="pt-6 space-y-6">
            
            <div class="space-y-4">
              <div>
                <div class="flex items-center gap-2 mb-1 min-h-[24px]">
                  <label class="block text-sm font-medium text-gray-700">{{ trans('impostazioni.label.print_legal_note') }}</label>
                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="text-slate-400 hover:text-primary outline-none">
                        <Info class="w-4 h-4" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                      <h4 class="text-sm font-bold mb-2">{{ trans('impostazioni.label.print_legal_note') }}</h4>
                      <p class="text-xs text-slate-500 leading-relaxed" v-html="trans('impostazioni.label.print_legal_note_tooltip')"></p>
                    </HoverCardContent>
                  </HoverCard>
                </div>
                <Textarea 
                  v-model="form.nota_legale_stampe" 
                  rows="3" 
                  :placeholder="trans('impostazioni.placeholder.print_legal_note')" 
                />
                <p class="text-sm text-muted-foreground mt-1">
                  {{ trans('impostazioni.label.print_legal_note_help') }}
                </p>
                <p v-if="form.errors.nota_legale_stampe" class="text-sm text-red-500 mt-1">
                  {{ form.errors.nota_legale_stampe }}
                </p>
              </div>

              <div class="pt-4">
                <div class="flex items-center gap-2 mb-1 min-h-[24px]">
                  <label class="block text-sm font-medium text-gray-700">{{ trans('impostazioni.label.print_admin_signature') }}</label>
                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="text-slate-400 hover:text-primary outline-none">
                        <Info class="w-4 h-4" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                      <h4 class="text-sm font-bold mb-2">{{ trans('impostazioni.label.print_admin_signature') }}</h4>
                      <p class="text-xs text-slate-500 leading-relaxed" v-html="trans('impostazioni.label.print_admin_signature_tooltip')"></p>
                    </HoverCardContent>
                  </HoverCard>
                </div>
                <div class="flex items-center gap-4">
                  <div 
                    class="relative h-24 w-48 border-2 border-dashed rounded-lg flex items-center justify-center bg-gray-50 overflow-hidden"
                    :class="{'border-gray-300': !signaturePreviewUrl, 'border-emerald-500': signaturePreviewUrl}"
                  >
                    <img v-if="signaturePreviewUrl" :src="signaturePreviewUrl" class="max-h-full max-w-full object-contain" />
                    <div v-else class="text-center p-4">
                      <Upload class="w-6 h-6 mx-auto text-gray-400 mb-1" />
                      <span class="text-xs text-gray-500">{{ trans('impostazioni.label.print_no_signature') }}</span>
                    </div>
                  </div>
                  
                  <div class="flex flex-col gap-2">
                    <div>
                      <!-- ⚠️ `image/*` comprende GIF, BMP, WEBP **e SVG**: il selettore lasciava scegliere
                           proprio l'SVG che la regola respinge, cioè lo stesso difetto appena tolto
                           dalle quattro schermate dei documenti, spostato di una schermata. -->
                      <input type="file" id="firma-stampe-upload" accept="image/jpeg,image/png" class="hidden" @change="handleSignatureUpload" />
                      <label for="firma-stampe-upload" class="cursor-pointer inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring border border-input bg-transparent shadow-sm hover:bg-accent hover:text-accent-foreground h-9 px-4 py-2">
                        {{ trans('impostazioni.label.print_upload_image') }}
                      </label>
                    </div>
                    <Button v-if="signaturePreviewUrl" type="button" variant="destructive" size="sm" @click="removeSignature" class="w-full">
                      <Trash class="w-4 h-4 mr-2" />
                      {{ trans('impostazioni.label.print_remove_signature') }}
                    </Button>
                  </div>
                </div>
                <p class="text-sm text-muted-foreground mt-2">
                  <!-- ⚠️ Qui c'era `print_signature_help`, che esiste **solo in spagnolo**: in italiano
                       `trans()` restituiva la chiave, e siccome una stringa non vuota è truthy il
                       ripiego scritto accanto non scattava mai — a video compariva
                       «impostazioni.label.print_signature_help». La chiave buona è questa, ed esiste
                       in tutte e quattro le lingue. -->
                  {{ trans('impostazioni.label.print_admin_signature_help', { limite: String(limiteFirma ?? '') }) }}
                </p>
                <p v-if="form.errors.firma_stampe" class="text-sm text-red-500 mt-1">
                  {{ form.errors.firma_stampe }}
                </p>
              </div>
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

    <PrintSetupGuide v-model:open="showGuide" />
  </AppLayout>
</template>
