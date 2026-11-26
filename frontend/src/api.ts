export type Role = 'ADMIN' | 'SUPERVISOR' | 'EMPLOYEE'
export type User = {
  id: string
  name: string
  email: string
  role: Role
  department_id: string | null
  organization_timezone: string
  business_name?: string
}
export type Option = { id: string; name: string; active: boolean }
export type AssignmentStatus = 'SCHEDULED' | 'CANCELLED' | 'COMPLETED'
export type Assignment = {
  id: string
  employee_id: string
  department_id: string
  shift_type_id: string
  starts_at: string
  ends_at: string
  status: AssignmentStatus
  version: number
  available_actions: ('edit' | 'cancel')[]
}

const messages: Record<string, string> = {
  INVALID_CREDENTIALS: 'Surel atau kata sandi tidak sesuai.',
  CSRF_TOKEN_MISMATCH: 'Token sesi kedaluwarsa. Coba kembali.',
  RATE_LIMITED: 'Terlalu banyak percobaan. Tunggu sebentar sebelum mencoba lagi.',
  VERSION_CONFLICT: 'Penugasan sudah diperbarui. Muat ulang jadwal sebelum mengubahnya.',
  ASSIGNMENT_OVERLAP: 'Penugasan bertumpang tindih dengan shift lain.',
  MINIMUM_REST_VIOLATION: 'Jeda istirahat antara penugasan terlalu pendek.',
  QUALIFICATION_REQUIRED: 'Karyawan belum memenuhi kualifikasi tipe shift ini.',
  ASSIGNMENT_HAS_HANDOVER: 'Penugasan terhubung dengan serah terima.',
  INVALID_TRANSITION: 'Status penugasan atau sesi sudah berubah. Muat ulang untuk melihat kondisi terbaru.',
}

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string) {
    super(messages[code] ?? message)
  }
}

type Page<T> = { data: T[]; meta: { total: number } }
type ReadOptions = { signal?: AbortSignal }

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...options,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', ...options.headers },
  })

  if (response.status === 204) return undefined as T
  const body = await response.json().catch(() => null)
  if (!response.ok || body === null) {
    const fields = body?.error?.details?.fields as Record<string, string[]> | undefined
    const message = fields ? Object.values(fields).flat().join(' ') : body?.error?.message
    throw new ApiError(response.status, body?.error?.code ?? '', message ?? 'API tidak merespons seperti yang diharapkan.')
  }
  return body as T
}

export async function read<T>(path: string, options?: ReadOptions) {
  return (await request<{ data: T }>(`/api/v1${path}`, options)).data
}

export async function list<T>(path: string, filters: Record<string, string>, options?: ReadOptions) {
  const query = new URLSearchParams({ ...filters, per_page: '100' })
  const rows: T[] = []
  for (let page = 1; ; page++) {
    query.set('page', String(page))
    const result = await request<Page<T>>(`/api/v1${path}?${query}`, options)
    rows.push(...result.data)
    if (rows.length >= result.meta.total || result.data.length === 0) return rows
  }
}

export async function write<T>(path: string, method: 'POST' | 'PATCH' | 'DELETE', payload: object) {
  await request<void>('/sanctum/csrf-cookie')
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  if (!cookie) throw new ApiError(419, 'CSRF_TOKEN_MISMATCH', '')
  const result = await request<{ data: T } | undefined>(`/api/v1${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) },
    body: JSON.stringify(payload),
  })
  return result?.data
}

export function errorMessage(error: unknown) {
  return error instanceof ApiError ? error.message : 'Koneksi ke server gagal. Coba lagi.'
}
