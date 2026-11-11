import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthContext'
import { errorMessage } from '../lib/api'
import { Button, ErrorBox, Field, Input } from '../components/ui'
import { ThemeToggle } from '../lib/theme'
import backdrop from '../../asset/airplane.webp'

export default function LoginPage() {
  const { login, user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const signIn = useMutation({
    mutationFn: () => login(email, password),
    onSuccess: () => navigate((location.state as { from?: string } | null)?.from ?? '/', { replace: true }),
  })

  if (user) return <Navigate to="/" replace />

  const submit = (e: FormEvent) => {
    e.preventDefault()
    signIn.mutate()
  }

  return (
    <div className="relative flex min-h-[100dvh] items-center justify-center overflow-hidden bg-ink p-4">
      <img src={backdrop} alt="" aria-hidden className="absolute inset-0 h-full w-full object-cover opacity-70" />
      <div className="absolute inset-0 bg-gradient-to-b from-ink/30 via-ink/40 to-ink/90" aria-hidden />
      <div className="absolute right-4 top-4"><ThemeToggle /></div>

      <form onSubmit={submit} className="relative w-full max-w-sm space-y-4 rounded-2xl border border-gold/30 bg-surface/85 p-6 shadow-2xl backdrop-blur-md sm:p-8">
        <div className="text-center">
          <img src="/images/flyse-logo.png" alt="FLYSE TRAVELS AB" className="mx-auto h-24 w-auto" />
          <h1 className="mt-3 text-lg font-semibold text-slate-900">FLYSE TRAVELS AB</h1>
          <p className="text-sm text-slate-500">Sign in to continue</p>
        </div>
        {signIn.isError && <ErrorBox message={errorMessage(signIn.error)} />}
        <Field label="Email">
          <Input type="email" autoComplete="username" inputMode="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus />
        </Field>
        <Field label="Password">
          <Input type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        <Button type="submit" loading={signIn.isPending} className="w-full py-2.5">Sign in</Button>
      </form>
    </div>
  )
}
