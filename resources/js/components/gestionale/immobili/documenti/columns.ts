import { h } from 'vue';
import { router } from '@inertiajs/vue3';
import DropdownAction from '@/components/gestionale/immobili/documenti/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/gestionale/immobili/documenti/DataTableColumnHeader.vue';
import { publishedConstants } from '@/lib/documenti/constants';
import { usePermission } from "@/composables/permissions";
import { FileText, Download, FileImage, FileSpreadsheet, FileArchive, File } from 'lucide-vue-next';
import { Permission } from "@/enums/Permission";
import { trans } from 'laravel-vue-i18n';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';

const { hasPermission, generateRoute } = usePermission();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const formatFileSize = (bytes: number): string => {
  if (!bytes) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
};

const getMimeIcon = (mime: string) => {
  const m = (mime ?? '').toUpperCase();
  if (m === 'PDF') return FileText;
  if (['PNG', 'JPG', 'JPEG', 'GIF', 'WEBP', 'SVG'].includes(m)) return FileImage;
  if (['XLS', 'XLSX', 'CSV'].includes(m)) return FileSpreadsheet;
  if (['ZIP', 'RAR', '7Z'].includes(m)) return FileArchive;
  return File;
};

// ---------------------------------------------------------------------------
// Approval Dot Toggle
// ---------------------------------------------------------------------------

const renderApprovalDot = (documento: Documento, canApprove: boolean, onToggle: () => void) => {
  const approved = Boolean(documento.is_approved);

  const dot = h('span', { class: 'relative flex h-2.5 w-2.5 shrink-0' }, [
    approved
      ? h('span', { class: 'absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75' })
      : null,
    h('span', {
      class: `relative inline-flex rounded-full h-2.5 w-2.5 transition-colors ${
        approved ? 'bg-emerald-500' : 'bg-rose-500'
      }`,
    }),
  ]);

  const tag = canApprove ? 'button' : 'div';

  return h(tag, {
    class: `flex items-center justify-center rounded-full p-1 transition-transform ${
      canApprove ? 'cursor-pointer hover:scale-125 hover:bg-slate-100 dark:hover:bg-slate-800' : 'cursor-default'
    }`,
    title: approved 
        ? (canApprove ? 'Approvato – clicca per revocare' : 'Approvato') 
        : (canApprove ? 'Non approvato – clicca per approvare' : 'In attesa di approvazione'),
    ...(canApprove ? { onClick: onToggle } : {}),
  }, [dot]);
};

// ---------------------------------------------------------------------------
// Column definitions
// ---------------------------------------------------------------------------

export const createColumns = (condominio: Building, immobile: Immobile): ColumnDef<Documento>[] => [

  // ── 1. DOCUMENTO ──────────────────────────────────────────────────────────
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Documento' }),
    cell: ({ row, table }) => {
      const documento = row.original;

      const toggleApproval = () => {
        router.put(route(generateRoute('documenti.toggle-approval'), { id: documento.id }), {}, {
          preserveScroll: true,
          only: ['stats', 'documenti'],
          onSuccess: () => {
            documento.is_approved = !documento.is_approved;
            documento.is_published = documento.is_approved;
            table.options.meta?.updateData(row.index, {
              ...documento,
              is_published: documento.is_approved,
            });
          },
        });
      };

      const canApprove = hasPermission([Permission.APPROVE_ARCHIVE_DOCUMENTS]);
      const MimeIcon = getMimeIcon(documento.mime_type);

      const fileIcon = h('div', {
        class: 'p-2 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-indigo-500 shadow-sm shrink-0 group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/50 transition-colors',
      }, [h(MimeIcon, { class: 'w-4 h-4' })]);

      const textContainer = h('div', { class: 'flex flex-col min-w-0 flex-1' }, [
        
        // Riga 1: Titolo + Dot
        h('div', { class: 'flex items-center gap-2 mb-0.5' }, [
            renderApprovalDot(documento, canApprove, toggleApproval),
            h('a', {
                href: route(generateRoute('documenti.download'), { documento: documento.id }),
                class: 'font-semibold text-sm text-slate-900 dark:text-slate-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors truncate leading-tight',
            }, documento.name),
        ]),

        // Riga 2: Descrizione (se presente)
        documento.description
          ? h('p', {
              class: 'text-[11px] text-slate-500 dark:text-slate-400 truncate leading-tight ml-1 pl-5 max-w-sm',
              title: documento.description,
            }, documento.description)
          : null,

        // Riga 3: Metadati accorpati
        h('div', { class: 'flex items-center gap-1.5 mt-0.5 ml-1 pl-5' }, [ 
            h('span', { class: 'text-[10px] text-slate-400 uppercase tracking-widest font-mono' }, documento.mime_type || 'FILE'),
            h('span', { class: 'text-[10px] text-slate-300' }, '•'),
            h('span', { class: 'text-[10px] text-slate-400 tracking-widest' }, documento.file_size ? formatFileSize(documento.file_size) : 'N/A'),
            h('span', { class: 'text-[10px] text-slate-300' }, '•'),
            h('span', { class: 'text-[10px] text-slate-400 leading-none truncate flex items-center gap-1 group-hover:text-indigo-500 transition-colors' }, [
                h(Download, { class: 'w-3 h-3' }),
                'Scarica'
            ])
        ])
      ]);

      return h('div', { class: 'flex items-center gap-3 py-1 group' }, [
        fileIcon,
        textContainer
      ]);
    },
  },

  // ── 2. VISIBILITÀ ─────────────────────────────────────────────────────────
  {
    accessorKey: 'is_published',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Visibilità' }),
    cell: ({ row }) => {
      const value = Boolean(row.getValue('is_published'));
      const stato = publishedConstants.find(p => p.value === value);
      if (!stato) return h('span', '–');

      const colorClasses = value
        ? 'bg-blue-50 text-blue-700 border border-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-800/50'
        : 'bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700';

      return h('span', {
        class: `inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest ${colorClasses}`,
      }, [
        h(stato.icon, { class: 'w-3 h-3' }),
        h('span', trans(stato.label)),
      ]);
    },
    filterFn: (row, id, value) => value.includes(Boolean(row.getValue(id))),
  },

  // ── 3. DATA ───────────────────────────────────────────────────────────────
  {
    accessorKey: 'created_at',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data' }),
    cell: ({ row }) => {
      const date = row.getValue('created_at') as string;
      return h('span', {
        class: 'text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap',
      }, date ?? '—');
    },
  },

  // ── 4. AUTORE ─────────────────────────────────────────────────────────────
  {
    id: 'uploaded_by',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Autore' }),
    cell: ({ row }) => {
      const documento = row.original;
      const name: string = documento.created_by?.user?.name ?? '—';

      const initials = name === '—'
        ? '?'
        : name.split(' ').slice(0, 2).map((w: string) => w[0]?.toUpperCase() ?? '').join('');

      return h('div', {
        class: 'w-7 h-7 rounded-full bg-slate-100 border border-slate-200 dark:bg-slate-800 dark:border-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center text-[10px] font-bold shrink-0 cursor-help transition-transform hover:scale-110 shadow-sm',
        title: `Caricato da: ${name}`, 
      }, initials);
    },
  },

  // ── 5. AZIONI ─────────────────────────────────────────────────────────────
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const documento = row.original as Documento;
      return h('div', { class: 'flex justify-end pr-2' },
        h(DropdownAction, { documento, condominio, immobile }),
      );
    },
  },
];