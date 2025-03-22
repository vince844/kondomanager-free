import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Anagrafica } from '@/types/anagrafiche';
import DropdownAction from '@/components/anagrafiche/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/anagrafiche/DataTableColumnHeader.vue';

export const columns: ColumnDef<Anagrafica>[] = [
    {
        accessorKey: 'nome',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Nome e cognome' }), 
        cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('nome')),
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
          const anagrafica = row.original
          return h('div', { class: 'relative' }, h(DropdownAction, {
            anagrafica,
          }))
        },
      }
]