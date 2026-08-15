// components/gestionale/immobili/columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3'
import { usePermission } from "@/composables/permissions"
import DropdownAction from '@/components/gestionale/immobili/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/immobili/DataTableColumnHeader.vue'
import AnagraficheStack from '@/components/AnagraficheStack.vue'
import { Home, ArrowRight, MapPin, FileSearch, Hash } from 'lucide-vue-next'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Immobile>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Unità Immobiliare' }),
      cell: ({ row }) => {
        const immobile = row.original
        
        return h(Link, {
          href: route(generateRoute('gestionale.immobili.show'), { condominio: condominio.id, immobile: immobile.id }),
          class: 'group flex items-center gap-3 py-1 outline-none'
        }, () => [
          h('div', { 
              // Sostituito slate-100 con indigo-50
              class: 'p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 dark:text-indigo-400 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/60 transition-colors shrink-0' 
          }, [
              h(Home, { class: 'w-4 h-4' })
          ]),
          
          h('div', { class: 'flex flex-col min-w-0' }, [
              h('div', { class: 'flex items-center gap-2 mb-0.5' }, [
                  h('span', { 
                      class: 'px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-tighter bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-md border border-indigo-100 dark:border-indigo-800' 
                  }, immobile.tipologia?.nome || 'U.I.'),

                  h('span', {
                      // Sostituito group-hover:text-primary con group-hover:text-indigo-600
                      class: 'font-bold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
                  }, immobile.nome),
              ]),
              h('span', { 
                  // Sostituito group-hover:text-slate-500 con group-hover:text-indigo-500
                  class: 'text-[10px] text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors' 
              }, [
                  `Visualizza Interno ${immobile.interno || '-'}`,
                  // Sostituito text-primary/60 con text-indigo-500/60
                  h(ArrowRight, { class: 'w-3 h-3 animate-pulse text-indigo-500/60' })
              ])
          ])
        ]);
      }
    },
    {
      accessorKey: 'struttura',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ubicazione' }),
      cell: ({ row }) => {
        const immobile = row.original
        return h('div', { class: 'flex flex-col text-xs space-y-1' }, [
          h('div', { class: 'flex items-center gap-1.5 text-slate-700 dark:text-slate-300 font-medium' }, [
            h(MapPin, { class: 'w-3 h-3 text-slate-400' }),
            h('span', `Pal. ${immobile.palazzina?.name ?? '-'} | Sc. ${immobile.scala?.name ?? '-'}`)
          ]),
          h('span', { class: 'text-[10px] text-slate-400 uppercase tracking-tight' }, `Piano: ${immobile.piano ?? '-'}`)
        ])
      }
    },
    {
      accessorKey: 'catasto',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dati catastali' }),
      cell: ({ row }) => {
        const immobile = row.original
        if (!immobile.foglio_catasto && !immobile.particella_catasto) {
          return h('span', { class: 'text-xs text-slate-300 italic' }, 'Dati non inseriti')
        }

        return h('div', { class: 'flex flex-col text-[11px] space-y-1' }, [
          h('div', { class: 'flex items-center gap-1.5 text-slate-600 dark:text-slate-400' }, [
            h(FileSearch, { class: 'w-3 h-3 text-slate-400' }),
            h('span', { class: 'font-mono' }, `Foglio: ${immobile.foglio_catasto ?? '-'} | Particella: ${immobile.particella_catasto ?? '-'}`)
          ]),
          h('div', { class: 'flex items-center gap-1.5 text-slate-600 dark:text-slate-400' }, [
            h(Hash, { class: 'w-3 h-3 text-slate-400' }),
            h('span', { class: 'font-mono' }, `Subalterno: ${immobile.subalterno_catasto ?? '-'}`)
          ])
        ])
      }
    },
    {
      accessorKey: 'dati_tecnici',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dati tecnici' }),
      cell: ({ row }) => {
        const immobile = row.original
        return h('div', { class: 'flex flex-col text-xs' }, [
          h('span', { class: 'font-semibold text-slate-700 dark:text-slate-300' }, immobile.superficie ? `${immobile.superficie} m²` : '-'),
          h('span', { class: 'text-[10px] text-slate-400' }, `${immobile.numero_vani ?? '-'} vani`)
        ])
      }
    },
    {
      /**
       * I soggetti collegati all'unità.
       *
       * ⚠️ **Mancava, ed era la lacuna più grossa di questa tabella:** si vedevano palazzina,
       * dati catastali e metri quadri, e **non chi ci abita o chi la possiede** — cioè il dato
       * per cui un amministratore apre l'elenco delle unità.
       *
       * Riusa `AnagraficheStack`, lo stesso componente dell'elenco condomini: bolle con le
       * iniziali, «+N» oltre la terza, e un pannello con l'elenco completo al clic. Stessa
       * grafica e stessa logica di là, di proposito — un secondo modo di mostrare le stesse
       * persone sarebbe un dialetto in più da imparare.
       *
       * Le righe portano `url` verso la scheda anagrafiche dell'unità, così il pannello non è
       * solo una vetrina: da lì si arriva dove si modifica.
       */
      accessorKey: 'anagrafiche',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Soggetti' }),
      cell: ({ row }) => {
        const immobile = row.original
        /**
         * ⚠️ **Chi si mostra, e perché il criterio non è «chi è ancora titolare».** Reperto della
         * revisione avversariale della beta.52: il pannello elencava Rossi e Bianchi identici —
         * «PROPRIETARIO 100 %» entrambi — su un'unità dove Rossi ha `data_fine` al 31/12/2025.
         * Alla domanda per cui la colonna esiste, «chi possiede oggi», rispondeva sbagliato.
         *
         * Le due condizioni si trattano in modo **opposto**, e non è una svista:
         *
         * - `attivo = false` → **si nasconde**. Il motore di riparto filtra su `pivot.attivo` e
         *   quella riga non partecipa a niente: mostrarla con il badge pieno è un'affermazione
         *   falsa. Sono righe che nessuna interfaccia può riaccendere, vedi
         *   `VerificaTitolaritaCommand`.
         * - `data_fine` passata ma `attivo = true` → **si mostra, marcata**. Il motore **non legge
         *   le date** (blocco B2, 1.11): quel soggetto continua a essere addebitato. Nasconderlo
         *   perché «è cessato» renderebbe invisibile proprio la situazione che costa denaro, ed è
         *   il terzo segnale che `kondomanager:verifica-titolarita` va a cercare.
         *
         * La regola in una riga: **il pannello mostra ciò che il motore addebita**, non ciò che
         * l'anagrafe dichiara. Il giorno in cui il motore leggerà le date, questo filtro cambierà
         * con lui — e il commento è qui perché quel giorno si sappia perché.
         */
        const oggi = new Date().toISOString().slice(0, 10)

        const anagrafiche = (immobile.anagrafiche ?? [])
          .filter((a) => a.pivot?.attivo !== false)
          .map((a) => {
            const fine = a.pivot?.data_fine ? String(a.pivot.data_fine).slice(0, 10) : null

            return {
              id: a.id,
              nome: a.nome,
              indirizzo: a.indirizzo,
              // Ruolo e quota rendono il pannello una risposta e non un elenco di nomi: da un
              // elenco di unità la domanda è «chi è, a che titolo, per quanto».
              ruolo: a.pivot?.tipologia,
              quota: a.pivot?.quota,
              nota: fine && fine < oggi ? `fino al ${fine.split('-').reverse().join('/')}` : null,
              url: route(generateRoute('gestionale.immobili.anagrafiche.index'), {
                condominio: condominio.id,
                immobile: immobile.id,
              }),
            }
          })

        return h(AnagraficheStack, {
          anagrafiche,
          title: 'Soggetti collegati',
          description: `Proprietari, inquilini e usufruttuari di ${immobile.nome}`,
        })
      },
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const immobile = row.original
        return h('div', { class: 'flex justify-end pr-2' },
          h(DropdownAction, { immobile, condominio })
        )
      },
    },
  ]
}