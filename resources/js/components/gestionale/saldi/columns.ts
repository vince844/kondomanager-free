import { h } from 'vue';
import type { ColumnDef } from '@tanstack/vue-table';
import DataTableColumnHeader from '@/components/gestionale/saldi/DataTableColumnHeader.vue'; // <-- Import corretto!
import WalletCell from '@/components/gestionale/saldi/WalletCell.vue'; 
import type { ImmobileConSaldi } from '@/types/gestionale/saldi';
import type { Building } from '@/types/buildings';

export function getColumns(condominio: Building, gestioni: any[]): ColumnDef<ImmobileConSaldi>[] {
  return [
    {
      accessorKey: 'nome', 
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Immobile' }),
      cell: ({ row }) => {
        const immobile = row.original;
        const scala = immobile.scala ? `Sc. ${immobile.scala.name}` : '';
        const palazzina = immobile.palazzina ? `Pal. ${immobile.palazzina.name}` : '';
        const location = [palazzina, scala].filter(Boolean).join(' - ');

        return h('div', { class: 'flex flex-col' }, [
          h('span', { class: 'font-bold' }, `${immobile.nome} ${immobile.interno ? `(Int. ${immobile.interno})` : ''}`),
          h('span', { class: 'text-xs text-muted-foreground' }, location),
        ]);
      },
    },
    {
      id: 'soggetti',
      header: () => h('div', { class: 'font-bold text-muted-foreground' }, 'Soggetti'),
      cell: ({ row }) => {
        const anagrafiche = row.original.anagrafiche;
        
        if (!anagrafiche || anagrafiche.length === 0) {
            return h('span', { class: 'text-xs text-muted-foreground italic' }, 'Nessun soggetto associato');
        }

        return h('div', { class: 'flex flex-col gap-2' }, 
          anagrafiche.map(anagrafica => h('div', { class: 'flex flex-col' }, [
            h('span', { class: 'font-medium text-sm' }, `${anagrafica.nome} ${anagrafica.cognome}`),
            h('span', { class: 'text-[10px] uppercase text-muted-foreground' }, anagrafica.pivot.tipo_rapporto)
          ]))
        );
      },
    },
    {
      id: 'wallet',
      header: () => h('div', { class: 'font-bold text-muted-foreground' }, 'Wallet Saldi'),
      cell: ({ row }) => {
        return h(WalletCell, { 
            immobile: row.original, 
            gestioni: gestioni 
        });
      },
    },
  ];
}