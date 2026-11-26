import { useEffect, useState } from 'react'
import { ApiError, errorMessage, readPage, write } from './api'
import { useFeedback } from './feedback'
import { formatLocalTime } from './time'

const messages: Record<string, string> = {
  SWAP_CREATED: 'Ada permintaan tukar shift untuk Anda.',
  SWAP_PENDING_APPROVAL: 'Rekan menerima permintaan. Keputusan Supervisor diperlukan.',
  SWAP_APPROVED: 'Permintaan tukar shift disetujui.',
  SWAP_REJECTED: 'Permintaan tukar shift ditolak.',
  SWAP_CANCELLED: 'Permintaan tukar shift dibatalkan.',
  SWAP_EXPIRED: 'Permintaan tukar shift kedaluwarsa.',
  HANDOVER_SUBMITTED: 'Ada laporan serah terima untuk shift Anda.',
  HANDOVER_ACKNOWLEDGED: 'Penerima telah mengakui laporan serah terima Anda.',
}

type Entry = { id: string; event: string; subject_id: string; created_at: string; read_at: string | null }
const pageSize = 25
const control = 'min-h-11 min-w-11 enabled:cursor-pointer active:underline'

export function Notifications({ zone, onExpired }: { zone: string; onExpired: () => void }) {
  const showFeedback = useFeedback()
  const [page, setPage] = useState(1)
  const [refresh, setRefresh] = useState(0)
  const [entries, setEntries] = useState<Entry[] | null>(null)
  const [total, setTotal] = useState(0)
  const [error, setError] = useState('')
  const [actionError, setActionError] = useState('')
  const [pending, setPending] = useState<string | null>(null)
  const lastPage = Math.max(1, Math.ceil(total / pageSize))

  useEffect(() => {
    const controller = new AbortController()
    const query = new URLSearchParams({ page: String(page), per_page: String(pageSize) })
    readPage<Entry>('/notifications', query, { signal: controller.signal }).then((result) => {
      if (controller.signal.aborted) return
      setTotal(result.meta.total)
      const available = Math.max(1, Math.ceil(result.meta.total / pageSize))
      if (page > available) setPage(available)
      else setEntries(result.data)
    }).catch((failure: unknown) => {
      if (controller.signal.aborted) return
      if (failure instanceof ApiError && failure.status === 401) onExpired()
      else setError(errorMessage(failure))
    })
    return () => controller.abort()
  }, [page, refresh, onExpired])

  function load(nextPage: number) {
    setEntries(null)
    setError('')
    setActionError('')
    if (nextPage === page) setRefresh((value) => value + 1)
    else setPage(nextPage)
  }

  async function markRead(id: string) {
    setPending(id)
    showFeedback(null)
    setActionError('')
    try {
      await write('/notifications/' + id + '/read', 'POST', {})
      setEntries((current) => current?.map((entry) => entry.id === id
        ? { ...entry, read_at: new Date().toISOString() } : entry) ?? null)
      showFeedback('Notifikasi ditandai sebagai dibaca.')
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onExpired()
      else setActionError(errorMessage(failure))
    } finally {
      setPending(null)
    }
  }

  return <>
    <header className="page-heading">
      <h1>Notifikasi</h1>
      <button className={control} disabled={pending !== null || (!entries && !error)} onClick={() => load(1)}>Muat ulang</button>
    </header>
    <section className="schedule grid gap-4 p-4 sm:p-5" aria-label="Daftar notifikasi">
      {actionError && <p role="alert" className="error">{actionError}</p>}
      {error ? <p role="alert" className="error">{error}</p> : !entries
        ? <p role="status">Memuat notifikasi…</p> : entries.length === 0
        ? <p className="muted">Belum ada notifikasi.</p> :
          <ul className="grid gap-4">{entries.map((entry) => {
            const target = entry.event.startsWith('SWAP_') ? 'tukar-shift' : 'serah-terima'
            const record = target === 'tukar-shift' ? 'permintaan tukar' : 'laporan serah terima'
            return <li key={entry.id} className="grid min-w-0 gap-2 border-b border-slate-200 pb-4 last:border-0 last:pb-0">
              <p className="font-semibold">{messages[entry.event] ?? 'Ada pembaruan aktivitas.'}</p>
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <time className="muted" dateTime={entry.created_at}>
                  {formatLocalTime(entry.created_at, zone)}
                </time>
                <span>{entry.read_at ? 'Dibaca' : 'Belum dibaca'}</span>
              </div>
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <a className="flex min-h-11 items-center text-blue-800 underline"
                  href={'#' + target + '?id=' + entry.subject_id}>Buka {record} #{entry.subject_id}</a>
                <button className={control} disabled={pending !== null || entry.read_at !== null}
                  onClick={() => void markRead(entry.id)}>
                  {pending === entry.id ? 'Menyimpan…' : 'Tandai dibaca'}
                </button>
              </div>
            </li>
          })}</ul>}
      {lastPage > 1 && <nav className="flex flex-wrap items-center gap-2" aria-label="Halaman notifikasi">
        <button className={control} disabled={!entries || pending !== null || page <= 1} onClick={() => load(page - 1)}>Sebelumnya</button>
        <p className="text-sm" role="status">Halaman {page} dari {lastPage}</p>
        <button className={control} disabled={!entries || pending !== null || page >= lastPage} onClick={() => load(page + 1)}>Berikutnya</button>
      </nav>}
    </section>
  </>
}
