<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { ref } from 'vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { Printer, Trash, Upload } from 'lucide-vue-next'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'
import Alert from '@/components/Alert.vue'
import { computed } from 'vue'
import { FileText, PenTool, LayoutTemplate } from 'lucide-vue-next'

const page = usePage()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: trans('impostazioni.label.settings') || 'Impostazioni',
    href: '/impostazioni',
  },
  {
    title: trans('impostazioni.dialogs.print_settings_title') || 'Stampe PDF',
    href: '/impostazioni/stampe',
  },
]

const {
  nota_legale_stampe,
  firma_stampe_url,
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
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head :title="trans('impostazioni.dialogs.print_settings_title') || 'Impostazioni Stampe PDF'" />

    <div class="px-4 py-6 space-y-6">
      <PageHeaderGuide
        :page-title="trans('impostazioni.dialogs.print_settings_title') || 'Impostazioni Stampe PDF'"
        :page-description="trans('impostazioni.dialogs.print_settings_description') || 'Configura l\'aspetto e i dati predefiniti che appariranno in fondo a tutti i documenti generati dal sistema.'"
        :icon="Printer"
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
        <Card>
          <CardContent class="pt-6 space-y-6">
            
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ trans('impostazioni.label.print_legal_note') || 'Nota Legale / Footer' }}</label>
                <Textarea 
                  v-model="form.nota_legale_stampe" 
                  rows="3" 
                  :placeholder="trans('impostazioni.placeholder.print_legal_note') || 'Es: Professione esercitata ai sensi della legge 14 gennaio 2013, n.4 - P.IVA 01234567890 - Polizza RC n. XYZ'" 
                />
                <p class="text-sm text-muted-foreground mt-1">
                  {{ trans('impostazioni.label.print_legal_note_help') || 'Questo testo apparirà nel footer (piè di pagina) di ogni prospetto, assieme al numero di pagina.' }}
                </p>
                <p v-if="form.errors.nota_legale_stampe" class="text-sm text-red-500 mt-1">
                  {{ form.errors.nota_legale_stampe }}
                </p>
              </div>

              <div class="pt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ trans('impostazioni.label.print_admin_signature') || 'Firma Amministratore' }}</label>
                <div class="flex items-center gap-4">
                  <div 
                    class="relative h-24 w-48 border-2 border-dashed rounded-lg flex items-center justify-center bg-gray-50 overflow-hidden"
                    :class="{'border-gray-300': !signaturePreviewUrl, 'border-emerald-500': signaturePreviewUrl}"
                  >
                    <img v-if="signaturePreviewUrl" :src="signaturePreviewUrl" class="max-h-full max-w-full object-contain" />
                    <div v-else class="text-center p-4">
                      <Upload class="w-6 h-6 mx-auto text-gray-400 mb-1" />
                      <span class="text-xs text-gray-500">{{ trans('impostazioni.label.print_no_signature') || 'Nessuna firma' }}</span>
                    </div>
                  </div>
                  
                  <div class="flex flex-col gap-2">
                    <div>
                      <input type="file" id="firma-stampe-upload" accept="image/*" class="hidden" @change="handleSignatureUpload" />
                      <label for="firma-stampe-upload" class="cursor-pointer inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring border border-input bg-transparent shadow-sm hover:bg-accent hover:text-accent-foreground h-9 px-4 py-2">
                        {{ trans('impostazioni.label.print_upload_image') || 'Carica Immagine' }}
                      </label>
                    </div>
                    <Button v-if="signaturePreviewUrl" type="button" variant="destructive" size="sm" @click="removeSignature" class="w-full">
                      <Trash class="w-4 h-4 mr-2" />
                      {{ trans('impostazioni.label.print_remove_signature') || 'Rimuovi' }}
                    </Button>
                  </div>
                </div>
                <p class="text-sm text-muted-foreground mt-2">
                  {{ trans('impostazioni.label.print_signature_help') || 'Usa un\'immagine PNG o JPG con sfondo bianco o trasparente (max 2MB). L\'immagine verrà stampata alla fine dell\'ultimo foglio di prospetti e rendiconti.' }}
                </p>
                <p v-if="form.errors.firma_stampe" class="text-sm text-red-500 mt-1">
                  {{ form.errors.firma_stampe }}
                </p>
              </div>
            </div>

          </CardContent>

          <CardFooter class="px-0 pt-6 flex items-center gap-4 border-t px-6 pb-6 mt-6">
            <Button :disabled="form.processing" class="mt-6">
              <span v-if="form.processing" class="animate-spin inline-block h-4 w-4 border-2 border-current rounded-full border-t-transparent mr-2" />
              {{ trans('impostazioni.actions.save_settings') || 'Salva Impostazioni' }}
            </Button>
          </CardFooter>
        </Card>
      </form>
    </div>
  </AppLayout>
</template>
