import { useState } from 'react';
import { EmailTemplate } from '@samchlolaurepartners/shared';

interface Props {
  template: EmailTemplate;
  onSave: (updated: Pick<EmailTemplate, 'subject' | 'body_html'>) => Promise<void>;
}

const VARIABLES = [
  '{{nom_client}}', '{{email_client}}', '{{telephone_client}}',
  '{{dates}}', '{{date_arrivee}}', '{{date_depart}}',
  '{{adultes}}', '{{enfants}}', '{{hebergement}}',
  '{{partenaire}}', '{{notes}}', '{{message}}',
];

export default function EmailTemplateEditor({ template, onSave }: Props) {
  const [subject, setSubject] = useState(template.subject);
  const [body, setBody] = useState(template.body_html);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  const insertVariable = (variable: string) => {
    setBody((prev) => prev + variable);
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      await onSave({ subject, body_html: body });
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-4">
      {/* Subject */}
      <div>
        <label className="label">Objet de l'email</label>
        <input
          type="text"
          className="input"
          value={subject}
          onChange={(e) => setSubject(e.target.value)}
        />
      </div>

      {/* Variable insertion buttons */}
      <div>
        <label className="label">Variables disponibles</label>
        <div className="flex flex-wrap gap-2">
          {VARIABLES.map((v) => (
            <button
              key={v}
              type="button"
              onClick={() => insertVariable(v)}
              className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded font-mono text-gray-700 transition-colors"
            >
              {v}
            </button>
          ))}
        </div>
      </div>

      {/* Body HTML editor */}
      <div>
        <label className="label">Corps de l'email (HTML)</label>
        <textarea
          className="input font-mono text-sm"
          rows={12}
          value={body}
          onChange={(e) => setBody(e.target.value)}
        />
      </div>

      {/* Preview */}
      <details className="border border-gray-200 rounded-lg">
        <summary className="px-4 py-2 text-sm font-medium text-gray-700 cursor-pointer">
          Aperçu HTML
        </summary>
        <div
          className="p-4 prose max-w-none text-sm"
          dangerouslySetInnerHTML={{ __html: body }}
        />
      </details>

      <button
        onClick={handleSave}
        disabled={saving}
        className="btn-primary"
      >
        {saving ? 'Sauvegarde...' : saved ? '✓ Sauvegardé' : 'Sauvegarder'}
      </button>
    </div>
  );
}
