-- Unlock PAMELA AINE's account (update with correct username or email if needed)
UPDATE users 
SET status = 'active', account_locked_until = NULL, failed_login_attempts = 0
WHERE username = 'pamelaaine' OR email = 'pamelaaine@example.com';
-- If you know the exact username or email, update the WHERE clause accordingly.
