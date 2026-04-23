import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { TenantProvider } from './context/TenantContext';
import PrivateRoute from './components/PrivateRoute';

import HomePage from './pages/HomePage';
import PropertiesPage from './pages/PropertiesPage';
import PropertyDetailPage from './pages/PropertyDetailPage';
import ContactPage from './pages/ContactPage';
import LoginPage from './pages/LoginPage';

// Partner dashboard
import PartnerDashboard from './pages/partner/Dashboard';
import PartnerReservations from './pages/partner/Reservations';
import PartnerReservationDetail from './pages/partner/ReservationDetail';
import PartnerTemplates from './pages/partner/Templates';
import PartnerSettings from './pages/partner/Settings';

// Admin
import AdminPartners from './pages/admin/Partners';
import AdminPartnerForm from './pages/admin/PartnerForm';
import AdminFees from './pages/admin/Fees';
import AdminVersions from './pages/admin/Versions';
import AdminSync from './pages/admin/Sync';

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <TenantProvider>
          <Routes>
            {/* Public */}
            <Route path="/" element={<HomePage />} />
            <Route path="/properties" element={<PropertiesPage />} />
            <Route path="/properties/:id" element={<PropertyDetailPage />} />
            <Route path="/contact" element={<ContactPage />} />
            <Route path="/login" element={<LoginPage />} />

            {/* Partner Dashboard */}
            <Route path="/partner" element={<PrivateRoute role="partner" />}>
              <Route index element={<Navigate to="/partner/dashboard" replace />} />
              <Route path="dashboard" element={<PartnerDashboard />} />
              <Route path="reservations" element={<PartnerReservations />} />
              <Route path="reservations/:id" element={<PartnerReservationDetail />} />
              <Route path="templates" element={<PartnerTemplates />} />
              <Route path="settings" element={<PartnerSettings />} />
            </Route>

            {/* Admin */}
            <Route path="/admin" element={<PrivateRoute role="admin" />}>
              <Route index element={<Navigate to="/admin/partners" replace />} />
              <Route path="partners" element={<AdminPartners />} />
              <Route path="partners/new" element={<AdminPartnerForm />} />
              <Route path="partners/:id/edit" element={<AdminPartnerForm />} />
              <Route path="fees" element={<AdminFees />} />
              <Route path="versions" element={<AdminVersions />} />
              <Route path="sync" element={<AdminSync />} />
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </TenantProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}
