import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Segnalazione } from '@/types/segnalazioni';
import DropdownAction from '@/components/segnalazioni/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/segnalazioni/DataTableColumnHeader.vue';

export const columns: ColumnDef<Segnalazione>[] = [
    {
        accessorKey: 'subject',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Oggetto' }), 
        cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('subject')),
      },
      {
        id: 'actions',
        enableHiding: false,
        cell: ({ row }) => {
          const segnalazione = row.original
          return h('div', { class: 'relative' }, h(DropdownAction, {
            segnalazione,
          }))
        },
      }
]