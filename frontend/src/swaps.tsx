import { DateTime } from 'luxon'
import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, errorMessage, list, read, write } from './api'
import type { Assignment, Option, User } from './api'

const statuses = {
  PENDING_PEER: 'Menunggu rekan', PENDING_APPROVAL: 'Menunggu Supervisor',
  APPROVED: 'Disetujui', REJECTED: 'Ditolak', CANCELLED: 'Dibatalkan', EXPIRED: 'Kedaluwarsa',
}
const actions = {
  accept: 'Terima', 'peer-reject': 'Tolak', approve: 'Setujui', reject: 'Tolak', cancel: 'Batalkan permintaan',
}
type Status = keyof typeof statuses
type Action = keyof typeof actions
type Swap = {
  id: string
  requester_id: string
  target_employee_id: string
  source_assignment_id: string
  target_assignment_id: string
  reason: string
  status: Status
  terminal_reason: string | null
  expires_at: string
}
type Detail = Swap & {
  events: { id: string; to_status: Status; created_at: string }[]
}
type TeamShift = Pick<Assignment, 'id' | 'starts_at' | 'ends_at' | 'shift_type_id'> & {
  employee_id?: string
  display_name?: string
}

const reasons: Record<string, string> = {
  ASSIGNMENT_CHANGED: 'Penugasan sudah berubah sejak permintaan dibuat.',
  ASSIGNMENT_RESERVED: 'Salah satu shift masih terkait dengan permintaan lain.',
  SWAP_EXPIRED: 'Tenggat pertukaran sudah lewat.',
  NOTICE_TOO_SHORT: 'Waktu yang tersisa sebelum shift terlalu singkat.',
  INELIGIBLE_EMPLOYEE: 'Salah satu karyawan tidak lagi memenuhi syarat.',
  SHIFT_DURATION_LIMIT: 'Durasi shift melampaui batas yang berlaku.',
}

function availableActions(row: Swap, user: User): Action[] {
  if (user.role === 'SUPERVISOR' && row.status === 'PENDING_APPROVAL') return ['approve', 'reject']
  if (user.role !== 'EMPLOYEE') return []
  if (row.status === 'PENDING_PEER' && row.target_employee_id === user.id) return ['accept', 'peer-reject']
  if (row.requester_id === user.id && ['PENDING_PEER', 'PENDING_APPROVAL'].includes(row.status)) return ['cancel']
  return []
}

function formatTime(value: string, zone: string) {
  return DateTime.fromISO(value, { zone }).setLocale('id').toFormat('d LLL yyyy, HH:mm')
}

export function Swaps({ user, onExpired }: { user: User; onExpired: () => void }) {
  const [rows, setRows] = useState<Swap[] | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [detail, setDetail] = useState<Detail | null>(null)
  const [shifts, setShifts] = useState<TeamShift[]>([])
  const [creating, setCreating] = useState(false)
  const [pending, setPending] = useState(false)
  const [refresh, setRefresh] = useState(0)
  const [listError, setListError] = useState('')
  const [detailError, setDetailError] = useState('')
  const [actionError, setActionError] = useState('')
  const [message, setMessage] = useState('')
  const zone = user.organization_timezone

  useEffect(() => {
    const controller = new AbortController()
    setListError('')
    void list<Swap>('/swaps', {}, { signal: controller.signal }).then(setRows, (error: unknown) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setListError(errorMessage(error))
    })
    return () => controller.abort()
  }, [refresh, onExpired])

  useEffect(() => {
    if (!selected) return
    const controller = new AbortController()
    const options = { signal: controller.signal }
    setDetailError('')

    async function load(id: string) {
      try {
        const swap = await read<Detail>(`/swaps/${id}`, options)
        const assignments = await Promise.all(
          [swap.source_assignment_id, swap.target_assignment_id].map(async (assignmentId) => {
            try {
              return await read<TeamShift>(`/assignments/${assignmentId}`, options)
            } catch (error) {
              if (error instanceof ApiError && [403, 404].includes(error.status)) return null
              throw error
            }
          }),
        )
        if (controller.signal.aborted) return
        setDetail(swap)
        setShifts(assignments.filter((entry): entry is TeamShift => entry !== null))
      } catch (error) {
        if (controller.signal.aborted) return
        if (error instanceof ApiError && error.status === 401) onExpired()
        else setDetailError(errorMessage(error))
      }
    }

    void load(selected)
    return () => controller.abort()
  }, [selected, refresh, onExpired])

  function select(id: string) {
    if (id !== selected) {
      setDetail(null)
      setSelected(id)
    }
    setCreating(false)
    setActionError('')
    setMessage('')
  }

  async function decide(action: Action) {
    if (!detail || pending) return
    setPending(true)
    setActionError('')
    setMessage('')
    try {
      await write(`/swaps/${detail.id}/${action}`, 'POST', {})
      setMessage(action === 'approve'
        ? 'Pertukaran disetujui. Jadwal terbaru tersedia di kalender.'
        : 'Status permintaan diperbarui.')
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setActionError(error instanceof ApiError ? reasons[error.code] ?? errorMessage(error) : errorMessage(error))
    } finally {
      setDetail(null)
      setRefresh((value) => value + 1)
      setPending(false)
    }
  }

  return <>
    <section className="page-heading">
      <h1>Tukar shift</h1>
      {user.role === 'EMPLOYEE' && !creating && <button className="primary" disabled={pending} onClick={() => {
        setCreating(true)
        setSelected(null)
        setDetail(null)
        setMessage('')
        setActionError('')
      }}>Ajukan pertukaran</button>}
    </section>
    {creating && <SwapForm user={user} onExpired={onExpired} onClose={() => setCreating(false)} onCreated={(id) => {
      select(id)
      setMessage('Permintaan dikirim dan menunggu keputusan rekan.')
      setRefresh((value) => value + 1)
    }} />}
    {message && <p className="my-4 text-emerald-800" role="status">{message}</p>}
    <div className="grid items-start gap-4 lg:grid-cols-[minmax(16rem,1fr)_2fr]">
      <section className="schedule grid gap-3 p-4 sm:p-5" aria-labelledby="swap-list">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="swap-list" className="text-lg font-semibold">Daftar permintaan</h2>
          {!listError && <button disabled={pending} onClick={() => {
            setDetail(null)
            setRefresh((value) => value + 1)
          }}>Muat ulang</button>}
        </div>
        {listError ? <div className="grid gap-2">
          <p role="alert" className="error">{listError}</p>
          <button onClick={() => setRefresh((value) => value + 1)}>Muat ulang</button>
        </div> : !rows ? <p role="status">Memuat permintaan…</p> : rows.length === 0 ? (
          <p className="muted">Belum ada permintaan tukar shift.</p>
        ) : <ul className="grid gap-2">{rows.map((row) => (
          <li key={row.id}><button type="button" disabled={pending || creating} aria-pressed={selected === row.id}
            className="grid w-full gap-1 text-left aria-pressed:border-blue-600 aria-pressed:bg-blue-50"
            onClick={() => select(row.id)}>
            <span>Permintaan #{row.id} · {statuses[row.status]}</span>
            <span className="truncate font-normal muted">{row.reason}</span>
          </button></li>
        ))}</ul>}
      </section>
      {selected && <section className="schedule grid gap-4 p-4 sm:p-5" aria-labelledby="swap-detail">
        <h2 id="swap-detail" className="text-lg font-semibold">Permintaan #{selected}</h2>
        {actionError && <p role="alert" className="error">{actionError}</p>}
        {detailError ? <div className="grid gap-2">
          <p role="alert" className="error">{detailError}</p>
          <button onClick={() => setRefresh((value) => value + 1)}>Muat ulang rincian</button>
        </div> : !detail ? <p role="status">Memuat rincian…</p> : <>
          <dl className="grid gap-3 sm:grid-cols-2">
            <div><dt className="font-semibold">Status</dt><dd>{statuses[detail.status]}</dd></div>
            <div><dt className="font-semibold">Tenggat</dt><dd>{formatTime(detail.expires_at, zone)}</dd></div>
            {([['Shift asal', detail.source_assignment_id], ['Shift tujuan', detail.target_assignment_id]] as const)
              .map(([label, id]) => {
                const shift = shifts.find((entry) => entry.id === id)
                return <div key={id}><dt className="font-semibold">{label}</dt>
                  <dd>{shift
                    ? `${formatTime(shift.starts_at, zone)} – ${formatTime(shift.ends_at, zone)}`
                    : `Penugasan #${id}`}</dd></div>
              })}
            <div className="sm:col-span-2"><dt className="font-semibold">Alasan pengajuan</dt>
              <dd className="whitespace-pre-wrap break-words">{detail.reason}</dd></div>
            {detail.terminal_reason && <div className="sm:col-span-2"><dt className="font-semibold">Hasil keputusan</dt>
              <dd>{reasons[detail.terminal_reason] ?? new ApiError(409, detail.terminal_reason, statuses[detail.status]).message}</dd></div>}
          </dl>
          <div className="actions">{availableActions(detail, user).map((action) => <button key={action}
            disabled={pending} className={action === 'accept' || action === 'approve' ? 'primary' : undefined}
            onClick={() => void decide(action)}>{actions[action]}</button>)}</div>
          {detail.events.length > 0 && <div>
            <h3 className="font-semibold">Riwayat status</h3>
            <ol className="mt-2 grid gap-1 text-sm muted">{detail.events.map((event) => (
              <li key={event.id}>{formatTime(event.created_at, zone)} · {statuses[event.to_status]}</li>
            ))}</ol>
          </div>}
        </>}
      </section>}
    </div>
  </>
}

function SwapForm({ user, onExpired, onClose, onCreated }: {
  user: User
  onExpired: () => void
  onClose: () => void
  onCreated: (id: string) => void
}) {
  const zone = user.organization_timezone
  const [week, setWeek] = useState(() => DateTime.now().setZone(zone).startOf('week'))
  const [source, setSource] = useState<TeamShift | null>(null)
  const [target, setTarget] = useState<TeamShift | null>(null)
  const [data, setData] = useState<{ shifts: TeamShift[]; types: Option[] } | null>(null)
  const [loadError, setLoadError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [pending, setPending] = useState(false)
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    const options = { signal: controller.signal }
    setData(null)
    setLoadError('')
    void Promise.all([
      list<TeamShift>('/assignments', {
        from: week.toUTC().toISO()!, to: week.plus({ weeks: 1 }).toUTC().toISO()!,
        view: 'team', status: 'SCHEDULED',
      }, options),
      list<Option>('/shift-types', {}, options),
    ]).then(([shifts, types]) => {
      if (!controller.signal.aborted) setData({
        shifts: shifts.filter((shift) => Date.parse(shift.starts_at) > Date.now()), types,
      })
    }, (error: unknown) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setLoadError(errorMessage(error))
    })
    return () => controller.abort()
  }, [week, retry, onExpired])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!source || !target || pending) return
    const reason = String(new FormData(event.currentTarget).get('reason') ?? '').trim()
    if (!reason) {
      setSubmitError('Isi alasan pertukaran.')
      return
    }
    setPending(true)
    setSubmitError('')
    try {
      const swap = await write<Swap>('/swaps', 'POST', {
        source_assignment_id: source.id, target_assignment_id: target.id, reason,
      })
      if (!swap) throw new Error('Respons pengajuan kosong.')
      onCreated(swap.id)
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) onExpired()
      else setSubmitError(error instanceof ApiError ? reasons[error.code] ?? errorMessage(error) : errorMessage(error))
    } finally {
      setPending(false)
    }
  }

  const own = data?.shifts.filter((shift) => shift.employee_id === user.id) ?? []
  const peers = data?.shifts.filter((shift) => shift.employee_id !== user.id) ?? []
  function optionText(shift: TeamShift) {
    const type = data?.types.find((entry) => entry.id === shift.shift_type_id)?.name
    return [shift.display_name, type, formatTime(shift.starts_at, zone)].filter(Boolean).join(' · ')
  }

  return <form className="schedule mb-4 grid gap-4 p-4 sm:p-5" onSubmit={(event) => void submit(event)}>
    <h2 className="text-lg font-semibold">Ajukan pertukaran</h2>
    <div className="period">
      <strong>{week.setLocale('id').toFormat('d LLL yyyy')} – {week.plus({ days: 6 }).setLocale('id').toFormat('d LLL yyyy')}</strong>
      <div className="week-buttons">
        <button type="button" disabled={pending || week <= DateTime.now().setZone(zone).startOf('week')}
          onClick={() => setWeek(week.minus({ weeks: 1 }))}>Minggu sebelumnya</button>
        <button type="button" disabled={pending} onClick={() => setWeek(week.plus({ weeks: 1 }))}>Minggu berikutnya</button>
      </div>
    </div>
    {loadError ? <div className="actions"><p role="alert" className="error">{loadError}</p>
      <button type="button" onClick={() => setRetry((value) => value + 1)}>Coba lagi</button></div>
      : !data && <p role="status">Memuat pilihan shift…</p>}
    <div className="grid gap-4 sm:grid-cols-2">
      {[{ label: 'Shift saya', value: source, setValue: setSource, options: own },
        { label: 'Shift rekan', value: target, setValue: setTarget, options: peers }].map(({ label, value, setValue, options }) => (
        <label key={label}>{label}
          <select required disabled={pending || (!data && !value)} value={value?.id ?? ''} onChange={(event) => {
            setValue(options.find((shift) => shift.id === event.target.value) ?? null)
          }}>
            <option value="">{data && options.length === 0 ? 'Tidak ada shift pada minggu ini' : 'Pilih shift'}</option>
            {value && !options.some((shift) => shift.id === value.id) && <option value={value.id}>{optionText(value)}</option>}
            {options.map((shift) => <option key={shift.id} value={shift.id}>{optionText(shift)}</option>)}
          </select>
        </label>
      ))}
    </div>
    <label>Alasan pertukaran<textarea name="reason" required maxLength={1000} rows={3} disabled={pending} /></label>
    {submitError && <p role="alert" className="error">{submitError}</p>}
    <div className="dialog-actions">
      <button type="button" disabled={pending} onClick={onClose}>Tutup</button>
      <button className="primary" disabled={pending || !source || !target}>{pending ? 'Mengirim…' : 'Kirim permintaan'}</button>
    </div>
  </form>
}
