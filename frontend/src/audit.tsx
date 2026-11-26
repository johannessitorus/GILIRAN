import { useEffect, useState } from 'react'
import { ApiError, errorMessage, readPage } from './api'
import { formatLocalTime, zoneLabel } from './time'

const pageSize = 25

const entities: Record<string, string> = {
  assignments: 'Penugasan', swaps: 'Tukar shift', handovers: 'Serah terima',
  handover_tasks: 'Tugas tindak lanjut', departments: 'Departemen', users: 'Akun',
}

const actions: Record<string, string> = {
  CREATED: 'Dibuat', UPDATED: 'Diperbarui', CANCELLED: 'Dibatalkan', COMPLETED: 'Diselesaikan',
  SWAP_OWNERSHIP_CHANGED: 'Pemilik shift ditukar', DRAFT_CREATED: 'Draf dibuat',
  DRAFT_UPDATED: 'Draf diperbarui', DRAFT_ADOPTED: 'Draf diambil alih',
  SUBMITTED: 'Laporan dikirim', ACKNOWLEDGED: 'Penerimaan diakui', DELETED: 'Dihapus',
  STATUS_CHANGED: 'Status tugas diubah', PENDING_APPROVAL: 'Diterima rekan',
  APPROVED: 'Disetujui', REJECTED: 'Ditolak', EXPIRED: 'Kedaluwarsa',
}

const statuses: Record<string, string> = {
  SCHEDULED: 'Terjadwal', COMPLETED: 'Selesai', CANCELLED: 'Dibatalkan', DRAFT: 'Draf',
  SUBMITTED: 'Terkirim', ACKNOWLEDGED: 'Diterima', OPEN: 'Terbuka', IN_PROGRESS: 'Sedang dikerjakan',
  DONE: 'Selesai', PENDING_PEER: 'Menunggu rekan', PENDING_APPROVAL: 'Menunggu Supervisor',
  APPROVED: 'Disetujui', REJECTED: 'Ditolak', EXPIRED: 'Kedaluwarsa',
}

const fields: Record<string, string> = {
  status: 'Status', version: 'Versi', employee_id: 'ID karyawan', shift_type_id: 'ID tipe shift',
  starts_at: 'Mulai shift', ends_at: 'Akhir shift', department_id: 'ID departemen', role: 'Peran',
  active: 'Akun atau departemen aktif', source_assignment_id: 'ID shift keluar',
  recipient_assignment_id: 'ID shift penerima', draft_owner_id: 'ID pemilik draf',
  submitted_by: 'ID pengirim laporan', submitted_at: 'Waktu pengiriman',
  acknowledged_by: 'ID penerima laporan', acknowledged_at: 'Waktu pengakuan',
  handover_report_id: 'ID laporan', created_by: 'ID pembuat tugas', updated_by: 'ID pengubah tugas',
}

type Snapshot = Record<string, unknown>
type AuditEntry = {
  id: string
  actor_id: string | null
  department_id: string | null
  entity_type: string
  entity_id: string
  action: string
  outcome: string
  created_at: string
  metadata: {
    before?: Snapshot | unknown[]
    after?: Snapshot | unknown[]
    from_status?: string | null
    to_status?: string | null
    reason_code?: string
    swap_request_id?: string | number
  }
}

function formatDate(value: string, zone: string) {
  return formatLocalTime(value, zone, 'd LLL yyyy, HH.mm.ss')
}

function fieldValue(field: string, value: unknown, zone: string) {
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak'
  if (typeof value === 'number') return String(value)
  if (typeof value !== 'string') return 'Tidak tercatat'
  if (field.endsWith('_at')) return formatDate(value, zone)
  if (field === 'status') return statuses[value] ?? value
  if (field === 'role') return ({ ADMIN: 'Owner', SUPERVISOR: 'Supervisor', EMPLOYEE: 'Karyawan' })[value] ?? value
  return value
}

function snapshotFields(snapshot: AuditEntry['metadata']['before']) {
  return snapshot && !Array.isArray(snapshot) ? snapshot : {}
}

function EntryDetails({ entry, zone }: { entry: AuditEntry; zone: string }) {
  const metadata = entry.metadata
  const before = snapshotFields(metadata.before)
  const after = snapshotFields(metadata.after)
  const changes = Object.keys(fields).filter((key) => key in before || key in after)
  const hasStatus = metadata.from_status != null || metadata.to_status != null
  const hasContext = metadata.reason_code != null || metadata.swap_request_id != null
  if (!changes.length && !hasStatus && !hasContext) return null

  return <details>
    <summary className="w-fit cursor-pointer py-3 font-semibold text-blue-800">Rincian perubahan</summary>
    {(hasStatus || hasContext) && <dl className="mb-3 grid gap-2 text-sm">
      {hasStatus && <div><dt className="font-semibold">Status tukar shift</dt>
        <dd>{fieldValue('status', metadata.from_status, zone)} → {fieldValue('status', metadata.to_status, zone)}</dd></div>}
      {metadata.reason_code && <div><dt className="font-semibold">Kode alasan</dt><dd className="break-words">{metadata.reason_code}</dd></div>}
      {metadata.swap_request_id != null && <div><dt className="font-semibold">ID permintaan tukar</dt><dd>{metadata.swap_request_id}</dd></div>}
    </dl>}
    {changes.length > 0 && <dl className="grid gap-2 text-sm">
      {changes.map((key) => <div key={key} className="rounded-lg border border-slate-300 bg-slate-50 p-3">
        <dt className="font-semibold">{fields[key]}</dt>
        <dd className="grid gap-1 break-words sm:grid-cols-2">
          <span>Sebelum: {fieldValue(key, before[key], zone)}</span>
          <span>Sesudah: {fieldValue(key, after[key], zone)}</span>
        </dd>
      </div>)}
    </dl>}
  </details>
}

export function AuditLog({ zone, onExpired }: { zone: string; onExpired: () => void }) {
  const [page, setPage] = useState(1)
  const [refresh, setRefresh] = useState(0)
  const [entries, setEntries] = useState<AuditEntry[] | null>(null)
  const [total, setTotal] = useState(0)
  const [error, setError] = useState('')
  const lastPage = Math.max(1, Math.ceil(total / pageSize))

  useEffect(() => {
    const controller = new AbortController()
    const query = new URLSearchParams({ page: String(page), per_page: String(pageSize) })
    readPage<AuditEntry>('/audit-logs', query, { signal: controller.signal }).then((result) => {
      if (controller.signal.aborted) return
      const available = Math.max(1, Math.ceil(result.meta.total / pageSize))
      setTotal(result.meta.total)
      if (page > available) setPage(available)
      else setEntries(result.data)
    }).catch((failure: unknown) => {
      if (controller.signal.aborted) return
      if (failure instanceof ApiError && failure.status === 401) onExpired()
      else setError(failure instanceof ApiError && failure.status === 403
        ? 'Akun Anda tidak memiliki akses ke riwayat audit.' : errorMessage(failure))
    })
    return () => controller.abort()
  }, [page, refresh, onExpired])

  function load(nextPage: number) {
    setEntries(null)
    setError('')
    if (nextPage !== page) setPage(nextPage)
    else setRefresh((value) => value + 1)
  }

  return <>
    <header className="page-heading">
      <div><h1>Riwayat audit</h1><p className="muted">Aktivitas terbaru dalam zona waktu {zoneLabel(zone)}.</p></div>
      <button className="min-h-11 enabled:cursor-pointer active:underline" disabled={!entries && !error}
        onClick={() => load(1)}>Muat ulang</button>
    </header>
    <section className="schedule grid gap-4 p-4 sm:p-5" aria-label="Daftar aktivitas audit">
      {error ? <p role="alert" className="error">{error}</p> : !entries
        ? <p role="status">Memuat riwayat audit…</p> : entries.length === 0
        ? <p className="muted">Belum ada aktivitas yang tercatat dalam lingkup akun Anda.</p> :
          <ol className="grid gap-4">
            {entries.map((entry) => <li key={entry.id} className="grid min-w-0 gap-2 border-b border-slate-200 pb-4 last:border-0 last:pb-0">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="min-w-0 break-words font-semibold">{actions[entry.action] ?? entry.action} · {entities[entry.entity_type] ?? entry.entity_type} #{entry.entity_id}</h2>
                <time className="muted text-sm" dateTime={entry.created_at}>{formatDate(entry.created_at, zone)}</time>
              </div>
              <dl className="flex flex-wrap gap-x-5 gap-y-1 text-sm">
                <div><dt className="inline font-semibold">Pelaku </dt><dd className="inline">{entry.actor_id === null ? 'Sistem' : 'Pengguna #' + entry.actor_id}</dd></div>
                {entry.department_id !== null && <div><dt className="inline font-semibold">Departemen </dt><dd className="inline">#{entry.department_id}</dd></div>}
                <div><dt className="inline font-semibold">Hasil </dt><dd className="inline">{entry.outcome === 'SUCCESS' ? 'Berhasil' : entry.outcome}</dd></div>
              </dl>
              <EntryDetails entry={entry} zone={zone} />
            </li>)}
          </ol>}
      {lastPage > 1 && <nav className="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4" aria-label="Halaman riwayat audit">
        <button className="min-h-11 enabled:cursor-pointer active:underline" disabled={!entries || page <= 1}
          onClick={() => load(page - 1)}>Sebelumnya</button>
        <p className="text-sm" role="status">Halaman {page} dari {lastPage}</p>
        <button className="min-h-11 enabled:cursor-pointer active:underline" disabled={!entries || page >= lastPage}
          onClick={() => load(page + 1)}>Berikutnya</button>
      </nav>}
    </section>
  </>
}
