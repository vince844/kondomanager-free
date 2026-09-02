import { h } from 'vue'
import DataTableColumnHeader from '@/components/gestionale/movimenti/fatture/DataTableColumnHeader.vue' 
import DropdownAction from './DataTableRowActions.vue'
import { Archive, AlertTriangle, CheckCircle, Clock, FileMinus, RotateCcw, Files } from 'lucide-vue-next'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import type { FatturaPassiva } from '@/types/gestionale/fatture'
import type { ColumnDef } from '@tanstack/vue-table'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'

const { euro } = useCurrencyFormatter(); 

export const createColumns = (condominioId: number): ColumnDef<FatturaPassiva>[] => [
  {
    accessorKey: 'fornitore',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Fornitore & Documento' }),
    size: 280,
    cell: ({ row }) => {
        const fattura = row.original;
        const fornitoreNome = fattura.fornitore?.ragione_sociale || 'N/D';
        
        const badgeRow = [];

        // Badge Nota di Credito
        if (fattura.tipo_documento === 'nota_credito') {
            badgeRow.push(
                h('span', { 
                    class: 'inline-flex items-center gap-1 bg-rose-50 text-rose-600 border border-rose-200 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded' 
                }, [h(FileMinus, { class: 'w-3 h-3' }), ' Nota Credito'])
            );
        }

        // Badge Pregresso
        if (fattura.is_pregresso) {
            badgeRow.push(
                h('span', { 
                    class: 'inline-flex items-center gap-1 bg-amber-50 text-amber-600 border border-amber-200 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded' 
                }, [h(Archive, { class: 'w-3 h-3' }), ' Pregresso'])
            );
        }

        // Array degli elementi della seconda riga (Numero, Ritenuta, Download)
        const documentMetaElements = [];
        
        // 1. Numero Documento
        documentMetaElements.push(h('span', `n. ${fattura.numero_documento || 'S/N'}`));

        // 2. Badge Ritenuta (se presente)
        // Era un punto ciano di 6 px con la spiegazione solo nel `title`: si
        // notava a fatica e non si capiva senza andarci sopra col mouse — cioè
        // mai, su un elenco che si scorre. Stesso badge dei Pagamenti fornitori,
        // così la stessa informazione ha lo stesso aspetto nelle due pagine.
        if (fattura.importo_ritenuta && fattura.importo_ritenuta > 0) {
            documentMetaElements.push(
                h('span', {
                    class: 'inline-flex items-center gap-1 bg-cyan-50 text-cyan-600 border border-cyan-200 '
                         + 'text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded shrink-0 ml-1.5',
                    title: 'Soggetto a ritenuta d\'acconto',
                }, ['Ritenuta'])
            );
        }

        // 3. Indicatore allegati (se c'è almeno un documento)
        // ⚠️ Fino alla beta.11 era un link che scaricava direttamente, ed era
        // «il documento» perché non poteva essercene più di uno. Dalla beta.12
        // (Coda 102) gli allegati si accumulano: un link solo non sa più dire
        // cosa farebbe click (scaricare quale?), quindi è tornato a essere solo
        // un indicatore — vedere e scaricare si fa dal menu "..." o dal Dettaglio.
        //
        // ⚠️ Il numero si SCRIVE, non si mette nel `title`. La prima stesura lo
        // affidava a un `title`: si vedeva solo col mouse sopra e su un touch non
        // esisteva — la stessa cosa che questa beta ha corretto sul cestino del
        // Dettaglio, violata qui venti righe dopo averla scritta.
        //
        // ⚠️ E l'icona non è `FileText`: in questo stesso modulo significa già
        // «fattura» (FatturaRegisterNew.vue:719, accanto alla parola «Fattura») e
        // su FatturaShow.vue:497 significa «NESSUN documento allegato», cioè
        // l'opposto di quello che direbbe qui.
        if (fattura.documenti && fattura.documenti.length > 0) {
            const n = fattura.documenti.length;
            documentMetaElements.push(
                h('span', {
                    class: 'inline-flex items-center gap-1 text-slate-400 ml-2 shrink-0',
                }, [
                    h(Files, { class: 'w-3.5 h-3.5' }),
                    h('span', { class: 'text-[11px] tabular-nums' }, String(n)),
                    h('span', { class: 'sr-only' }, n === 1 ? 'documento allegato' : 'documenti allegati'),
                ])
            );
        }

        return h('div', { class: 'flex flex-col gap-1 overflow-hidden' }, [
            // Riga 1: Ragione sociale
            h('span', { class: 'font-bold text-sm text-slate-900 truncate' }, fornitoreNome),

            // Riga 2: Numero e Metadati (raggruppati correttamente in un div flex)
            h('div', { class: 'text-xs text-slate-400 flex items-center' }, documentMetaElements),

            // Riga 3: Badge (solo se presenti)
            badgeRow.length > 0
                ? h('div', { class: 'flex items-center gap-1 flex-wrap mt-0.5' }, badgeRow)
                : null
        ]);
    },
    enableSorting: false,
  },
  {
    accessorKey: 'data_documento',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Date' }),
    size: 130,
    cell: ({ row }) => {
        const fattura = row.original;
        
        // Protezione se mancano le date
        const dataDoc = fattura.data_documento 
            ? new Date(fattura.data_documento).toLocaleDateString('it-IT') 
            : '-';
        
        const dataScad = fattura.data_scadenza 
            ? new Date(fattura.data_scadenza).toLocaleDateString('it-IT') 
            : '-';
            
        const isScaduta = fattura.data_scadenza 
            ? new Date(fattura.data_scadenza) < new Date() && fattura.stato_pagamento !== 'pagata'
            : false;

        return h('div', { class: 'flex flex-col gap-0.5' }, [
            h('span', { class: 'text-xs text-slate-600 font-medium whitespace-nowrap' }, `Doc: ${dataDoc}`),
            h('span', { class: `text-xs whitespace-nowrap ${isScaduta ? 'text-red-600 font-bold' : 'text-slate-400'}` }, `Scad: ${dataScad}`)
        ]);
    },
  },
  {
    accessorKey: 'stato_approvazione',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Approvazione' }),
    size: 150,
    cell: ({ row }) => {
        const stato = row.getValue('stato_approvazione') as string;

        const config: Record<string, { label: string; class: string; icon: any }> = {
            approvata: { 
                label: 'Approvata', 
                class: 'bg-emerald-50 text-emerald-700 border border-emerald-200', 
                icon: CheckCircle 
            },
            da_approvare: { 
                label: 'Da approvare', 
                class: 'bg-slate-100 text-slate-500 border border-slate-200', 
                icon: Clock 
            },
            contestata: { 
                label: 'Contestata', 
                class: 'bg-red-50 text-red-700 border border-red-200', 
                icon: AlertTriangle 
            },
            sforo_motivato: { 
                label: 'Sforo motivato', 
                class: 'bg-indigo-50 text-indigo-700 border border-indigo-200', 
                icon: AlertTriangle 
            },
        };

        const { label, class: cssClass, icon } = config[stato] ?? config['da_approvare'];

        return h('span', { 
            class: `inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider whitespace-nowrap ${cssClass}` 
        }, [
            h(icon, { class: 'w-3 h-3 shrink-0' }), 
            label
        ]);
    }
  },
  {
    accessorKey: 'stato_pagamento',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Pagamento' }),
    size: 120,
    cell: ({ row }) => {
        const fattura = row.original;
        const stato = fattura.stato_pagamento as string;
        const isStornata = fattura.dati_extra?.is_stornata === true;

        const config: Record<string, { label: string; class: string; icon: any }> = {
            aperta:   { 
                label: 'Da pagare', 
                class: 'bg-amber-50 text-amber-700 border border-amber-200', 
                icon: Clock 
            },
            pagata:   { 
                label: 'Pagata', 
                class: 'bg-emerald-50 text-emerald-700 border border-emerald-200', 
                icon: CheckCircle 
            },
            parziale: { 
                label: 'Parziale', 
                class: 'bg-blue-50 text-blue-700 border border-blue-200', 
                icon: AlertTriangle 
            },
            stornata: { 
                label: 'Stornata', 
                class: 'bg-slate-100 text-slate-500 border border-slate-200 decoration-slate-400 line-through', 
                icon: RotateCcw 
            },
        };

        // Priorità visiva allo storno
        const stateKey = isStornata ? 'stornata' : stato;
        const { label, class: cssClass, icon } = config[stateKey] ?? config['aperta'];

        return h('span', { 
            class: `inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider whitespace-nowrap ${cssClass}`,
            title: isStornata ? 'Documento annullato tramite Nota di Credito' : '' 
        }, [
            h(icon, { class: 'w-3 h-3 shrink-0' }), 
            label
        ]);
    }
  },
  {
    accessorKey: 'totale_documento', // Cambiamo in totale_documento come dato principale
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importi' }),
    size: 130,
    cell: ({ row }) => {
        const fattura = row.original;
        
        const imponibile = fattura.importo_imponibile;
        const iva = fattura.importo_iva;
        const totaleDoc = fattura.totale_documento;
        const ritenuta = fattura.importo_ritenuta || 0;
        const netto = fattura.netto_a_pagare;

        const isNota = totaleDoc < 0;
        const isStornata = fattura.dati_extra?.is_stornata === true;

        // Righe per il tooltip strutturato
        const rows: Array<{ label: string; value: string; bold?: boolean; textClass?: string; bgClass?: string }> = [
            { label: 'Imponibile', value: euro(imponibile) },
            { label: 'IVA', value: euro(iva) },
            { label: 'Totale Lordo', value: euro(totaleDoc), bold: true },
        ];
        if (ritenuta > 0) {
            rows.push({ label: 'Ritenuta Acconto', value: `-${euro(ritenuta)}`, textClass: 'text-rose-600' });
        }
        rows.push({ label: 'Da bonificare', value: euro(netto), bold: true, bgClass: 'bg-emerald-50 border border-emerald-100', textClass: 'text-emerald-700' });

        return h(HoverCard, null, {
            default: () => [
                h(HoverCardTrigger, { asChild: false }, {
                    default: () => h('div', { 
                        class: 'flex flex-col group cursor-help'
                    }, [
                        // 1. IL LORDO
                        h('span', { 
                            class: `font-black text-sm whitespace-nowrap transition-colors ${
                                isStornata 
                                    ? 'text-slate-400 line-through' 
                                    : (isNota ? 'text-emerald-600' : 'text-slate-900 group-hover:text-indigo-600')
                            }` 
                        }, euro(totaleDoc)),
                        
                        // 2. IL NETTO / BONIFICO
                        ritenuta > 0 && !isStornata
                            ? h('span', { class: 'text-[10px] text-amber-600 font-bold mt-0.5 bg-amber-50 px-1.5 py-0.5 rounded border border-amber-100' }, `Bonifico: ${euro(netto)}`)
                            : null,
                        
                        // Etichetta "Accredito"
                        isNota && !isStornata
                            ? h('span', { class: 'text-[9px] text-emerald-500 font-bold uppercase tracking-wide mt-0.5' }, 'Accredito')
                            : null,
                        
                        // Etichetta "Annullato"
                        isStornata
                            ? h('span', { class: 'text-[9px] text-slate-400 font-bold uppercase tracking-wide mt-0.5' }, 'Annullato')
                            : null
                    ])
                }),
                h(HoverCardContent, { class: 'w-56 p-0 shadow-xl border-gray-200 z-50', side: 'top', align: 'end', sideOffset: 5 }, {
                    default: () => h('div', { class: 'flex flex-col' }, [
                        h('div', { class: 'px-3 py-2 bg-gray-50 border-b border-gray-100 rounded-t-md' }, [
                            h('span', { class: 'text-[10px] font-bold text-gray-500 uppercase tracking-wider' }, 'Dettaglio Importi')
                        ]),
                        h('div', { class: 'p-2 space-y-1' }, rows.map(r => 
                            h('div', { class: `flex items-center justify-between text-xs px-2 py-1.5 rounded-md ${r.bgClass || 'bg-white'}` }, [
                                h('span', { class: `font-medium text-slate-600` }, r.label),
                                h('span', { class: `${r.bold ? 'font-bold' : ''} ${r.textClass || 'text-slate-900'}` }, r.value)
                            ])
                        ))
                    ])
                })
            ]
        });
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    size: 50,
    cell: ({ row }) => h(DropdownAction, { 
        fattura: row.original,
        condominioId: condominioId 
    }),
  },
]