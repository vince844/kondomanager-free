// columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3'
import DropdownAction from '@/components/gestionale/pianiRate/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/pianiRate/DataTableColumnHeader.vue'
import { usePermission } from "@/composables/permissions"
import { useCurrencyFormatter } from "@/composables/useCurrencyFormatter" 
import { CalendarRange, ArrowRight } from 'lucide-vue-next'
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

      return h(Link, {
        prefetch: true,
        href: route(generateRoute('gestionale.esercizi.piani-rate.show'), { condominio: condominio.id, esercizio: esercizio.id,  pianoRate: pianoRate.id }),
        class: 'group flex items-start gap-3 py-1 outline-none'
      }, () => [
        h('div', { 
            class: 'p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 dark:text-indigo-400 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/60 transition-colors shrink-0 mt-0.5' 
        }, [
            h(CalendarRange, { class: 'w-4 h-4' })
        ]),
        
        h('div', { class: 'flex flex-col min-w-0' }, [
            h('div', { class: 'flex items-center gap-2 mb-0.5' }, [
                stato ? h('span', { 
                    class: 'px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-tighter bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-md border border-indigo-100 dark:border-indigo-800' 
                }, stato) : null,

                h('span', {
                    class: 'font-bold text-sm text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
                }, pianoRate.nome),
            ]),
            
            desc ? h('span', {
              class: 'text-xs text-slate-500 dark:text-slate-400 truncate max-w-sm mt-0.5'
            }, desc) : null,

            h('span', { 
                class: 'text-[10px] font-semibold text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors mt-2' 
            }, [
                'Gestisci Piano Rate',
                h(ArrowRight, { class: 'w-3 h-3 text-indigo-400/60' })
            ])
        ])
      ]);
    } 
  },
  {
    accessorKey: 'dettagli_rate',
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