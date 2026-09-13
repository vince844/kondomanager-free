import { h } from 'vue'
import { Calendar } from 'lucide-vue-next'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
// Stessa palette del Libro Giornale e della Prima nota, importata e non ricopiata: sono tre
// letture dello stesso giornale, e un badge deve avere lo stesso colore in tutte e tre.
import { badgeBase, CATEGORIA_PER_TIPO, COLORI_CATEGORIA, COLORI_STATO, STATO_LABELS } from '../scritture/columns'
import type { ColumnDef } from '@tanstack/vue-table'

/** Una riga sorella della stessa scrittura: la contropartita vera, per il pannello. */
export interface RigaSorella {
  id: number
  codice: string
  conto: string
  /** In centesimi. */
  dare: number | null
  /** In centesimi. */
  avere: number | null
  cassa: string | null
}

/** Riga del mastrino di un conto (D21) — RegistroContabilitaService::mastrino(). */
export interface MastrinoRow {
  id: number
  scrittura_id: number
  /** Progressivo nel periodo, calcolato in lettura: non cambia coi filtri. */
  numero: number
  /** Data effettiva del movimento (data_competenza): è quella su cui il mastrino ordina. */
  data: string
  data_registrazione: string
  protocollo: string
  descrizione: string
  controparte: string | null
  stato: string
  stornata: boolean
  /** Il nome dell'esercizio della scrittura, solo quando NON è quello del periodo (D21.2). */
  altro_esercizio: string | null
  cassa: string
  tipo_movimento: string
  tipo_movimento_label: string
  nota: string | null
  /** In centesimi. */
  dare: number | null
  /** In centesimi. */
  avere: number | null
  /** In centesimi, nel verso naturale del conto, dal riporto in poi. */
  saldo_progressivo: number
  sorelle?: RigaSorella[]
}

function formatData(iso: string): string {
  const [anno, mese, giorno] = iso.split('-')
  return `${giorno}/${mese}/${anno}`
}

/**
 * Nessuna colonna «azioni», come nel Libro Giornale e nella Prima nota: il rimando alla
 * scrittura vive nel pannello che si apre sulla riga.
 */
export function createColumns(): ColumnDef<MastrinoRow>[] {
  const { euro } = useCurrencyFormatter()

  return [
    {
      accessorKey: 'numero',
      header: () => h('div', { class: 'text-right w-full' }, 'N.'),
      size: 76,
      cell: ({ row }) => h('div', { class: 'text-right tabular-nums text-slate-400' }, row.original.numero),
    },
    {
      accessorKey: 'data',
      header: 'Data',
      size: 118,
      cell: ({ row }) => h('span', { class: 'font-bold text-slate-800 tabular-nums flex items-center gap-1.5' }, [
        h(Calendar, { class: 'w-3 h-3 text-slate-400' }),
        formatData(row.original.data),
      ]),
    },
    {
      accessorKey: 'descrizione',
      header: 'Descrizione',
      size: 260,
      cell: ({ row }) => {
        const r = row.original
        const categoria = CATEGORIA_PER_TIPO[r.tipo_movimento] ?? 'tecnico'
        return h('div', { class: 'flex flex-col gap-1 min-w-0' }, [
          h('span', { class: 'block truncate text-slate-800', title: r.descrizione }, r.descrizione),
          h('div', { class: 'flex items-center gap-1.5 flex-wrap' }, [
            h('span', { class: `${badgeBase} ${COLORI_CATEGORIA[categoria]}` }, r.tipo_movimento_label),
            r.stornata
              ? h('span', { class: `${badgeBase} ${COLORI_STATO.annullata}` }, STATO_LABELS.annullata)
              : null,
            // La riga di un altro esercizio dentro questo periodo: è l'anomalia che il controllo
            // «liquidità e riepilogo» dello Stato patrimoniale segnala, vista dal conto.
            r.altro_esercizio
              ? h('span', {
                  class: `${badgeBase} bg-amber-50 text-amber-800 border border-amber-200`,
                  title: 'Questa scrittura appartiene a un altro esercizio, ma la sua data cade in questo periodo.',
                }, `esercizio ${r.altro_esercizio}`)
              : null,
          ]),
          h('span', { class: 'text-[10px] text-slate-400 tabular-nums' }, r.protocollo),
        ])
      },
    },
    {
      accessorKey: 'controparte',
      header: 'Controparte',
      size: 176,
      cell: ({ row }) => row.original.controparte
        ? h('div', { class: 'truncate text-slate-700', title: row.original.controparte }, row.original.controparte)
        : h('span', { class: 'text-slate-300 italic' }, '—'),
    },
    {
      accessorKey: 'dare',
      header: () => h('div', { class: 'text-right w-full' }, 'Dare'),
      size: 110,
      cell: ({ row }) => h('div', { class: 'text-right tabular-nums font-bold text-slate-700' },
        row.original.dare !== null ? euro(row.original.dare) : ''),
    },
    {
      accessorKey: 'avere',
      header: () => h('div', { class: 'text-right w-full' }, 'Avere'),
      size: 110,
      cell: ({ row }) => h('div', { class: 'text-right tabular-nums font-bold text-slate-700' },
        row.original.avere !== null ? euro(row.original.avere) : ''),
    },
    {
      accessorKey: 'saldo_progressivo',
      header: () => h('div', { class: 'text-right w-full' }, 'Saldo'),
      size: 120,
      cell: ({ row }) => h('div', {
        class: [
          'text-right tabular-nums font-bold',
          row.original.saldo_progressivo < 0 ? 'text-rose-600' : 'text-slate-700',
        ],
        title: 'Saldo progressivo nel verso naturale del conto, dal riporto in poi. Negativo = contro natura.',
      }, euro(row.original.saldo_progressivo)),
    },
  ]
}
