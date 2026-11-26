import { createRoot } from 'react-dom/client'
import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, errorMessage, read, write } from './api'
import type { User } from './api'
import { Schedule } from './schedule'
import { Swaps } from './swaps'
import { Handovers } from './handovers'
import { AuditLog } from './audit'
import { AccountForm, Business, isAccountPage } from './accounts'
import './style.css'

const roles = { ADMIN: 'Owner', SUPERVISOR: 'Supervisor', EMPLOYEE: 'Karyawan' }

function Brand() {
  return <div className="brand"><span aria-hidden="true">G</span> GILIRAN</div>
}

function App() {
  const [user, setUser] = useState<User | null>(null)
  const [checking, setChecking] = useState(true)
  const [pending, setPending] = useState(false)
  const [notice, setNotice] = useState('')
  const [authError, setAuthError] = useState('')
  const [startupError, setStartupError] = useState('')
  const [route, setRoute] = useState(() => location.hash.slice(1))
  const [page, search] = route.split('?')
  const params = new URLSearchParams(search)
  const swapPage = page === 'tukar-shift'
  const handoverPage = page === 'serah-terima'
  const auditPage = page === 'audit' && user !== null && user.role !== 'EMPLOYEE'
  const businessPage = page === 'usaha' && user?.role === 'ADMIN'

  useEffect(() => {
    function updatePage() { setRoute(location.hash.slice(1)) }
    window.addEventListener('hashchange', updatePage)
    return () => window.removeEventListener('hashchange', updatePage)
  }, [])

  const restore = useCallback(async () => {
    setChecking(true)
    setStartupError('')
    try {
      setUser(await read<User>('/auth/me'))
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) setUser(null)
      else setStartupError(errorMessage(error))
    } finally {
      setChecking(false)
    }
  }, [])

  useEffect(() => { void restore() }, [restore])

  const expire = useCallback(() => {
    setUser(null)
    setAuthError('')
    setNotice('Sesi telah berakhir. Masuk kembali untuk membuka jadwal.')
  }, [])

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const fields = new FormData(event.currentTarget)
    setPending(true)
    setNotice('')
    setAuthError('')
    try {
      const account = await write<User>('/auth/login', 'POST', { email: fields.get('email'), password: fields.get('password') })
      setUser(account ?? await read<User>('/auth/me'))
      const invite = params.get('undangan')
      if (invite && /^[a-f0-9]{64}$/.test(invite)) location.hash = `accept-invite?token=${invite}`
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) await restore()
      else setAuthError(errorMessage(error))
    } finally {
      setPending(false)
    }
  }

  if (!checking && !startupError && isAccountPage(page)) {
    const token = params.get('token') ?? ''
    return <main className="login"><section className="login-panel">
      <Brand />
      <AccountForm key={route} mode={page} token={token} user={user} onComplete={(message) => {
        location.hash = user ? 'jadwal' : 'masuk'
        setNotice(message)
        setAuthError('')
        if (user && page === 'reset-password') void restore()
      }} />
      {user && page === 'accept-invite' && <button disabled={pending} onClick={() => void logout()}>Keluar untuk ganti akun</button>}
      {notice && <p role="status">{notice}</p>}
      {authError && <p role="alert" className="error">{authError}</p>}
    </section></main>
  }

  async function logout() {
    setPending(true)
    setNotice('')
    setAuthError('')
    try {
      await write('/auth/logout', 'POST', {})
      setUser(null)
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) setUser(null)
      else setAuthError(errorMessage(error))
    } finally {
      setPending(false)
    }
  }

  if (checking || startupError || !user) {
    return <main className="login">
      <section className="login-panel">
        <Brand />
        {checking ? <p role="status">Memeriksa sesi…</p> : startupError ? <>
          <p role="alert">{startupError}</p>
          <button className="primary" onClick={() => void restore()}>Coba lagi</button>
        </> : <>
          <h1>Masuk</h1>
          <p className="muted">Masuk dengan akun kerja untuk membuka jadwal shift.</p>
          <form onSubmit={login} className="grid gap-4">
            <label>Surel<input name="email" type="email" autoComplete="username" required /></label>
            <label>Kata sandi<input name="password" type="password" autoComplete="current-password" required /></label>
            {notice && <p role="status">{notice}</p>}
            {authError && <p role="alert" className="error">{authError}</p>}
            <button className="primary" disabled={pending}>{pending ? 'Memeriksa…' : 'Masuk'}</button>
          </form>
          <div className="flex flex-wrap justify-between gap-2 text-sm text-blue-800 underline">
            <a href="#daftar">Daftarkan usaha</a>
            <a href="#lupa-sandi">Lupa kata sandi?</a>
          </div>
        </>}
      </section>
    </main>
  }

  const title = { ADMIN: 'Jadwal organisasi', SUPERVISOR: 'Jadwal tim', EMPLOYEE: 'Jadwal saya' }[user.role]
  return <>
    <header className="topbar">
      <div className="frame topbar-inner">
        <Brand />
        <nav aria-label="Navigasi utama" className="flex flex-wrap gap-2">
          <a href="#jadwal" aria-current={swapPage || handoverPage || businessPage || auditPage ? undefined : 'page'}>{title}</a>
          <a href="#tukar-shift" aria-current={swapPage ? 'page' : undefined}>Tukar shift</a>
          <a href="#serah-terima" aria-current={handoverPage ? 'page' : undefined}>Serah terima</a>
          {user.role !== 'EMPLOYEE' && <a href="#audit" aria-current={auditPage ? 'page' : undefined}>Riwayat audit</a>}
          {user.role === 'ADMIN' && <a href="#usaha" aria-current={businessPage ? 'page' : undefined}>Kelola usaha</a>}
        </nav>
        <div className="account">
          <div><strong>{user.name}</strong><span>{[roles[user.role], user.business_name].filter(Boolean).join(' · ')}</span></div>
          <button disabled={pending} onClick={() => void logout()}>{pending ? 'Keluar…' : 'Keluar'}</button>
        </div>
      </div>
    </header>
    <main className="frame workspace">
      {notice && <p role="status">{notice}</p>}
      {authError && <p role="alert" className="error">{authError}</p>}
      {businessPage ? <Business onExpired={expire} /> : swapPage
        ? <Swaps key={user.id} user={user} onExpired={expire} />
        : handoverPage ? <Handovers key={user.id} user={user} onExpired={expire} />
        : auditPage ? <AuditLog key={user.id} zone={user.organization_timezone} onExpired={expire} />
        : <Schedule key={user.id} user={user} title={title} onExpired={expire} />}
    </main>
  </>
}

createRoot(document.getElementById('root')!).render(<App />)
