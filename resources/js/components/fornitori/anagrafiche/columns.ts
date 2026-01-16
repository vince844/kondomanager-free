import { h } from 'vue'
import DropdownAction from '@/components/fornitori/anagrafiche/DataTableRowActions.vue'
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import type { ColumnDef } from '@tanstack/vue-table'
import type { AnagraficaFornitore } from '@/types/anagrafiche'
import type { Fornitore } from '@/types/fornitori'

export const createColumns = (fornitore: Fornitore): ColumnDef<AnagraficaFornitore>[] => [
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
    accessorKey: 'pivot.ruolo',
    header: 'Ruolo',
    cell: ({ row }) => {
      const anagrafica = row.original 
      return h('div', { class: 'capitalize flex space-x-2 items-center' }, [
        anagrafica
          ? h(
              Badge,
              { variant: 'outline', class: 'rounded-md' },
              () => anagrafica.pivot.ruolo
            )
          : null,
      ]);
    },
  },
  {
    accessorKey: 'user_id',
    header: 'Accesso login',
    cell: ({ row }) => {
      const anagrafica = row.original 
      const hasAccess = anagrafica.user_id !== null
      
      return h('div', { class: 'flex items-center gap-2' }, [
        h(hasAccess ? CheckCircle2 : XCircle, { 
          class: hasAccess ? 'h-4 w-4 text-green-600' : 'h-4 w-4 text-red-600' 
        }),
        h('span', hasAccess ? 'Attivo' : 'Non attivo')
      ]);
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const anagrafica = row.original as AnagraficaFornitore
      return h(DropdownAction, {
        fornitore,
        anagrafica
      })
    },
  },
]