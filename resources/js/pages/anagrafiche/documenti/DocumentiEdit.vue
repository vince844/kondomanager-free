<script setup lang="ts">
/**
 * La modifica di un documento sulla scheda di un'anagrafica.
 *
 * Stessa pagina del caricamento, con una differenza sola e importante: **il file è facoltativo**.
 * Assente significa «tieni quello che c'è», presente significa «sostituiscilo». Renderlo
 * obbligatorio costringerebbe a ricaricare lo stesso PDF per correggere un nome scritto male.
 *
 * ⚠️ L'invio è un **POST con `_method: 'put'`**: un `PUT` con un file non esiste nei moduli HTML, e
 * mandandolo come `PUT` vero il file non arriverebbe — il documento si aggiornerebbe tenendo il
 * vecchio allegato, senza un errore che lo dica.
 */
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import InputError from '@/components/InputError.vue';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Item } from '@/components/ui/item';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Eye, FileText, FileUp, Info, LoaderCircle, Save, UploadCloud, X } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import vSelect from 'vue-select';
import { publishedConstants } from '@/lib/documenti/constants';
import type { PublishedType } from '@/types/documenti';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  /** Limite di caricamento già scritto per l'utente («20 MB»), calcolato dal server: non è un numero nostro. */
  limiteFile: string;
  anagrafica: { id: number; nome: string };
  documento: {
    id: number;
    name: string;
    description: string | null;
    is_published: boolean;
    is_approved: boolean;
    mime_type: string | null;
  };
}>();

const { generatePath, generateRoute } = usePermission();

const file = ref<File | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);

const form = useForm<{
  name: string;
  description: string;
  is_published: boolean;
  is_approved: boolean;
  file: File | null;
  _method: string;
}>({
  name: props.documento.name ?? '',
  description: props.documento.description ?? '',
  is_published: Boolean(props.documento.is_published),
  is_approved: Boolean(props.documento.is_approved),
  file: null,
  _method: 'put',
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Anagrafiche', href: route(generateRoute('anagrafiche.index')) },
  { title: props.anagrafica.nome, href: generatePath('anagrafiche/:anagrafica', { anagrafica: props.anagrafica.id }) },
  { title: 'Documenti', href: generatePath('anagrafiche/:anagrafica/documenti', { anagrafica: props.anagrafica.id }) },
  { title: 'Modifica documento', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Sostituire il file',
    description: `Lascia l'area vuota per tenere il file attuale. Se ne carichi uno nuovo — PDF, fino a ${props.limiteFile} — sostituisce il vecchio, che viene cancellato dal disco.`,
    icon: FileUp,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Dettagli',
    description: 'Inserisci un nome chiaro e una descrizione facoltativa.',
    icon: FileText,
    colorVariant: 'emerald' as const,
  },
  {
    title: 'Visibilità',
    description: 'Scegli se la persona lo vede nella sua area riservata o se resta a uso interno.',
    icon: Eye,
    colorVariant: 'amber' as const,
  },
]);

function fileDalCampo(evento: Event): void {
  const scelto = (evento.target as HTMLInputElement).files?.[0] ?? null;

  if (scelto) {
    registraFile(scelto);
  }
}

function fileTrascinato(evento: DragEvent): void {
  evento.preventDefault();

  const trascinato = evento.dataTransfer?.files[0] ?? null;

  if (! trascinato) return;

  registraFile(trascinato);

  // Il campo nascosto va allineato a mano: trascinare non lo riempie, e senza questo un invio
  // successivo partirebbe senza file.
  if (fileInputRef.value) {
    const trasferimento = new DataTransfer();
    trasferimento.items.add(trascinato);
    fileInputRef.value.files = trasferimento.files;
  }
}

function registraFile(scelto: File): void {
  file.value = scelto;
  form.file = scelto;
  form.clearErrors('file');

  // ⚠️ Qui il nome **non** si propone dal file: in modifica c'è già, e sovrascriverlo perché si è
  // sostituito l'allegato cancellerebbe una scelta fatta apposta.
}

function togliFile(): void {
  file.value = null;
  form.file = null;

  if (fileInputRef.value) {
    fileInputRef.value.value = '';
  }

  form.clearErrors('file');
}

function salva(): void {
  form.post(
    route(generateRoute('anagrafiche.documenti.update'), {
      anagrafica: props.anagrafica.id,
      documento: props.documento.id,
    }),
    { preserveScroll: true },
  );
}
</script>

<template>
  <Head :title="`Modifica documento — ${anagrafica.nome}`" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">
      <PageHeaderGuide
        :page-title="`Modifica documento: ${documento.name}`"
        :page-subtitle="`Documento archiviato sulla scheda di ${anagrafica.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="generatePath('anagrafiche/:anagrafica/documenti', { anagrafica: props.anagrafica.id })"
        back-text="Torna ai documenti"
      />

      <form class="space-y-6" @submit.prevent="salva">
        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Contenuto documento</CardTitle>
            <CardDescription>Inserisci i dettagli principali e allega il file.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
              <div class="sm:col-span-6">
                <Label for="name">Nome documento</Label>
                <Input
                  id="name"
                  v-model="form.name"
                  class="mt-1 block w-full bg-white dark:bg-slate-950"
                  placeholder="Es. carta d'identità"
                  @focus="form.clearErrors('name')"
                />
                <InputError :message="form.errors.name" />
              </div>

              <div class="sm:col-span-6">
                <Label for="description">Descrizione</Label>
                <Textarea
                  id="description"
                  v-model="form.description"
                  class="mt-1 block w-full min-h-[160px] bg-white dark:bg-slate-950"
                  placeholder="Descrizione facoltativa del documento"
                  @focus="form.clearErrors('description')"
                />
                <InputError :message="form.errors.description" />
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-6">
              <div class="sm:col-span-6">
                <label for="file-upload" class="block cursor-pointer" @dragover.prevent @drop="fileTrascinato">
                  <Empty class="border border-dashed bg-white dark:bg-slate-950 hover:bg-accent/10 transition">
                    <EmptyHeader>
                      <EmptyMedia variant="icon">
                        <UploadCloud class="w-8 h-8 text-muted-foreground" />
                      </EmptyMedia>
                      <EmptyTitle>Sostituisci il file</EmptyTitle>
                      <EmptyDescription>
                        Trascina qui il file nuovo oppure clicca per selezionarlo.
                        <div class="text-xs text-muted-foreground mt-1">
                          Lascia vuoto per tenere quello attuale · solo PDF (max {{ limiteFile }})
                        </div>
                      </EmptyDescription>
                    </EmptyHeader>
                  </Empty>

                  <input
                    id="file-upload"
                    ref="fileInputRef"
                    type="file"
                    class="hidden"
                    accept="application/pdf"
                    @change="fileDalCampo"
                  />
                </label>

                <div v-if="file" class="mt-4">
                  <Item class="flex items-center justify-between border rounded-lg p-3 shadow-sm bg-white dark:bg-slate-950">
                    <div class="flex items-center gap-3">
                      <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                        <FileText class="w-5 h-5 text-muted-foreground" />
                      </div>
                      <div class="flex flex-col">
                        <span class="text-sm font-medium truncate max-w-[250px]">{{ file.name }}</span>
                        <span class="text-xs text-muted-foreground">{{ (file.size / 1024).toFixed(1) }} KB</span>
                      </div>
                    </div>
                    <Button type="button" variant="ghost" size="icon" title="Rimuovi documento" @click.prevent="togliFile">
                      <X class="w-4 h-4 text-red-500 hover:text-red-600" />
                    </Button>
                  </Item>
                </div>

                <InputError :message="form.errors.file" class="mt-2" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Impostazioni</CardTitle>
            <CardDescription>Configura la visibilità del documento.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
              <div class="sm:col-span-3">
                <div class="flex items-center gap-2 min-h-[24px] mb-1">
                  <Label for="is_published">Visibilità</Label>
                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="text-slate-400 hover:text-primary outline-none">
                        <Info class="w-4 h-4" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                      <h4 class="text-sm font-bold mb-2">Visibilità</h4>
                      <p class="text-xs text-slate-500 leading-relaxed">
                        Scegli se la persona vede il documento nella sua area riservata, oppure se resta
                        a uso interno dell'amministratore.
                      </p>
                    </HoverCardContent>
                  </HoverCard>
                </div>
                <v-select
                  id="is_published"
                  v-model="form.is_published"
                  class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                  :options="publishedConstants"
                  label="label"
                  placeholder="Seleziona visibilità"
                  :reduce="(is_published: PublishedType) => is_published.value"
                  @update:model-value="form.clearErrors('is_published')"
                >
                  <template #option="{ label, icon }">
                    <div class="flex items-center gap-2">
                      <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                      <span>{{ trans(label) }}</span>
                    </div>
                  </template>
                  <template #selected-option="{ label, icon }">
                    <div v-if="label" class="flex items-center gap-2">
                      <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                      <span>{{ trans(label) }}</span>
                    </div>
                  </template>
                </v-select>
                <InputError :message="form.errors.is_published" />
              </div>
            </div>
          </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
          <Link
            :href="generatePath('anagrafiche/:anagrafica/documenti', { anagrafica: props.anagrafica.id })"
            class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
          >
            Annulla
          </Link>

          <Button type="submit" :disabled="form.processing" class="h-9 px-8 text-sm font-semibold shadow-md gap-2">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            <Save v-else class="h-4 w-4" />
            Salva modifiche
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<!--
  ⚠️ **Il foglio di stile di `vue-select` va importato dalla pagina che lo usa.**
  Senza questa riga la tendina si disegna nuda: alta il doppio, con la crocetta e la freccia
  impilate sotto al valore invece che in fondo alla riga. Non è un caso isolato — tutte le pagine
  del fornitore che montano `v-select` hanno la stessa riga in coda, e mancava solo qui.
-->
<style src="vue-select/dist/vue-select.css"></style>
