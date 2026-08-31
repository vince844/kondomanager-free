import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import DropdownAction from '@/components/fornitori/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/fornitori/DataTableColumnHeader.vue';
import { usePermission } from "@/composables/permissions";
import { Badge }  from '@/components/ui/badge';
import { trans } from 'laravel-vue-i18n';
import AnagraficheStack from '@/components/AnagraficheStack.vue';
import { Truck, MapPin, ArrowRight } from 'lucide-vue-next'; 
import type { ColumnDef } from '@tanstack/vue-table'
import type { Fornitore } from '@/types/fornitori';

const { generateRoute } = usePermission();

export const columns: ColumnDef<Fornitore>[] = [
  {
      accessorKey: 'ragione_sociale',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('fornitori.table.name') }), 

      cell: ({ row }) => {
        const fornitore = row.original
        const piva = fornitore.partita_iva

        return h(Link, {
          href: route(generateRoute('fornitori.show'), { fornitore: fornitore.id }),
          class: 'group flex items-center gap-3 py-1 outline-none'
        }, () => [
          // Icona Fornitore (Sostituita Truck con palette Indigo)
          h('div', { 
              class: 'p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 dark:text-indigo-400 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/60 transition-colors shrink-0' 
          }, [
              h(Truck, { class: 'w-4 h-4' })
          ]),
          
          // Contenitore Ragione Sociale e P.IVA
          h('div', { class: 'flex flex-col min-w-0' }, [
              h('div', { class: 'flex items-center gap-2 mb-0.5' }, [
                  
                  // 1. P.IVA (Stilizzata come il selettore tipologia/codice)
                  piva ? h('span', { 
                      class: 'px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-tighter bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-md border border-indigo-100 dark:border-indigo-800' 
                  }, piva) : null,

                  // 2. Ragione Sociale
                  h('span', {
                      class: 'font-bold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
                  }, fornitore.ragione_sociale),
              ]),
              
              // Sottotitolo interattivo
              h('span', { 
                  class: 'text-[10px] text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors' 
              }, [
                  trans('fornitori.table.click_to_view'),
                  h(ArrowRight, { class: 'w-3 h-3 animate-pulse text-indigo-500/60' })
              ])
          ])
        ]);
      }
  },
  {
    accessorKey: 'indirizzo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('fornitori.table.address') }),
    cell: ({ row }) => {
      const f = row.original
      const indirizzo = f.indirizzo?.trim() || ''
      const capComune = [f.cap, f.comune].filter(Boolean).join(' ')
      const prov = f.provincia ? `(${f.provincia})` : ''

      const dettagli = [indirizzo, capComune, prov].filter(Boolean).join(', ')

      return h('div', { class: 'flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400 font-medium' }, [
        h(MapPin, { class: 'w-3.5 h-3.5 shrink-0 text-slate-400' }),
        h('span', { class: 'truncate max-w-[250px]' }, dettagli || '—')
      ])
    },
  },
  {
    accessorKey: 'codice_fiscale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('fornitori.label.tax_code') }), 
    cell: ({ row }) => h('div', { class: 'text-xs uppercase text-slate-500 dark:text-slate-400' }, row.getValue('codice_fiscale') || '—'),
  },
  {
    /*
     * ⚠️ **La colonna della categoria, dalla 1.11.0-beta.9.**
     *
     * Il dato arrivava gia' in ogni riga — il controller carica `with(['categoria'])` e
     * `FornitoreResource` la serializza — e **nessuno lo mostrava**. Classificare un fornitore non
     * produceva nessun effetto visibile in nessuna schermata, ed e' molto probabilmente il motivo
     * per cui sei fornitori su otto non avevano categoria.
     *
     * `id` esplicito e non `accessorKey`: la barra dei filtri chiede alla tabella
     * `getColumn('categoria')` per tenerci lo stato del filtro sfaccettato, e il valore vero e' un
     * oggetto annidato, non una chiave piatta.
     */
    id: 'categoria',
    accessorFn: (riga) => riga.categoria?.name ?? null,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('fornitori.table.category') }),

    cell: ({ row }) => {
      const categoria = row.original.categoria;

      if (!categoria) {
        return h('span', { class: 'text-xs italic text-slate-400' }, '—');
      }

      // Una pillola, non testo nudo: e' una classificazione scelta da un elenco chiuso, e si legge
      // come tale. Stesso trattamento che la categoria ha nella scheda del fornitore.
      return h('span', {
        class: 'inline-flex items-center rounded-md bg-slate-100 dark:bg-slate-800 px-2 py-1 text-xs font-medium text-slate-700 dark:text-slate-300',
      }, categoria.name);
    },
  },
  {
    accessorKey: 'referenti',
      /**
       * ⚠️ **Non ordinabile, e non è una mancanza.** La cella contiene un **elenco** di soggetti,
       * non un valore: un'unità con «Esposito + Russo» non ha una posizione in un ordinamento
       * alfabetico finché qualcuno non decide *quale dei due* faccia da chiave.
       *
       * Finché quella decisione non è presa, l'intestazione ordinava per **quante** persone ci
       * sono nella cella — che è ciò che la libreria fa quando il valore è un array e nessuno
       * dichiara un criterio. Nessuno che clicca lì si aspetta quello: era un reperto aperto
       * della revisione della beta.52.
       *
       * Se un giorno serve, si sceglie la chiave (per esempio «il primo proprietario in ordine
       * alfabetico») e si ordina sul server. Una decisione presa, non un default ereditato.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('fornitori.table.residents') }), // Usiamo 'Anagrafiche' o 'Referenti'
  
    cell: ({ row }) => {
      /*
       * ⚠️ **Le righe del pannello sono cliccabili, dalla 1.11.0-beta.9.**
       *
       * Il componente era gia' questo, ma senza `url` le voci del pannello erano testo morto: si
       * vedeva chi sono i rappresentanti e non ci si poteva entrare. `AnagraficheStack` accetta un
       * `url` per riga e in quel caso rende ogni voce un `Link` con la freccia — la stessa forma
       * che ha nella pagina delle categorie di fornitore, dove ogni fornitore porta alla sua scheda.
       *
       * L'aggiunta si fa qui e non in `AnagraficaResource`, che e' condivisa da mezzo programma:
       * l'indirizzo giusto dipende da **dove** si sta guardando l'anagrafica, non dall'anagrafica.
       *
       * ⚠️ Il ruolo del rappresentante **non** viene passato di proposito, pur essendo nella pivot
       * (`withPivot('ruolo')`) e pur essendo `AnagraficheStack` capace di mostrarlo: colori ed
       * etichette li prende da `@/lib/gestionale/ruoli-immobile`, che e' il vocabolario dei ruoli
       * su un'**unita'** — proprietario, usufruttuario, inquilino. «Titolare» o «tecnico» di un
       * fornitore cadrebbero nel grigio dei ruoli sconosciuti, che e' esattamente il difetto per
       * cui quel file e' stato scritto. Serve prima il vocabolario dei ruoli del fornitore, gemello
       * di `RuoloRappresentanteFornitore` costruito nella beta.7.
       */
      const referenti = (row.original.referenti || []).map((r: any) => ({
        ...r,
        // `show` e non `edit`: il pannello promette «apri una scheda per i suoi recapiti», e una
        // scheda si legge, non si compila. *(Per mezza giornata questo puntava a `edit`, perche'
        // `AnagraficaController::show()` aveva il corpo vuoto e rispondeva 200 con niente dentro,
        // cioe' pagina bianca. La scheda dell'anagrafica e' stata costruita nella stessa beta,
        // proprio perche' seguendo questo collegamento nuovo si e' svegliato quel difetto vecchio.)*
        url: route(generateRoute('anagrafiche.show'), { anagrafica: r.id }),
      }));

      /*
       * ⚠️ **Titolo e descrizione propri, altrimenti il pannello parla di un condominio.**
       *
       * `AnagraficheStack` ha come ripiego i testi dell'elenco condomini — «Anagrafiche», «le
       * persone associate a questo condominio» — perche' e' li' che e' nato. Su un fornitore erano
       * semplicemente falsi: queste sono le persone che rispondono per una ditta, e di condomini
       * non ce n'e' nessuno in questa schermata. Il ripiego era invisibile finche' la colonna e'
       * rimasta vuota su ogni riga, cioe' fino alla prima installazione con un rappresentante
       * registrato.
       */
      return h(AnagraficheStack, {
        anagrafiche: referenti,
        title: trans('fornitori.table.representatives_title'),
        description: trans('fornitori.table.representatives_desc'),
      });
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => h('div', { class: 'relative text-right' }, h(DropdownAction, { fornitore: row.original })),
  }
];