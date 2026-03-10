import { h } from 'vue'
import DataTableColumnHeader from '@/components/gestionale/movimenti/fatture/DataTableColumnHeader.vue' 
import DropdownAction from './DataTableRowActions.vue'
import { Badge } from '@/components/ui/badge'
import { Archive, AlertTriangle, CheckCircle, Clock } from 'lucide-vue-next'
import type { FatturaPassiva } from '@/types/gestionale/fatture'
import type { ColumnDef } from '@tanstack/vue-table'

// Helper per la valuta
const formatEuro = (centesimi: number) =>
    new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(centesimi / 100);

export const createColumns = (condominioId: number): ColumnDef<FatturaPassiva>[] => [
  {
    accessorKey: 'fornitore',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Fornitore & Documento' }),
    cell: ({ row }) => {
        const fattura = row.original;
        const fornitoreNome = fattura.fornitore?.ragione_sociale || 'N/D';
        
        const children = [
            h('span', { class: 'font-bold text-sm text-slate-900 truncate max-w-[200px] block' }, fornitoreNome),
            h('span', { class: 'text-xs text-slate-400 font-mono block' }, `n. ${fattura.numero_documento}`)
        ];

        // Se è pregressa, aggiungiamo il badge
        if (fattura.is_pregresso) {
            children.push(
                h('span', { class: 'inline-flex items-center gap-1 bg-amber-50 text-amber-600 border border-amber-200 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded shadow-sm mt-1' }, [
                    h(Archive, { class: 'w-3 h-3' }), ' Pregresso'
                ])
            );
        }

        return h('div', { class: 'flex flex-col items-start' }, children);
    },
    enableSorting: false,
  },
  {
    accessorKey: 'data_documento',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Date' }),
    cell: ({ row }) => {
        const fattura = row.original;
        const dataDoc = new Date(fattura.data_documento).toLocaleDateString('it-IT');
        const dataScad = new Date(fattura.data_scadenza).toLocaleDateString('it-IT');
        
        const isScaduta = new Date(fattura.data_scadenza) < new Date() && fattura.stato_pagamento !== 'pagata';

        return h('div', { class: 'flex flex-col' }, [
            h('span', { class: 'text-xs text-slate-600 font-medium' }, `Doc: ${dataDoc}`),
            h('span', { class: `text-xs ${isScaduta ? 'text-red-600 font-bold' : 'text-slate-400'}` }, `Scad: ${dataScad}`)
        ]);
    },
  },
  {
    accessorKey: 'stato_approvazione',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Approvazione' }),
    cell: ({ row }) => {
      const stato = row.getValue('stato_approvazione') as string;
      
      const config: Record<string, { label: string, class: string, icon: any }> = {
          approvata:      { label: 'Approvata', class: 'bg-emerald-100 text-emerald-700', icon: CheckCircle },
          da_approvare:   { label: 'Da approvare', class: 'bg-slate-100 text-slate-600', icon: Clock },
          contestata:     { label: 'Contestata', class: 'bg-red-100 text-red-700', icon: AlertTriangle },
          sforo_motivato: { label: 'Sforo motivato', class: 'bg-orange-100 text-orange-700 border border-orange-200 shadow-sm', icon: AlertTriangle },
      };

      const { label, class: cssClass, icon } = config[stato] || config['da_approvare'];

      return h('span', { class: `inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider ${cssClass}` }, [
          h(icon, { class: 'w-3 h-3' }), label
      ]);
    }
  },
  {
    accessorKey: 'stato_pagamento',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Pagamento' }),
    cell: ({ row }) => {
      const stato = row.getValue('stato_pagamento') as string;
      
      const config: Record<string, string> = {
          aperta:    'bg-amber-100 text-amber-700 border-amber-200',
          pagata:    'bg-emerald-100 text-emerald-700 border-emerald-200',
          parziale:  'bg-blue-100 text-blue-700 border-blue-200',
      };

      const cssClass = config[stato] || 'bg-slate-100 text-slate-600 border-slate-200';

      return h(Badge, { variant: 'outline', class: `uppercase tracking-wider text-[10px] font-bold shadow-none ${cssClass}` }, () => stato);
    }
  },
  {
    accessorKey: 'netto_a_pagare', 
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo' }),
    cell: ({ row }) => {
        return h('div', { class: 'font-black text-slate-900 text-sm font-mono text-right' }, formatEuro(row.getValue('netto_a_pagare')));
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => h(DropdownAction, { 
        fattura: row.original,
        condominioId: condominioId 
    }),
  },
]