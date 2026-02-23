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

      // L'intero blocco è avvolto nel Link per rendere tutto cliccabile
      return h(Link, {
        href: route(generateRoute('gestionale.index'), { condominio: condominio.id }),
        class: 'group flex items-center gap-3 py-1 outline-none'
      }, () => [
        // Icona stilizzata (cambia colore al passaggio del mouse sulla riga)
        h('div', { 
            class: 'p-2 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400 shadow-sm group-hover:bg-primary/10 group-hover:text-primary transition-colors shrink-0' 
        }, [
            h(Building2, { class: 'w-4 h-4' })
        ]),
        
        // Contenitore Testi
        h('div', { class: 'flex flex-col min-w-0' }, [
            h('div', { class: 'flex items-center gap-2 mb-0.5' }, [
                
                // 1. CODICE (Prima del nome)
                label ? h(Badge, { 
                    variant: 'secondary', 
                    class: 'h-4 px-1.5 text-[10px] font-mono bg-slate-100 dark:bg-slate-800 border-none text-slate-500' 
                }, () => label) : null,

                // 2. NOME
                h('span', {
                    class: 'font-bold text-slate-900 dark:text-slate-100 group-hover:text-primary transition-colors truncate',
                }, condominio.nome),
            ]),
            
            // Sottotitolo interattivo
            h('span', { 
                class: 'text-[10px] text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-slate-500 transition-colors' 
            }, [
                trans('condomini.table.click_to_manage'),
                h(ArrowRight, { class: 'w-3 h-3 animate-pulse text-primary/60' })
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