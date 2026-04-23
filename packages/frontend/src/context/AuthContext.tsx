import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import api from '../api';
import { User } from '@samchlolaurepartners/shared';

interface AuthContextValue {
  user: Omit<User, 'password_hash'> | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
}

const AuthContext = createContext<AuthContextValue>({
  user: null,
  loading: true,
  login: async () => {},
  logout: () => {},
});

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<Omit<User, 'password_hash'> | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    if (!token) { setLoading(false); return; }

    api.get<{ data: Omit<User, 'password_hash'> }>('/api/auth/me')
      .then((res) => setUser(res.data.data))
      .catch(() => { localStorage.removeItem('auth_token'); })
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const res = await api.post<{ token: string; user: Omit<User, 'password_hash'> }>(
      '/api/auth/login',
      { email, password }
    );
    localStorage.setItem('auth_token', res.data.token);
    setUser(res.data.user);
  }, []);

  const logout = useCallback(() => {
    localStorage.removeItem('auth_token');
    setUser(null);
    window.location.href = '/login';
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  return useContext(AuthContext);
}
