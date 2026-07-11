export interface BackupProgress {
  percent: number;
  phase: string;
  detail: string | null;
}

export interface BackupItem {
  uuid: string;
  filename: string | null;
  status: 'pending' | 'dumping_database' | 'archiving_files' | 'finalizing' | 'completed' | 'failed';
  progress: BackupProgress | null;
  size: number | null;
  checksum: string | null;
  error: string | null;
  created_at: string | null;
  completed_at: string | null;
  created_by: string | null;
  database_rows: number | null;
  files_count: number | null;
}

export interface PreflightCheck {
  key: 'zip_extension' | 'db_driver' | 'dir_writable' | 'disk_space';
  ok: boolean;
  detail: {
    driver?: string;
    estimated_bytes?: number;
    required_bytes?: number;
    free_bytes?: number | null;
  };
}

export interface BackupPreflight {
  ok: boolean;
  checks: PreflightCheck[];
  estimated_bytes: number;
  free_bytes: number | null;
}
