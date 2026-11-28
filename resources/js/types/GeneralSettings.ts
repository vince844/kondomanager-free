// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { PageProps } from '@inertiajs/core'
import type { Flash } from '@/types/flash'

export interface CondominioOption {
  id: number
  nome: string
}

export interface RoleOption {
  id: number
  name: string
}

export interface GeneralSettings extends PageProps {
  flash?: { message?: Flash }
  can_register: boolean
  language: string
  app_name: string
  open_condominio_on_login: boolean
  default_condominio_id: number | null
  condomini: CondominioOption[]
  default_user_role: string
  roles: RoleOption[]
  /** Chi apre la pagina può cambiare il ruolo della registrazione? Solo l'amministratore (Coda 258). */
  ruolo_registrazione_modificabile: boolean
  comunicazioni_registra_aperture?: boolean
  errori_avviso_dalla_pagina?: boolean
  due_fattori_obbligatoria_pannello?: boolean
  aperture_registrate?: number
}