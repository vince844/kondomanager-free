import { h } from 'vue'
import { AlertTriangle, Calendar } from 'lucide-vue-next'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
// ⚠️ **Importati, non ricopiati.** Un badge «pagamento fornitore» deve avere lo stesso colore qui
// e nel Libro Giornale: sono due letture dello stesso giornale, e due palette che divergono nel
// tempo sarebbero un malinteso in più — la stessa classe di problema da cui è nato tutto il
// dossier dei registri. Le costanti vivono là perché là sono nate; se un terzo consumatore
// comparisse, è quello il momento di estrarle in un modulo condiviso.
import { badgeBase, CATEGORIA_PER_TIPO, COLORI_CATEGORIA, COLORI_STATO, STATO_LABELS } from '../scritture/columns'
import type { ColumnDef } from '@tanstack/vue-table'

/** Riga del registro di contabilità (art. 1130, comma 1, n. 7 c.c.) — RegistroContabilitaService::registro(). */
export interface RegistroRow {
  id: number
  scrittura_id: number
  /** Numero d'operazione, calcolato in lettura sull'esercizio intero (D5) — non cambia coi filtri. */
  numero: number
  /** Data effettiva del movimento (data_competenza) — è quella su cui il registro ordina. */
  data: string
  /** Data di annotazione (data_registrazione) — a fianco, per la regola dei trenta giorni (D6). */
  data_annotazione: string
  oltre_trenta_giorni: boolean
  protocollo: string
  descrizione: string
  controparte: string | null
  stato: string
  /** Vero se esiste uno storno di questa operazione, qualunque ne sia il tipo. */
  stornata: boolean
  /** La cassa reale che questa riga ha effettivamente mosso — banca o contanti, mai un fondo. */
  cassa: string
  tipo_movimento: string
  tipo_movimento_label: string
  nota: string | null
  /** In centesimi. */
  entrata: number | null
  /** In centesimi. */
  uscita: number | null
  /** In centesimi, cumulativo su TUTTE le casse reali dall'inizio dell'esercizio filtrato. */
  saldo_progressivo: number
  /** In centesimi, cumulativo sulla sola cassa di questa riga. */
  saldo_cassa_progressivo: number
}

function formatData(iso: string): string {
  const [anno, mese, giorno] = iso.split('-')
  return `${giorno}/${mese}/${anno}`
}

/**
 * ⚠️ **Nessuna colonna «azioni», ed è una scelta condivisa col Libro Giornale.** Il rimando al
 * dettaglio della scrittura vive nel pannello che si apre sulla riga, in tutte e due le pagine:
 * un'icona in coda che duplica quel link costa una colonna e un'incoerenza fra due elenchi
 * gemelli. Deciso da Vincenzo il 09/09/2026 chiudendo la beta.24.
 */
export function createColumns(): ColumnDef<RegistroRow>[] {
  const { euro } = useCurrencyFormatter()

  return [
    {
      accessorKey: 'numero',
      header: () => h('div', { class: 'text-right w-full' }, 'N.'),
      // ⚠️ **Dimensionata su cinque cifre, non su quelle dei dati di prova.** Con 42px e
      // l'imbottitura della cella restavano 13px di testo: «1» ci stava, «5008» no — e un
      // esercizio con cinquemila movimenti non è un caso di scuola, è un condominio grande.
      // Misurato due volte: generando il registro con 5.000 movimenti veri, e poi iniettando
      // «99999» nella cella per vedere quando smette di starci. La cella spende 32px in
      // imbottitura, quindi la larghezza utile è quella meno 32.
      size: 76,
      // Il «numero operazione» è il primo dei dati minimi che la fonte citata al §2 di
      // docs/registri_contabili.md elenca per questo registro, prima ancora della data.
      cell: ({ row }) => h('div', { class: 'text-right tabular-nums text-slate-400' }, row.original.numero),
    },
    {
      accessorKey: 'data',
      header: 'Data',
      size: 128,
      cell: ({ row }) => {
        const r = row.original
        return h('div', { class: 'flex flex-col gap-0.5' }, [
          h('span', { class: 'font-bold text-slate-800 tabular-nums flex items-center gap-1.5' }, [
            h(Calendar, { class: 'w-3 h-3 text-slate-400' }),
            formatData(r.data),
          ]),
          h('span', {
            class: [
              'text-[10px] tabular-nums flex items-center gap-1',
              r.oltre_trenta_giorni ? 'text-amber-600 font-semibold' : 'text-slate-400',
            ],
            title: r.oltre_trenta_giorni
              ? 'Annotato oltre i trenta giorni previsti dall\'art. 1130, comma 1, n. 7 c.c.'
              : 'Data di annotazione',
          }, [
            r.oltre_trenta_giorni ? h(AlertTriangle, { class: 'w-3 h-3' }) : null,
            `ann. ${formatData(r.data_annotazione)}`,
          ]),
        ])
      },
    },
    {
      accessorKey: 'descrizione',
      header: 'Descrizione',
      // ⚠️ Le dimensioni sono esplicite su OGNI colonna: TanStack assegna 150px di default a
      // chi non la dichiara, e con `table-fixed` quel default diventa un taglio vero — il badge
      // «storno pagamento fornitore» usciva tagliato a metà parola. Visto a video, non nel codice.
      size: 210,
      cell: ({ row }) => {
        const r = row.original
        const categoria = CATEGORIA_PER_TIPO[r.tipo_movimento] ?? 'tecnico'
        return h('div', { class: 'flex flex-col gap-1 min-w-0' }, [
          // ⚠️ **Una riga sola, con il testo intero nel tooltip.** Una causale lunga mandava la
          // riga a tre linee e rendeva l'elenco irregolare da scorrere — e questo registro esiste
          // per essere consultato a colpo d'occhio. Il testo per intero non si perde: sta nel
          // `title`, e comunque per intero è nella stampa, dove lo spazio c'è.
          h('span', { class: 'block truncate text-slate-800', title: r.descrizione }, r.descrizione),
          h('div', { class: 'flex items-center gap-1.5 flex-wrap' }, [
            h('span', { class: `${badgeBase} ${COLORI_CATEGORIA[categoria]}` }, r.tipo_movimento_label),
            // Lo stato in riga solo quando è una notizia: una scrittura «registrata» è la norma e
            // non merita un badge su ogni riga, una stornata sì — sta nel registro per D7, e chi
            // legge deve vederlo senza aprire nulla.
            // `stornata` e non `stato === 'annullata'`: solo lo storno di un incasso marca
            // l'originale; per gli altri il segnale è la figlia di tipo storno, e lo calcola
            // il servizio una volta per tutti e tre i consumatori.
            r.stornata
              ? h('span', { class: `${badgeBase} ${COLORI_STATO.annullata}` }, STATO_LABELS.annullata)
              : null,
          ]),
          // Il protocollo sotto i badge e non in mezzo a loro: è un riferimento da leggere, non
          // un'etichetta da scorrere.
          h('span', { class: 'text-[10px] text-slate-400 tabular-nums' }, r.protocollo),
        ])
      },
    },
    {
      accessorKey: 'cassa',
      header: 'Cassa',
      size: 156,
      // ⚠️ **Non riusa `badgeBase`**, che porta `uppercase`: il nome di una cassa è un nome
      // proprio, e «CASSA CONTO CORRENTE» urlato è la maiuscola fuori posto che questo progetto
      // corregge da sempre. Aggiungere `normal-case` non basta — nel CSS generato da Tailwind
      // `uppercase` vince comunque per ordine, verificato leggendo lo stile calcolato a video.
      // ⚠️ **Niente riquadro attorno al nome della cassa, ed è una misura.** Come chip bordato
      // la cornice — bordo, sfondo, imbottitura, icona — costava 36px dei 133 utili della cella,
      // e il nome «Cassa conto corrente», che ne chiede 107, usciva troncato o sbordava sotto la
      // colonna accanto facendole sembrare attaccate. Un nome di conto è un nome, non uno stato:
      // non ha bisogno di un riquadro. Il `title` resta per i nomi davvero lunghi.
      // Anche l'icona è andata, per lo stesso motivo: fra icona e spaziatura se ne andavano 18
      // dei 133px utili, e «Cassa conto corrente» restava troncato. L'intestazione della colonna
      // dice già che è una cassa; l'icona non aggiungeva informazione, toglieva lettere.
      cell: ({ row }) => h('div', {
        class: 'truncate text-[12px] text-slate-600',
        title: row.original.cassa,
      }, row.original.cassa),
    },
    {
      accessorKey: 'controparte',
      header: 'Controparte',
      size: 166,
      cell: ({ row }) => row.original.controparte
        ? h('div', { class: 'truncate text-slate-700', title: row.original.controparte }, row.original.controparte)
        : h('span', { class: 'text-slate-300 italic' }, '—'),
    },
    {
      accessorKey: 'entrata',
      header: () => h('div', { class: 'text-right w-full' }, 'Entrata'),
      size: 105,
      cell: ({ row }) => h('div', { class: 'text-right tabular-nums font-bold text-emerald-700' },
        row.original.entrata !== null ? euro(row.original.entrata) : ''),
    },
    {
      accessorKey: 'uscita',
      header: () => h('div', { class: 'text-right w-full' }, 'Uscita'),
      size: 105,
      cell: ({ row }) => h('div', { class: 'text-right tabular-nums font-bold text-rose-700' },
        row.original.uscita !== null ? euro(row.original.uscita) : ''),
    },
    {
      accessorKey: 'saldo_progressivo',
      header: () => h('div', { class: 'text-right w-full' }, 'Saldo'),
      size: 115,
      cell: ({ row }) => h('div', {
        class: [
          'text-right tabular-nums font-bold',
          row.original.saldo_progressivo < 0 ? 'text-rose-600' : 'text-slate-700',
        ],
        title: 'Saldo complessivo di tutte le casse reali. Il saldo della singola cassa è nel dettaglio della riga.',
      }, euro(row.original.saldo_progressivo)),
    },
  ]
}
