// columns.ts
import { h } from 'vue'
import { usePermission } from "@/composables/permissions";
import DropdownAction from '@/components/gestionale/esercizi/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/esercizi/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Esercizio>[] {
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
        const esercizio = row.original as Esercizio
        return h('div', { class: 'relative' },
          h(DropdownAction, { esercizio, condominio })
        )
      },
    },
  ]
}
