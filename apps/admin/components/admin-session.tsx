'use client';
// UNIT: ADMIN-SESSION
// FILE: admin-session.tsx
// STYLE: none
// PARENT: ADMIN-SHELL, AI-REQUEST-LOG, AUDIT-PANEL, COMPOSER-CLIENT, CRAWL-PANEL, DEMAND-PANEL +33
// USES: LIB-ADMIN-API
// API: GET /admin/me, POST /admin/auth/login, POST /admin/auth/logout
// MAP: docs/PROJECT_MAP.md

/**
 * جلسة المشغّل: مصدر الحقيقة الوحيد لهوية المستخدم وصلاحياته في الواجهة.
 *
 * تُستدعى GET /admin/me عند الإقلاع؛ الرد 401 يعني «غير مسجَّل» فيُحوَّل
 * المستخدم إلى /login. الصلاحيات المعروضة هنا للعرض فقط — الخادم يفرضها
 * مرة أخرى على كل مسار (MIP §8.4)، فإخفاء زر ليس حماية.
 */

import { usePathname, useRouter } from 'next/navigation';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, firstError, setCsrfToken, type Identity } from '../lib/admin-api';

type SessionState =
  | { status: 'loading' }
  | { status: 'anonymous' }
  | { status: 'error'; message: string }
  | { status: 'authenticated'; identity: Identity };

interface SessionContextValue {
  state: SessionState;
  can: (permission: string) => boolean;
  signIn: (email: string, password: string) => Promise<{ ok: true } | { ok: false; message: string }>;
  signOut: () => Promise<void>;
  reload: () => Promise<void>;
}

const SessionContext = createContext<SessionContextValue | null>(null);

export function useSession(): SessionContextValue {
  const value = useContext(SessionContext);
  if (value === null) throw new Error('useSession يجب أن يُستدعى داخل AdminSessionProvider.');
  return value;
}

export function AdminSessionProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<SessionState>({ status: 'loading' });
  const router = useRouter();
  const pathname = usePathname();

  // سلسلة وعود لا `async`: كل تحديث حالة يقع في `then` فلا يُعدّ متزامنًا داخل الأثر.
  const reload = useCallback(
    () =>
      api.get<Identity>('/admin/me').then((result) => {
        if (result.ok) {
          setCsrfToken(result.data.csrfToken);
          setState({ status: 'authenticated', identity: result.data });
          return;
        }
        setCsrfToken(null);
        if (result.status === 401) {
          setState({ status: 'anonymous' });
          return;
        }
        setState({ status: 'error', message: result.message });
      }),
    [],
  );

  useEffect(() => {
    void reload();
  }, [reload]);

  useEffect(() => {
    if (state.status === 'anonymous' && pathname !== '/login') router.replace('/login');
    if (state.status === 'authenticated' && pathname === '/login') router.replace('/');
  }, [state.status, pathname, router]);

  const signIn = useCallback<SessionContextValue['signIn']>(async (email, password) => {
    const result = await api.post<Identity>('/admin/auth/login', { email, password });
    if (!result.ok) return { ok: false, message: firstError(result) };
    setCsrfToken(result.data.csrfToken);
    setState({ status: 'authenticated', identity: result.data });
    return { ok: true };
  }, []);

  const signOut = useCallback(async () => {
    await api.post('/admin/auth/logout');
    setCsrfToken(null);
    setState({ status: 'anonymous' });
    router.replace('/login');
  }, [router]);

  const value = useMemo<SessionContextValue>(
    () => ({
      state,
      can: (permission: string) =>
        state.status === 'authenticated' && state.identity.permissions.includes(permission),
      signIn,
      signOut,
      reload,
    }),
    [state, signIn, signOut, reload],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}
