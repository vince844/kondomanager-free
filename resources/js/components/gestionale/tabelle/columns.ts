// columns.ts
import { h } from 'vue'
import { TableProperties, ArrowRight } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3'
import { usePermission } from "@/composables/permissions";
import DropdownAction from '@/components/gestionale/tabelle/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/tabelle/DataTableColumnHeader.vue'
import { typeConstants } from '@/lib/gestionale/tabelle/constants';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Tabella } from '@/types/gestionale/tabelle'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Tabella>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const desc = tabella.note;

        return h(Link, {
          prefetch: true,
          href: route(generateRoute('gestionale.tabelle.quote.index'), { condominio: condominio.id, tabella: tabella.id }),
          class: 'group flex items-start gap-3 py-1 outline-none'
        }, () => [
          // Icona laterale
          h('div', { 
              class: 'p-2 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-indigo-500 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/50 transition-colors shrink-0 mt-0.5' 
          }, [
              h(TableProperties, { class: 'w-4 h-4' })
          ]),
          
          // Contenitore Testi
          h('div', { class: 'flex flex-col min-w-0' }, [
              // Riga 1: Titolo
              h('span', {
                  class: 'font-bold text-sm text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
              }, tabella.nome),

              // Riga 2: Descrizione 
              desc ? h('span', {
                class: 'text-xs text-slate-500 dark:text-slate-400 truncate max-w-sm mt-0.5'
              }, desc) : null,

              // Riga 3: Action "Gestione Quote"
              h('span', { 
                  class: 'text-[10px] font-semibold text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors mt-2' 
              }, [
                  'Gestione Quote',
                  h(ArrowRight, { class: 'w-3 h-3 text-indigo-400/60' })
              ])
          ])
        ]);
      },
    },
    {
      accessorKey: 'palazzina',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: 'Palazzina' }),

      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const palazzina = tabella.palazzina?.name ?? '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, palazzina),
        ])
      }
        
    },
    {
      accessorKey: 'scala',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: 'Scala' }),

      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const scala = tabella.scala?.name ?? '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, scala),
        ])
      }
        
    },
    {
      accessorKey: 'tipo',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipologia' }),
      cell: ({ row }) => {
  
        const value = row.getValue('tipo');
        const stato = typeConstants.find(p => p.value === value);
    
        if (!stato) return h('span', '–');
    
        return h('div', { class: 'flex items-center gap-2' }, [
          h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
          h('span', stato.label)
        ]);
      }
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const tabella = row.original as Tabella
        return h('div', { class: 'relative' },
          h(DropdownAction, { tabella, condominio })
        )
      },
    },
  ]
}
