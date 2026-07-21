UPDATE workers w
INNER JOIN users u ON u.worker_id = w.id
SET w.email = u.email
WHERE u.role = 'Personal'
  AND NOT (w.email <=> u.email);
