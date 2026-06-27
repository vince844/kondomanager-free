import { h } from 'vue'
import DropdownAction from './DataTableRowActions.vue'
import DataTableColumnHeader from './DataTableColumnHeader.vue'
import { CalendarDays, Info, Banknote, Coins, Building2, CheckCircle, RotateCcw, Clock } from 'lucide-vue-next'
import { useFormat } from '@/composables/useFormat'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Incasso } from '@/types/gestionale/movimenti'

const { formatDate } = useFormat()

export const createColumns = (condominioId: number): ColumnDef<Incasso>[] => [

  // ── 1. RIFERIMENTO: Protocollo + Date accorpati ────────────────────
  {
    accessorKey: 'numero_protocollo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Riferimento' }),
    enableSorting: false,
    size: 130,
    cell: ({ row }) => {
      const dataComp = formatDate(row.original.data_competenza)
      const dataReg  = row.original.data_registrazione ? formatDate(row.original.data_registrazione) : '-'

      return h('div', { class: 'flex flex-col gap-0.5' }, [
        h('span', { class: 'text-xs font-bold whitespace-nowrap text-slate-700' },
          '#' + row.getValue('numero_protocollo')
        ),
        h('span', { class: 'text-xs text-slate-600 font-medium whitespace-nowrap' }, dataComp),
        h('span', { class: 'text-xs text-slate-400 whitespace-nowrap' }, `Reg: ${dataReg}`),
      ])
    },
  },

  // ── 2. SOGGETTO (invariato) ────────────────────────────────────────
  {
    accessorKey: 'pagante',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Soggetto' }),
    size: 180,
    cell: ({ row }) => {
      const pagante      = row.original.pagante || { principale: 'Sconosciuto', altri_count: 0, lista_completa: '', ruolo: '' }
      const anagraficaId = row.original.anagrafica_id_principale

      const nameSpan = h('span', {
        class: 'font-bold text-sm text-blue-600 truncate hover:underline cursor-pointer',
      }, pagante.principale)

      const altriBadge = pagante.altri_count > 0
        ? h('span', {
            class: 'inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold text-blue-700 bg-blue-100 rounded-full cursor-help ml-1.5 shrink-0',
            title: pagante.lista_completa,
          }, `+${pagante.altri_count}`)
        : null

      const nameRow = anagraficaId
        ? h('a', {
            href: `/admin/gestionale/${condominioId}/anagrafiche/${anagraficaId}/estratto-conto`,
            class: 'flex items-center min-w-0',
          }, [nameSpan, altriBadge].filter(Boolean))
        : h('div', { class: 'flex items-center min-w-0' }, [nameSpan, altriBadge].filter(Boolean))

      return h('div', { class: 'flex flex-col gap-0.5 overflow-hidden' }, [
        nameRow,
        h('span', { class: 'text-xs text-slate-400' }, pagante.ruolo || 'Condòmino'),
      ])
    },
  },

  // ── 3. DESCRIZIONE (invariata) ─────────────────────────────────────
  {
    accessorKey: 'causale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
    size: 240,
    cell: ({ row }) => {
      const gestione   = row.original.gestione_nome || 'Generica'
      const dettagli   = row.original.dettagli_rate || []
      const rateUniche = new Set(dettagli.map((d: any) => d.numero)).size
      const haCredito  = dettagli.some((d: any) => d.tipo === 'credito')

      const riassuntoRate = dettagli.length > 0
        ? `${rateUniche} ${rateUniche === 1 ? 'rata' : 'rate'}` + (haCredito ? ' · credito usato' : '')
        : 'Acconto / Generico'

      const gestioneBadge = h('span', {
        class: 'inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-md uppercase tracking-wider whitespace-nowrap bg-slate-100 text-slate-500 border border-slate-200 shrink-0 max-w-[200px]',
      }, [
        h(CalendarDays, { class: 'w-3 h-3 shrink-0' }),
        h('span', { class: 'truncate' }, gestione),
      ])

      const dettaglioBadge = dettagli.length > 0
        ? h(HoverCard, null, {
            default: () => [
              h(HoverCardTrigger, { asChild: false }, {
                default: () => h('span', {
                  class: 'inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-md uppercase tracking-wider whitespace-nowrap bg-blue-50 text-blue-600 border border-blue-200 cursor-help shrink-0',
                }, [
                  h(Info, { class: 'w-3 h-3 shrink-0' }),
                  riassuntoRate,
                ]),
              }),
              h(HoverCardContent, { class: 'w-64 p-0 shadow-xl border-gray-200 z-50', side: 'top', align: 'start', sideOffset: 5 }, {
                default: () => h('div', { class: 'flex flex-col' }, [
                  h('div', { class: 'px-3 py-2 bg-gray-50 border-b border-gray-100 rounded-t-md flex items-center justify-between' }, [
                    h('span', { class: 'text-[10px] font-bold text-gray-500 uppercase tracking-wider' }, 'Dettaglio Copertura'),
                    (haCredito && dettagli.some((d: any) => d.tipo === 'contanti'))
                      ? h('span', { class: 'text-[9px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full font-semibold' }, 'Misto')
                      : null,
                  ]),
                  h('div', { class: 'p-2 space-y-1.5' },
                    dettagli.map((rata: any) => {
                      const isCredito = rata.tipo === 'credito'
                      return h('div', {
                        class: `flex items-center justify-between text-xs px-2 py-1 rounded-md ${isCredito ? 'bg-blue-50' : 'bg-gray-50'}`,
                      }, [
                        h('div', { class: 'flex items-center gap-2' }, [
                          h(isCredito ? Coins : Banknote, {
                            class: `w-3 h-3 shrink-0 ${isCredito ? 'text-blue-500' : 'text-emerald-500'}`,
                          }),
                          h('div', { class: 'flex flex-col' }, [
                            h('span', { class: 'font-medium text-gray-700' },
                              `Rata n.${rata.numero}` + (rata.immobile ? ` · Int. ${rata.immobile}` : '')
                            ),
                            h('span', { class: 'text-[9px] text-gray-400' }, rata.scadenza),
                          ]),
                        ]),
                        h('div', { class: 'flex flex-col items-end gap-0.5' }, [
                          h('span', { class: `font-bold ${isCredito ? 'text-blue-600' : 'text-emerald-600'}` }, rata.importo_formatted),
                          h('span', { class: `text-[9px] font-semibold uppercase tracking-wide ${isCredito ? 'text-blue-400' : 'text-emerald-400'}` },
                            isCredito ? 'Credito' : 'Contanti'
                          ),
                        ]),
                      ])
                    })
                  ),
                ]),
              }),
            ],
          })
        : null

      return h('div', { class: 'flex flex-col gap-1 overflow-hidden' }, [
        h('span', { class: 'font-bold text-sm text-slate-900 truncate' }, row.getValue('causale')),
        h('div', { class: 'flex items-center gap-1.5 flex-wrap min-w-0' }, [gestioneBadge, dettaglioBadge].filter(Boolean)),
      ])
    },
  },

  // ── 4. IMPORTO + RISORSA accorpati ────────────────────────────────
  {
    accessorKey: 'importo_totale_raw',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo' }),
    size: 150,
    cell: ({ row }) => {
      const formatted = row.original.importo_totale_formatted
      const cassaNome = row.original.cassa_nome || 'N/D'
      const cassaTipo = row.original.cassa_tipo_label || 'Risorsa'

      return h('div', { class: 'flex flex-col gap-0.5 group cursor-default' }, [
        h('span', { class: 'font-black text-sm whitespace-nowrap text-slate-900 group-hover:text-emerald-600 transition-colors tabular-nums' },
          formatted
        ),
        h('div', { class: 'flex items-center gap-1 min-w-0' }, [
          h(Building2, { class: 'w-3 h-3 text-slate-400 shrink-0' }),
          h('span', { class: 'text-[11px] text-slate-400 truncate', title: cassaNome }, cassaNome),
        ]),
        h('span', { class: 'text-[11px] text-slate-400 whitespace-nowrap' }, cassaTipo),
      ])
    },
  },

  // ── 5. STATO (invariato) ───────────────────────────────────────────
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    size: 110,
    cell: ({ row }) => {
      const stato = row.getValue('stato') as string

      const config: Record<string, { label: string; class: string; icon: any }> = {
        registrata: {
          label: 'Confermato',
          class: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
          icon: CheckCircle,
        },
        stornato: {
          label: 'Stornato',
          class: 'bg-slate-100 text-slate-500 border border-slate-200',
          icon: RotateCcw,
        },
        annullata: {
          label: 'Annullato',
          class: 'bg-rose-50 text-rose-700 border border-rose-200',
          icon: Clock,
        },
      }

      const { label, class: cssClass, icon } = config[stato] ?? config['registrata']

      return h('span', {
        class: `inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider whitespace-nowrap ${cssClass}`,
      }, [
        h(icon, { class: 'w-3 h-3 shrink-0' }),
        label,
      ])
    },
  },

  // ── 6. AZIONI (invariato) ──────────────────────────────────────────
  {
    id: 'actions',
    enableHiding: false,
    size: 50,
    cell: ({ row }) => h(DropdownAction, {
      incasso: row.original,
      condominioId: condominioId,
    }),
  },
]