import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Anagrafica } from '@/types/anagrafiche'
import type { Fornitore } from '@/types/fornitori'
import DropdownAction from '@/components/fornitori/anagrafiche/DataTableRowActions.vue'

/* export const columns: ColumnDef<Anagrafica>[] = [ */
export const createColumns = (fornitore: Fornitore): ColumnDef<Anagrafica>[] => [
  {
    accessorKey: 'nome',
    header: 'Nome',
    cell: ({ row }) => {
      const anagrafica = row.original 
      return h('span', { class: 'capitalize font-bold' }, anagrafica.nome)
    },
  },
  {
    accessorKey: 'indirizzo',
    header: 'Indirizzo',
    cell: ({ row }) => {
      const anagrafica = row.original 
      return h('span', { class: 'capitalize' }, anagrafica.indirizzo)
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const anagrafica = row.original as Anagrafica
      return h(DropdownAction, {
        fornitore,
        anagrafica
      })
    },
  },
]
