-- Default admin user
-- Password: changeme (bcrypt hash — update before production!)
-- Generate a new hash with: node -e "const b=require('bcryptjs');b.hash('yourpassword',12).then(console.log)"
INSERT IGNORE INTO users (email, password_hash, role, partner_id)
VALUES (
  'admin@mauritius-booking.com',
  '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBPj/RiAqgR0Uu',
  'admin',
  NULL
);
