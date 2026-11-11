import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, setUnauthorizedHandler, tokenStore } from '../lib/api'
import { qk } from '../lib/queryKeys'
import type { Abilities, User } from '../lib/types'

type Me = { user: User; abilities: Abilities }

type AuthState = {
  user: User | null
  abilities: Abilities | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  can: (ability: keyof Abilities) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

/**
 * The signed-in user lives in the TanStack Query cache under `qk.me`.
 * Login stores the token and seeds that cache; logout or any 401 clears every cache.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [token, setToken] = useState(() => tokenStore.get())

  const me = useQuery({
    queryKey: qk.me,
    queryFn: async () => (await api.get<Me>('/me')).data,
    enabled: Boolean(token),
    staleTime: Infinity,
    retry: false,
  })

  const reset = useCallback(() => {
    tokenStore.clear()
    setToken(null)
    queryClient.clear()
  }, [queryClient])

  useEffect(() => setUnauthorizedHandler(reset), [reset])
  useEffect(() => {
    if (me.isError) reset()
  }, [me.isError, reset])

  const loginMutation = useMutation({
    mutationFn: async ({ email, password }: { email: string; password: string }) =>
      (await api.post<Me & { token: string }>('/auth/login', { email, password, device_name: navigator.userAgent.slice(0, 100) })).data,
    onSuccess: ({ token: newToken, user, abilities }) => {
      tokenStore.set(newToken)
      queryClient.setQueryData<Me>(qk.me, { user, abilities })
      setToken(newToken)
    },
  })

  const logoutMutation = useMutation({
    mutationFn: async () => { await api.post('/auth/logout') },
    onSettled: reset,
  })

  const { mutateAsync: loginAsync } = loginMutation
  const { mutateAsync: logoutAsync } = logoutMutation
  const login = useCallback(async (email: string, password: string) => { await loginAsync({ email, password }) }, [loginAsync])
  const logout = useCallback(async () => { await logoutAsync().catch(() => undefined) }, [logoutAsync])

  const data = token ? me.data : undefined
  const value = useMemo<AuthState>(() => ({
    user: data?.user ?? null,
    abilities: data?.abilities ?? null,
    loading: Boolean(token) && me.isPending,
    login,
    logout,
    can: (ability) => Boolean(data?.abilities?.[ability]),
  }), [data, token, me.isPending, login, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
