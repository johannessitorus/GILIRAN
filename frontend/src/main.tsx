import { createRoot } from 'react-dom/client'
import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, errorMessage, read, write } from './api'
import type { User } from './api'
import { Schedule } from './schedule'
import { Swaps } from './swaps'
import './style.css'

const roles = { ADMIN: 'Admin', SUPERVISOR: 'Supervisor', EMPLOYEE: 'Karyawan' }

function Brand() {
  return <div className="brand"><span aria-hidden="true">G</span> GILIRAN</div>
}

function App() {
  const [user, setUser] = useState<User | null>(null)
  const [checking, setChecking] = useState(true)
  const [pending, setPending] = useState(false)
  const [notice, setNotice] = useState('')
  const [startupError, setStartupError] = useState('')
  const [swapPage, setSwapPage] = useState(() => location.hash === '#tukar-shift')

  useEffect(() => {
    function updatePage() { setSwapPage(location.hash === '#tukar-shift') }
    window.addEventListener('hashchange', updatePage)
    return () => window.removeEventListener('hashchange', updatePage)
  }, [])

  const restore = useCallback(async () => {
    setChecking(true)
    setStartupError('')
    try {
      setUser(await read<User>('/auth/me'))
    } catch (error) {
      if (!(error instanceof ApiError && error.status === 401)) setStartupError(errorMessage(error))
    } finally {
      setChecking(false)
    }
  }, [])

  useEffect(() => { void restore() }, [restore])

  const expire = useCallback(() => {
    setUser(null)
    setNotice('Sesi telah berakhir. Masuk kembali untuk membuka jadwal.')
  }, [])

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const fields = new FormData(event.currentTarget)
    setPending(true)
    setNotice('')
    try {
      const account = await write<User>('/auth/login', 'POST', { email: fields.get('email'), password: fields.get('password') })
      setUser(account ?? await read<User>('/auth/me'))
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) await restore()
      else setNotice(errorMessage(error))
    } finally {
      setPending(false)
    }
  }

  async function logout() {
    setPending(true)
    setNotice('')
    try {
      await write('/auth/logout', 'POST', {})
      setUser(null)
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) setUser(null)
      else setNotice(errorMessage(error))
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
            {notice && <p role="alert" className="error">{notice}</p>}
            <button className="primary" disabled={pending}>{pending ? 'Memeriksa…' : 'Masuk'}</button>
          </form>
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
          <a href="#jadwal" aria-current={swapPage ? undefined : 'page'}>{title}</a>
          <a href="#tukar-shift" aria-current={swapPage ? 'page' : undefined}>Tukar shift</a>
        </nav>
        <div className="account">
          <div><strong>{user.name}</strong><span>{roles[user.role]}</span></div>
          <button disabled={pending} onClick={() => void logout()}>{pending ? 'Keluar…' : 'Keluar'}</button>
        </div>
      </div>
    </header>
    <main className="frame workspace">
      {notice && <p role="alert" className="error">{notice}</p>}
      {swapPage
        ? <Swaps key={user.id} user={user} onExpired={expire} />
        : <Schedule key={user.id} user={user} title={title} onExpired={expire} />}
    </main>
  </>
}

createRoot(document.getElementById('root')!).render(<App />)
