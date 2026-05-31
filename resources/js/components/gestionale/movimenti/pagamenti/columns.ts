import { h } from 'vue'
import DataTableColumnHeader from '@/components/gestionale/movimenti/fatture/DataTableColumnHeader.vue' 
import DropdownAction from './DataTableRowActions.vue'
import { CheckCircle, RotateCcw, Building2, Calendar, FileText } from 'lucide-vue-next'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import type { ColumnDef } from '@tanstack/vue-table'

const { euro } = useCurrencyFormatter(); 

export const createColumns = (condominioId: number): ColumnDef<any>[] => [
  {
    accessorKey: 'fornitore',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Fornitore' }),
    size: 250,
    cell: ({ row }) => {
        const pagamento = row.original;
        
        // Cerca il fornitore reale, altrimenti usa lo snapshot se è stato eliminato
        const fornitoreNome = pagamento.fornitore?.ragione_sociale 
            || pagamento.fornitore_snapshot?.ragione_sociale 
            || 'Fornitore Sconosciuto';
            
        const isStornato = pagamento.stato === 'stornato';

        const badgeRow = [];

        // Badge Bonifico Parlante
        if (pagamento.bonifico_parlante) {
            badgeRow.push(
                h('span', { 
                    class: 'inline-flex items-center gap-1 bg-indigo-50 text-indigo-600 border border-indigo-200 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded' 
                }, [h(FileText, { class: 'w-3 h-3' }), ' Detrazione ' + (pagamento.tipo_detrazione || '')])
            );
        }
        
        // Ritenuta d'acconto
        if (pagamento.importo_ritenuta > 0) {
            badgeRow.push(
                h('span', { 
                    class: 'inline-flex items-center gap-1 bg-cyan-50 text-cyan-600 border border-cyan-200 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded' 
                }, ['Ritenuta'])
            );
        }

        return h('div', { class: 'flex flex-col gap-1 overflow-hidden' }, [
            h('span', { class: `font-bold text-sm truncate ${isStornato ? 'text-slate-400' : 'text-slate-900'}` }, fornitoreNome),
            
            // Badge (solo se presenti)
            badgeRow.length > 0
                ? h('div', { class: 'flex items-center gap-1 flex-wrap mt-0.5' }, badgeRow)
                : h('span', { class: 'text-xs text-slate-400' }, 'Pagamento standard')
        ]);
    },
    enableSorting: false,
  },
  {
    accessorKey: 'conto_corrente',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Addebito' }),
    size: 200,
    cell: ({ row }) => {
        const pagamento = row.original;
        const contoNome = pagamento.conto_corrente?.nome || 'Cassa/Conto non trovato';
        const iban = pagamento.conto_corrente?.iban || '';

        return h('div', { class: 'flex flex-col gap-0.5' }, [
            h('span', { class: 'text-xs font-semibold text-slate-700 flex items-center gap-1' }, [
                h(Building2, { class: 'w-3.5 h-3.5 text-slate-400' }),
                contoNome
            ]),
            iban ? h('span', { class: 'text-[10px] text-slate-500 font-mono tracking-tight' }, iban) : null
        ]);
    },
    enableSorting: false,
  },
  {
    accessorKey: 'data_pagamento',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data & Metodo' }),
    size: 150,
    cell: ({ row }) => {
        const pagamento = row.original;
        
        const dataPagamento = pagamento.data_pagamento_fmt || '-';
        const metodo = pagamento.metodo_pagamento || '-';

        return h('div', { class: 'flex flex-col gap-0.5' }, [
            h('span', { class: 'text-xs font-bold text-slate-700 flex items-center gap-1.5' }, [
                h(Calendar, { class: 'w-3.5 h-3.5 text-slate-400' }),
                dataPagamento
            ]),
            h('span', { class: 'text-[10px] text-slate-500 uppercase tracking-wider font-semibold' }, metodo)
        ]);
    },
  },
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    size: 130,
    cell: ({ row }) => {
        const stato = row.original.stato as string;
        const isStorno = row.original.is_storno as boolean;

        const config: Record<string, { label: string; class: string; icon: any }> = {
            confermato: { 
                label: isStorno ? 'Storno Confermato' : 'Confermato', 
                class: isStorno ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200', 
                icon: isStorno ? RotateCcw : CheckCircle 
            },
            stornato: { 
                label: 'Originale Stornato', 
                class: 'bg-slate-100 text-slate-500 border border-slate-200 decoration-slate-400', 
                icon: RotateCcw 
            },
        };

        const { label, class: cssClass, icon } = config[stato] ?? { label: stato, class: 'bg-slate-100 text-slate-500', icon: CheckCircle };

        return h('span', { 
            class: `inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider whitespace-nowrap ${cssClass}`
        }, [
            h(icon, { class: 'w-3 h-3 shrink-0' }), 
            label
        ]);
    }
  },
  {
    accessorKey: 'importi',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importi' }),
    size: 150,
    cell: ({ row }) => {
        const pagamento = row.original;
        
        const lordo = pagamento.importo_lordo;
        const netto = pagamento.importo_netto;
        const ritenuta = pagamento.importo_ritenuta || 0;
        const commissioni = pagamento.importo_commissione || 0;

        const isStornato = pagamento.stato === 'stornato';

        // Scontrino per il tooltip
        const scontrino = 
            `DETTAGLIO TRANSAZIONE\n\n` +
            `Totale Debito Saldato: ${euro(lordo)}\n` +
            (ritenuta > 0 ? `Ritenuta (Trattenuta): -${euro(ritenuta)}\n` : '') +
            `Importo Bonificato: ${euro(netto)}\n` +
            (commissioni > 0 ? `Commissioni Bancarie: +${euro(commissioni)}\n` : '') +
            `----------------------\n` +
            `Totale Uscita Cassa: ${euro(netto + commissioni)}`;

        return h('div', { 
            class: 'flex flex-col items-end group cursor-help',
            title: scontrino
        }, [
            h('span', { class: 'text-[9px] font-black uppercase text-slate-400 tracking-wider' }, 'Totale Pagato'),
            h('span', { 
                class: `font-black text-sm whitespace-nowrap transition-colors ${
                    isStornato ? 'text-slate-400' : 'text-slate-900 group-hover:text-indigo-600'
                }` 
            }, euro(lordo)),
            
            ritenuta > 0 && !isStornato
                ? h('span', { class: 'text-[10px] text-amber-600 font-bold mt-0.5' }, `Netto: ${euro(netto)}`)
                : null
        ]);
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    size: 50,
    cell: ({ row }) => h(DropdownAction, { 
        pagamento: row.original,
        condominioId: condominioId 
    }),
  },
]
