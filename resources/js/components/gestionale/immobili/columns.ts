// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/immobili/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/immobili/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'

export function getColumns(condominio: Building): ColumnDef<Immobile>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) =>
        h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize font-bold' }, row.getValue('nome') as string),
        ]),
    },
    {
      accessorKey: 'descrizione',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Descrizione' }),
      cell: ({ row }) =>
        h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, row.getValue('descrizione') as string),
        ]),
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const immobile = row.original as Immobile
        return h('div', { class: 'relative' },
          h(DropdownAction, { immobile, condominio })
        )
      },
    },
  ]
}
