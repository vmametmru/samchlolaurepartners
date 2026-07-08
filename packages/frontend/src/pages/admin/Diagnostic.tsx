import { useState } from 'react';
import Navbar from '../../components/Navbar';
import api from '../../api';

interface DiagnosticData {
  database: { ok: boolean; error?: string };
  lodgify: {
    ok: boolean;
    error?: string;
    http_status?: number | null;
    property_count?: number;
    response_keys?: string[] | null;
    response_body?: unknown;
    sample?: unknown[];
  };
  cache: {
    properties_cached: boolean;
    keys_checked: string[];
  };
  env: {
    NODE_ENV: string;
    PORT: string;
    LODGIFY_BASE_URL: string;
    LODGIFY_API_KEY_SET: boolean;
    CORS_ORIGIN: string;
    DB_HOST: string;
    DB_NAME: string;
  };
}

function StatusBadge({ ok }: { ok: boolean }) {
  return ok ? (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">✓ OK</span>
  ) : (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">✕ Erreur</span>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="card p-6 space-y-3">
      <h2 className="text-lg font-semibold text-gray-800">{title}</h2>
      {children}
    </div>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 text-sm">
      <span className="text-gray-500 shrink-0 w-48">{label}</span>
      <span className="text-gray-900 font-mono text-right break-all">{value}</span>
    </div>
  );
}

export default function AdminDiagnostic() {
  const [loading, setLoading] = useState(false);
  const [data, setData] = useState<DiagnosticData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showRaw, setShowRaw] = useState(false);

  const apiUrl = import.meta.env.VITE_API_URL ?? '(non défini — chemin relatif /api)';

  const runDiagnostic = async () => {
    setLoading(true);
    setError(null);
    setData(null);
    try {
      const res = await api.get<{ data: DiagnosticData }>('/api/diagnostic');
      setData(res.data.data);
    } catch (err: unknown) {
      const axiosErr = err as { response?: { status: number }; message?: string };
      if (axiosErr?.response?.status === 401 || axiosErr?.response?.status === 403) {
        setError('Accès refusé — réservé aux administrateurs.');
      } else {
        setError(`Impossible de joindre le backend : ${axiosErr?.message ?? String(err)}`);
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-3xl mx-auto px-4 py-8 space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Diagnostic système</h1>
            <p className="text-gray-500 text-sm mt-1">
              Vérifie la connectivité backend, la base de données et l'API Lodgify.
            </p>
          </div>
          <button
            onClick={runDiagnostic}
            disabled={loading}
            className="btn-primary"
          >
            {loading ? 'Analyse en cours…' : '▶ Lancer le diagnostic'}
          </button>
        </div>

        {/* Frontend config */}
        <Section title="Configuration frontend">
          <Row label="VITE_API_URL" value={apiUrl} />
          <Row label="URL effective (login)" value={`${apiUrl}/api/auth/login`} />
          <Row label="URL effective (hébergements)" value={`${apiUrl}/api/lodgify/properties`} />
        </Section>

        {error && (
          <div className="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
            ✕ {error}
          </div>
        )}

        {data && (
          <>
            {/* Database */}
            <Section title="Base de données">
              <div className="flex items-center gap-3">
                <StatusBadge ok={data.database.ok} />
                {data.database.error && (
                  <span className="text-red-600 text-sm font-mono">{data.database.error}</span>
                )}
              </div>
              <Row label="Hôte" value={data.env.DB_HOST} />
              <Row label="Base" value={data.env.DB_NAME} />
            </Section>

            {/* Lodgify */}
            <Section title="API Lodgify">
              <div className="flex items-center gap-3">
                <StatusBadge ok={data.lodgify.ok} />
                {data.lodgify.http_status != null && (
                  <span className="text-xs text-gray-500">HTTP {data.lodgify.http_status}</span>
                )}
              </div>
              <Row label="URL de base" value={data.env.LODGIFY_BASE_URL} />
              <Row label="Clé API configurée" value={data.env.LODGIFY_API_KEY_SET ? '✓ Oui' : '✕ Non'} />
              {data.lodgify.ok ? (
                <>
                  <Row label="Propriétés trouvées" value={String(data.lodgify.property_count ?? 0)} />
                  {data.lodgify.response_keys && (
                    <Row label="Clés de réponse" value={data.lodgify.response_keys.join(', ')} />
                  )}
                </>
              ) : (
                <div className="bg-red-50 border border-red-200 rounded p-3 text-sm text-red-700 font-mono break-all">
                  {data.lodgify.error}
                  {data.lodgify.response_body != null && (
                    <pre className="mt-2 text-xs whitespace-pre-wrap">
                      {JSON.stringify(data.lodgify.response_body, null, 2)}
                    </pre>
                  )}
                </div>
              )}
            </Section>

            {/* Cache */}
            <Section title="Cache">
              <Row
                label="Propriétés en cache"
                value={data.cache.properties_cached ? '✓ Oui' : '✕ Non (sera rechargé au prochain appel)'}
              />
            </Section>

            {/* Environnement */}
            <Section title="Environnement backend">
              <Row label="NODE_ENV" value={data.env.NODE_ENV} />
              <Row label="PORT" value={data.env.PORT} />
              <Row label="CORS_ORIGIN" value={data.env.CORS_ORIGIN} />
            </Section>

            {/* Lodgify sample */}
            {data.lodgify.ok && data.lodgify.sample && data.lodgify.sample.length > 0 && (
              <Section title="Échantillon de propriétés Lodgify (2 premières)">
                <button
                  onClick={() => setShowRaw(!showRaw)}
                  className="text-sm text-brand-500 hover:underline"
                >
                  {showRaw ? 'Masquer' : 'Afficher'} les données brutes
                </button>
                {showRaw && (
                  <pre className="bg-gray-100 rounded p-4 text-xs overflow-auto max-h-96 text-gray-800 whitespace-pre-wrap">
                    {JSON.stringify(data.lodgify.sample, null, 2)}
                  </pre>
                )}
              </Section>
            )}
          </>
        )}
      </div>
    </div>
  );
}
