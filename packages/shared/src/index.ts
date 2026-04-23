// ─── Partner ─────────────────────────────────────────────────────────────────

export interface Partner {
  id: number;
  subdomain: string;
  name: string;
  logo_url: string | null;
  primary_color: string;
  email: string;
  markup_percent: number;
  smtp_host: string | null;
  smtp_port: number | null;
  smtp_user: string | null;
  smtp_pass: string | null;
  active: boolean;
  created_at: string;
  updated_at: string;
}

export type PartnerPublic = Omit<Partner, 'smtp_host' | 'smtp_port' | 'smtp_user' | 'smtp_pass' | 'markup_percent'>;

// ─── Users ───────────────────────────────────────────────────────────────────

export type UserRole = 'admin' | 'partner';

export interface User {
  id: number;
  partner_id: number | null;
  email: string;
  password_hash: string;
  role: UserRole;
  created_at: string;
  updated_at: string;
}

// ─── Reservation Requests ────────────────────────────────────────────────────

export type ReservationRequestStatus = 'pending' | 'confirmed' | 'cancelled';

export interface GuestNationality {
  type: 'adult' | 'child';
  nationality: string;
}

export interface ReservationRequest {
  id: number;
  partner_id: number;
  property_id: string;
  property_name: string;
  client_name: string;
  client_email: string;
  client_phone: string | null;
  checkin_date: string;
  checkout_date: string;
  adults: number;
  children: number;
  guests: GuestNationality[];
  message: string | null;
  status: ReservationRequestStatus;
  created_at: string;
  updated_at: string;
}

// ─── Reservations ─────────────────────────────────────────────────────────────

export interface Reservation {
  id: number;
  request_id: number;
  partner_id: number;
  confirmed_at: string;
  cancelled_at: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

// ─── Email Templates ──────────────────────────────────────────────────────────

export type EmailTemplateType =
  | 'REQUEST_RECEIVED_PARTNER'
  | 'REQUEST_RECEIVED_CLIENT'
  | 'RESERVATION_CONFIRMED'
  | 'RESERVATION_CANCELLED'
  | 'REMINDER';

export interface EmailTemplate {
  id: number;
  partner_id: number;
  type: EmailTemplateType;
  subject: string;
  body_html: string;
  created_at: string;
  updated_at: string;
}

// ─── Email Schedules ──────────────────────────────────────────────────────────

export interface EmailSchedule {
  id: number;
  partner_id: number;
  days_before_arrival: number;
  template_type: EmailTemplateType;
  active: boolean;
  created_at: string;
  updated_at: string;
}

// ─── Cleaning Fees ────────────────────────────────────────────────────────────

export interface CleaningFee {
  id: number;
  property_id: string | null;
  per_person_per_night: number;
  created_at: string;
  updated_at: string;
}

// ─── Tourist Tax ──────────────────────────────────────────────────────────────

export interface TouristTax {
  id: number;
  per_person_per_night: number;
  applies_to_foreigners_only: boolean;
  applies_to_children: boolean;
  created_at: string;
  updated_at: string;
}

// ─── Lodgify ──────────────────────────────────────────────────────────────────

export interface LodgifyPropertyImage {
  url: string;
  text: string | null;
}

export interface LodgifyAmenity {
  name: string;
}

export interface LodgifyProperty {
  id: number;
  name: string;
  description: string;
  images: LodgifyPropertyImage[];
  amenities: LodgifyAmenity[];
  latitude: number | null;
  longitude: number | null;
  max_guests: number;
  bedrooms: number;
  bathrooms: number;
}

export interface LodgifyAvailabilityDay {
  date: string;
  available: boolean;
  min_stay: number;
}

export interface LodgifyRate {
  date_from: string;
  date_to: string;
  price_per_night: number;
  currency: string;
}

// ─── Rate with Markup ─────────────────────────────────────────────────────────

export interface RateWithMarkup extends LodgifyRate {
  price_per_night_with_markup: number;
  markup_percent: number;
}

// ─── Lodgify Cache ────────────────────────────────────────────────────────────

export interface LodgifyCache {
  id: number;
  cache_key: string;
  data: string;
  expires_at: string;
  created_at: string;
}

// ─── App Versions ─────────────────────────────────────────────────────────────

export interface AppVersion {
  id: number;
  version: string;
  deployed_at: string;
  deployed_by: string;
  notes: string | null;
  rolled_back_at: string | null;
}

// ─── DB Migrations ────────────────────────────────────────────────────────────

export interface DbMigration {
  id: number;
  filename: string;
  applied_at: string;
}

// ─── API Response Wrappers ───────────────────────────────────────────────────

export interface ApiResponse<T> {
  data: T;
  message?: string;
}

export interface ApiError {
  error: string;
  message: string;
  statusCode: number;
}

// ─── Auth ─────────────────────────────────────────────────────────────────────

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;
  user: Omit<User, 'password_hash'>;
  partner?: PartnerPublic;
}

// ─── Contact Form ─────────────────────────────────────────────────────────────

export interface ContactFormData {
  name: string;
  email: string;
  phone?: string;
  checkin_date?: string;
  checkout_date?: string;
  adults?: number;
  children?: number;
  guests?: GuestNationality[];
  message: string;
}
