<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Alert from '@/components/Alert.vue';
import type { Flash } from '@/types/flash';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/gestionale/movimenti/registroContabilita/Datatable.vue';
import { createColumns } from '@/components/gestionale/movimenti/registroContabilita/columns';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { BookOpenCheck, Landmark, Scale3D, Printer, ArrowDownCircle, ArrowUpCircle, ListTree, ChevronDown } from 'lucide-vue-next';
import type { RegistroRow } from '@/components/gestionale/movimenti/registroContabilita/columns';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

interface PaginationMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface SaldoCassa {
    cassa: string;
    /** In centesimi: il saldo dopo l'ultimo movimento di QUESTA cassa. */
    saldo: number;
    movimenti: number;
    /** In centesimi, sulla sola cassa: serve a confrontare il registro con l'estratto conto. */
    entrate: number;
    uscite: number;
}

interface Riepilogo {
    /** In centesimi, sui soli movimenti mostrati. */
    totale_entrate: number;
    /** In centesimi, sui soli movimenti mostrati. */
    totale_uscite: number;
    /** In centesimi: il saldo dopo l'ultimo movimento mostrato, NON `entrate − uscite`. */
    saldo_finale: number;
    /** La data di quell'ultimo movimento, per dire a quando si riferisce il saldo. */
    saldo_alla_data: string | null;
    /** Un saldo per ogni cassa reale che si è mossa: il totale da solo può nasconderne uno scoperto. */
    saldi_per_cassa: SaldoCassa[];
}

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: Esercizio;
    esercizi: Esercizio[];
    righe: { data: RegistroRow[]; meta: PaginationMeta };
    riepilogo: Riepilogo;
    filters: { search?: string; data_da?: string; data_a?: string };
}>();

const { generatePath, generateRoute } = usePermission();

// Stesso idioma del Libro Giornale. Serve a una cosa precisa: la stampa si apre in una scheda
// nuova, e oltre il tetto di righe il controller risponde con un redirect qui, con il motivo nel
// flash. Senza questo blocco la scheda nuova mostrava la stessa pagina e nessun avviso — trovato
// dalla revisione della beta.24.
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const { euro } = useCurrencyFormatter();

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Prima nota' },
]);

const columns = createColumns();

const filtrato = computed(() => !!(props.filters.search || props.filters.data_da || props.filters.data_a));

const formatData = (iso: string) => {
    const [anno, mese, giorno] = iso.split('-');
    return `${giorno}/${mese}/${anno}`;
};

/**
 * ⚠️ **Il saldo non è «entrate − uscite», e la differenza si vede solo quando si filtra.**
 * Senza filtri i due numeri coincidono; filtrando luglio, «entrate − uscite» è il netto di
 * luglio, mentre il saldo di cassa è quello che c'era davvero sul conto a fine luglio. La norma
 * vuole questo registro proprio per dire «le somme a disposizione del condominio» in un dato
 * momento: qui si dichiara anche a quale data si riferisce.
 */
/**
 * ⚠️ **Il totale può nascondere un conto scoperto.** Con banca a −370,56 e contanti a +372,50 la
 * card dice «1,94», che è vero e non serve a niente: non dice che il conto corrente è sotto di
 * quasi quattrocento euro. Il dettaglio si apre solo quando le casse mosse sono più di una —
 * con una sola cassa il totale È già il dettaglio, e un pulsante che apre un elenco di una riga
 * sarebbe rumore.
 */
const piuCasse = computed(() => props.riepilogo.saldi_per_cassa.length > 1);
const dettaglioSaldoAperto = ref(false);

const etichettaSaldo = computed(() => props.riepilogo.saldo_alla_data
    ? `Saldo di cassa al ${formatData(props.riepilogo.saldo_alla_data)}`
    : 'Saldo di cassa');

/**
 * Stessi filtri della pagina, mai "tutto l'esercizio" — coerente col Libro Giornale
 * (§10.1.2 di docs/registri_contabili.md): un pulsante sulla pagina già filtrata stampa
 * quello che l'amministratore sta guardando, non una sorpresa più lunga.
 *
 * ⚠️ **Due voci esplicite, non una casella da ricordarsi.** La decisione D9 tiene la data di
 * annotazione fuori dal foglio che va in assemblea — «un registro che denuncia da solo i ritardi
 * del suo autore [...] è un'arma contro l'amministratore» — e prevede l'eccezione «in stampa entra
 * solo se l'amministratore lo chiede». Una casella con memoria produrrebbe il caso peggiore: una
 * copia di controllo consegnata all'assemblea perché nessuno si è accorto che era rimasta accesa.
 * Qui si sceglie ogni volta, il nome dice a chi serve, e la copia di controllo si dichiara anche
 * sul foglio stampato.
 */
function stampaRegistro(conAnnotazioni = false) {
    window.open(route(generateRoute('gestionale.esercizi.registro-contabilita.print'), {
        condominio: props.condominio.id,
        esercizio: props.esercizio.id,
        ...props.filters,
        ...(conAnnotazioni ? { annotazioni: 1 } : {}),
    }), '_blank');
}

const pageGuides = [
    {
        title: 'Registro di contabilità',
        description: 'Le entrate e le uscite reali dell\'esercizio, in ordine cronologico, come richiesto dall\'art. 1130, comma 1, n. 7 c.c. — non le scritture in partita doppia: quelle sono nel Libro Giornale. Il saldo parte da zero all\'inizio dell\'esercizio: il riporto dall\'anno precedente non è ancora compreso.',
        icon: BookOpenCheck,
        colorVariant: 'blue' as const,
        // Il rimando al Libro Giornale era testuale in una scheda che dichiara la differenza fra
        // i due registri: chi la legge sta cercando l'altro. Il Libro Giornale rimanda qui con un
        // link vero dalla beta.24, questo è il verso che mancava.
        link: {
            label: 'Vai al Libro Giornale',
            href: generatePath('gestionale/:condominio/esercizi/:esercizio/scritture', {
                condominio: props.condominio.id,
                esercizio: props.esercizio.id,
            }),
        },
    },
    { title: 'Solo denaro che si muove davvero', description: 'Compaiono i movimenti su banca e contanti. Un accantonamento verso un fondo di riserva non è né un\'entrata né un\'uscita — il denaro resta sullo stesso conto corrente — e non compare. Per questo il saldo di cassa è al netto dei fondi accantonati, e non coincide con l\'estratto conto.', icon: Landmark, colorVariant: 'emerald' as const },
    { title: 'Due date per ogni riga', description: 'La data del movimento, su cui il registro è ordinato, e accanto la data in cui è stato annotato: la norma dà trenta giorni, e uno scarto più lungo è segnalato.', icon: Scale3D, colorVariant: 'amber' as const },
];
</script>

<template>
    <Head title="Prima nota" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Prima nota"
                page-subtitle="Registro di contabilità: entrate e uscite reali dell'esercizio, in ordine cronologico."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" class="h-8 px-3 border-slate-200 text-slate-700 bg-white hover:bg-slate-50 shadow-sm shrink-0 gap-2">
                                <Printer class="w-4 h-4" />
                                <span class="hidden sm:inline">Stampa</span>
                                <ChevronDown class="w-3.5 h-3.5 text-slate-400" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-72">
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampaRegistro(false)">
                                <span class="font-medium">Stampa per l'assemblea</span>
                                <span class="text-[11px] text-slate-500 leading-snug">Le date dei movimenti, come chiede la norma.</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampaRegistro(true)">
                                <span class="font-medium">Copia di controllo</span>
                                <span class="text-[11px] text-slate-500 leading-snug">Aggiunge le date di annotazione e segnala i ritardi oltre i trenta giorni. Per te, non per i condòmini.</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div v-if="flashMessage" class="mb-4">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                            <div class="border rounded-xl p-4 shadow-sm flex items-center gap-3 bg-emerald-50/50 border-emerald-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-emerald-100 border-emerald-200">
                                    <ArrowDownCircle class="w-5 h-5 text-emerald-600" />
                                </div>
                                <div>
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Totale entrate{{ filtrato ? ' (parziale)' : '' }}</div>
                                    <div class="text-lg font-bold tabular-nums text-emerald-700">{{ euro(riepilogo.totale_entrate) }}</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-center gap-3 bg-rose-50/50 border-rose-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-rose-100 border-rose-200">
                                    <ArrowUpCircle class="w-5 h-5 text-rose-600" />
                                </div>
                                <div>
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Totale uscite{{ filtrato ? ' (parziale)' : '' }}</div>
                                    <div class="text-lg font-bold tabular-nums text-rose-700">{{ euro(riepilogo.totale_uscite) }}</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-center gap-3 bg-slate-50/50 border-slate-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-slate-100 border-slate-200">
                                    <Landmark class="w-5 h-5 text-slate-600" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">{{ etichettaSaldo }}</div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg font-bold tabular-nums" :class="riepilogo.saldo_finale < 0 ? 'text-rose-600' : 'text-slate-700'">{{ euro(riepilogo.saldo_finale) }}</span>
                                        <button
                                            v-if="piuCasse"
                                            type="button"
                                            class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
                                            @click="dettaglioSaldoAperto = true"
                                        >
                                            <ListTree class="w-3.5 h-3.5" />
                                            dettaglio su {{ riepilogo.saldi_per_cassa.length }} casse
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <DataTable
                            :columns="columns"
                            :data="righe.data"
                            :condominio="props.condominio"
                            :esercizio="props.esercizio"
                            :meta="righe.meta"
                        />
                    </MovimentiLayout>
                </section>
            </div>
        </div>

        <Dialog v-model:open="dettaglioSaldoAperto">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ etichettaSaldo }}</DialogTitle>
                    <DialogDescription>
                        Come si divide fra le casse che si sono mosse. Il totale da solo non direbbe
                        se uno dei conti è scoperto.
                        <template v-if="filtrato">
                            Movimenti, entrate e uscite contano solo il periodo filtrato; il saldo è
                            invece quello vero a quella data.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <table class="w-full text-sm">
                    <!--
                        ⚠️ **Tre colonne su quattro seguono il filtro, una no.** Movimenti, entrate e
                        uscite contano solo il periodo mostrato; il saldo è il progressivo vero a
                        quella data, e porta dentro tutto l'esercizio precedente. Le due colonne di
                        denaro lo dichiarano in intestazione — stessa forma delle card, che dicono
                        già «totale entrate (parziale)» — e la descrizione sopra copre anche
                        «mov.», dove «(parziale)» in un'intestazione abbreviata a 11px non ci sta.
                    -->
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="text-left font-bold text-[11px] uppercase tracking-wider text-slate-500 py-2">Cassa</th>
                            <th class="text-right font-bold text-[11px] uppercase tracking-wider text-slate-500 py-2">Mov.</th>
                            <th class="text-right font-bold text-[11px] uppercase tracking-wider text-slate-500 py-2">Entrate{{ filtrato ? ' (parziale)' : '' }}</th>
                            <th class="text-right font-bold text-[11px] uppercase tracking-wider text-slate-500 py-2">Uscite{{ filtrato ? ' (parziale)' : '' }}</th>
                            <th class="text-right font-bold text-[11px] uppercase tracking-wider text-slate-500 py-2">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in riepilogo.saldi_per_cassa" :key="s.cassa" class="border-b border-slate-100">
                            <td class="py-2 text-slate-700">{{ s.cassa }}</td>
                            <td class="py-2 text-right tabular-nums text-slate-500">{{ s.movimenti }}</td>
                            <td class="py-2 text-right tabular-nums text-emerald-700">{{ euro(s.entrate) }}</td>
                            <td class="py-2 text-right tabular-nums text-rose-700">{{ euro(s.uscite) }}</td>
                            <td class="py-2 text-right tabular-nums font-semibold" :class="s.saldo < 0 ? 'text-rose-600' : 'text-slate-700'">{{ euro(s.saldo) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300">
                            <td class="py-2 font-bold text-slate-800">Totale</td>
                            <td class="py-2 text-right tabular-nums text-slate-500">{{ riepilogo.saldi_per_cassa.reduce((t, s) => t + s.movimenti, 0) }}</td>
                            <td class="py-2 text-right tabular-nums font-bold text-emerald-700">{{ euro(riepilogo.totale_entrate) }}</td>
                            <td class="py-2 text-right tabular-nums font-bold text-rose-700">{{ euro(riepilogo.totale_uscite) }}</td>
                            <td class="py-2 text-right tabular-nums font-bold" :class="riepilogo.saldo_finale < 0 ? 'text-rose-600' : 'text-slate-800'">{{ euro(riepilogo.saldo_finale) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <p class="text-[12px] text-slate-500 leading-relaxed">
                    Compaiono solo le casse di liquidità reale — banca e contanti. Un fondo di riserva
                    non è una cassa a sé: quel denaro sta sullo stesso conto corrente, e per questo
                    non ha una riga qui né movimenti propri nel registro. Le entrate e le uscite della
                    cassa banca sono la riga con cui confrontare l'estratto conto; un prelievo verso i
                    contanti compare in entrambe le casse, ed è corretto — quel denaro si è mosso
                    davvero, due volte.
                </p>
            </DialogContent>
        </Dialog>
    </GestionaleLayout>
</template>
