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
