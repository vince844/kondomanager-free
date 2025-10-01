// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/gestioni/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/gestioni/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Gestione } from '@/types/gestionale/gestioni'
import type { Building } from '@/types/buildings'

export function getColumns(condominio: Building): ColumnDef<Gestione>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('nome')),

    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const gestione = row.original as Gestione
        return h('div', { class: 'relative' },
          h(DropdownAction, { gestione, condominio })
        )
      },
    },
  ]
}
