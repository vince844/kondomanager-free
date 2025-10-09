// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/pianiDeiConti/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/pianiDeiConti/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Building } from '@/types/buildings'

export function getColumns(condominio: Building): ColumnDef<PianoDeiConti>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => h('div', { class: 'font-bold' }, row.getValue('nome')),

    },
    {
      accessorKey: 'descrizione',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
      cell: ({ row }) => h('div', row.getValue('descrizione')),

    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const pianoDeiConti = row.original as PianoDeiConti
        return h('div', { class: 'relative' },
          h(DropdownAction, { pianoDeiConti, condominio })
        )
      },
    },
  ]
}
