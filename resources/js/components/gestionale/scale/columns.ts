// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/scale/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/scale/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Scala } from '@/types/gestionale/scale'
import type { Building } from '@/types/buildings'
import { trans } from 'laravel-vue-i18n'

export function getColumns(condominio: Building): ColumnDef<Scala>[] {
  return [
    {
      accessorKey: 'name',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: trans('gestionale.list_pages.scale.table.name') }),
      cell: ({ row }) =>
        h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize font-bold' }, row.getValue('name') as string),
        ]),
    },
    {
      accessorKey: 'palazzina',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: trans('gestionale.list_pages.scale.table.building') }),

      cell: ({ row }) => {
        const scala = row.original as Scala
        const palazzina = scala.palazzina ? scala.palazzina.name : '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, palazzina),
        ])
      }
        
    },
    {
      accessorKey: 'description',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: trans('gestionale.list_pages.scale.table.description') }),
      cell: ({ row }) =>
        h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, row.getValue('description') as string),
        ]),
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const scala = row.original as Scala
        return h('div', { class: 'relative' },
          h(DropdownAction, { scala, condominio })
        )
      },
    },
  ]
}
