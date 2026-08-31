<script setup lang="ts">
/**
 * La pagina che gestisce le categorie di fornitore.
 *
 * ## ⚠️ Perché non è una copia della pagina delle categorie documenti
 *
 * Quella monta una tabella TanStack con paginazione e ordinamento **serviti dal server**, cioè
 * quattro file di impalcatura per un elenco che qui parte da diciannove righe e che nessuno farà
 * crescere di mille. Qui l'elenco arriva intero e il filtro è locale: si vede tutto senza pagine, e
 * non c'è una richiesta al server per scrivere una lettera nella casella di ricerca.
 *
 * L'aspetto è lo stesso — stessa intestazione, stessa scheda bordata, stesse azioni per riga — ma
 * la macchina sotto è quella che serve a questo elenco, non quella che serviva a un altro.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import AnagraficheStack from '@/components/AnagraficheStack.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { ArrowLeft, ChevronRight, FilePenLine, MoreHorizontal, Plus, ShieldCheck, Tags, Trash2, Wrench } from 'lucide-vue-next';
import { trans, transChoice } from 'laravel-vue-i18n';
import { usePermission } from '@/composables/permissions';
import { useConfermaEliminazione } from '@/composables/useConfermaEliminazione';
import type { Flash } from '@/types/flash';
import type { BreadcrumbItem } from '@/types';

interface CategoriaFornitore {
  id: number;
  name: string;
  description: string | null;
  fornitori_count: number;
  /** La forma che `AnagraficheStack` si aspetta: l'adattamento lo fa il controller. */
  fornitori: Array<{ id: number; nome: string; indirizzo: string | null; url: string }>;
}

const props = defineProps<{ categorie: CategoriaFornitore[] }>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const { generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('fornitori.header.list_fornitori_head'), href: route(generateRoute('fornitori.index')) },
  { title: trans('fornitori.categorie.head'), href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('fornitori.categorie.guides.own_title'),
    description: trans('fornitori.categorie.guides.own_desc'),
    icon: Tags,
    colorVariant: 'blue' as const,
  },
  {
    title: trans('fornitori.categorie.guides.use_title'),
    description: trans('fornitori.categorie.guides.use_desc'),
    icon: Wrench,
    colorVariant: 'emerald' as const,
  },
  {
    title: trans('fornitori.categorie.guides.safe_title'),
    description: trans('fornitori.categorie.guides.safe_desc'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const,
  },
]);

/* ------------------------------------------------------------------ filtro */

const filtro = ref('');

const elenco = computed(() => {
  const cercato = filtro.value.trim().toLowerCase();

  if (cercato === '') {
    return props.categorie;
  }

  // Anche sulla descrizione: chi cerca «caldaia» deve trovare «Termotecnico e caldaie» pure se ha
  // rinominato la categoria e la parola è rimasta solo nella descrizione.
  return props.categorie.filter(
    (c) => c.name.toLowerCase().includes(cercato) || (c.description ?? '').toLowerCase().includes(cercato),
  );
});

/* ------------------------------------------------------- creazione e modifica */

const formNuova = useForm({ name: '', description: '' });
const nuovaAperta = ref(false);

function apriNuova() {
  formNuova.reset();
  formNuova.clearErrors();
  nuovaAperta.value = true;
}

function creaCategoria() {
  formNuova
    .transform((dati) => ({ ...dati, description: dati.description.trim() || null }))
    .post(route(generateRoute('categorie-fornitore.store')), {
      preserveScroll: true,
      onSuccess: () => {
        formNuova.reset();
        nuovaAperta.value = false;
      },
    });
}

const inModifica = ref<CategoriaFornitore | null>(null);
const formModifica = useForm({ name: '', description: '' });

function apriModifica(categoria: CategoriaFornitore) {
  inModifica.value = categoria;
  formModifica.clearErrors();
  formModifica.name = categoria.name;
  formModifica.description = categoria.description ?? '';
}

function salvaModifica() {
  if (inModifica.value === null) return;

  formModifica
    .transform((dati) => ({ ...dati, description: dati.description.trim() || null }))
    .put(route(generateRoute('categorie-fornitore.update'), { categoria: inModifica.value.id }), {
      preserveScroll: true,
      onSuccess: () => {
        inModifica.value = null;
      },
    });
}

/* ---------------------------------------------------------------- eliminazione */

/**
 * Il ciclo della conferma vive in `useConfermaEliminazione`, non qui — stessa ragione della scheda
 * documenti dell'anagrafica, dove il difetto è stato trovato: tenendo il dato e l'interruttore
 * della finestra nella stessa variabile, la chiusura del dialogo azzerava il dato prima che la
 * conferma lo leggesse, e **la categoria non veniva eliminata**. Prove in
 * `resources/js/composables/useConfermaEliminazione.test.ts`.
 */
const eliminazione = useConfermaEliminazione<CategoriaFornitore>();

function chiediConferma(categoria: CategoriaFornitore) {
  // La conferma si apre **solo** se la categoria è libera: se qualcuno la usa si apre l'altra
  // finestra, quella che dice chi — e a quella la categoria serve lo stesso, per elencarli.
  eliminazione.chiedi(categoria, categoria.fornitori_count === 0);
}

function eliminaCategoria() {
  eliminazione.conferma((categoria) => {
    router.delete(route(generateRoute('categorie-fornitore.destroy'), { categoria: categoria.id }), {
      preserveScroll: true,
      onFinish: () => eliminazione.conclusa(),
    });
  });
}
</script>

<template>
  <Head :title="trans('fornitori.categorie.head')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">
      <PageHeaderGuide
        :page-title="trans('fornitori.categorie.title')"
        :page-subtitle="trans('fornitori.categorie.description')"
        :breadcrumbs="breadcrumbs"
        :guides="pageGuides"
      >
        <template #actions>
          <Link
            :href="route(generateRoute('fornitori.index'))"
            class="inline-flex items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-sm font-medium text-slate-700 dark:text-slate-300 px-3 py-1.5 h-8 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
          >
            <ArrowLeft class="w-4 h-4 text-slate-500" />
            <span>{{ trans('fornitori.categorie.back') }}</span>
          </Link>
        </template>
      </PageHeaderGuide>

      <div v-if="flashMessage">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4">
          <Input
            v-model="filtro"
            :placeholder="trans('fornitori.categorie.filter')"
            class="h-8 w-full sm:max-w-xs"
          />

          <Button size="sm" class="h-8 shrink-0" @click="apriNuova">
            <!-- Icona verde, come il «+» di «Crea fornitori»: è la convenzione delle azioni che
                 creano qualcosa. -->
            <Plus class="w-4 h-4 text-green-500" />
            {{ trans('fornitori.categorie.new') }}
          </Button>
        </div>

        <div class="border rounded-md">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{{ trans('fornitori.categorie.name') }}</TableHead>
                <TableHead>{{ trans('fornitori.categorie.description_label') }}</TableHead>
                <TableHead class="w-32">{{ trans('fornitori.categorie.used_by') }}</TableHead>
                <TableHead class="w-16 text-right">{{ trans('fornitori.categorie.actions') }}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="categoria in elenco" :key="categoria.id">
                <TableCell>
                  <!--
                    Il nome porta ai **fornitori di questa categoria**, non a una scheda della
                    categoria: una categoria non ha una pagina di dettaglio, e la domanda che uno ha
                    in mente leggendo «Idraulico» è «chi sono». È la stessa forma dell'archivio
                    documenti, dove il nome della categoria porta ai documenti di quella.
                    La barra dei filtri di là si reidrata da `filters`, così la pagina che si apre
                    **dichiara** di essere filtrata invece di esserlo in silenzio.
                  -->
                  <Link
                    :href="route(generateRoute('fornitori.index'), { categoria_id: [categoria.id] })"
                    class="font-bold hover:text-zinc-500 transition-colors duration-150"
                  >
                    {{ categoria.name }}
                  </Link>
                </TableCell>
                <TableCell class="text-slate-600 dark:text-slate-400">{{ categoria.description }}</TableCell>
                <TableCell>
                  <!--
                    ⚠️ **Le iniziali, non un numero.** Qui c'era «1 fornitore» scritto in chiaro, e
                    diceva *quanti* senza dire *quali*: con tre fornitori uno sapeva che erano tre e
                    doveva poi cercarseli da qualche altra parte — dove peraltro il programma non
                    sa rispondere, perché l'elenco fornitori non mostra né filtra la categoria.

                    `AnagraficheStack` è lo stesso componente dell'elenco condomini e di quello
                    delle unità: mostra le prime tre iniziali più un «+N», e aperto elenca tutti con
                    l'indirizzo. Ogni riga porta alla scheda del fornitore, cioè **al posto dove si
                    cambia la categoria** — che è esattamente quello che serve fare prima di poter
                    eliminare la categoria.
                  -->
                  <AnagraficheStack
                    :anagrafiche="categoria.fornitori"
                    :title="trans('fornitori.categorie.suppliers_title')"
                    :description="trans('fornitori.categorie.suppliers_desc', { categoria: categoria.name })"
                  />
                </TableCell>
                <TableCell class="text-right whitespace-nowrap">
                  <!--
                    Tre puntini e menù a tendina, non due icone in fila: è la forma che hanno tutte
                    le altre tabelle del programma, e un'eccezione qui si leggerebbe come «questa
                    schermata funziona in un altro modo».
                  -->
                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        class="w-8 h-8 p-0"
                        :aria-label="trans('fornitori.categorie.actions')"
                      >
                        <MoreHorizontal class="w-4 h-4" />
                      </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end">
                      <DropdownMenuLabel>{{ trans('fornitori.categorie.actions') }}</DropdownMenuLabel>

                      <DropdownMenuItem @click="apriModifica(categoria)">
                        <FilePenLine class="w-4 h-4" />
                        {{ trans('fornitori.categorie.edit') }}
                      </DropdownMenuItem>

                      <!--
                        ⚠️ **La voce resta attiva anche quando la categoria è in uso.** Era spenta,
                        col motivo in un `title`: ma una voce spenta è un vicolo cieco — non si sa
                        perché lo è, e il `title` di un elemento disabilitato molti browser non lo
                        mostrano nemmeno. Cliccandola si apre invece una modale che dice **chi** la
                        sta usando e ci porta dentro. Idea di Vincenzo.
                      -->
                      <DropdownMenuItem @click="chiediConferma(categoria)">
                        <Trash2 class="w-4 h-4" />
                        {{ trans('fornitori.categorie.delete') }}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </TableCell>
              </TableRow>

              <TableRow v-if="elenco.length === 0">
                <TableCell colspan="4" class="h-24 text-center text-slate-500">
                  {{ categorie.length === 0
                    ? trans('fornitori.categorie.empty')
                    : trans('fornitori.categorie.no_results') }}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </div>
    </div>

    <!-- Nuova categoria -->
    <Sheet v-model:open="nuovaAperta">
      <SheetContent side="right" class="p-6">
        <SheetHeader class="mt-4 p-0">
          <SheetTitle>{{ trans('fornitori.categorie.new_title') }}</SheetTitle>
          <SheetDescription>{{ trans('fornitori.categorie.new_description') }}</SheetDescription>
        </SheetHeader>

        <form class="mt-6 space-y-4" @submit.prevent="creaCategoria">
          <div>
            <Label for="categoria-nuova-nome">{{ trans('fornitori.categorie.name') }}</Label>
            <Input
              id="categoria-nuova-nome"
              v-model="formNuova.name"
              :class="{ 'border-red-500': formNuova.errors.name }"
              :placeholder="trans('fornitori.categorie.name_placeholder')"
              class="w-full mt-1"
            />
            <p v-if="formNuova.errors.name" class="text-red-500 text-sm mt-1">{{ formNuova.errors.name }}</p>
          </div>

          <div>
            <Label for="categoria-nuova-descrizione">{{ trans('fornitori.categorie.description_label') }}</Label>
            <Textarea
              id="categoria-nuova-descrizione"
              v-model="formNuova.description"
              :class="{ 'border-red-500': formNuova.errors.description }"
              :placeholder="trans('fornitori.categorie.description_placeholder')"
              class="w-full mt-1 min-h-[120px]"
            />
            <p v-if="formNuova.errors.description" class="text-red-500 text-sm mt-1">{{ formNuova.errors.description }}</p>
            <p v-else class="text-xs text-slate-500 mt-1">{{ trans('fornitori.categorie.description_hint') }}</p>
          </div>

          <div class="flex justify-end">
            <Button type="submit" :disabled="formNuova.processing || !formNuova.name.trim()">
              {{ trans('fornitori.categorie.save') }}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>

    <!-- Modifica categoria -->
    <Sheet :open="inModifica !== null" @update:open="(v: boolean) => { if (!v) inModifica = null }">
      <SheetContent side="right" class="p-6">
        <SheetHeader class="mt-4 p-0">
          <SheetTitle>
            {{ trans('fornitori.categorie.edit_title', { categoria: inModifica?.name?.toLowerCase() ?? '' }) }}
          </SheetTitle>
          <SheetDescription>{{ trans('fornitori.categorie.edit_description') }}</SheetDescription>
        </SheetHeader>

        <form class="mt-6 space-y-4" @submit.prevent="salvaModifica">
          <div>
            <Label for="categoria-nome">{{ trans('fornitori.categorie.name') }}</Label>
            <Input
              id="categoria-nome"
              v-model="formModifica.name"
              :class="{ 'border-red-500': formModifica.errors.name }"
              :placeholder="trans('fornitori.categorie.name_placeholder')"
              class="w-full mt-1"
            />
            <p v-if="formModifica.errors.name" class="text-red-500 text-sm mt-1">{{ formModifica.errors.name }}</p>
          </div>

          <div>
            <Label for="categoria-descrizione">{{ trans('fornitori.categorie.description_label') }}</Label>
            <Textarea
              id="categoria-descrizione"
              v-model="formModifica.description"
              :class="{ 'border-red-500': formModifica.errors.description }"
              :placeholder="trans('fornitori.categorie.description_placeholder')"
              class="w-full mt-1 min-h-[120px]"
            />
            <p v-if="formModifica.errors.description" class="text-red-500 text-sm mt-1">{{ formModifica.errors.description }}</p>
            <p v-else class="text-xs text-slate-500 mt-1">{{ trans('fornitori.categorie.description_hint') }}</p>
          </div>

          <div class="flex justify-end">
            <Button type="submit" :disabled="formModifica.processing || !formModifica.name.trim()">
              {{ trans('fornitori.categorie.save') }}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>

    <!--
      ⚠️ **Due dialoghi, non uno con il pulsante spento.** Un «Elimina» disabilitato dentro la
      finestra sposta il vicolo cieco di un passo invece di toglierlo. Qui la domanda e la
      spiegazione sono due schermate diverse perché sono due situazioni diverse: nella prima si
      decide, nella seconda non c'è niente da decidere e c'è qualcosa da fare.
    -->
    <ConfirmDialog
      :model-value="eliminazione.confermaAperta.value"
      :title="trans('fornitori.categorie.delete_title')"
      :description="trans('fornitori.categorie.delete_description')"
      variant="destructive"
      :loading="eliminazione.inCorso.value"
      @update:model-value="eliminazione.suCambioApertura"
      @confirm="eliminaCategoria"
    />

    <Dialog
      :open="eliminazione.daEliminare.value !== null && eliminazione.daEliminare.value.fornitori_count > 0"
      @update:open="(v: boolean) => { if (!v) eliminazione.conclusa() }"
    >
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ trans('fornitori.categorie.blocked_title') }}</DialogTitle>
          <DialogDescription>
            {{ transChoice('fornitori.categorie.blocked_intro', eliminazione.daEliminare.value?.fornitori_count ?? 0, {
              count: String(eliminazione.daEliminare.value?.fornitori_count ?? 0),
              categoria: eliminazione.daEliminare.value?.name ?? '',
            }) }}
          </DialogDescription>
        </DialogHeader>

        <!--
          L'elenco è **cliccabile**: la modale non si limita a dire di no, porta dove si risolve.
          I dati ci sono già — sono gli stessi che disegnano le iniziali nella colonna — quindi non
          costa una richiesta in più.
        -->
        <ul class="max-h-64 space-y-1 overflow-y-auto">
          <li v-for="f in eliminazione.daEliminare.value?.fornitori ?? []" :key="f.id">
            <Link
              :href="f.url"
              class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 dark:border-slate-800 px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors"
            >
              <span class="min-w-0">
                <span class="block font-semibold truncate">{{ f.nome }}</span>
                <span v-if="f.indirizzo" class="block text-xs text-slate-500 truncate">{{ f.indirizzo }}</span>
              </span>
              <ChevronRight class="w-4 h-4 shrink-0 text-slate-400" />
            </Link>
          </li>
        </ul>

        <p class="text-xs text-slate-500">{{ trans('fornitori.categorie.blocked_how') }}</p>

        <DialogFooter>
          <Button variant="outline" @click="eliminazione.conclusa()">
            {{ trans('fornitori.categorie.blocked_close') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
