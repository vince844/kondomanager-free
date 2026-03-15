import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'

import DropdownAction from '@/components/gestionale/casse/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/casse/DataTableColumnHeader.vue'
import { Badge } from '@/components/ui/badge'
import { Star, Banknote, PiggyBank, Wallet, Box } from 'lucide-vue-next'

import type { Cassa } from '@/types/gestionale/casse'
import type { Building } from '@/types/buildings'

const formatTipoConto = (tipo: string | null | undefined) => {
  if (!tipo) return ''
  const labels: Record<string, string> = {
    ordinario: 'Conto Ordinario',
    dedicato: 'Conto Dedicato',
    postale: 'Conto Postale',
    contabilita_speciale: 'Contabilità Speciale',
    estero: 'Conto Estero',
    altro: 'Altro',
  }
  return labels[tipo] || tipo
}

// Config badge per tipo cassa
const tipoConfig: Record<string, { label: string; class: string; icon: any }> = {
  banca:     { label: 'Conto corrente', class: 'bg-blue-50 text-blue-700 border-blue-200',   icon: Banknote },
  contanti:  { label: 'Cassa contanti', class: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: Wallet },
  fondo:     { label: 'Fondo riserva',  class: 'bg-amber-50 text-amber-700 border-amber-200', icon: PiggyBank },
  virtuale:  { label: 'Cassa virtuale', class: 'bg-slate-50 text-slate-600 border-slate-200', icon: Box },
}

export function getColumns(condominio: Building): ColumnDef<Cassa>[] {
  return [
    // ─── TIPO ───────────────────────────────────────────────────
    {
      accessorKey: 'tipo',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipo' }),
      size: 150,
      cell: ({ row }) => {
        const tipo = row.original.tipo
        const config = tipoConfig[tipo] ?? { label: tipo, class: 'bg-slate-50 text-slate-600 border-slate-200', icon: Box }

        return h('span', {
          class: `inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider border ${config.class}`
        }, [
          h(config.icon, { class: 'w-3 h-3 shrink-0' }),
          config.label
        ])
      },
    },

    // ─── NOME / DETTAGLI ────────────────────────────────────────
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dettagli Risorsa' }),
      cell: ({ row }) => {
        const cassa = row.original
        const isBanca = cassa.tipo === 'banca'
        const isPredefinito = Boolean(cassa.banca_predefinito)

        return h('div', { class: 'flex flex-col gap-0.5' }, [
          // Riga 1: Nome + badge Principale
          h('div', { class: 'flex items-center gap-2' }, [
            h('span', { class: 'font-semibold text-sm text-gray-900 dark:text-gray-100' }, cassa.nome),
            isPredefinito && isBanca
              ? h('span', { class: 'inline-flex items-center gap-1 text-[9px] font-black uppercase px-1.5 py-0.5 rounded bg-amber-50 text-amber-600 border border-amber-200' }, [
                  h(Star, { class: 'w-2.5 h-2.5 fill-amber-400 text-amber-400 shrink-0' }),
                  'Principale'
                ])
              : null
          ]),

          // Riga 2: tipo conto o descrizione
          h('span', { class: 'text-[11px] text-muted-foreground' },
            isBanca
              ? (formatTipoConto(cassa.banca_tipo_conto) || '—')
              : (cassa.descrizione || '—')
          ),

          // Riga 3: IBAN (solo banca)
          isBanca && cassa.banca_iban
            ? h('div', { class: 'flex items-center gap-1 mt-0.5' }, [
                h('span', { class: 'text-[9px] uppercase font-bold text-muted-foreground' }, 'IBAN'),
                h('span', { class: 'text-[10px] font-mono text-gray-500 dark:text-gray-400 truncate max-w-[180px]' }, cassa.banca_iban),
              ])
            : null,
        ])
      },
    },

    // ─── SALDO INIZIALE ─────────────────────────────────────────
    {
      accessorKey: 'saldo_iniziale_raw',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Saldo Iniziale' }),
      size: 120,
      cell: ({ row }) => {
          return h('div', { class: 'flex flex-col gap-0.5' }, [
              h('span', { class: 'text-sm text-slate-500 font-medium' }, row.original.saldo_iniziale_formatted),
              h('span', { class: 'text-[9px] text-slate-400 uppercase font-bold tracking-wide' }, 'apertura'),
          ])
      },
    },

    // ─── SALDO ATTUALE ──────────────────────────────────────────
    {
      accessorKey: 'saldo_raw',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Saldo Attuale' }),
      size: 130,
      cell: ({ row }) => {
          const amount = row.getValue('saldo_raw') as number

          return h('span', {
            class: [
              'inline-flex px-2 py-1 rounded-md font-bold text-sm',
              amount > 0.01  ? 'text-emerald-600 dark:text-emerald-400 dark:bg-emerald-950/30' : '',
              amount < -0.01 ? 'text-red-600 dark:text-red-400 dark:bg-red-950/30' : '',
              Math.abs(amount) <= 0.01 ? 'text-slate-500 bg-slate-50' : '',
            ]
          }, row.original.saldo_formatted)
      },
    },

    // ─── STATO ──────────────────────────────────────────────────
    {
      accessorKey: 'attiva',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
      size: 100,
      cell: ({ row }) => {
        const isActive = row.getValue('attiva')

        return h('span', {
          class: [
            'inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider border',
            isActive
              ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
              : 'bg-slate-50 text-slate-500 border-slate-200'
          ]
        }, [
          h('span', {
            class: [
              'flex h-1.5 w-1.5 rounded-full',
              isActive ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'
            ]
          }),
          isActive ? 'Attiva' : 'Archiviata'
        ])
      },
    },

    // ─── AZIONI ─────────────────────────────────────────────────
    {
      id: 'actions',
      enableHiding: false,
      size: 50,
      cell: ({ row }) => h(
        'div',
        { class: 'relative text-right' },
        h(DropdownAction, { cassa: row.original, condominio }),
      ),
    },
  ]
}