import type { Comunicazione } from '@/types/comunicazioni'

declare module '@tanstack/table-core' {
  interface TableMeta<TData extends unknown> {
    updateData: (rowIndex: number, updatedRow: TData) => void;
  }
}