import { useEffect, useState } from 'react';
import Navbar from '../../components/Navbar';
import EmailTemplateEditor from '../../components/EmailTemplateEditor';
import api from '../../api';
import { EmailTemplate, EmailTemplateType } from '@samchlolaurepartners/shared';

const TEMPLATE_LABELS: Record<EmailTemplateType, string> = {
  REQUEST_RECEIVED_PARTNER: 'Demande reçue (partenaire)',
  REQUEST_RECEIVED_CLIENT: 'Accusé réception (client)',
  RESERVATION_CONFIRMED: 'Réservation confirmée (client)',
  RESERVATION_CANCELLED: 'Réservation annulée (client)',
  REMINDER: 'Rappel avant arrivée',
};

export default function PartnerTemplates() {
  const [templates, setTemplates] = useState<EmailTemplate[]>([]);
  const [selected, setSelected] = useState<EmailTemplate | null>(null);
  const [loading, setLoading] = useState(true);

  const load = () => {
    api.get<{ data: EmailTemplate[] }>('/api/email-templates')
      .then((res) => setTemplates(res.data.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const handleSave = async (updated: Pick<EmailTemplate, 'subject' | 'body_html'>) => {
    if (!selected) return;
    await api.put(`/api/email-templates/${selected.id}`, updated);
    load();
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-6xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Templates d'emails</h1>

        <div className="flex gap-6">
          {/* Template list */}
          <div className="w-64 flex-shrink-0">
            <div className="card overflow-hidden">
              {loading ? (
                <div className="p-4 text-center text-gray-400">Chargement...</div>
              ) : (
                templates.map((t) => (
                  <button
                    key={t.id}
                    onClick={() => setSelected(t)}
                    className={`w-full text-left px-4 py-3 text-sm border-b border-gray-50 last:border-0 hover:bg-gray-50 transition-colors ${
                      selected?.id === t.id ? 'bg-brand-50 text-brand-600 font-medium' : 'text-gray-700'
                    }`}
                  >
                    {TEMPLATE_LABELS[t.type] ?? t.type}
                  </button>
                ))
              )}
              {!loading && templates.length === 0 && (
                <div className="p-4 text-center text-gray-400 text-sm">
                  Aucun template.<br />Contactez l'administrateur.
                </div>
              )}
            </div>
          </div>

          {/* Editor */}
          <div className="flex-1">
            {selected ? (
              <div className="card p-6">
                <h2 className="font-semibold text-gray-900 mb-4">
                  {TEMPLATE_LABELS[selected.type] ?? selected.type}
                </h2>
                <EmailTemplateEditor template={selected} onSave={handleSave} />
              </div>
            ) : (
              <div className="card p-8 text-center text-gray-400">
                Sélectionnez un template à éditer
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
