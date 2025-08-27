// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/palazzine/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/palazzine/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Palazzina } from '@/types/gestionale/palazzine'
import type { Building } from '@/types/buildings'

export function getColumns(condominio: Building): ColumnDef<Palazzina>[] {
  return [
    {
      accessorKey: 'name',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) =>
        h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize font-bold' }, row.getValue('name') as string),
        ]),
    },
    {
      accessorKey: 'description',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Descrizione' }),
      cell: ({ row }) =>
        h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, row.getValue('description') as string),
        ]),
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const palazzina = row.original as Palazzina
        return h('div', { class: 'relative' },
          h(DropdownAction, { palazzina, condominio })
        )
      },
    },
  ]
}
