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
    accessorKey: 'referenti',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('fornitori.table.residents') }), // Usiamo 'Anagrafiche' o 'Referenti'
  
    cell: ({ row }) => {
      // Sostituito tutto il codice manuale con il componente Drawer Premium
      return h(AnagraficheStack, { anagrafiche: row.original.referenti || [] });
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => h('div', { class: 'relative text-right' }, h(DropdownAction, { fornitore: row.original })),
  }
];