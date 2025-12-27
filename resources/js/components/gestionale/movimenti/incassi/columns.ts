import { h } from 'vue'
import DropdownAction from './DataTableRowActions.vue'
import DataTableColumnHeader from './DataTableColumnHeader.vue' 
import { Badge } from '@/components/ui/badge'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Incasso } from '@/types/gestionale/movimenti'

// 🔥 MODIFICA QUI: Importiamo solo useFormat
import { useFormat } from '@/composables/useFormat'

// Istanziamo il composable
const { formatDate, formatCurrency } = useFormat()

export const createColumns = (condominioId: number): ColumnDef<Incasso>[] => [
  {
    accessorKey: 'numero_protocollo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Protocollo' }),
    cell: ({ row }) => h('div', { class: 'font-mono font-bold text-primary' }, row.getValue('numero_protocollo')),
  },
  {
    accessorKey: 'data_competenza',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data' }),
    cell: ({ row }) => {
        // Usiamo formatDate dal tuo composable
        const dataCompetenza = formatDate(row.getValue('data_competenza'));
        const dataReg = formatDate(row.original.data_registrazione);

        return h('div', { class: 'flex flex-col' }, [
            h('span', { class: 'font-medium' }, dataCompetenza),
            h('span', { class: 'text-[10px] text-muted-foreground' }, 'Reg: ' + dataReg)
        ])
    },
  },
  {
    accessorKey: 'pagante_nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Condomino' }),
    cell: ({ row }) => h('div', { class: 'font-medium' }, row.getValue('pagante_nome')),
  },
  {
    accessorKey: 'causale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Causale' }),
    cell: ({ row }) => h('div', { class: 'truncate max-w-[200px] text-muted-foreground' }, row.getValue('causale')),
  },
  {
    accessorKey: 'importo_totale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo' }),
    cell: ({ row }) => {
        // Il backend invia già EURO (es. 150.50), quindi passiamo il numero diretto
        const valEuro = Number(row.getValue('importo_totale') || 0);
        
        // Usiamo formatCurrency dal tuo composable
        return h('div', { class: 'text-right font-bold text-emerald-600' }, formatCurrency(valEuro));
    },
  },
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    cell: ({ row }) => {
      const stato = row.getValue('stato') as string
      return h(Badge, { 
        variant: stato === 'annullata' ? 'destructive' : 'outline',
        class: stato === 'registrata' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ''
      }, () => stato === 'registrata' ? 'Registrato' : 'Annullato')
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => h(DropdownAction, { 
        incasso: row.original,
        condominioId: condominioId 
    }),
  },
]