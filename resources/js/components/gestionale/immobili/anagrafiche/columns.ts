import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Immobile } from '@/types/gestionale/immobili'
import type { Anagrafica } from '@/types/anagrafiche'

export const columns: ColumnDef<Anagrafica>[] = [
   {
    accessorKey: 'nome',
    header: 'Nome',
    cell: ({ row }) => {
      const anagrafica = row.original as Anagrafica
      return h('span', { class: 'capitalize' }, anagrafica.nome)
    },
  },
]
