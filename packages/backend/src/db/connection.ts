import mysql from 'mysql2/promise';
import dotenv from 'dotenv';

dotenv.config();

const pool = mysql.createPool({
  host: process.env.DB_HOST ?? 'localhost',
  port: parseInt(process.env.DB_PORT ?? '3306', 10),
  user: process.env.DB_USER ?? 'partners_user',
  password: process.env.DB_PASSWORD ?? 'partners_pass',
  database: process.env.DB_NAME ?? 'partners_db',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  timezone: '+00:00',
});

export default pool;
