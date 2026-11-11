import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import type { ReactNode } from 'react'
import { useAuth } from './auth/AuthContext'
import Layout from './components/Layout'
import { Loading } from './components/ui'
import type { Abilities } from './lib/types'
import LoginPage from './pages/LoginPage'
import DashboardPage from './pages/DashboardPage'
import BookingsPage from './pages/BookingsPage'
import BookingFormPage from './pages/BookingFormPage'
import BookingDetailPage from './pages/booking/BookingDetailPage'
import CustomersPage from './pages/CustomersPage'
import CustomerDetailPage from './pages/CustomerDetailPage'
import IssuesPage from './pages/IssuesPage'
import IssueDetailPage from './pages/IssueDetailPage'
import PaymentsPage from './pages/PaymentsPage'
import ReportsPage from './pages/ReportsPage'
import ExpensesPage from './pages/ExpensesPage'
import SuppliersPage from './pages/SuppliersPage'
import SettingsPage from './pages/SettingsPage'
import ProfilePage from './pages/ProfilePage'
import QuotationsPage from './pages/QuotationsPage'
import QuotationFormPage from './pages/QuotationFormPage'
import QuotationDetailPage from './pages/QuotationDetailPage'
import AirlinesPage from './pages/AirlinesPage'

function RequireAuth({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  const location = useLocation()
  if (loading) return <Loading />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  return <>{children}</>
}

function RequireAbility({ ability, children }: { ability: keyof Abilities; children: ReactNode }) {
  const { can } = useAuth()
  if (!can(ability)) return <div className="card p-6 text-sm text-slate-600">You do not have permission to view this page.</div>
  return <>{children}</>
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<RequireAuth><Layout /></RequireAuth>}>
        <Route index element={<DashboardPage />} />
        <Route path="bookings" element={<BookingsPage />} />
        <Route path="bookings/new" element={<RequireAbility ability="edit-records"><BookingFormPage /></RequireAbility>} />
        <Route path="bookings/:id" element={<BookingDetailPage />} />
        <Route path="bookings/:id/edit" element={<RequireAbility ability="edit-records"><BookingFormPage /></RequireAbility>} />
        <Route path="quotations" element={<QuotationsPage />} />
        <Route path="quotations/new" element={<RequireAbility ability="edit-records"><QuotationFormPage /></RequireAbility>} />
        <Route path="quotations/:id" element={<QuotationDetailPage />} />
        <Route path="quotations/:id/edit" element={<RequireAbility ability="edit-records"><QuotationFormPage /></RequireAbility>} />
        <Route path="airlines" element={<AirlinesPage />} />
        <Route path="customers" element={<CustomersPage />} />
        <Route path="customers/:id" element={<CustomerDetailPage />} />
        <Route path="issues" element={<IssuesPage />} />
        <Route path="issues/:id" element={<IssueDetailPage />} />
        <Route path="payments" element={<PaymentsPage />} />
        <Route path="reports" element={<RequireAbility ability="view-reports"><ReportsPage /></RequireAbility>} />
        <Route path="expenses" element={<RequireAbility ability="manage-expenses"><ExpensesPage /></RequireAbility>} />
        <Route path="suppliers" element={<SuppliersPage />} />
        <Route path="settings" element={<RequireAbility ability="manage-settings"><SettingsPage /></RequireAbility>} />
        <Route path="profile" element={<ProfilePage />} />
        <Route path="*" element={<div className="card p-6 text-sm">Page not found.</div>} />
      </Route>
    </Routes>
  )
}
