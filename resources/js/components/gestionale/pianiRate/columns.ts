// columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3'
import DropdownAction from '@/components/gestionale/pianiRate/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/pianiRate/DataTableColumnHeader.vue'
import { usePermission } from "@/composables/permissions"
import { useCurrencyFormatter } from "@/composables/useCurrencyFormatter" 
import { CalendarRange, ArrowRight, Wallet } from 'lucide-vue-next' // Aggiunto Wallet
import type { ColumnDef } from '@tanstack/vue-table'
import type { PianoRate } from '@/types/gestionale/piani-rate'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'

const { generateRoute } = usePermission();

// Inizializziamo la composable (impostando fromCents: true come da tuo standard)
const { euro } = useCurrencyFormatter({ fromCents: true });

export const createColumns = (condominio: Building, esercizio: Esercizio): ColumnDef<PianoRate>[] => [
{
    accessorKey: 'nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
    cell: ({ row }) => {
      const pianoRate = row.original
      const stato = pianoRate.stato 
      const desc = pianoRate.descrizione
      const hasSaldi = pianoRate.has_saldi || false; 

      // --- LOGICA COLORI DINAMICA ---
      const isApprovato = stato === 'approvato';
      
      const colorClasses = isApprovato 
        ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 border-emerald-100 dark:border-emerald-800'
        : 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 border-indigo-100 dark:border-indigo-800';

      const iconBgClasses = isApprovato
        ? 'bg-emerald-50 dark:bg-emerald-900/40 text-emerald-500 dark:text-emerald-400 group-hover:bg-emerald-100'
        : 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-500 dark:text-indigo-400 group-hover:bg-indigo-100';
      // ------------------------------

      return h(Link, {
        prefetch: true,
        href: route(generateRoute('gestionale.esercizi.piani-rate.show'), { condominio: condominio.id, esercizio: esercizio.id,  pianoRate: pianoRate.id }),
        class: 'group flex items-start gap-3 py-1 outline-none'
      }, () => [
        h('div', { 
            class: `p-2 rounded-lg shadow-sm transition-colors shrink-0 mt-0.5 ${iconBgClasses}` 
        }, [
            h(CalendarRange, { class: 'w-4 h-4' })
        ]),
        
        h('div', { class: 'flex flex-col min-w-0' }, [
            h('div', { class: 'flex items-center gap-2 mb-0.5 flex-wrap' }, [
                
                // BADGE STATO (Dinamico)
                stato ? h('span', { 
                    class: `px-1.5 py-0.5 text-[9px] font-bold uppercase border rounded-md ${colorClasses}` 
                }, stato) : null,

                // BADGE SALDI (Sempre Amber per contrasto)
                hasSaldi ? h('span', { 
                    class: 'inline-flex items-center gap-1 px-1.5 py-0.5 text-[9px] font-bold uppercase bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 rounded-md border border-amber-200 dark:border-amber-800',
                    title: 'Questo piano include i saldi dell\'anno precedente'
                }, [
                    h(Wallet, { class: 'w-2.5 h-2.5' }),
                    'Saldi Inclusi'
                ]) : null,

                h('span', {
                    class: 'font-bold text-sm text-slate-900 dark:text-slate-100 group-hover:text-primary transition-colors truncate',
                }, pianoRate.nome),
            ]),
            
            desc ? h('span', {
              class: 'text-xs text-slate-500 dark:text-slate-400 truncate max-w-sm mt-0.5'
            }, desc) : null,

            h('span', { 
                class: 'text-[10px] font-semibold text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-primary transition-colors mt-2' 
            }, [
                isApprovato ? 'Gestisci Scadenze' : 'Gestisci Piano Rate',
                h(ArrowRight, { class: 'w-3 h-3 text-slate-400 group-hover:text-primary' })
            ])
        ])
      ]);
    } 
  },
  {
    accessorKey: 'dettagli_rate',
      /**
       * ⚠️ **Non ordinabile.** «Emissione» monta numero di rate e stato in una cella: due domande, non una.
       *
       * Il server accetta solo le chiavi dichiarate nella richiesta: lasciarla cliccabile
       * manderebbe l'amministratore in un errore di validazione al primo clic.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Emissione' }),
    cell: ({ row }) => {
      const pianoRate = row.original
      
      return h('div', { class: 'flex flex-col' }, [
        h('span', { class: 'text-sm font-medium text-slate-700 dark:text-slate-300' }, `${pianoRate.numero_rate} Rate`),
        h('span', { class: 'text-xs text-slate-500' }, `Dal ${new Date(pianoRate.data_inizio).toLocaleDateString('it-IT')}`)
      ])
    },
  },
  {
    accessorKey: 'totale_capitoli',
      /**
       * ⚠️ **Non ordinabile.** «Importo totale» è aggregato dai capitoli: ordinarlo richiede una somma in sottoquery, che è una scelta sul costo della query.
       *
       * Il server accetta solo le chiavi dichiarate nella richiesta: lasciarla cliccabile
       * manderebbe l'amministratore in un errore di validazione al primo clic.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo totale' }),
    cell: ({ row }) => {
      // Usiamo la funzione euro della tua composable
      const totale = row.original.totale_capitoli || 0;

      return h('div', { class: 'font-bold text-sm text-slate-700 dark:text-slate-300' }, euro(totale));
    },
  },
  {
    accessorKey: 'gestione',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Gestione' }),
    cell: ({ row }) => {
      const nomeGestione = row.original.gestione?.nome;
      
      return h('div', { class: 'flex flex-col items-start gap-1.5' }, [
        nomeGestione
          ? h('span', { class: 'inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-widest bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700' }, nomeGestione)
          : h('span', { class: 'text-xs text-slate-400 italic' }, 'Gestione N/D')
      ]);
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const pianoRate = row.original as PianoRate
      return h('div', { class: 'flex justify-end pr-2' },
        h(DropdownAction, { pianoRate, condominio, esercizio })
      )
    },
   }
]