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
      const tipologia = anagrafica.pivot.tipologia

      // Definisci il colore in base alla tipologia
      let colore = ''
      switch (tipologia) {
        case 'proprietario':
          colore = 'text-blue-600'   
          break
        case 'usufruttuario':
          colore = 'text-purple-600' 
          break
        case 'inquilino':
          colore = 'text-green-600'  
          break
        default:
          colore = 'text-gray-600'
      }

      return h('span', { class: `capitalize ${colore}` }, tipologia)
    },
  },
  {
    accessorKey: 'saldo.iniziale',
    header: 'Saldo Iniziale',
    cell: ({ row }) => {
      const anagrafica = row.original as AnagraficaWithPivot
      const saldoFormattato = anagrafica.saldo.iniziale 
      const saldoRaw = anagrafica.saldo.amounts.iniziale 
      const colore = saldoRaw < 0 ? 'text-red-600' : 'text-green-600'

      return h('span', { class: `${colore}` }, saldoFormattato)
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
