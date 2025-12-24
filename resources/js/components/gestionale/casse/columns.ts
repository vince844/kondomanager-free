// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/casse/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/casse/DataTableColumnHeader.vue'
import { Badge } from '@/components/ui/badge' 
import { Landmark, Banknote, Wallet, Star } from 'lucide-vue-next' // Assicurati di importare Star
import type { ColumnDef } from '@tanstack/vue-table'
import type { Cassa } from '@/types/gestionale/casse'
import type { Building } from '@/types/buildings'

// Helper per formattare il tipo di conto
const formatTipoConto = (tipo: string | null | undefined) => {
    if (!tipo) return '';
    const labels: Record<string, string> = {
        ordinario: 'Conto Ordinario',
        dedicato: 'Conto Dedicato',
        postale: 'Conto Postale',
        contabilita_speciale: 'Contabilità Speciale',
        estero: 'Conto Estero',
        altro: 'Altro'
    };
    return labels[tipo] || tipo;
}

export function getColumns(condominio: Building): ColumnDef<Cassa>[] {
  return [
    // COLONNA 1: TIPO RISORSA (Badge Outline + Stella se Principale)
    {
      accessorKey: 'tipo',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipo' }),
      cell: ({ row }) => {
        const cassa = row.original
        const type = cassa.tipo
        const isPredefinito = Boolean(cassa.banca_predefinito) // Forziamo il booleano

        let badgeClass = ''
        let label = ''
        let icon = null

        switch (type) {
            case 'banca':
                badgeClass = 'border-blue-500 text-blue-600 dark:text-blue-400'
                label = 'Banca'
                icon = Landmark
                break;
            case 'contanti':
                badgeClass = 'border-emerald-500 text-emerald-600 dark:text-emerald-400'
                label = 'Cassa'
                icon = Banknote
                break;
            case 'fondo':
                badgeClass = 'border-amber-500 text-amber-600 dark:text-amber-400'
                label = 'Fondo'
                icon = Wallet
                break;
            default:
                badgeClass = 'border-gray-500 text-gray-600'
                label = 'Altro'
        }

        return h('div', { class: 'flex items-center gap-2' }, [
            // Badge Tipo
            h(Badge, { variant: 'outline', class: `${badgeClass} font-medium pr-2 gap-1` }, () => [
                 icon ? h(icon, { class: 'w-3 h-3' }) : null,
                 label
            ]),
            // Stella se predefinito (Visibilità immediata)
            (type === 'banca' && isPredefinito) 
                ? h(Star, { class: 'h-4 w-4 fill-amber-400 text-amber-500', 'aria-label': 'Conto Principale' }) 
                : null
        ])
      },
      size: 120,
    },

    // COLONNA 2: NOME, COORDINATE E TIPOLOGIA
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dettagli Risorsa' }),
      cell: ({ row }) => {
        const cassa = row.original
        const isBanca = cassa.tipo === 'banca'
        // Casting esplicito per sicurezza
        const isPredefinito = Boolean(cassa.banca_predefinito) 
        
        return h('div', { class: 'flex flex-col space-y-1' }, [
          
          // Riga 1: Nome
          h('div', { class: 'flex items-center gap-2' }, [
            h('span', { class: 'font-semibold text-gray-900 dark:text-gray-100 text-base' }, cassa.nome),
          ]),

          // Riga 2: Badge Principale + Tipologia Conto
          h('div', { class: 'flex items-center gap-2 flex-wrap' }, [
            
             // BADGE PRINCIPALE (Se è banca ed è predefinito)
             (isBanca && isPredefinito) 
                ? h(Badge, { class: 'bg-indigo-600 hover:bg-indigo-700 text-[10px] px-1.5 h-5' }, () => 'Principale') 
                : null,

             // Tipologia Conto
             isBanca
                ? h('span', { class: 'text-xs font-medium text-gray-500' }, formatTipoConto(cassa.banca_tipo_conto))
                : h('span', { class: 'text-xs text-muted-foreground' }, cassa.descrizione || '-')
          ]),

          // Riga 3: IBAN (Solo Banca)
          (isBanca && cassa.banca_iban)
             ? h('div', { class: 'flex items-center gap-1 mt-1' }, [
                 h('span', { class: 'text-[10px] uppercase text-muted-foreground font-bold' }, 'IBAN:'),
                 h('span', { class: 'text-xs font-mono text-gray-600 dark:text-gray-400 tracking-wide' }, cassa.banca_iban)
               ])
             : null
        ])
      },
    },

    // COLONNA 3: SALDO ATTUALE
    {
      accessorKey: 'saldo_attuale',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Saldo' }),
      cell: ({ row }) => {
        const amount = parseFloat(row.getValue('saldo_attuale') as string || '0')
        const formatted = new Intl.NumberFormat('it-IT', {
          style: 'currency',
          currency: 'EUR',
        }).format(amount)

        let colorClass = 'text-gray-500'
        if (amount > 0) colorClass = 'text-emerald-600 font-semibold'
        if (amount < 0) colorClass = 'text-red-600 font-semibold'

        return h('div', { class: colorClass }, formatted)
      },
    },

    // COLONNA 4: STATO
    {
        accessorKey: 'attiva',
        header: 'Stato',
        cell: ({ row }) => {
            const isActive = row.getValue('attiva')
            
            return h('div', { class: 'flex items-center gap-2' }, [
                h('span', { class: `flex h-2 w-2 rounded-full ${isActive ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.4)]' : 'bg-gray-300'}` }),
                h('span', { class: 'text-sm text-gray-600 dark:text-gray-400' }, isActive ? 'Attiva' : 'Non attiva')
            ])
        },
        size: 120,
    },

    // COLONNA 5: AZIONI
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const cassa = row.original as Cassa
        return h('div', { class: 'relative text-right' },
          h(DropdownAction, { cassa, condominio })
        )
      },
      size: 50,
    },
  ]
}