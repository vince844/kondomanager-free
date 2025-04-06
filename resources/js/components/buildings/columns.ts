import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Building } from '@/types/buildings';
import DropdownAction from './DataTableRowActions.vue';
import DataTableColumnHeader from './DataTableColumnHeader.vue';
import { Badge }  from '@/components/ui/badge';

export const columns: ColumnDef<Building>[] = [
    {
        accessorKey: 'nome',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }), 

        cell: ({ row }) => {
          const label = row.original.codice_identificativo
    
          return h('div', { class: 'flex space-x-2' }, [
            label ? h(Badge, { variant: 'outline', class: 'rounded-md' }, () => label) : null,
            h('span', { class: 'capitalize font-bold' }, row.getValue('nome')),
          ])
        },
      },
      {
        accessorKey: 'indirizzo',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Indirizzo' }), 
        cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('indirizzo')),
      },
      {
        id: 'actions',
        enableHiding: false,
        cell: ({ row }) => {
          const building = row.original
          return h('div', { class: 'relative' }, h(DropdownAction, {
            building,
          }))
        },
      }
]