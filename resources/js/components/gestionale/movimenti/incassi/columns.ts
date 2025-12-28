import { h } from 'vue'
import DropdownAction from './DataTableRowActions.vue'
import DataTableColumnHeader from './DataTableColumnHeader.vue' 
import { Badge } from '@/components/ui/badge'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Incasso } from '@/types/gestionale/movimenti'
import { CalendarDays } from 'lucide-vue-next'

import { useFormat } from '@/composables/useFormat'

const { formatDate, formatCurrency } = useFormat()

export const createColumns = (condominioId: number): ColumnDef<Incasso>[] => [
  {
    accessorKey: 'numero_protocollo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Protocollo' }),
    cell: ({ row }) => h('div', { class: 'text-xs font-bold' }, '#' + row.getValue('numero_protocollo')),
    enableSorting: false,
    size: 80, 
  },
  {
    accessorKey: 'data_competenza',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data' }),
    cell: ({ row }) => {
        const dataCompetenza = formatDate(row.getValue('data_competenza'));
        const dataReg = row.original.data_registrazione ? formatDate(row.original.data_registrazione) : '-';

        return h('div', { class: 'flex flex-col' }, [
            h('span', { class: 'font-semibold text-sm' }, dataCompetenza),
            h('span', { class: 'text-[10px] text-muted-foreground' }, 'Reg: ' + dataReg)
        ])
    },
  },
  {
    accessorKey: 'pagante_nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Soggetto' }),
    cell: ({ row }) => {
        return h('div', { class: 'flex items-center gap-2' }, [
            // ✅ AGGIUNTO: truncate e max-w per evitare rotture del layout
            h('span', { class: 'font-medium truncate max-w-[180px]' }, row.getValue('pagante_nome') || 'Sconosciuto')
        ])
    },
  },
  {
    accessorKey: 'causale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
    cell: ({ row }) => {
        const gestione = row.original.gestione_nome || 'Generica'; 
        
        return h('div', { class: 'flex flex-col space-y-1' }, [
            h('span', { class: 'truncate max-w-[250px] font-medium text-sm' }, row.getValue('causale')),
            h('div', { class: 'flex items-center gap-2' }, [
                 h(Badge, { variant: 'secondary', class: 'text-[10px] h-5 px-1 font-semibold rounded-md text-muted-foreground bg-gray-100' }, () => [
                    h(CalendarDays, { class: 'w-3 h-3 mr-1 font-semibold' }),
                    gestione
                 ])
            ])
        ])
    },
  },
  {
    id: 'risorsa',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Risorsa' }),
    cell: ({ row }) => {
        const cassa = row.original.cassa_nome || 'N/D';
        // ✅ AGGIUNTO: bg-white e border-gray-200 per effetto "tag fisico"
        return h(Badge, { variant: 'outline', class: 'text-xs text-gray-600 rounded-md bg-white border-gray-200' }, () => [
            cassa
        ])
    }
  },
  {
    accessorKey: 'importo_totale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo' }),
    cell: ({ row }) => {
        const valEuro = Number(row.getValue('importo_totale') || 0);
        return h('div', { class: 'font-bold text-emerald-600 text-md' }, '+ ' + formatCurrency(valEuro));
    },
  },
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    cell: ({ row }) => {
      const stato = row.getValue('stato') as string
      return h(Badge, { 
        variant: stato === 'annullata' ? 'destructive' : 'default',
        class: stato === 'registrata' 
            ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border-emerald-200 shadow-none rounded-md' 
            : 'shadow-none'
      }, () => stato === 'registrata' ? 'Confermato' : 'Annullato')
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