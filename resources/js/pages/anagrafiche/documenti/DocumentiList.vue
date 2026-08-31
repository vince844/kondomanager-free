<script setup lang="ts">
/**
 * La scheda «Documenti» di un'anagrafica.
 *
 * ## ⚠️ Quali documenti, ed è la decisione che conta
 *
 * Qui stanno i documenti **della persona** — la copia del documento d'identità, una delega per
 * l'assemblea, un contratto d'affitto — cioè quelli che l'amministratore archivia sulla sua scheda.
 * È la stessa forma che il fornitore ha da sempre, ed è la ragione per cui da qui si caricano.
 *
 * **Non** sono i documenti dell'archivio di cui la persona è solo *destinataria* — il verbale
 * mandato a tutti: quelli vivono nell'archivio, e mostrarli nella stessa tabella farebbe rispondere
 * a questa scheda due domande diverse, con un pulsante «elimina» che significherebbe due cose. Se
 * servirà anche quella vista sarà **una scheda in più**: il layout è fatto per aggiungerne.
 *
 * ## Perché non la tabella TanStack della scheda fornitore
 *
 * Quella monta cinque file di impalcatura per avere ordinamento e filtri serviti dal server. Qui
 * l'elenco è dei documenti di **una** persona: sono unità, non centinaia, e una tabella semplice
 * basta — la stessa scelta, e per la stessa ragione, della pagina delle categorie di fornitore.
 *
 * ⚠️ **I moduli invece sì**, e stanno in due pagine a sé (`DocumentiNew`, `DocumentiEdit`) ricalcate
 * su quelle del fornitore: la prima stesura li teneva in due pannelli laterali, e due schermate che
 * fanno la stessa cosa in due forme diverse costringono chi le usa a impararle due volte.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AnagraficaLayout from '@/layouts/anagrafiche/AnagraficaLayout.vue';
import Alert from '@/components/Alert.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermission } from '@/composables/permissions';
import { useConfermaEliminazione } from '@/composables/useConfermaEliminazione';
import {
  ChevronLeft, ChevronRight, FilePenLine, FileText, Inbox, MoreHorizontal, Trash2, UploadCloud,
} from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Documento } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  anagrafica: { id: number; nome: string };
  documenti: Documento[];
  meta: PaginationMeta;
  limiteFile: string;
}>();

const { generatePath, generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Anagrafiche', href: route(generateRoute('anagrafiche.index')) },
  { title: props.anagrafica.nome, href: generatePath('anagrafiche/:anagrafica', { anagrafica: props.anagrafica.id }) },
  { title: 'Documenti', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Documenti della persona',
    description: 'Copia del documento d\'identità, deleghe, contratti: quello che riguarda questa persona e non il condominio.',
    icon: FileText,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Solo PDF',
    description: `Il file deve essere un PDF e non superare ${props.limiteFile}: è il limite che accetta questo server, non una scelta della schermata.`,
    icon: UploadCloud,
    colorVariant: 'emerald' as const,
  },
  {
    title: 'Pubblicato o no',
    description: 'Un documento non pubblicato resta archiviato ma la persona non lo vede nella sua area riservata.',
    icon: Inbox,
    colorVariant: 'amber' as const,
  },
]);

/* ---------------------------------------------------------------- eliminazione */

/**
 * Il ciclo della conferma vive in `useConfermaEliminazione`, non qui.
 *
 * ⚠️ **Non è una comodità: è dove stava un difetto.** Tenendo il dato e l'interruttore della
 * finestra nella stessa variabile, la chiusura del dialogo — che `AlertDialogAction` fa al clic,
 * *prima* di emettere la conferma — azzerava il dato, e l'eliminazione non partiva: nessuna
 * richiesta, nessun errore, la riga restava lì. La regola e le sue prove stanno in
 * `resources/js/composables/useConfermaEliminazione.test.ts`.
 */
const eliminazione = useConfermaEliminazione<Documento>();

function eliminaDocumento() {
  eliminazione.conferma((documento) => {
    router.delete(
      route(generateRoute('anagrafiche.documenti.destroy'), {
        anagrafica: props.anagrafica.id,
        documento: documento.id,
      }),
      {
        preserveScroll: true,
        onFinish: () => eliminazione.conclusa(),
      },
    );
  });
}
</script>

<template>
  <AppLayout>
    <Head :title="`Documenti — ${anagrafica.nome}`" />

    <div class="px-6 py-8 space-y-4">
      <PageHeaderGuide
        :page-title="anagrafica.nome"
        page-subtitle="Documenti archiviati sulla scheda di questa persona"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="generatePath('anagrafiche')"
        back-text="Indietro"
      />

      <!--
        Il messaggio di esito sta **sotto l'intestazione e le card**, non sopra di esse: è la
        posizione che ha in tutti gli elenchi del programma. Metterlo in cima spinge giù la pagina
        intera e fa sparire il titolo dallo schermo proprio nel momento in cui uno vuole capire dove
        è finito il documento che ha appena salvato.
      -->
      <div v-if="flashMessage" class="py-3">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="w-full">
        <AnagraficaLayout>
          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
            <div class="flex items-center justify-between gap-2 pb-4">
              <span class="text-xs text-slate-500">
                {{ meta.total }} {{ meta.total === 1 ? 'documento' : 'documenti' }}
              </span>

              <!--
                Un collegamento a una **pagina**, non l'apertura di un pannello: è la forma che ha
                la scheda del fornitore, e due schermate che fanno la stessa cosa devono farla allo
                stesso modo.
              -->
              <Link
                :href="route(generateRoute('anagrafiche.documenti.create'), { anagrafica: anagrafica.id })"
                class="inline-flex items-center gap-2 rounded-md bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm px-3 py-1.5 h-8 text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
              >
                <!-- Icona verde, come il «+» di «Crea fornitori»: è la convenzione delle azioni
                     che creano qualcosa. -->
                <UploadCloud class="w-4 h-4 text-green-500" />
                Carica documento
              </Link>
            </div>

            <div class="border rounded-md">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Nome</TableHead>
                    <TableHead class="w-24">Tipo</TableHead>
                    <TableHead class="w-40">Caricato</TableHead>
                    <TableHead class="w-36">Stato</TableHead>
                    <TableHead class="w-16 text-right">Azioni</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="documento in documenti" :key="documento.id">
                    <TableCell>
                      <span class="font-semibold">{{ documento.name }}</span>
                      <span v-if="documento.description" class="block text-xs text-slate-500 truncate">
                        {{ documento.description }}
                      </span>
                    </TableCell>
                    <TableCell class="text-xs uppercase text-slate-500">{{ documento.mime_type }}</TableCell>
                    <TableCell class="text-xs text-slate-500">{{ documento.created_at }}</TableCell>
                    <TableCell>
                      <span
                        v-if="documento.is_published"
                        class="inline-flex items-center rounded-md bg-emerald-50 dark:bg-emerald-900/20 px-2 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-400"
                      >Pubblicato</span>
                      <span
                        v-else
                        class="inline-flex items-center rounded-md bg-slate-100 dark:bg-slate-800 px-2 py-1 text-xs font-medium text-slate-600 dark:text-slate-400"
                      >Non pubblicato</span>
                    </TableCell>
                    <TableCell class="text-right">
                      <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                          <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Azioni">
                            <MoreHorizontal class="w-4 h-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuLabel>Azioni</DropdownMenuLabel>
                          <DropdownMenuItem as-child>
                            <Link :href="route(generateRoute('anagrafiche.documenti.edit'), { anagrafica: anagrafica.id, documento: documento.id })">
                              <FilePenLine class="w-4 h-4" />
                              Modifica
                            </Link>
                          </DropdownMenuItem>
                          <DropdownMenuItem @click="eliminazione.chiedi(documento)">
                            <Trash2 class="w-4 h-4" />
                            Elimina
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>

                  <TableRow v-if="documenti.length === 0">
                    <TableCell colspan="5" class="h-28 text-center">
                      <div class="flex flex-col items-center gap-2 text-slate-400">
                        <Inbox class="w-6 h-6" />
                        <span class="text-sm">Nessun documento archiviato su questa persona.</span>
                        <span class="text-xs">Il pulsante «Carica documento» qui sopra ne aggiunge uno.</span>
                      </div>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>

            <!--
              ⚠️ **Un impaginatore minimo, non `DataTablePagination`.** Quel componente vuole
              l'oggetto `table` di TanStack, che qui non c'è: sarebbero cinque file di impalcatura
              per due frecce su un elenco che per una persona sola resta corto.
            -->
            <div v-if="meta.last_page > 1" class="flex items-center justify-between py-4">
              <span class="text-xs text-slate-500">
                Pagina {{ meta.current_page }} di {{ meta.last_page }}
              </span>

              <div class="flex items-center gap-2">
                <Link
                  v-if="meta.current_page > 1"
                  :href="route(generateRoute('anagrafiche.documenti.index'), { anagrafica: anagrafica.id, page: meta.current_page - 1 })"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-200 dark:border-slate-800 px-2 py-1 text-xs hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors"
                >
                  <ChevronLeft class="w-3.5 h-3.5" /> Precedente
                </Link>
                <Link
                  v-if="meta.current_page < meta.last_page"
                  :href="route(generateRoute('anagrafiche.documenti.index'), { anagrafica: anagrafica.id, page: meta.current_page + 1 })"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-200 dark:border-slate-800 px-2 py-1 text-xs hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors"
                >
                  Successiva <ChevronRight class="w-3.5 h-3.5" />
                </Link>
              </div>
            </div>
          </div>
        </AnagraficaLayout>
      </div>
    </div>

    <ConfirmDialog
      :model-value="eliminazione.confermaAperta.value"
      title="Eliminare il documento?"
      description="Il file viene rimosso dal disco e la riga sparisce dall'archivio: non si può annullare."
      variant="destructive"
      :loading="eliminazione.inCorso.value"
      @update:model-value="eliminazione.suCambioApertura"
      @confirm="eliminaDocumento"
    />
  </AppLayout>
</template>
