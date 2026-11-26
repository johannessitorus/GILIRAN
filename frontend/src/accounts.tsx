import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, errorMessage, list, write } from './api'
import type { Option, User } from './api'

const headings = {
  daftar: 'Daftarkan usaha',
  'lupa-sandi': 'Lupa kata sandi',
  'verify-owner': 'Aktifkan akun Owner',
  'accept-invite': 'Terima undangan',
  'reset-password': 'Buat kata sandi baru',
}
export type AccountPage = keyof typeof headings

export function isAccountPage(value: string): value is AccountPage {
  return Object.hasOwn(headings, value)
}

function formFields(form: HTMLFormElement) {
  const fields: Record<string, FormDataEntryValue> = {}
  new FormData(form).forEach((value, key) => { fields[key] = value })
  return fields
}

export function AccountForm({ mode, token, user, onComplete }: {
  mode: AccountPage; token: string; user: User | null; onComplete: (message: string) => void
}) {
  const [pending, setPending] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const isRegister = mode === 'daftar'
  const isEmailRequest = isRegister || mode === 'lupa-sandi'
  const invitation = mode === 'accept-invite'
  const signInUrl = invitation && !user ? `#masuk?undangan=${encodeURIComponent(token)}` : '#masuk'

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const fields = formFields(event.currentTarget)
    setPending(true)
    setMessage('')
    setError('')
    try {
      if (isEmailRequest) {
        await write(isRegister ? '/auth/register' : '/auth/forgot-password', 'POST', fields)
        setMessage(isRegister
          ? 'Jika pendaftaran dapat diproses, tautan aktivasi akan dikirim ke surel tersebut.'
          : 'Jika akun aktif terdaftar, tautan reset akan dikirim ke surel tersebut.')
      } else {
        await write(`/auth/links/${mode}`, 'POST', { ...fields, token })
        onComplete(invitation ? 'Undangan diterima.' : mode === 'verify-owner'
          ? 'Usaha dan akun Owner sudah aktif. Silakan masuk.'
          : 'Kata sandi sudah diperbarui. Gunakan kata sandi baru untuk masuk.')
      }
    } catch (failure) {
      if (failure instanceof ApiError && failure.code === 'ACCOUNT_EXISTS') {
        setError('Surel ini sudah terhubung ke akun atau keanggotaan lain.')
      } else if (failure instanceof ApiError && failure.status === 404) {
        setError('Tautan sudah digunakan, kedaluwarsa, atau tidak berlaku.')
      } else if (invitation && failure instanceof ApiError && failure.status === 401) {
        setError('Masuk dengan surel yang menerima undangan, lalu buka kembali tautannya.')
      } else setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }

  return <>
    <h1>{headings[mode]}</h1>
    {invitation && !user && <p className="muted">Sudah punya akun? <a className="underline" href={signInUrl}>Masuk untuk menerima undangan</a>.</p>}
    {invitation && user && <p className="muted">Anda masuk sebagai {user.email}.</p>}
    {!isEmailRequest && !/^[a-f0-9]{64}$/.test(token) ? <p className="error" role="alert">Tautan tidak lengkap.</p> : (
      <form className="grid gap-4" onSubmit={(event) => void submit(event)}>
        <fieldset disabled={pending} className="grid gap-4">
          {(isRegister || (invitation && !user)) && <label>Nama<input name="name" required maxLength={160} autoComplete="name" /></label>}
          {isRegister && <label>Nama usaha<input name="business_name" required maxLength={120} autoComplete="organization" /></label>}
          {isEmailRequest && <label>Surel<input name="email" type="email" required maxLength={255} autoComplete="email" /></label>}
          {!isEmailRequest && !(invitation && user) && <>
            <label>Kata sandi baru<input name="password" type="password" required minLength={8} autoComplete="new-password" /></label>
            <label>Ulangi kata sandi<input name="password_confirmation" type="password" required minLength={8} autoComplete="new-password" /></label>
          </>}
          <button className="primary">{pending ? 'Memproses…' : isEmailRequest ? 'Kirim tautan' : headings[mode]}</button>
        </fieldset>
      </form>
    )}
    {error && <p role="alert" className="error">{error}</p>}
    {message && <p role="status">{message}</p>}
    <a href={user ? '#jadwal' : '#masuk'} className="text-blue-800 underline">{user ? 'Kembali ke jadwal' : 'Kembali ke masuk'}</a>
  </>
}

export function Business({ onExpired }: { onExpired: () => void }) {
  const [departments, setDepartments] = useState<Option[] | null>(null)
  const [refresh, setRefresh] = useState(0)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    void list<Option>('/departments', { active: 'true' }, { signal: controller.signal }).then((data) => {
      if (!controller.signal.aborted) setDepartments(data)
    }, (failure: unknown) => {
      if (controller.signal.aborted) return
      if (failure instanceof ApiError && failure.status === 401) onExpired()
      else setError(errorMessage(failure))
    })
    return () => controller.abort()
  }, [refresh, onExpired])

  async function submit(event: FormEvent<HTMLFormElement>, invite: boolean) {
    event.preventDefault()
    const form = event.currentTarget
    const fields = formFields(form)
    setPending(true)
    setError('')
    setMessage('')
    try {
      await write(invite ? '/auth/invitations' : '/departments', 'POST', fields)
      form.reset()
      setMessage(invite ? 'Undangan dikirim.' : 'Cabang atau departemen ditambahkan.')
      if (!invite) setRefresh((value) => value + 1)
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onExpired()
      else setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }

  return <>
    <h1 className="mb-6">Kelola usaha</h1>
    {error && <p role="alert" className="error mb-4">{error}</p>}
    {message && <p role="status" className="mb-4">{message}</p>}
    <div className="grid items-start gap-4 md:grid-cols-2">
      <section className="schedule p-5">
        <h2 className="mb-4 text-lg font-semibold">Cabang atau departemen</h2>
        {departments && <ul className="mb-4 list-inside list-disc">{departments.map((department) =>
          <li key={department.id}>{department.name}</li>)}</ul>}
        <form onSubmit={(event) => void submit(event, false)}>
          <fieldset disabled={pending} className="grid gap-4">
            <label>Nama cabang atau departemen<input name="name" required maxLength={120} /></label>
            <button>Tambahkan cabang atau departemen</button>
          </fieldset>
        </form>
      </section>
      <form className="schedule p-5" onSubmit={(event) => void submit(event, true)}>
        <h2 className="mb-4 text-lg font-semibold">Undang anggota</h2>
        <fieldset disabled={pending || !departments?.length} className="grid gap-4">
          <label>Surel anggota<input name="email" type="email" required maxLength={255} /></label>
          <label><span id="invite-role">Peran</span><select name="role" aria-labelledby="invite-role" required><option value="EMPLOYEE">Karyawan</option>
            <option value="SUPERVISOR">Supervisor</option></select></label>
          <label><span id="invite-department">Cabang atau departemen</span>
            <select name="department_id" aria-labelledby="invite-department" required defaultValue="">
            <option value="" disabled>Pilih cabang atau departemen</option>
            {departments?.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
          </select></label>
          <button className="primary">Kirim undangan</button>
        </fieldset>
      </form>
    </div>
  </>
}
