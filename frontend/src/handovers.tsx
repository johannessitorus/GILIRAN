import { DateTime } from 'luxon'
import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, errorMessage, list, read, write } from './api'
import type { Assignment, User } from './api'

type Shift = Pick<Assignment, 'id' | 'starts_at' | 'ends_at' | 'status'> & {
  employee_id?: string
  display_name?: string
}

const statuses = { DRAFT: 'Draf', SUBMITTED: 'Terkirim', ACKNOWLEDGED: 'Diterima' }
const taskStatuses = { OPEN: 'Terbuka', IN_PROGRESS: 'Dalam proses', DONE: 'Selesai' }
type Report = {
  id: string
  source_assignment_id: string
  recipient_assignment_id: string
  draft_owner_id: string
  status: keyof typeof statuses
  summary: string | null
  issues: string | null
  submitted_at: string | null
  acknowledged_at: string | null
  version: number
}
type Detail = Report & {
  tasks: { id: string; title: string; details: string | null; status: keyof typeof taskStatuses }[]
}
type DetailData = { report: Detail; source: Shift | null; recipient: Shift | null }

const handoverErrors: Record<string, string> = {
  HANDOVER_EXISTS: 'Shift keluar ini sudah memiliki laporan. Muat ulang daftar untuk membukanya.',
  NO_RECIPIENT: 'Belum ada shift penerima yang memenuhi syarat.',
  RECIPIENT_SELECTION_REQUIRED: 'Beberapa shift penerima mulai bersamaan. Pilih salah satunya.',
  INVALID_RECIPIENT: 'Pilih shift aktif dengan waktu mulai paling awal setelah shift keluar berakhir.',
  DRAFT_ADOPTION_REQUIRED: 'Ambil alih draf sebelum mengubah atau mengirimnya.',
  RECIPIENT_SHIFT_NOT_STARTED: 'Laporan dapat diakui setelah shift penerima dimulai.',
  VERSION_CONFLICT: 'Laporan sudah berubah. Muat ulang rincian sebelum mencoba lagi.',
  INVALID_TRANSITION: 'Status laporan atau shift sudah berubah. Muat ulang rincian.',
}

function describeError(error: unknown) {
  return error instanceof ApiError ? handoverErrors[error.code] ?? errorMessage(error) : errorMessage(error)
}

function formatDate(value: string, zone: string) {
  return DateTime.fromISO(value, { zone }).setLocale('id').toFormat('d LLL yyyy, HH:mm')
}

function shiftLabel(shift: Shift | null, id: string, user: User) {
  if (!shift) return 'Penugasan #' + id
  const name = shift.employee_id === user.id ? user.name : shift.display_name
  return [formatDate(shift.starts_at, user.organization_timezone),
    formatDate(shift.ends_at, user.organization_timezone), name].filter(Boolean).join(' · ')
}

async function readShift(id: string, signal: AbortSignal) {
  try {
    return await read<Shift>('/assignments/' + id, { signal })
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null
    throw error
  }
}

export function Handovers({ user, onExpired }: { user: User; onExpired: () => void }) {
  const [rows, setRows] = useState<Report[] | null>(null)
  const [selected, setSelected] = useState('')
  const [creating, setCreating] = useState(false)
  const [detail, setDetail] = useState<DetailData | null>(null)
  const [listError, setListError] = useState('')
  const [detailError, setDetailError] = useState('')
  const [actionError, setActionError] = useState('')
  const [notice, setNotice] = useState('')
  const [pending, setPending] = useState(false)
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    list<Report>('/handovers', {}, { signal: controller.signal }).then(setRows).catch((error) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setListError(describeError(error))
    })
    return () => controller.abort()
  }, [refresh, onExpired])

  useEffect(() => {
    if (!selected) return
    const controller = new AbortController()
    const { signal } = controller
    async function load() {
      try {
        const report = await read<Detail>('/handovers/' + selected, { signal })
        const [source, recipient] = await Promise.all([
          readShift(report.source_assignment_id, signal),
          readShift(report.recipient_assignment_id, signal),
        ])
        if (!signal.aborted) setDetail({ report, source, recipient })
      } catch (error) {
        if (signal.aborted) return
        if (error instanceof ApiError && error.status === 401) onExpired()
        else setDetailError(describeError(error))
      }
    }
    void load()
    return () => controller.abort()
  }, [selected, refresh, onExpired])

  function reload() {
    setListError('')
    setDetailError('')
    setActionError('')
    setRows(null)
    setDetail(null)
    setRefresh((value) => value + 1)
  }

  function open(id: string) {
    setCreating(false)
    if (id === selected) return
    setSelected(id)
    setDetail(null)
    setDetailError('')
    setActionError('')
    setNotice('')
  }

  async function save(path: string, method: 'POST' | 'PATCH', payload: object, message: string) {
    setPending(true)
    setActionError('')
    setNotice('')
    try {
      const result = await write<Detail>(path, method, payload)
      if (!result) throw new Error('Respons laporan kosong')
      reload()
      setSelected(result.id)
      setCreating(false)
      setNotice(message)
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setActionError(describeError(error))
    } finally {
      setPending(false)
    }
  }

  const report = detail?.report
  const path = '/handovers/' + selected
  const isEmployee = user.role === 'EMPLOYEE'
  const ownsSource = isEmployee && detail?.source?.employee_id === user.id && detail.source.status !== 'CANCELLED'
  const canEdit = ownsSource && report?.status === 'DRAFT' && report.draft_owner_id === user.id
  const ownsRecipient = isEmployee && detail?.recipient?.employee_id === user.id && detail.recipient.status !== 'CANCELLED'
  const canAcknowledge = ownsRecipient && report?.status === 'SUBMITTED'

  return <>
    <header className="page-heading">
      <h1>Serah terima shift</h1>
      <div className="actions">
        <button disabled={pending || creating} onClick={reload}>Muat ulang</button>
        {isEmployee && !creating && <button className="primary" disabled={pending} onClick={() => {
          open('')
          setNotice('')
          setCreating(true)
        }}>Buat draf</button>}
      </div>
    </header>
    {notice && <p className="mb-4 text-emerald-800" role="status">{notice}</p>}
    <div className="grid items-start gap-4 md:grid-cols-[minmax(14rem,1fr)_minmax(0,2fr)]">
      <section className="schedule grid gap-3 p-5" aria-label="Daftar laporan">
        <h2 className="font-bold">Laporan</h2>
        {listError ? <p role="alert" className="error">{listError}</p> : !rows
          ? <p role="status">Memuat laporan…</p> : rows.length === 0
          ? <p className="muted">Belum ada laporan serah terima.</p>
          : <ul className="grid gap-2">{rows.map((row) => <li key={row.id}>
            <button className="w-full text-left aria-pressed:border-blue-700 aria-pressed:bg-blue-50"
              disabled={pending || creating} aria-pressed={row.id === selected} onClick={() => open(row.id)}>
              Laporan #{row.id} · {statuses[row.status]}
              {row.summary && <span className="muted block truncate font-normal">{row.summary}</span>}
            </button>
          </li>)}</ul>}
      </section>
      <section className="schedule grid gap-4 p-5" aria-label="Rincian laporan">
        {actionError && <p role="alert" className="error">{actionError}</p>}
        {creating ? <>
          <h2 className="text-xl font-bold">Draf baru</h2>
          <DraftForm user={user} rows={rows ?? []} pending={pending} onExpired={onExpired} onClose={() => {
            setCreating(false)
            setActionError('')
          }} onSave={(content) => save('/handovers', 'POST', content, 'Draf dibuat.')} />
        </> : detail && report ? <>
          <h2 className="text-xl font-bold">Laporan #{report.id} · {statuses[report.status]}</h2>
          <dl className="grid gap-3 sm:grid-cols-2">
            <div><dt className="font-semibold">Shift keluar</dt><dd>{shiftLabel(detail.source, report.source_assignment_id, user)}</dd></div>
            <div><dt className="font-semibold">Shift penerima</dt><dd>{shiftLabel(detail.recipient, report.recipient_assignment_id, user)}</dd></div>
            {report.submitted_at && <div><dt className="font-semibold">Dikirim</dt><dd>{formatDate(report.submitted_at, user.organization_timezone)}</dd></div>}
            {report.acknowledged_at && <div><dt className="font-semibold">Diakui</dt><dd>{formatDate(report.acknowledged_at, user.organization_timezone)}</dd></div>}
          </dl>
          {canEdit && detail.source ? <DraftForm key={report.version} user={user} rows={[]} source={detail.source}
            report={report} pending={pending} onExpired={onExpired}
            onSave={(content) => save(path, 'PATCH', { ...content, version: report.version }, 'Draf diperbarui.')}
            onSubmitReport={(recipientId) => save(path + '/submit', 'POST', {
              version: report.version, recipient_assignment_id: recipientId,
            }, 'Laporan dikirim.')} /> : <dl className="grid gap-3">
            <div><dt className="font-semibold">Ringkasan</dt><dd className="whitespace-pre-wrap break-words">{report.summary || '—'}</dd></div>
            <div><dt className="font-semibold">Kendala</dt><dd className="whitespace-pre-wrap break-words">{report.issues || '—'}</dd></div>
          </dl>}
          {report.tasks.length > 0 && <section>
            <h3 className="font-semibold">Tugas tindak lanjut</h3>
            <ul className="mt-2 grid gap-2">{report.tasks.map((task) => <li key={task.id} className="rounded-lg bg-slate-50 p-3">
              <strong>{task.title}</strong> · {taskStatuses[task.status]}
              {task.details && <p className="whitespace-pre-wrap break-words">{task.details}</p>}
            </li>)}</ul>
          </section>}
          {ownsSource && report.status === 'DRAFT' && !canEdit && <div>
            <button className="primary" disabled={pending} onClick={() => void save(path + '/adopt', 'POST',
              { version: report.version }, 'Draf diambil alih.')}>Ambil alih draf</button>
          </div>}
          {canAcknowledge && detail.recipient && <div className="grid justify-items-start gap-2">
            <button className="primary" disabled={pending || Date.parse(detail.recipient.starts_at) > Date.now()}
              onClick={() => void save(path + '/acknowledge', 'POST', { version: report.version },
                'Penerimaan laporan diakui.')}>Akui penerimaan</button>
            {Date.parse(detail.recipient.starts_at) > Date.now() &&
              <p className="muted">Tombol tersedia setelah shift penerima dimulai. Muat ulang saat shift dimulai.</p>}
          </div>}
        </> : detailError ? <p role="alert" className="error">{detailError}</p>
          : selected ? <p role="status">Memuat rincian…</p> : <p className="muted">Pilih laporan untuk melihat rincian.</p>}
      </section>
    </div>
  </>
}

function DraftForm({ user, rows, source, report, pending, onExpired, onClose, onSave, onSubmitReport }: {
  user: User
  rows: Report[]
  source?: Shift
  report?: Detail
  pending: boolean
  onExpired: () => void
  onClose?: () => void
  onSave: (content: object) => Promise<void>
  onSubmitReport?: (recipientId: string) => Promise<void>
}) {
  const zone = user.organization_timezone
  const [week, setWeek] = useState(() => DateTime.now().setZone(zone).startOf('week'))
  const [shifts, setShifts] = useState<Shift[] | null>(null)
  const [sourceId, setSourceId] = useState('')
  const [recipientId, setRecipientId] = useState(report?.recipient_assignment_id ?? '')
  const [recipients, setRecipients] = useState<Shift[] | null>(null)
  const [summary, setSummary] = useState(report?.summary ?? '')
  const [issues, setIssues] = useState(report?.issues ?? '')
  const [shiftError, setShiftError] = useState('')
  const [recipientError, setRecipientError] = useState('')
  const [retry, setRetry] = useState(0)
  const outgoing = source ?? shifts?.find((shift) => shift.id === sourceId)
  const endsAt = outgoing?.ends_at
  const [recipientWindow, setRecipientWindow] = useState(0)
  const windowStart = endsAt ? DateTime.fromISO(endsAt, { zone: 'utc' }).plus({ days: recipientWindow * 31 }).toISO() : null
  const contentChanged = summary !== (report?.summary ?? '') || issues !== (report?.issues ?? '')

  useEffect(() => {
    if (source) return
    const controller = new AbortController()
    list<Shift>('/assignments', {
      from: week.toUTC().toISO()!, to: week.plus({ weeks: 1 }).toUTC().toISO()!,
    }, { signal: controller.signal }).then(setShifts).catch((error) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setShiftError(describeError(error))
    })
    return () => controller.abort()
  }, [source, week, onExpired, retry])

  useEffect(() => {
    if (!windowStart) return
    const controller = new AbortController()
    const from = DateTime.fromISO(windowStart, { zone: 'utc' })
    list<Shift>('/assignments', {
      from: windowStart, to: from.plus({ days: 31 }).toISO()!, view: 'team',
    }, { signal: controller.signal }).then((items) => {
      if (controller.signal.aborted) return
      setRecipients(items.filter((shift) => shift.status !== 'CANCELLED' && Date.parse(shift.starts_at) >= from.toMillis()))
    }).catch((error) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setRecipientError(describeError(error))
    })
    return () => controller.abort()
  }, [windowStart, onExpired, retry])

  function changeWeek(step: number) {
    setWeek(week.plus({ weeks: step }))
    setShifts(null)
    setShiftError('')
    chooseSource('')
  }

  function chooseSource(id: string) {
    setSourceId(id)
    setRecipientWindow(0)
    setRecipients(null)
    setRecipientError('')
    setRecipientId('')
  }

  function browseRecipients(step: number) {
    setRecipientWindow(recipientWindow + step)
    setRecipients(null)
    setRecipientError('')
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!outgoing || pending) return
    void onSave({
      ...(!report && { source_assignment_id: outgoing.id }),
      ...(recipientId && { recipient_assignment_id: recipientId }),
      summary, issues,
    })
  }

  return <form className="grid gap-4" onSubmit={submit}>
    <fieldset className="grid min-w-0 gap-4" disabled={pending}>
      {!source && <>
        <div className="period">
          <strong>{formatDate(week.toISO()!, zone).split(',')[0]} – {formatDate(week.plus({ days: 6 }).toISO()!, zone).split(',')[0]}</strong>
          <div className="week-buttons">
            <button type="button" onClick={() => changeWeek(-1)}>Minggu sebelumnya</button>
            <button type="button" onClick={() => changeWeek(1)}>Minggu berikutnya</button>
          </div>
        </div>
        <label>Shift keluar<select required value={sourceId} disabled={!shifts} onChange={(event) => chooseSource(event.target.value)}>
          <option value="">Pilih shift keluar</option>
          {shifts?.filter((shift) => shift.status !== 'CANCELLED' && !rows.some((row) => row.source_assignment_id === shift.id))
            .map((shift) => <option key={shift.id} value={shift.id}>{shiftLabel(shift, shift.id, user)}</option>)}
        </select></label>
        {!shifts && !shiftError && <p role="status">Memuat shift keluar…</p>}
      </>}
      {outgoing && windowStart && <>
        <label>Shift penerima<select value={recipientId} onChange={(event) => setRecipientId(event.target.value)}>
          {report ? <option value={report.recipient_assignment_id}>Pertahankan shift penerima saat ini</option>
            : <option value="">Tentukan otomatis</option>}
          {recipientId && recipientId !== report?.recipient_assignment_id && !recipients?.some((shift) => shift.id === recipientId) &&
            <option value={recipientId}>Penugasan #{recipientId}</option>}
          {recipients?.filter((shift) => shift.id !== report?.recipient_assignment_id)
            .map((shift) => <option key={shift.id} value={shift.id}>{shiftLabel(shift, shift.id, user)}</option>)}
        </select></label>
        <p className="muted text-sm">Penerima harus mulai paling awal setelah shift keluar berakhir. Jika ada beberapa shift yang mulai bersamaan, pilih salah satunya.</p>
        <div className="actions">
          <button type="button" disabled={recipientWindow === 0} onClick={() => browseRecipients(-1)}>Rentang penerima sebelumnya</button>
          <button type="button" onClick={() => browseRecipients(1)}>Rentang penerima berikutnya</button>
        </div>
        <p className="muted text-sm" role="status">
          {formatDate(windowStart, zone)} – {formatDate(DateTime.fromISO(windowStart, { zone: 'utc' }).plus({ days: 31 }).toISO()!, zone)}
          {recipients ? ' · ' + recipients.length + ' shift' : !recipientError && ' · Memuat pilihan penerima…'}
        </p>
      </>}
      {(shiftError || recipientError) && <div>
        <p role="alert" className="error">{shiftError || recipientError}</p>
        <button type="button" onClick={() => {
          setShiftError('')
          setRecipientError('')
          setRetry((value) => value + 1)
        }}>Coba lagi</button>
      </div>}
      <label>Ringkasan<textarea rows={3} value={summary} onChange={(event) => setSummary(event.target.value)} /></label>
      <label>Kendala<textarea rows={3} value={issues} onChange={(event) => setIssues(event.target.value)} /></label>
      {report && (!report.summary?.trim() || contentChanged) && <p className="muted text-sm">
        Isi ringkasan dan simpan draf sebelum mengirim.
      </p>}
      <div className="actions">
        {onClose && <button type="button" onClick={onClose}>Tutup</button>}
        <button className={report ? undefined : 'primary'} disabled={!outgoing}>Simpan draf</button>
        {report && onSubmitReport && <button type="button" className="primary"
          disabled={contentChanged || !report.summary?.trim()}
          onClick={() => void onSubmitReport(recipientId)}>Kirim laporan</button>}
      </div>
    </fieldset>
  </form>
}
