import { h } from 'vue'
import DropdownAction from './DataTableRowActions.vue'
import DataTableColumnHeader from './DataTableColumnHeader.vue' 
import { Badge } from '@/components/ui/badge'
import { CalendarDays, Info, Banknote, Coins } from 'lucide-vue-next'
import { useFormat } from '@/composables/useFormat'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Incasso } from '@/types/gestionale/movimenti'

const { formatDate } = useFormat()

export const createColumns = (condominioId: number): ColumnDef<Incasso>[] => [
  {
    accessorKey: 'numero_protocollo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Protocollo' }),
    cell: ({ row }) => h('div', { class: 'text-xs font-bold whitespace-nowrap' }, '#' + row.getValue('numero_protocollo')),
    enableSorting: false,
    size: 120, 
},
  {
    accessorKey: 'data_competenza',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data' }),
    size: 110,
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
    accessorKey: 'pagante',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Soggetto' }),
    size: 140,
    cell: ({ row }) => {
        const pagante = row.original.pagante || { principale: 'Sconosciuto', altri_count: 0, lista_completa: '', ruolo: '' };
        const anagraficaId = row.original.anagrafica_id_principale; 

        const nameContent = [
            h('span', { class: 'font-medium truncate max-w-[140px] hover:underline cursor-pointer text-blue-600' }, pagante.principale)
        ];

        if (pagante.altri_count > 0) {
            nameContent.push(
                h('span', {
                    class: 'inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold text-blue-700 bg-blue-100 rounded-full cursor-help ml-2',
                    title: pagante.lista_completa 
                }, `+${pagante.altri_count}`)
            );
        }

        const linkWrapper = anagraficaId 
            ? h('a', { href: `/admin/gestionale/${condominioId}/anagrafiche/${anagraficaId}/estratto-conto`, class: 'flex items-center' }, nameContent)
            : h('div', { class: 'flex items-center' }, nameContent);

        return h('div', { class: 'flex flex-col' }, [
            linkWrapper,
            h('span', { class: 'text-[10px] text-muted-foreground' }, pagante.ruolo || 'Condòmino') 
        ]);
    },
  },
  {
    accessorKey: 'causale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
    size: 260,
    cell: ({ row }) => {
        const gestione = row.original.gestione_nome || 'Generica'; 
        const dettagli = row.original.dettagli_rate || []; 
        
        const rateUniche = new Set(dettagli.map((d: any) => d.numero)).size
        const haCredito = dettagli.some((d: any) => d.tipo === 'credito')

        const riassuntoRate = dettagli.length > 0
            ? `${rateUniche} rate` + (haCredito ? ' · credito usato' : '')
            : 'Acconto / Generico'

        return h('div', { class: 'flex flex-col space-y-1 overflow-hidden' }, [
            h('span', { class: 'truncate font-medium text-sm' }, row.getValue('causale')),
            
            h('div', { class: 'flex items-center gap-1.5 flex-wrap min-w-0' }, [
                 
                 h(Badge, { variant: 'secondary', class: 'text-[10px] h-5 px-1 font-semibold rounded-md text-muted-foreground bg-gray-100 shrink-0' }, () => [
                    h(CalendarDays, { class: 'w-3 h-3 mr-1 shrink-0' }),
                    h('span', { class: 'truncate max-w-[80px]' }, gestione)
                 ]),
                 
                 dettagli.length > 0 
                 ? h(HoverCard, null, {
                    default: () => [
                        h(HoverCardTrigger, { asChild: false }, { 
                            default: () => h('div', { 
                                class: 'flex items-center cursor-help text-[10px] text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full hover:bg-blue-100 transition-colors border border-blue-100 whitespace-nowrap shrink-0' 
                            }, [
                                h(Info, { class: 'w-3 h-3 mr-1 shrink-0' }),
                                riassuntoRate
                            ])
                        }),

                        h(HoverCardContent, { class: 'w-64 p-0 shadow-xl border-gray-200 z-50', side: 'top', align: 'start', sideOffset: 5 }, {
                            default: () => h('div', { class: 'flex flex-col' }, [
                                // Header
                                h('div', { class: 'px-3 py-2 bg-gray-50 border-b border-gray-100 rounded-t-md flex items-center justify-between' }, [
                                    h('span', { class: 'text-[10px] font-bold text-gray-500 uppercase tracking-wider' }, 'Dettaglio Copertura'),
                                    haCredito && dettagli.some((d: any) => d.tipo === 'contanti')
                                        ? h('span', { class: 'text-[9px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full font-semibold' }, 'Misto')
                                        : null
                                ]),
                                
                                // Body
                                h('div', { class: 'p-2 space-y-1.5' }, [
                                    ...dettagli.map((rata: any) => {
                                        const isCredito = rata.tipo === 'credito'
                                        return h('div', {
                                            class: 'flex items-center justify-between text-xs px-2 py-1 rounded-md ' +
                                                (isCredito ? 'bg-blue-50' : 'bg-gray-50')
                                        }, [
                                            h('div', { class: 'flex items-center gap-2' }, [
                                                h(isCredito ? Coins : Banknote, {
                                                    class: 'w-3 h-3 shrink-0 ' + (isCredito ? 'text-blue-500' : 'text-emerald-500')
                                                }),
                                                h('div', { class: 'flex flex-col' }, [
                                                    h('span', { class: 'font-medium text-gray-700' },
                                                        `Rata n.${rata.numero}` + (rata.immobile ? ` · Int. ${rata.immobile}` : '')
                                                    ),
                                                    h('span', { class: 'text-[9px] text-gray-400' }, rata.scadenza)
                                                ])
                                            ]),
                                            h('div', { class: 'flex flex-col items-end gap-0.5' }, [
                                                h('span', {
                                                    class: 'font-mono font-bold ' + (isCredito ? 'text-blue-600' : 'text-emerald-600')
                                                }, rata.importo_formatted),
                                                h('span', {
                                                    class: 'text-[9px] font-semibold uppercase tracking-wide ' + 
                                                        (isCredito ? 'text-blue-400' : 'text-emerald-400')
                                                }, isCredito ? 'Credito' : 'Contanti')
                                            ])
                                        ])
                                    })
                                ])
                            ])
                        })
                    ]
                 }) 
                 : null
            ])
        ])
    },
  },
  {
    id: 'risorsa',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Risorsa' }),
    size: 130,
    cell: ({ row }) => {
        const cassaNome = row.original.cassa_nome || 'N/D';
        const cassaTipo = row.original.cassa_tipo_label || 'Risorsa';

        return h('div', { class: 'flex flex-col items-start gap-0.5' }, [
            h(Badge, { variant: 'outline', class: 'text-xs text-gray-600 rounded-md bg-white border-gray-200 truncate max-w-full' }, () => cassaNome),
            h('span', { class: 'text-[10px] text-muted-foreground ml-0.5 truncate' }, cassaTipo)
        ])
    }
  },
  {
    accessorKey: 'importo_totale_raw', 
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo' }),
    size: 110,
    cell: ({ row }) => {
        const formattedLabel = row.original.importo_totale_formatted;
        return h('div', { class: 'font-bold text-emerald-600 text-md whitespace-nowrap' }, [
            '+ ',
            formattedLabel 
        ]);
    },
  },
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    size: 110,
    cell: ({ row }) => {
      const stato = row.getValue('stato') as string
      return h(Badge, { 
        variant: stato === 'annullata' ? 'destructive' : 'default',
        class: stato === 'registrata' 
            ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border-emerald-200 shadow-none rounded-md' 
            : 'shadow-none rounded-md'
      }, () => stato === 'registrata' ? 'Confermato' : 'Annullato')
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    size: 50,
    cell: ({ row }) => h(DropdownAction, { 
        incasso: row.original,
        condominioId: condominioId 
    }),
  },
]