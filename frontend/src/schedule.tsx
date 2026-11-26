import { DateTime } from 'luxon'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, errorMessage, list, write } from './api'
import type { Assignment, Option, User } from './api'

const statuses = { SCHEDULED: 'Terjadwal', COMPLETED: 'Selesai', CANCELLED: 'Dibatalkan' }
type Catalog = { employees: Option[]; departments: Option[]; types: Option[] }
type Editor = { action: 'create' | 'edit' | 'cancel'; row?: Assignment }

export function Schedule({ user, title, onExpired }: { user: User; title: string; onExpired: () => void }) {
  const zone = user.organization_timezone
  const supervisor = user.role === 'SUPERVISOR'
  const admin = user.role === 'ADMIN'
  const today = DateTime.now().setZone(zone).setLocale('id')
  const [week, setWeek] = useState(() => today.startOf('week'))
  const [catalog, setCatalog] = useState<Catalog | null>(null)
  const [rows, setRows] = useState<Assignment[] | null>(null)
  const [scope, setScope] = useState('')
  const [status, setStatus] = useState('')
  const [refresh, setRefresh] = useState(0)
  const [error, setError] = useState('')
  const [editor, setEditor] = useState<Editor | null>(null)
  const [message, setMessage] = useState('')

  useEffect(() => {
    const controller = new AbortController()
    const options = { signal: controller.signal }
    const filters: Record<string, string> = {
      from: week.toUTC().toISO()!,
      to: week.plus({ days: 7 }).toUTC().toISO()!,
    }
    if (scope) filters[admin ? 'department_id' : 'employee_id'] = scope
    if (status) filters.status = status
    setRows(null)
    setError('')

    async function load() {
      try {
        const data = await Promise.all([
          list<Assignment>('/assignments', filters, options),
          admin || supervisor ? list<Option>('/users', { role: 'EMPLOYEE' }, options) : [],
          admin ? list<Option>('/departments', {}, options) : [],
          list<Option>('/shift-types', {}, options),
        ])
        if (controller.signal.aborted) return
        const [assignments, employees, departments, types] = data
        setCatalog({ employees, departments, types })
        setRows(assignments)
      } catch (failure) {
        if (controller.signal.aborted) return
        if (failure instanceof ApiError && failure.status === 401) onExpired()
        else setError(errorMessage(failure))
      }
    }

    void load()
    return () => controller.abort()
  }, [week, scope, status, admin, supervisor, onExpired, refresh])

  const days = Array.from({ length: 7 }, (_, index) => week.plus({ days: index }))
  const employees = new Map(catalog?.employees.map((entry) => [entry.id, entry.name]))
  const types = new Map(catalog?.types.map((entry) => [entry.id, entry.name]))
  const scopeOptions = admin ? catalog?.departments : catalog?.employees

  function reloaded(message: string) {
    setEditor(null)
    setMessage(message)
    setRefresh((value) => value + 1)
  }

  function formatTime(value: string) {
    return DateTime.fromISO(value, { zone }).setLocale('id').toFormat('d LLL, HH:mm')
  }

  return <>
    <section className="page-heading">
      <div><h1>{title}</h1><p className="muted">Kalender mingguan · {zone}</p></div>
      {supervisor && <button className="primary" disabled={!catalog} onClick={() => {
        setMessage('')
        setEditor({ action: 'create' })
      }}>+ Tambah penugasan</button>}
    </section>
    <section className="schedule" aria-label="Kalender shift">
      <div className="toolbar">
        <div className="period">
          <strong>{week.toFormat('d LLL yyyy')} – {days[6].toFormat('d LLL yyyy')}</strong>
          <div className="week-buttons">
            <button aria-label="Minggu sebelumnya" onClick={() => setWeek(week.minus({ days: 7 }))}>‹</button>
            <button onClick={() => setWeek(today.startOf('week'))}>Minggu ini</button>
            <button aria-label="Minggu berikutnya" onClick={() => setWeek(week.plus({ days: 7 }))}>›</button>
          </div>
        </div>
        <div className="filters">
          {(admin || supervisor) && <label><span id="schedule-scope">{admin ? 'Departemen' : 'Karyawan'}</span>
            <select aria-labelledby="schedule-scope" value={scope} onChange={(event) => setScope(event.target.value)}>
              <option value="">Semua</option>
              {scopeOptions?.map((entry) => <option key={entry.id} value={entry.id}>{entry.name}</option>)}
            </select>
          </label>}
          <label><span id="schedule-status">Status</span>
            <select aria-labelledby="schedule-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">Semua status</option>
              {Object.entries(statuses).map(([key, name]) => <option key={key} value={key}>{name}</option>)}
            </select>
          </label>
          <button onClick={() => setRefresh((value) => value + 1)}>Muat ulang</button>
        </div>
      </div>
      {message && <p className="success" role="status">{message}</p>}
      {error ? <p role="alert" className="error schedule-state">{error}</p> : !rows
        ? <p role="status" className="schedule-state muted">Memuat jadwal…</p>
        : days.map((day) => {
          const items = rows.filter((row) => DateTime.fromISO(row.starts_at) < day.plus({ days: 1 })
            && DateTime.fromISO(row.ends_at) > day)
          return <section className="day" key={day.toISODate()}>
            <h2 className={day.hasSame(today, 'day') ? 'today' : ''}>
              {day.toFormat('cccc')} <span>{day.toFormat('d LLL')}</span>
            </h2>
            {items.length === 0 ? <p className="muted empty">Tidak ada shift</p> : <ul>
              {items.map((row) => <li className="shift" key={row.id}>
                <div>
                  <strong>{types.get(row.shift_type_id) ?? `Tipe shift #${row.shift_type_id}`}</strong>
                  {user.role !== 'EMPLOYEE' && <span>{employees.get(row.employee_id) ?? `Karyawan #${row.employee_id}`}</span>}
                </div>
                <div className="shift-time">{formatTime(row.starts_at)} – {formatTime(row.ends_at)}</div>
                <span className={`status ${row.status.toLowerCase()}`}>{statuses[row.status]}</span>
                {supervisor && row.available_actions?.length > 0 && <div className="actions">
                  {row.available_actions.includes('edit') && <button onClick={() => {
                    setMessage('')
                    setEditor({ action: 'edit', row })
                  }}>Ubah</button>}
                  {row.available_actions.includes('cancel') && <button onClick={() => {
                    setMessage('')
                    setEditor({ action: 'cancel', row })
                  }}>Batalkan</button>}
                </div>}
              </li>)}
            </ul>}
          </section>
        })}
    </section>
    {supervisor && editor && catalog && <AssignmentDialog editor={editor} zone={zone} catalog={catalog}
      onClose={() => setEditor(null)} onExpired={onExpired} onReload={reloaded} />}
  </>
}

function AssignmentDialog({ editor, zone, catalog, onClose, onExpired, onReload }: {
  editor: Editor
  zone: string
  catalog: Catalog
  onClose: () => void
  onExpired: () => void
  onReload: (message: string) => void
}) {
  const dialog = useRef<HTMLDialogElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [stale, setStale] = useState(false)
  const { row, action } = editor
  const title = { create: 'Tambah penugasan', edit: 'Ubah penugasan', cancel: 'Batalkan penugasan' }[action]
  const format = "yyyy-MM-dd'T'HH:mm"
  const starts = row ? DateTime.fromISO(row.starts_at, { zone }).toFormat(format) : ''
  const ends = row ? DateTime.fromISO(row.ends_at, { zone }).toFormat(format) : ''

  useEffect(() => { dialog.current?.showModal() }, [])

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const employee = String(form.get('employee_id') ?? '')
    const type = String(form.get('shift_type_id') ?? '')
    const start = String(form.get('starts_at') ?? '')
    const end = String(form.get('ends_at') ?? '')
    const changes: Record<string, string | number> = {}
    setError('')

    if (action !== 'cancel') {
      if (employee !== row?.employee_id) changes.employee_id = employee
      if (type !== row?.shift_type_id) changes.shift_type_id = type
      for (const [field, value, original] of [
        ['starts_at', start, starts], ['ends_at', end, ends],
      ]) {
        if (value === original) continue
        const time = DateTime.fromISO(value, { zone })
        if (!time.isValid || time.toFormat(format) !== value || time.getPossibleOffsets().length > 1) {
          setError('Pilih waktu yang valid dan tidak ambigu dalam zona waktu organisasi.')
          return
        }
        changes[field] = time.toUTC().toISO()!
      }
      if (Object.keys(changes).length === 0) {
        setError('Ubah setidaknya satu kolom sebelum menyimpan.')
        return
      }
    }

    setBusy(true)
    try {
      if (!row) await write('/assignments', 'POST', changes)
      else if (action === 'cancel') await write(`/assignments/${row.id}/cancel`, 'POST', { version: row.version })
      else await write(`/assignments/${row.id}`, 'PATCH', { ...changes, version: row.version })
      onReload(action === 'cancel' ? 'Penugasan dibatalkan.' : 'Penugasan disimpan.')
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onExpired()
      else if (!(failure instanceof ApiError) || failure.status >= 500 || ['VERSION_CONFLICT', 'INVALID_TRANSITION', 'NOT_FOUND'].includes(failure.code)) {
        setStale(true)
        setError(`${errorMessage(failure)} Periksa jadwal terbaru sebelum mencoba lagi.`)
      } else setError(errorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  function choices(values: Option[], selected?: string) {
    return values.filter((entry) => entry.active || entry.id === selected)
      .map((entry) => <option key={entry.id} value={entry.id}>{entry.name}{entry.active ? '' : ' (nonaktif)'}</option>)
  }

  return <dialog ref={dialog} aria-labelledby="editor-title" onCancel={(event) => {
    event.preventDefault()
    if (!busy) onClose()
  }}>
    <h2 id="editor-title">{title}</h2>
    <form onSubmit={save}>
      {action === 'cancel' ? <p>Batalkan penugasan ini? Statusnya akan berubah menjadi dibatalkan.</p> : <>
        <label><span id="assignment-employee">Karyawan</span>
          <select aria-labelledby="assignment-employee" name="employee_id" defaultValue={row?.employee_id ?? ''} required>
            <option value="" disabled>Pilih karyawan</option>{choices(catalog.employees, row?.employee_id)}
          </select>
        </label>
        <label><span id="assignment-type">Tipe shift</span>
          <select aria-labelledby="assignment-type" name="shift_type_id" defaultValue={row?.shift_type_id ?? ''} required>
            <option value="" disabled>Pilih tipe shift</option>{choices(catalog.types, row?.shift_type_id)}
          </select>
        </label>
        <p className="muted">Zona waktu · {zone}</p>
        <label>Mulai<input name="starts_at" type="datetime-local" defaultValue={starts} required /></label>
        <label>Selesai<input name="ends_at" type="datetime-local" defaultValue={ends} required /></label>
      </>}
      {error && <p className="error" role="alert">{error}</p>}
      <div className="dialog-actions">
        <button type="button" disabled={busy} onClick={onClose}>Tutup</button>
        {stale ? <button type="button" className="primary" onClick={() => onReload('')}>Muat ulang jadwal</button>
          : <button className={action === 'cancel' ? 'danger' : 'primary'} disabled={busy}>
            {busy ? 'Memproses…' : action === 'cancel' ? 'Ya, batalkan' : 'Simpan'}
          </button>}
      </div>
    </form>
  </dialog>
}
