import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useTenant } from '../context/TenantContext';

export default function Navbar() {
  const { user, logout } = useAuth();
  const { partner } = useTenant();
  const navigate = useNavigate();

  const primaryColor = partner?.primary_color ?? '#E61E4D';

  return (
    <nav className="bg-white border-b border-gray-200 sticky top-0 z-50 shadow-sm">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Logo / Brand */}
          <Link to="/" className="flex items-center gap-3">
            {partner?.logo_url ? (
              <img src={partner.logo_url} alt={partner.name} className="h-8 w-auto" />
            ) : (
              <span className="text-xl font-bold" style={{ color: primaryColor }}>
                {partner?.name ?? 'Partners Portal'}
              </span>
            )}
          </Link>

          {/* Nav links */}
          <div className="hidden md:flex items-center gap-6">
            <Link to="/properties" className="text-sm text-gray-600 hover:text-gray-900 font-medium">
              Hébergements
            </Link>
            <Link to="/contact" className="text-sm text-gray-600 hover:text-gray-900 font-medium">
              Contact
            </Link>
          </div>

          {/* Auth */}
          <div className="flex items-center gap-3">
            {user ? (
              <>
                {user.role === 'admin' && (
                  <Link to="/admin" className="text-sm text-gray-600 hover:text-gray-900">
                    Admin
                  </Link>
                )}
                {(user.role === 'partner' || user.role === 'admin') && (
                  <Link to="/partner/dashboard" className="text-sm text-gray-600 hover:text-gray-900">
                    Dashboard
                  </Link>
                )}
                <button
                  onClick={logout}
                  className="btn-secondary text-sm"
                >
                  Déconnexion
                </button>
              </>
            ) : (
              <button
                onClick={() => navigate('/login')}
                className="btn-primary text-sm"
                style={{ backgroundColor: primaryColor }}
              >
                Connexion
              </button>
            )}
          </div>
        </div>
      </div>
    </nav>
  );
}
