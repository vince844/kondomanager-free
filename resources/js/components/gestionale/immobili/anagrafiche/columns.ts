import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { AnagraficaWithPivot } from '@/types/anagrafiche'
import type { Building } from '@/types/buildings'
import type { Immobile } from '@/types/gestionale/immobili'
import DropdownAction from '@/components/gestionale/immobili/anagrafiche/DataTableRowActions.vue'

export const createColumns = (condominio: Building, immobile: Immobile): ColumnDef<AnagraficaWithPivot>[] => [
  {
    accessorKey: 'nome',
    header: 'Nome',
    cell: ({ row }) => {
      const anagrafica = row.original as AnagraficaWithPivot
      return h('span', { class: 'capitalize font-bold' }, anagrafica.nome)
    },
  },
  {
    accessorKey: 'tipologia',
    header: 'Tipologia',
    cell: ({ row }) => {
      const anagrafica = row.original as AnagraficaWithPivot
      return h('span', { class: 'capitalize' }, anagrafica.pivot.tipologia)
    },
  },
  {
    accessorKey: 'quota',
    header: 'Quota',
    cell: ({ row }) => {
      const anagrafica = row.original as AnagraficaWithPivot
      return h('span', { class: 'capitalize' }, `${anagrafica.pivot.quota} %`)
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const anagrafica = row.original as AnagraficaWithPivot
      return h(DropdownAction, {
        anagrafica,
        condominio,
        immobile
      })
    },
  },
]
