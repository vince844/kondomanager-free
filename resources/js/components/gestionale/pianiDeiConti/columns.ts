// columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import DropdownAction from '@/components/gestionale/pianiDeiConti/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/pianiDeiConti/DataTableColumnHeader.vue'
import { usePermission } from "@/composables/permissions";
import { useCurrencyFormatter } from "@/composables/useCurrencyFormatter"; 
import { FolderTree, ArrowRight, Layers, CalendarClock } from 'lucide-vue-next'; 
import type { ColumnDef } from '@tanstack/vue-table'
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi';

const { generateRoute } = usePermission();
const { euro } = useCurrencyFormatter({ fromCents: true }); 

export const createColumns = (condominio: Building, esercizio: Esercizio): ColumnDef<PianoDeiConti>[] => [
  {
    accessorKey: 'nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Piano dei conti' }),
    cell: ({ row }) => {
      const pianoConto = row.original;
      const desc = pianoConto.descrizione;

      return h(Link, {
        prefetch: true,
        href: route(generateRoute('gestionale.esercizi.piani-conti.show'), { 
          condominio: condominio.id, 
          esercizio: esercizio.id,  
          pianoConto: pianoConto.id 
        }),
        class: 'group flex items-start gap-3 py-1 outline-none'
      }, () => [
        // Icona laterale
        h('div', { 
            class: 'p-2 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-indigo-500 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/50 transition-colors shrink-0 mt-0.5' 
        }, [
            h(FolderTree, { class: 'w-4 h-4' })
        ]),
        
        // Contenitore Testi
        h('div', { class: 'flex flex-col min-w-0' }, [
            
            // Riga 1: Titolo
            h('span', {
                class: 'font-bold text-sm text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
            }, pianoConto.nome),

            // Riga 2: Descrizione 
            desc ? h('span', {
              class: 'text-xs text-slate-500 dark:text-slate-400 truncate max-w-sm mt-0.5'
            }, desc) : null,

            // Riga 3: Action "Visualizza" (Statica, senza animazione)
            h('span', { 
                class: 'text-[10px] font-semibold text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors mt-2' 
            }, [
                'Visualizza struttura',
                h(ArrowRight, { class: 'w-3 h-3 text-indigo-400/60' })
            ])
        ])
      ]);
    } 
  },
  {
    accessorKey: 'gestione',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Gestione' }),
    cell: ({ row }) => {
      const nomeGestione = row.original.gestione?.nome;
      
      return h('div', { class: 'flex flex-col items-start gap-1.5' }, [
        nomeGestione
          ? h('span', { class: 'inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-widest bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700' }, nomeGestione)
          : h('span', { class: 'text-xs text-slate-400 italic' }, 'Gestione N/D')
      ]);
    },
  },
  {
    id: 'totale',
      /**
       * ⚠️ **Non ordinabile.** «Budget & Composizione» è un totale aggregato dai conti figli: stessa scelta dell'importo dei piani rate.
       *
       * Il server accetta solo le chiavi dichiarate nella richiesta: lasciarla cliccabile
       * manderebbe l'amministratore in un errore di validazione al primo clic.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Budget & Composizione' }),
    cell: ({ row }) => {
      const totale = row.original.importo_totale ?? 0;
      const capitoliCount = row.original.capitoli_count ?? 0;
      
      return h('div', { class: 'flex flex-col gap-1' }, [
        h('span', { class: 'text-sm font-bold text-slate-700 dark:text-slate-300' }, euro(totale)),
        h('span', { class: 'text-[10px] text-slate-500 flex items-center gap-1 uppercase tracking-tight' }, [
            h(Layers, { class: 'w-3 h-3' }),
            `${capitoliCount} voci di spesa`
        ]),
      ]);
    },
  },
  {
    // --- NUOVA COLONNA: Data di creazione ---
    accessorKey: 'data_creazione',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data creazione' }),
    cell: ({ row }) => {
        return h('div', { class: 'flex items-center gap-2 text-slate-500 dark:text-slate-400' }, [
            h(CalendarClock, { class: 'w-3.5 h-3.5' }),
            h('span', { class: 'text-xs whitespace-nowrap' }, row.original.data_creazione)
        ]);
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const pianoDeiConti = row.original as PianoDeiConti;
      return h('div', { class: 'flex justify-end pr-2' }, 
        h(DropdownAction, { pianoDeiConti, condominio, esercizio })
      );
    },
   }
]