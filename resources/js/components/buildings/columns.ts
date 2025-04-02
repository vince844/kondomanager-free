import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Building } from '@/types/buildings';
import DropdownAction from './DataTableRowActions.vue';
import DataTableColumnHeader from './DataTableColumnHeader.vue';

export const columns: ColumnDef<Building>[] = [
    {
        accessorKey: 'nome',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }), 
        cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('nome')),
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