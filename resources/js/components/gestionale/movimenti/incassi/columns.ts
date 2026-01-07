import { h } from 'vue'
import DropdownAction from './DataTableRowActions.vue'
import DataTableColumnHeader from './DataTableColumnHeader.vue' 
import { Badge } from '@/components/ui/badge'
import { CalendarDays, Info } from 'lucide-vue-next'
import { useFormat } from '@/composables/useFormat'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Incasso } from '@/types/gestionale/movimenti'

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
  
  // COLONNA MODIFICATA PER GESTIRE PAGANTI MULTIPLI
  {
    accessorKey: 'pagante',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Soggetto' }),
    cell: ({ row }) => {
        const pagante = row.original.pagante || { principale: 'Sconosciuto', altri_count: 0, lista_completa: '', ruolo: '' };
        const anagraficaId = row.original.anagrafica_id_principale; 

        // Costruiamo la prima riga (Nome + Badge +X)
        const nameContent = [
            h('span', { class: 'font-medium truncate max-w-[180px] hover:underline cursor-pointer text-blue-600' }, pagante.principale)
        ];

        if (pagante.altri_count > 0) {
            nameContent.push(
                h('span', {
                    class: 'inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold text-blue-700 bg-blue-100 rounded-full cursor-help ml-2',
                    title: pagante.lista_completa 
                }, `+${pagante.altri_count}`)
            );
        }

        // Wrapper per il link (se esiste ID)
        const linkWrapper = anagraficaId 
            ? h('a', { href: `/admin/gestionale/${condominioId}/anagrafiche/${anagraficaId}/estratto-conto`, class: 'flex items-center' }, nameContent)
            : h('div', { class: 'flex items-center' }, nameContent);

        // 🔥 STRUTTURA A DUE RIGHE
        return h('div', { class: 'flex flex-col' }, [
            linkWrapper, // Riga 1: Nome
            // Riga 2: Ruolo (stile "Reg: ...")
            h('span', { class: 'text-[10px] text-muted-foreground' }, pagante.ruolo || 'Condòmino') 
        ]);
    },
  },
  {
    accessorKey: 'causale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
    cell: ({ row }) => {
        const gestione = row.original.gestione_nome || 'Generica'; 
        const dettagli = row.original.dettagli_rate || []; 
        
        const riassuntoRate = dettagli.length > 0 
            ? `${dettagli.length} rate saldate` 
            : 'Acconto / Generico';

        return h('div', { class: 'flex flex-col space-y-1' }, [
            // Causale
            h('span', { class: 'truncate max-w-[250px] font-medium text-sm' }, row.getValue('causale')),
            
            // Riga Badge
            h('div', { class: 'flex items-center gap-2 flex-wrap' }, [
                 
                 // Badge Gestione
                 h(Badge, { variant: 'secondary', class: 'text-[10px] h-5 px-1 font-semibold rounded-md text-muted-foreground bg-gray-100' }, () => [
                    h(CalendarDays, { class: 'w-3 h-3 mr-1 font-semibold' }),
                    gestione
                 ]),
                 
                 // LOGICA HOVER CARD SHADCN
                 dettagli.length > 0 
                 ? h(HoverCard, null, {
                    default: () => [
                        // TRIGGER: Il Badge Blu cliccabile/hoverable
                        h(HoverCardTrigger, { asChild: false }, { 
                            default: () => h('div', { 
                                class: 'flex items-center cursor-help text-[10px] text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full hover:bg-blue-100 transition-colors border border-blue-100' 
                            }, [
                                h(Info, { class: 'w-3 h-3 mr-1' }),
                                riassuntoRate
                            ])
                        }),

                        // CONTENT: Il Box Bianco (Teleported nel body automaticamente)
                        h(HoverCardContent, { class: 'w-64 p-0 shadow-xl border-gray-200 z-50', side: 'top', align: 'start', sideOffset: 5 }, {
                            default: () => h('div', { class: 'flex flex-col' }, [
                                // Header del popup
                                h('div', { class: 'px-3 py-2 bg-gray-50 border-b border-gray-100 rounded-t-md' }, [
                                    h('span', { class: 'text-[10px] font-bold text-gray-500 uppercase tracking-wider' }, 'Dettaglio Copertura')
                                ]),
                                
                                // Body del popup (Lista Rate)
                                h('div', { class: 'p-2 space-y-1' }, [
                                    ...dettagli.map((rata: any) => 
                                        h('div', { class: 'flex justify-between items-center text-xs px-1 py-0.5 rounded hover:bg-gray-50' }, [
                                            h('span', { class: 'text-gray-700 font-medium' }, `Rata n. ${rata.numero}`),
                                            h('span', { class: 'font-mono text-emerald-600 font-bold' }, rata.importo_formatted) 
                                        ])
                                    )
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
    cell: ({ row }) => {
        const cassaNome = row.original.cassa_nome || 'N/D';
        const cassaTipo = row.original.cassa_tipo_label || 'Risorsa'; // Campo nuovo dal backend

        // 🔥 STRUTTURA A DUE RIGHE
        return h('div', { class: 'flex flex-col items-start gap-0.5' }, [
            // Riga 1: Badge col Nome
            h(Badge, { variant: 'outline', class: 'text-xs text-gray-600 rounded-md bg-white border-gray-200' }, () => [
                cassaNome
            ]),
            // Riga 2: Tipo Risorsa (stile "Reg: ...")
            h('span', { class: 'text-[10px] text-muted-foreground ml-0.5' }, cassaTipo)
        ])
    }
  },
  {
    // Usiamo 'importo_totale_raw' per il sorting corretto (numerico)
    accessorKey: 'importo_totale_raw', 
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo' }),
    cell: ({ row }) => {
        // 1. Recuperiamo il numero per il colore (opzionale, qui è sempre positivo, ma utile per coerenza)
        // const amount = row.getValue('importo_totale_raw') as number;

        // 2. Recuperiamo la stringa formattata dal backend
        // Nota: Dobbiamo accedere a row.original perché 'importo_totale_formatted' non è l'accessorKey
        const formattedLabel = row.original.importo_totale_formatted;

        return h('div', { class: 'font-bold text-emerald-600 text-md' }, [
            '+ ', // Aggiungiamo il "+" fisso per gli incassi
            formattedLabel 
        ]);
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