import { DateTime } from 'luxon'

export function zoneLabel(zone: string) {
  return zone === 'Asia/Jakarta' ? 'WIB (Waktu Indonesia Barat)' : zone
}

export function formatLocalTime(value: string, zone: string, format = 'd LLL yyyy, HH.mm') {
  const iso = DateTime.fromISO(value, { zone })
  const date = iso.isValid ? iso : DateTime.fromSQL(value, { zone })
  return date.isValid ? date.setLocale('id').toFormat(format) : value
}
