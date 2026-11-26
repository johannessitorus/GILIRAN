import { createContext, useCallback, useContext, useState } from 'react'
import type { ReactNode } from 'react'

type Feedback = { message: string; kind: 'success' | 'info' }
const FeedbackContext = createContext<(message: string | null, kind?: Feedback['kind']) => void>(() => {})

export function FeedbackProvider({ children }: { children: ReactNode }) {
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  const show = useCallback((message: string | null, kind: Feedback['kind'] = 'success') => {
    setFeedback(message === null ? null : { message, kind })
  }, [])

  return <FeedbackContext.Provider value={show}>
    {children}
    <div className="feedback-viewport">
      <div className={feedback ? 'feedback-card' : undefined}>
        <p role="status" aria-atomic="true" className="min-w-0 break-words">
          {feedback && <><strong className={feedback.kind === 'success' ? 'block text-emerald-800' : 'block text-blue-800'}>
            {feedback.kind === 'success' ? 'Berhasil' : 'Informasi'}
          </strong>{feedback.message}</>}
        </p>
        {feedback && <button type="button" className="min-h-11 cursor-pointer active:underline"
          aria-label="Tutup pemberitahuan" onClick={() => setFeedback(null)}>Tutup</button>}
      </div>
    </div>
  </FeedbackContext.Provider>
}

export function useFeedback() {
  return useContext(FeedbackContext)
}
