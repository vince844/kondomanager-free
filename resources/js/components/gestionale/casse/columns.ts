import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'

import DropdownAction from '@/components/gestionale/casse/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/casse/DataTableColumnHeader.vue'
import { Badge } from '@/components/ui/badge'

import { Star } from 'lucide-vue-next'

import type { Cassa } from '@/types/gestionale/casse'
import type { Building } from '@/types/buildings'

// Helper per formattare il tipo di conto
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

export function getColumns(condominio: Building): ColumnDef<Cassa>[] {
  return [
    {
      accessorKey: 'tipo',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Tipo' }),
      cell: ({ row }) => {
        const cassa = row.original
        const type = cassa.tipo
        const isPredefinito = Boolean(cassa.banca_predefinito)

        let label = 'Altro'
        if (type === 'banca') label = 'Conto corrente'
        if (type === 'contanti') label = 'Cassa contanti'
        if (type === 'fondo') label = 'Fondo riserva'

        return h(
          Badge,
          {
            variant: 'outline',
            class:
              'inline-flex items-center gap-1 font-medium px-1.5 py-0.5 rounded-md whitespace-nowrap',
          },
          () => [
            type === 'banca' && isPredefinito
              ? h(Star, {
                  class: 'w-3 h-3 fill-amber-400 text-amber-400 shrink-0', 
                  'aria-label': 'Conto Principale',
                })
              : null,
            label,
          ],
        )
      },
      size: 140,
    },
    {
      accessorKey: 'nome',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Dettagli Risorsa' }),
      cell: ({ row }) => {
        const cassa = row.original
        const isBanca = cassa.tipo === 'banca'

        return h('div', { class: 'flex flex-col space-y-1' }, [
          // Nome
          h(
            'span',
            {
              class:
                'font-semibold text-gray-900 dark:text-gray-100 text-sm md:text-base',
            },
            cassa.nome,
          ),

          // Tipologia conto / descrizione
          h(
            'span',
            { class: 'text-xs text-muted-foreground' },
            isBanca
              ? formatTipoConto(cassa.banca_tipo_conto)
              : cassa.descrizione || '-',
          ),

          // IBAN (solo banca)
          isBanca && cassa.banca_iban
            ? h('div', { class: 'flex items-center gap-1 mt-1' }, [
                h(
                  'span',
                  {
                    class:
                      'text-[10px] uppercase text-muted-foreground font-bold',
                  },
                  'IBAN:',
                ),
                h(
                  'span',
                  {
                    class:
                      'text-xs font-mono text-gray-600 dark:text-gray-400 tracking-wide',
                  },
                  cassa.banca_iban,
                ),
              ])
            : null,
        ])
      },
    },
    {
      // Usiamo 'saldo_raw' per l'ordinamento (sorting) corretto
      accessorKey: 'saldo_raw', 
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Saldo Attuale' }),
      cell: ({ row }) => {
        // 1. Recuperiamo il numero per decidere il COLORE
        const amount = row.getValue('saldo_raw') as number

        // 2. Recuperiamo la stringa già pronta per la VISUALIZZAZIONE
        // (Nota: row.original ci dà accesso a tutto l'oggetto CassaResource)
        const formattedLabel = row.original.saldo_formatted

        // 3. Logica Colori semplicissima
        let colorClass = 'text-gray-500'
        if (amount > 0.01) colorClass = 'text-emerald-600'
        if (amount < -0.01) colorClass = 'text-red-600'

        return h(
            'div', 
            { class: `font-bold text-sm ${colorClass}` }, 
            formattedLabel // Stampiamo direttamente la stringa di PHP
        )
      },
    },
    {
      accessorKey: 'attiva',
      header: 'Stato',
      cell: ({ row }) => {
        const isActive = row.getValue('attiva')

        return h('div', { class: 'flex items-center gap-2' }, [
          h('span', {
            class: `flex h-2 w-2 rounded-full ${
              isActive
                ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]'
                : 'bg-gray-300'
            }`,
          }),
          h(
            'span',
            { class: 'text-xs font-medium text-gray-600 dark:text-gray-400' },
            isActive ? 'Attiva' : 'Archiviata',
          ),
        ])
      },
      size: 100,
    },

    // ─────────────────────────────────────────────────────────────
    // COLONNA 5: AZIONI
    // ─────────────────────────────────────────────────────────────
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const cassa = row.original
        return h(
          'div',
          { class: 'relative text-right' },
          h(DropdownAction, { cassa, condominio }),
        )
      },
      size: 50,
    },
  ]
}