import React, { createContext, useContext, useEffect, useState } from 'react';
import api from '../api';
import { PartnerPublic } from '@samchlolaurepartners/shared';

interface TenantContextValue {
  partner: PartnerPublic | null;
  loading: boolean;
}

const TenantContext = createContext<TenantContextValue>({ partner: null, loading: true });

export function TenantProvider({ children }: { children: React.ReactNode }) {
  const [partner, setPartner] = useState<PartnerPublic | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get<{ data: PartnerPublic }>('/api/partners/current')
      .then((res) => setPartner(res.data.data))
      .catch(() => setPartner(null))
      .finally(() => setLoading(false));
  }, []);

  return (
    <TenantContext.Provider value={{ partner, loading }}>
      {children}
    </TenantContext.Provider>
  );
}

export function useTenant(): TenantContextValue {
  return useContext(TenantContext);
}
