import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Role } from '@/types/roles';
import DropdownAction from '@/components/roles/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/roles/DataTableColumnHeader.vue';

export const columns: ColumnDef<Role>[] = [
    {
      accessorKey: 'name',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ruolo' }), 
      cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('name')),
    },
    {
      accessorKey: 'description',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }), 
      cell: ({ row }) => h('div', row.getValue('description')),
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const role = row.original
        return h('div', { class: 'relative' }, h(DropdownAction, {
          role,
        }))
      },
    }
]