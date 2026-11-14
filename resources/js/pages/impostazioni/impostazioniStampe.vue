<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { ref, computed, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { Printer, Trash, Upload } from 'lucide-vue-next'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'
import Alert from '@/components/Alert.vue'

import { FileText, PenTool, LayoutTemplate, Info } from 'lucide-vue-next'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import PrintSetupGuide from '@/components/guides/PrintSetupGuide.vue'
import CarteDelloStudio from '@/components/stampe/CarteDelloStudio.vue'

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

// I valori di partenza del modulo, letti una volta. Dopo un salvataggio riuscito i campi dello studio si rileggono dalle props nuove, cioè come il
// server li ha salvati (senza spazi, il codice fiscale in maiuscolo); un rifiuto lascia quello che si era scritto.
const {
  nota_legale_stampe,
  /** Limite di caricamento già scritto per l'utente («2 MB»), calcolato dal server. */
  limiteFirma,
  /** Lo studio in testa alle stampe. */
  studio,
} = page.props as any

// Quello che il salvataggio cambia, letto dal vivo: dopo una richiesta Inertia tiene la pagina e le dà le props nuove.
const props = computed(() => page.props as any)
/** L'indirizzo della firma e del logo salvati, se ci sono. */
const firmaSalvata = computed<string | null>(() => props.value.firma_stampe_url || null)
const logoSalvato = computed<string | null>(() => props.value.logo_stampe_url || null)
/** Perché la firma salvata pesa su ogni stampa e va caricata di nuovo; null se non serve. */
const firmaDaRicaricareSalvata = computed<string | null>(() => props.value.firma_da_ricaricare || null)
/** Le carte dello studio, la carta di ogni gruppo di documenti (nullo: la predefinita) e i cinque gruppi con i loro esempi. */
const carte = computed(() => props.value.carte ?? [])
const cartaPerGruppo = computed(() => props.value.carta_per_gruppo ?? {})
const gruppiCarta = computed(() => props.value.gruppi_carta ?? [])
/** Il file più grande della carta intestata, già scritto per chi legge, dal server. */
const limiteCarta = computed<string | undefined>(() => props.value.limiteCarta ?? undefined)

/** Lo studio come l'ha salvato il server. */
const studioSalvato = computed<Record<string, string | null | undefined>>(() => props.value.studio ?? {})

const flashMessage = computed(() => props.value.flash?.message)

/** Il messaggio in cima è quello del modulo delle impostazioni: le carte scrivono il loro nella loro sezione. */
const messaggioDelleCarte = ref(false)

// Le carte per prime: la pagina parla soprattutto dello studio in testa alle stampe; poi il piede e la firma.
const pageGuides = computed(() => [
  {
    title: trans('impostazioni.guides.print_layout_title'),
    description: trans('impostazioni.guides.print_layout_desc'),
    icon: LayoutTemplate,
    colorVariant: 'amber' as const
  },
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
  }
])

const form = useForm({
  nota_legale_stampe: (nota_legale_stampe as string) || '',
  firma_stampe: null as File | null,
  delete_firma_stampe: false,
  logo_stampe: null as File | null,
  delete_logo_stampe: false,
  studio_nome: studio?.nome ?? '',
  studio_indirizzo: studio?.indirizzo ?? '',
  studio_email: studio?.email ?? '',
  studio_pec: studio?.pec ?? '',
  studio_telefono: studio?.telefono ?? '',
  studio_comune: studio?.comune ?? '',
  studio_partita_iva: studio?.partita_iva ?? '',
  studio_codice_fiscale: studio?.codice_fiscale ?? '',
  studio_sito: studio?.sito ?? '',
})

/** I campi dello studio: in testa alle stampe, accanto al condominio. */
const campiStudio = [
  { campo: 'studio_nome', etichetta: 'Nome dello studio', tipo: 'text' },
  { campo: 'studio_indirizzo', etichetta: 'Indirizzo', tipo: 'text' },
  { campo: 'studio_partita_iva', etichetta: 'Partita IVA (11 cifre)', tipo: 'text' },
  { campo: 'studio_codice_fiscale', etichetta: 'Codice fiscale', tipo: 'text' },
  { campo: 'studio_email', etichetta: 'Email (le risposte alle lettere arrivano qui)', tipo: 'email' },
  { campo: 'studio_pec', etichetta: 'PEC', tipo: 'email' },
  { campo: 'studio_telefono', etichetta: 'Telefono', tipo: 'text' },
  { campo: 'studio_sito', etichetta: 'Sito', tipo: 'text' },
  { campo: 'studio_comune', etichetta: 'Comune (accanto all\'indirizzo e nel luogo delle lettere; se è vuoto, vale quello del condominio)', tipo: 'text' },
] as const

const showGuide = ref(false)

const signaturePreviewUrl = ref<string | null>(firmaSalvata.value)

/** La richiesta di caricare di nuovo la firma: sparisce quando se ne sceglie una nuova. */
const firmaDaRicaricare = ref<string | null>(firmaDaRicaricareSalvata.value)

const handleSignatureUpload = (e: Event) => {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    const file = target.files[0]
    form.firma_stampe = file
    form.delete_firma_stampe = false
    signaturePreviewUrl.value = URL.createObjectURL(file)
    firmaDaRicaricare.value = null
  }
}

const removeSignature = () => {
  form.firma_stampe = null
  form.delete_firma_stampe = true
  signaturePreviewUrl.value = null
  const input = document.getElementById('firma-stampe-upload') as HTMLInputElement
  if (input) input.value = ''
}

/** Il logo dello studio: come la firma, un PNG o un JPG; si stampa accanto al nome dello studio. */
const anteprimaLogo = ref<string | null>(logoSalvato.value)

// Un salvataggio che cambia la firma o il logo (caricato, o tolto): l'anteprima è quella del server, e il file scelto non si manda di nuovo. Un
// rifiuto rimanda gli stessi indirizzi, e quello che si era scelto resta da mandare.
watch(firmaSalvata, (nuova) => {
  signaturePreviewUrl.value = nuova
  form.firma_stampe = null
  form.delete_firma_stampe = false
  const input = document.getElementById('firma-stampe-upload') as HTMLInputElement | null
  if (input) input.value = ''
})
watch(logoSalvato, (nuovo) => {
  anteprimaLogo.value = nuovo
  form.logo_stampe = null
  form.delete_logo_stampe = false
  const input = document.getElementById('logo-stampe-upload') as HTMLInputElement | null
  if (input) input.value = ''
})
watch(firmaDaRicaricareSalvata, (nuova) => {
  firmaDaRicaricare.value = nuova
})

const caricaLogo = (e: Event) => {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    const file = target.files[0]
    form.logo_stampe = file
    form.delete_logo_stampe = false
    anteprimaLogo.value = URL.createObjectURL(file)
  }
}

const rimuoviLogo = () => {
  form.logo_stampe = null
  form.delete_logo_stampe = true
  anteprimaLogo.value = null
  const input = document.getElementById('logo-stampe-upload') as HTMLInputElement
  if (input) input.value = ''
}

const submit = () => {
  messaggioDelleCarte.value = false
  form.post(route('impostazioni.stampe.store'), {
    preserveScroll: true,
    onSuccess: () => {
      for (const { campo } of campiStudio) (form as any)[campo] = studioSalvato.value[campo.slice('studio_'.length)] ?? ''
    },
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
            Guida delle stampe
          </Button>
        </template>
      </PageHeaderGuide>

      <div v-if="flashMessage && !messaggioDelleCarte" class="py-2">
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
                <p v-if="firmaDaRicaricare" class="text-sm text-amber-700 dark:text-amber-400 mt-1" data-testid="firma-da-ricaricare">{{ firmaDaRicaricare }}</p>
                <p v-if="form.errors.firma_stampe" class="text-sm text-red-500 mt-1">
                  {{ form.errors.firma_stampe }}
                </p>
              </div>
            </div>

            <div class="pt-4 space-y-3" data-testid="studio-delle-stampe">
              <div>
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">Lo studio, in testa alle stampe</h3>
                <p class="text-sm text-muted-foreground">
                  In testa a tutte le stampe, tranne il modello F24, con una carta a campi: a sinistra il logo e lo studio con i suoi dati, a destra il condominio.
                  Nelle lettere, nelle ricevute e negli avvisi lo studio sta in alto a sinistra e sotto il condominio, perché a destra c'è il destinatario (nelle ricevute, quando vanno per posta); con la
                  carta intestata, lo studio è quello stampato sulla carta. Senza il nome dello studio la testata delle stampe a campi è quella del solo condominio.
                </p>
              </div>
              <div class="grid gap-3 sm:grid-cols-2">
                <label v-for="c in campiStudio" :key="c.campo" class="space-y-1">
                  <span class="block text-xs text-slate-500 dark:text-slate-400">{{ c.etichetta }}</span>
                  <Input v-model="(form as any)[c.campo]" :type="c.tipo" class="h-9 text-sm" :data-testid="c.campo" />
                  <span v-if="(form.errors as any)[c.campo]" class="block text-sm text-red-500">{{ (form.errors as any)[c.campo] }}</span>
                </label>
              </div>
              <div class="space-y-1">
                <span class="block text-xs text-slate-500 dark:text-slate-400">Logo dello studio</span>
                <div class="flex items-center gap-4">
                  <div
                    class="relative h-16 w-40 border-2 border-dashed rounded-lg flex items-center justify-center bg-gray-50 overflow-hidden"
                    :class="{'border-gray-300': !anteprimaLogo, 'border-emerald-500': anteprimaLogo}"
                  >
                    <img v-if="anteprimaLogo" :src="anteprimaLogo" class="max-h-full max-w-full object-contain" data-testid="anteprima-logo" />
                    <span v-else class="text-xs text-gray-500">Nessun logo</span>
                  </div>
                  <div class="flex flex-col gap-2">
                    <input type="file" id="logo-stampe-upload" accept="image/jpeg,image/png" class="hidden" @change="caricaLogo" />
                    <label for="logo-stampe-upload" class="cursor-pointer inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring border border-input bg-transparent shadow-sm hover:bg-accent hover:text-accent-foreground h-9 px-4 py-2">
                      Carica il logo
                    </label>
                    <Button v-if="anteprimaLogo" type="button" variant="destructive" size="sm" @click="rimuoviLogo" class="w-full" data-testid="rimuovi-logo">
                      <Trash class="w-4 h-4 mr-2" />
                      Rimuovi il logo
                    </Button>
                  </div>
                </div>
                <p class="text-sm text-muted-foreground">
                  PNG o JPG, fino a {{ limiteFirma }}. Si stampa accanto al nome dello studio, alto al massimo 14 mm e largo al massimo 40 mm; uno sfondo trasparente resta trasparente.
                </p>
                <p v-if="form.errors.logo_stampe" class="text-sm text-red-500">{{ form.errors.logo_stampe }}</p>
              </div>
            </div>

            <!-- I modelli delle lettere hanno la loro pagina: un riquadro per arrivarci. -->
            <div class="pt-4" data-testid="riquadro-modelli-lettere">
              <div class="flex flex-wrap items-center gap-3 rounded-lg border p-4">
                <div class="min-w-0 flex-1">
                  <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">Modelli delle lettere</h3>
                  <p class="text-sm text-muted-foreground">
                    I modelli delle lettere (promemoria, solleciti, diffide e messe in mora), per tutti i condomini o per uno: nella finestra della lettera li scegli, e il testo resta modificabile per ogni invio.
                  </p>
                </div>
                <Link :href="route('impostazioni.stampe.modelli-lettere')" class="inline-flex h-9 items-center rounded-md border px-4 text-sm font-medium hover:bg-accent">
                  Apri i modelli delle lettere
                </Link>
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

      <!-- Le carte si salvano da sole, ognuna con il suo pulsante: fuori dal modulo delle impostazioni. -->
      <Card>
        <CardContent class="pt-6">
          <CarteDelloStudio :carte="carte" :carta-per-gruppo="cartaPerGruppo" :gruppi="gruppiCarta" :limite-carta="limiteCarta" @richiesta="messaggioDelleCarte = true" />
        </CardContent>
      </Card>
    </div>

    <PrintSetupGuide v-model:open="showGuide" />
  </AppLayout>
</template>
