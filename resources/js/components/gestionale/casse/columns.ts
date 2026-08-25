import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import DropdownAction from '@/components/gestionale/casse/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/casse/DataTableColumnHeader.vue'
import { Star, Banknote, PiggyBank, Wallet, Box, CheckCircle2, Lock, Unlock } from 'lucide-vue-next'
import type { Cassa } from '@/types/gestionale/casse'
import type { Building } from '@/types/buildings'

// ─── UTILS ──────────────────────────────────────────────────────
const truncateText = (text: string, len = 40) => 
  text.length > len ? text.slice(0, len) + '…' : text

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

const formatSottotipoFondo = (tipo: string | null | undefined) => {
  if (!tipo) return 'Fondo'
  const labels: Record<string, string> = {
    generico: 'Generico / Imprevisti',
    vincolato_lavori: 'Vincolato Lavori',
    tfr: 'Accantonamento TFR',
    morosita: 'Fondo Morosità'
  }
  return labels[tipo] || tipo
}

// Config badge per tipo cassa
const tipoConfig = {
  banca:     { label: 'Conto corrente', class: 'bg-blue-50 text-blue-700 border-blue-200',   icon: Banknote },
  contanti:  { label: 'Cassa contanti', class: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: Wallet },
  fondo:     { label: 'Fondo riserva',  class: 'bg-amber-50 text-amber-700 border-amber-200', icon: PiggyBank },
  virtuale:  { label: 'Cassa virtuale', class: 'bg-slate-50 text-slate-600 border-slate-200', icon: Box },
} as const

export function getColumns(condominio: Building): ColumnDef<Cassa>[] {
  return [
    // ─── TIPO ───────────────────────────────────────────────────
    {
      accessorKey: 'tipo',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipo' }),
      size: 140,
      cell: ({ row }) => {
        const tipo = row.original.tipo
        // Fallback robusto per tipi sconosciuti
        const config = tipoConfig[tipo as keyof typeof tipoConfig] ?? { 
          label: tipo ?? 'N/D', 
          class: 'bg-slate-50 text-slate-600 border-slate-200', 
          icon: Box 
        }

        return h('span', {
          class: `inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wide border ${config.class}`
        }, [
          h(config.icon, { class: 'w-3.5 h-3.5 shrink-0' }),
          config.label
        ])
      },
    },

    // ─── NOME / DETTAGLI ────────────────────────────────────────
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dettagli Risorsa' }),
      cell: ({ row }) => {
        // Applichiamo il cast solo internamente alla cella per estrarre i dati extra
        const cassa = row.original 
        
        const isBanca = cassa.tipo === 'banca'
        const isFondo = cassa.tipo === 'fondo'
        const isPredefinito = Boolean(cassa.banca_predefinito)
        
        const vincolo = cassa.vincolo_descrizione 
            ? ` — ${truncateText(cassa.vincolo_descrizione)}` 
            : ''

        return h('div', { class: 'flex flex-col gap-1 py-1' }, [
          
          // Riga 1: Nome + badge Principale
          h('div', { class: 'flex items-center gap-2.5' }, [
            h('span', { class: 'font-semibold text-sm text-slate-900 dark:text-slate-100 leading-tight' }, cassa.nome),
            
            isPredefinito && isBanca
              ? h('span', { class: 'inline-flex items-center gap-1 text-[9px] font-black uppercase px-1.5 py-0.5 rounded-full bg-amber-50/60 text-amber-600 border border-amber-200/60' }, [
                  h(Star, { class: 'w-2.5 h-2.5 fill-amber-500 text-amber-500 shrink-0' }),
                  'Principale'
                ])
              : null
          ]),

          // Riga 2: Sub-descrizione contestuale
          h('span', { class: 'text-[11px] text-slate-500 dark:text-slate-400 leading-tight' },
            isBanca 
              ? (formatTipoConto(cassa.banca_tipo_conto) || '—')
              : isFondo
                ? (formatSottotipoFondo(cassa.sottotipo_fondo) + vincolo)
                : (cassa.descrizione || '—')
          ),

          // Riga 3: Dati tecnici (IBAN style o Status Fondo Avanzato)
          isBanca && cassa.banca_iban
            ? h('div', { class: 'mt-1.5' }, [
                h('span', { class: 'inline-flex items-center px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700/50' }, [
                  h('span', { class: 'text-[9px] uppercase font-bold text-slate-400 mr-2 tracking-widest' }, 'IBAN'),
                  h('span', { class: 'text-[10px] font-mono font-medium text-slate-700 dark:text-slate-300 truncate max-w-[140px] md:max-w-[180px] tracking-tight' }, cassa.banca_iban),
                ])
              ])
            : null,
            
          isFondo
            ? h('div', { class: 'flex items-center mt-1.5' }, [
                cassa.is_utilizzabile_per_imprevisti
                  ? (
                      cassa.is_override_assemblea
                        ? h('span', { class: 'inline-flex items-center gap-1 text-[9px] font-bold text-purple-600 uppercase tracking-wide' }, [
                            h(Unlock, { class: 'w-3 h-3' }), 'Sbloccato (deroga)'
                          ])
                        : h('span', { class: 'inline-flex items-center gap-1 text-[9px] font-bold text-emerald-600 uppercase tracking-wide' }, [
                            h(CheckCircle2, { class: 'w-3 h-3' }), 'Libero'
                          ])
                    )
                  : h('span', { class: 'inline-flex items-center gap-1 text-[9px] font-bold text-red-600 uppercase tracking-wide' }, [
                      h(Lock, { class: 'w-3 h-3' }), 'Vincolato'
                    ])
              ])
            : null,
        ])
      },
    },

    // ─── SALDO INIZIALE ─────────────────────────────────────────
    {
      accessorKey: 'saldo_iniziale_raw',
      header: ({ column }) => h('div', { class: 'text-right w-full flex justify-end items-center' }, h(DataTableColumnHeader, { column, title: 'Saldo Apertura' })),
      size: 130,
      cell: ({ row }) => {
          const cassa = row.original 
          return h('div', { class: 'flex flex-col items-end gap-0.5 w-full pr-4' }, [
              h('span', { class: 'text-[13px] text-slate-500 font-medium tabular-nums' }, cassa.saldo_iniziale_formatted),
          ])
      },
    },

    // ─── SALDO ATTUALE ──────────────────────────────────────────
    {
      accessorKey: 'saldo_raw',
      /**
       * ⚠️ **Non ordinabile.** «Saldo Attuale» non è un campo: è calcolato sommando i movimenti della cassa.
       */
      enableSorting: false,
      header: ({ column }) => h('div', { class: 'text-right w-full flex justify-end items-center' }, h(DataTableColumnHeader, { column, title: 'Saldo Attuale' })),
      size: 140,
      cell: ({ row }) => {
          const cassa = row.original
          const amount = row.getValue('saldo_raw') as number

          return h('div', { class: 'text-right font-semibold text-[14px] tabular-nums flex justify-end items-center w-full pr-4' }, [
            h('span', {
              class: [
                amount > 0.01 ? 'text-emerald-600 dark:text-emerald-400' : '',
                amount < -0.01 ? 'text-red-600 dark:text-red-400' : '',
                Math.abs(amount) <= 0.01 ? 'text-slate-500' : '',
              ]
            }, cassa.saldo_formatted)
          ])
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
            'inline-flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-1 rounded-full uppercase tracking-wide',
            isActive
              ? 'text-emerald-700 bg-emerald-50/50 dark:bg-emerald-900/20'
              : 'text-slate-500 bg-slate-50/50 dark:bg-slate-900/20'
          ]
        }, [
          h('span', {
            class: [
              'flex h-1.5 w-1.5 rounded-full',
              isActive ? 'bg-emerald-500 shadow-[0_0_4px_rgba(16,185,129,0.5)]' : 'bg-slate-300'
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
        { class: 'relative text-right pr-2' },
        h(DropdownAction, { cassa: row.original, condominio }),
      ),
    },
  ]
}