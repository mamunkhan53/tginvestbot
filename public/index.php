<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Novikov\AdminAuth;
use Novikov\AdminSession;
use Novikov\Database;

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'self' https://replit.com https://*.replit.dev");
header('Strict-Transport-Security: max-age=31536000');
$base = rtrim(getenv('BASE_PATH') ?: '', '/');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = substr((string)$path, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];
$redirect = static function (string $path) use ($base): never {
    header('Location: ' . $base . $path, true, 303);
    exit;
};
$stop = static function (int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
};
try {
    if ($route === '/webhooks/telegram') {
        require dirname(__DIR__) . '/webhooks/telegram.php';
        exit;
    }
    $operation = ltrim($route,'/');
    $isOperation = in_array($operation,\Novikov\Admin\Operations::ROUTES,true);
    if (!$isOperation && !in_array($route, ['/', '/login', '/logout', '/audit', '/mfa', '/recovery', '/recovery-codes'], true)) $stop(404, 'Not found.');
    $allowed = ($isOperation || in_array($route, ['/login', '/mfa', '/recovery'], true)) ? ['GET', 'POST'] : ($route === '/logout' ? ['POST'] : ['GET']);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        $stop(405, 'Method not allowed.');
    }
    AdminSession::start($base);
    $db = Database::connect();
    $auth = new AdminAuth($db, getenv('SESSION_SECRET') ?: '');
    $admin = $auth->current();
    if ($method === 'POST' && !AdminSession::csrfValid($_POST['csrf'] ?? null)) {
        $auth->audit($admin ? (int)$admin['id'] : null, 'csrf_rejected', 'Invalid CSRF token');
        $stop(403, 'Invalid or expired form. Reload the page and try again.');
    }
    $error = null;
    $logs = [];
    $canAudit = $admin && AdminAuth::allowed($admin['role'], 'audit');
    if ($isOperation) {
        if (!$admin) $redirect('/login');
        $notice = $_SESSION['operations_notice'] ?? null;
        unset($_SESSION['operations_notice']);
        if ($method === 'POST') {
            try {
                (new \Novikov\Admin\Mutations($db,$admin))->run($operation,$_POST);
                $_SESSION['operations_notice']='Operation saved and audited.';
                $redirect('/'.$operation);
            } catch (DomainException $e) {
                http_response_code(422);
                $error=$e->getMessage();
            }
        }
        $search=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';
        $number=is_scalar($_GET['p']??1)?filter_var($_GET['p']??1,FILTER_VALIDATE_INT):false;
        $number=max(1,min(10000,$number?:1));
        try {
            $data=(new \Novikov\Admin\Operations($db,$admin))->data($operation,$search,$number);
        } catch (DomainException $e) { $stop(403,$e->getMessage()); }
        if (($_GET['export']??'') === 'csv' && in_array($operation,['reports','transactions'],true)) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$operation.'-page-'.$number.'.csv"');
            $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,array_values($data['columns']));
            foreach($data['rows'] as $row) {
                $values=[];
                foreach(array_keys($data['columns']) as $key) {
                    $value=(string)($row[$key]??'');
                    if(preg_match('/^[\s]*[=+@-]/u',$value))$value="'".$value;
                    $values[]=$value;
                }
                fputcsv($out,$values);
            }
            fclose($out);exit;
        }
        $csrf=$_SESSION['csrf'];$page=$operation;
        require dirname(__DIR__).'/views/operations.php';exit;
    }
    if ($route === '/mfa' || $route === '/recovery') {
        if ($admin) $redirect('/');
        if (session_status() !== PHP_SESSION_ACTIVE) $redirect('/login');
        if ($route === '/mfa' && (!isset($_SESSION['pending_id']) || time() >= $_SESSION['pending_until'])) $redirect('/login');
        if ($method === 'POST') {
            foreach (['code', 'email', 'token', 'password'] as $field) {
                if (isset($_POST[$field]) && (!is_string($_POST[$field]) || strlen($_POST[$field]) > 1024)) $stop(400, 'Invalid form input.');
            }
            $result = $route === '/mfa'
                ? $auth->mfa()->verify(trim($_POST['code'] ?? ''))
                : $auth->mfa()->reset($_POST['email'] ?? '', trim($_POST['token'] ?? ''), $_POST['password'] ?? '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if ($result === 'ok') {
                if ($route === '/recovery') {
                    AdminSession::clear();
                    $redirect('/login');
                }
                $redirect(isset($_SESSION['fresh_codes']) ? '/recovery-codes' : '/');
            }
            http_response_code($result === 'throttled' ? 429 : 401);
            $error = $result === 'throttled' ? 'Too many attempts. Try again in 15 minutes.' : 'Invalid or expired code, authorization, or account access. New passwords must contain at least 16 characters.';
        }
        $page = $route === '/mfa' ? 'mfa' : 'recovery';
    } elseif ($route === '/login') {
        if ($admin) $redirect('/');
        // An expired session was destroyed: obtain a new anonymous token on a fresh request.
        if (session_status() !== PHP_SESSION_ACTIVE) $redirect('/login');
        if ($method === 'POST') {
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            if (!is_string($email) || !is_string($password) || strlen($email) > 254 || strlen($password) > 1024) {
                $stop(400, 'Invalid form input.');
            }
            // Never trust client-supplied forwarding headers. Behind an unconfigured proxy
            // the source bucket is deliberately shared rather than spoofable.
            $result = $auth->login($email, $password, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if ($result === 'mfa') $redirect('/mfa');
            http_response_code($result === 'throttled' ? 429 : 401);
            if ($result === 'throttled') header('Retry-After: 900');
            $error = $result === 'throttled'
                ? 'Too many sign-in attempts. Please try again in 15 minutes.'
                : 'Unable to sign in. Check your credentials and account access.';
        }
        $page = 'login';
    } else {
        if (!$admin) $redirect('/login');
        if ($route === '/logout') {
            // Revoke locally even when the audit database is unavailable.
            AdminSession::clear();
            $auth->audit((int)$admin['id'], 'logout', 'Administrator signed out');
            $redirect('/login');
        }
        $page = $route === '/audit' ? 'audit' : ($route === '/recovery-codes' ? 'recovery-codes' : 'dashboard');
        if($page==='dashboard')$overview=(new \Novikov\Admin\Operations($db,$admin))->overview();
        if ($page === 'recovery-codes') {
            $codes = $_SESSION['fresh_codes'] ?? [];
            unset($_SESSION['fresh_codes']);
        }
        if ($page === 'audit') {
            if (!$canAudit) {
                $auth->audit((int)$admin['id'], 'access_denied', 'Audit permission required');
                $stop(403, 'You do not have permission to view audit logs.');
            }
            $auth->audit((int)$admin['id'], 'audit_viewed', 'Viewed latest 100 audit events');
            $logs = $db->query('SELECT l.id,u.email,l.action,l.reason,l.created_at FROM admin_activity_logs l LEFT JOIN admin_users u ON u.id=l.admin_id ORDER BY l.id DESC LIMIT 100')->fetchAll();
        }
    }
    $csrf = $_SESSION['csrf'];
    require dirname(__DIR__) . '/views/' . (in_array($page, ['mfa', 'recovery', 'recovery-codes'], true) ? 'security' : ($page === 'login' ? 'login' : 'dashboard')) . '.php';
} catch (Throwable $e) {
    // Do not leak DSNs, SQL, request bodies, cookies, or credentials.
    error_log('NOVIKOV admin request failed: ' . get_class($e));
    $stop(503, 'Administrator service unavailable. Check database migrations and secure configuration. Real-money operations remain disabled.');
}
