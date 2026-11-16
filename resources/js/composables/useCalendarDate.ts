// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// composables/useCalendarDate.ts
import { CalendarDate } from '@internationalized/date'

export function toCalendarDate(date: any): CalendarDate | undefined {
  if (!date) return undefined
  if (date instanceof CalendarDate) return date
  if (
    typeof date === 'object' &&
    typeof date.year === 'number' &&
    typeof date.month === 'number' &&
    typeof date.day === 'number'
  ) {
    return new CalendarDate(date.year, date.month, date.day)
  }
  return undefined
}
