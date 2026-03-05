import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import DropdownAction from './DataTableRowActions.vue';
import DataTableColumnHeader from './DataTableColumnHeader.vue';
import { usePermission } from "@/composables/permissions";
import { Badge }  from '@/components/ui/badge';
import { trans } from 'laravel-vue-i18n';
import AnagraficheStack from '@/components/buildings/AnagraficheStack.vue';
import { Building2, ArrowRight } from 'lucide-vue-next'; 
import type { ColumnDef } from '@tanstack/vue-table'
import type { Building } from '@/types/buildings';

const { generateRoute } = usePermission();

export const columns: ColumnDef<Building>[] = [
  {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('condomini.table.name') }), 

      cell: ({ row }) => {
        const condominio = row.original
        const label = row.original.codice_identificativo

        return h(Link, {
          href: route(generateRoute('gestionale.index'), { condominio: condominio.id }),
          class: 'group flex items-center gap-3 py-1 outline-none'
        }, () => [
          // Icona stilizzata con palette Indigo
          h('div', { 
              class: 'p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 dark:text-indigo-400 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/60 transition-colors shrink-0' 
          }, [
              h(Building2, { class: 'w-4 h-4' })
          ]),
          
          // Contenitore Testi
          h('div', { class: 'flex flex-col min-w-0' }, [
              h('div', { class: 'flex items-center gap-2 mb-0.5' }, [
                  
                  // 1. CODICE (Badge stilizzato come il selettore tipologia immobili)
                  label ? h('span', { 
                      class: 'px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-tighter bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-md border border-indigo-100 dark:border-indigo-800' 
                  }, label) : null,

                  // 2. NOME
                  h('span', {
                      class: 'font-bold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
                  }, condominio.nome),
              ]),
              
              // Sottotitolo interattivo allineato all'estetica indigo
              h('span', { 
                  class: 'text-[10px] text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors' 
              }, [
                  trans('condomini.table.click_to_manage'),
                  h(ArrowRight, { class: 'w-3 h-3 animate-pulse text-indigo-500/60' })
              ])
          ])
        ]);
      }
  },
  {
    accessorKey: 'indirizzo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('condomini.table.address') }), 
    cell: ({ row }) => h('div', { class: 'text-sm text-slate-600 dark:text-slate-400 font-medium truncate max-w-[200px]' }, row.getValue('indirizzo')),
  },
  {
    accessorKey: 'anagrafiche',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('condomini.table.residents') }),
  
    cell: ({ row }) => {
      return h(AnagraficheStack, { anagrafiche: row.original.anagrafiche });
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const building = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { building }))
    },
  }
];